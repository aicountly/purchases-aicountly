<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\IdempotencyKey;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\ResponseSent;

/**
 * Commands Books refused ONLY because their Idempotency-Key was too long — found, confirmed
 * against Books, and re-issued under the key they now go out with.
 *
 * Until keys were sized on the wire (IdempotencyKey), every purchase-return and claim debit note
 * reached Books with a key of 66+ characters, and a bill did too once a company and request id
 * had enough digits. Books answered 400 `idempotency_key_too_long` BEFORE writing anything, the
 * 400 was recorded as a business refusal (BLOCKED), and as the key is deterministic no retry
 * could ever succeed: the return sat DISPATCHED with the supplier's payable overstated.
 *
 * Fixing the key does not move those commands — a BLOCKED command is never sent again on its
 * own. This does, one company at a time, in three steps that each read before they write:
 *
 *   1. candidates()  this product's database only: which commands of the company Books refused
 *                    for the key's length (and any this re-armed earlier but did not get to send)
 *   2. checkBooks()  Books, read-only, as a person: is there ANY voucher for the document — a
 *                    posted one under its reference (the return or claim number, the supplier's
 *                    invoice) or a draft carrying our source identity? Somebody may have entered
 *                    the debit note by hand while it was stuck; re-issuing it then would book it
 *                    twice. Anything found, or no answer, and the command is left alone.
 *   3. reissue()     only for a command Books has just confirmed it holds nothing for: re-armed
 *                    (IntegrationCommand::rearmAfterKeyRefusal) and sent through the SAME
 *                    operation the screen runs — BillService::post, requestDebitNote, a claim
 *                    resolution's approve — as that person, so Books checks their permission and
 *                    every bookkeeping step (the return DEBITED, the order's counters, the claim
 *                    settled) happens exactly as it would from the screen.
 *
 * Idempotent: a command re-issued is no longer a candidate; one re-armed whose send did not
 * happen (the run stopped) is a candidate again and is sent on the next run, under the same key.
 */
final class BooksKeyRecovery
{
    /** The Books voucher each recoverable command posts, and the document it belongs to. */
    private const OPERATIONS = [
        BillService::COMMAND_BILL => [
            'vch_type_id' => BooksClient::VCH_PURCHASE, 'entity' => 'bill_request', 'source_type' => 'purchases.bill', 'what' => 'supplier bill',
        ],
        ReturnClaimService::COMMAND_DEBIT_NOTE => [
            'vch_type_id' => BooksClient::VCH_DEBIT_NOTE, 'entity' => 'purchase_return', 'source_type' => 'purchases.return', 'what' => 'purchase-return debit note',
        ],
        ClaimResolutionService::COMMAND_DEBIT_NOTE => [
            'vch_type_id' => BooksClient::VCH_DEBIT_NOTE, 'entity' => 'claim_resolution', 'source_type' => 'purchases.claim_resolution', 'what' => 'claim debit note',
        ],
    ];

    /** Every state of a Books draft (Books VoucherApprovalService / DraftsController). */
    private const DRAFT_STATUSES = ['draft', 'pending_approval', 'approved', 'rejected', 'posted'];

    /**
     * Commands of one company Books refused for the length of their key.
     *
     * @return list<array<string, mixed>>
     */
    public static function candidates(int $cmpId, ?int $commandId = null): array
    {
        $params = [
            'cmp'     => $cmpId,
            'refusal' => '%' . IntegrationCommand::KEY_TOO_LONG_REFUSAL . '%',
            'by'      => IntegrationCommand::KEY_RECOVERY,
        ];
        $only = '';
        if ($commandId !== null) {
            $only = ' AND command_id = :only';
            $params['only'] = $commandId;
        }
        $rows = Db::all(
            "SELECT command_id, cmp_id, fy_id, bo_id, command_type, entity_type, entity_id, revision,
                    idempotency_key, status, attempts, last_error, last_status_code, resolved_by,
                    request_payload, last_attempt_at, updated_at
               FROM " . IntegrationCommand::TABLE . "
              WHERE cmp_id = :cmp AND target_service = 'books'
                AND ((status = 'BLOCKED' AND last_status_code = 400 AND last_error ILIKE :refusal)
                     OR (status = 'PENDING' AND resolved_by = :by))" . $only . '
              ORDER BY command_id',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $stored = (string) $row['idempotency_key'];
            // What went out before keys were sized: the stored key and its step, unchanged.
            $refused = $stored . ':draft';
            if (strlen($refused) <= IdempotencyKey::BOOKS) {
                continue; // refused for some other key; not this defect
            }
            $payload = Db::jsonColumn($row['request_payload']);
            $operation = self::OPERATIONS[(string) $row['command_type']] ?? null;
            $out[] = [
                'command_id'      => (int) $row['command_id'],
                'cmp_id'          => (int) $row['cmp_id'],
                'fy_id'           => (int) $row['fy_id'],
                'bo_id'           => (int) $row['bo_id'],
                'command_type'    => (string) $row['command_type'],
                'entity_type'     => (string) $row['entity_type'],
                'entity_id'       => (int) $row['entity_id'],
                'revision'        => (int) $row['revision'],
                'status'          => (string) $row['status'],
                'rearmed_earlier' => $row['status'] === IntegrationCommand::PENDING,
                'last_error'      => $row['last_error'],
                'refused_at'      => $row['last_attempt_at'] ?? $row['updated_at'],
                'document_no'     => self::documentNo((string) $row['entity_type'], (int) $row['entity_id'], (int) $row['cmp_id']),
                'supported'       => $operation !== null && $operation['entity'] === $row['entity_type'],
                'vch_type_id'     => $operation['vch_type_id'] ?? null,
                'what'            => $operation['what'] ?? (string) $row['command_type'],
                'bill_ref'        => trim((string) ($payload['bill']['bill_ref'] ?? '')),
                'party_acc_id'    => (int) ($payload['party']['acc_id'] ?? 0),
                'source'          => [
                    'source_app'           => (string) ($payload['source_app'] ?? ''),
                    'source_document_type' => (string) ($payload['source_document_type'] ?? ($operation['source_type'] ?? '')),
                    'source_document_id'   => (int) ($payload['source_document_id'] ?? $row['entity_id']),
                ],
                'stored_key'      => $stored,
                'refused_key'     => $refused,
                'wire_keys'       => BooksClient::wireKeys($stored),
            ];
        }

        return $out;
    }

    /**
     * Ask Books, read-only, whether it holds anything for this command's document.
     *
     * By reference: a POSTED voucher of the type whose number or bill reference is the
     * document's reference (the register search, then an exact comparison). By source: a draft
     * in any state whose payload names this document as its source. In the command's year,
     * across all branches.
     *
     * @param array<string, mixed> $candidate one of candidates()
     * @return array{verdict: 'no_voucher'|'voucher_found'|'unknown', detail: string, matches: list<array<string, mixed>>}
     */
    public static function checkBooks(BooksClient $books, array $candidate): array
    {
        if (!$candidate['supported'] || $candidate['vch_type_id'] === null) {
            return ['verdict' => 'unknown', 'detail' => 'Not a bill or debit note this tool knows how to check.', 'matches' => []];
        }
        $type = (int) $candidate['vch_type_id'];
        $scope = Context::of((int) $candidate['cmp_id'], (int) $candidate['fy_id'], 0);
        $matches = [];

        $reference = (string) $candidate['bill_ref'];
        if ($reference === '') {
            return ['verdict' => 'unknown', 'detail' => 'The stored request names no reference to look for in Books.', 'matches' => []];
        }
        $register = $books->registers($scope, ['vch_type_id' => $type, 'bill_ref' => $reference]);
        if (!$register['ok'] || !is_array($register['body']['data'] ?? null)) {
            return ['verdict' => 'unknown', 'detail' => 'Books did not answer the register search (' . ($register['error'] ?? ('HTTP ' . $register['status'])) . ').', 'matches' => []];
        }
        foreach ($register['body']['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = trim((string) ($row['bill_ref'] ?? ''));
            $number = trim((string) ($row['vch_number'] ?? ''));
            if (strcasecmp($ref, $reference) === 0 || strcasecmp($number, $reference) === 0) {
                $matches[] = [
                    'found_as'     => 'posted voucher',
                    'vch_txn_id'   => (int) ($row['vch_txn_id'] ?? 0),
                    'vch_number'   => $number,
                    'vch_date'     => $row['vch_date'] ?? null,
                    'party_acc_id' => (int) ($row['party_acc_id'] ?? 0),
                    'bill_ref'     => $ref,
                ];
            }
        }

        $source = $candidate['source'];
        foreach (self::DRAFT_STATUSES as $status) {
            $drafts = $books->drafts($scope, $type, $status);
            if (!$drafts['ok'] || !is_array($drafts['body']['data'] ?? null)) {
                return ['verdict' => 'unknown', 'detail' => 'Books did not answer the drafts search (' . ($drafts['error'] ?? ('HTTP ' . $drafts['status'])) . ').', 'matches' => $matches];
            }
            foreach ($drafts['body']['data'] as $draft) {
                $p = is_array($draft['payload'] ?? null) ? $draft['payload'] : [];
                if ((string) ($p['source_app'] ?? '') === 'purchases'
                    && (string) ($p['source_document_type'] ?? '') === $source['source_document_type']
                    && (int) ($p['source_document_id'] ?? 0) === (int) $source['source_document_id']) {
                    $matches[] = [
                        'found_as' => 'draft (' . $status . ')',
                        'draft_id' => (int) ($draft['draft_id'] ?? 0),
                        'status'   => (string) ($draft['status'] ?? $status),
                    ];
                }
            }
        }

        if ($matches !== []) {
            return [
                'verdict' => 'voucher_found',
                'detail'  => sprintf('Books holds %d record(s) for %s %s. Left as it is: a person decides whether that is this %s.', count($matches), $candidate['what'], $reference, $candidate['what']),
                'matches' => $matches,
            ];
        }

        return [
            'verdict' => 'no_voucher',
            'detail'  => sprintf('No posted voucher with reference %s and no draft from this document in Books (year %d, all branches).', $reference, (int) $candidate['fy_id']),
            'matches' => [],
        ];
    }

    /**
     * Re-issue one command Books has just confirmed it holds nothing for, as $auth.
     *
     * @param array<string, mixed> $candidate one of candidates(), whose checkBooks() verdict is no_voucher
     * @return array{outcome: string, detail: string, command_status: ?string, reference: array<string, mixed>}
     */
    public static function reissue(Auth $auth, array $candidate, string $reason): array
    {
        if (!$candidate['supported']) {
            return ['outcome' => 'skipped', 'detail' => 'Not an operation this tool re-issues.', 'command_status' => $candidate['status'], 'reference' => []];
        }
        $ctx = Context::of((int) $candidate['cmp_id'], (int) $candidate['fy_id'], (int) $candidate['bo_id']);
        try {
            // Manage decides, for this person, the company, year and branch the command was
            // made in — exactly as a request from the screen would be decided.
            $ctx->assertAllowed($auth);
        } catch (ResponseSent $e) {
            return ['outcome' => 'not_allowed', 'detail' => $e->getMessage(), 'command_status' => $candidate['status'], 'reference' => []];
        }

        $commandId = (int) $candidate['command_id'];
        if ($candidate['status'] === IntegrationCommand::BLOCKED) {
            $note = sprintf(
                'Re-issued under its new key: Books refused it only for the length of its Idempotency-Key, and holds no voucher for it (checked %s). %s',
                gmdate('Y-m-d H:i') . ' UTC',
                $reason,
            );
            $rearmed = Db::transaction(static function () use ($candidate, $commandId, $note): bool {
                if (!IntegrationCommand::rearmAfterKeyRefusal($commandId, (string) $candidate['stored_key'], $note)) {
                    return false;
                }
                if ($candidate['entity_type'] === 'claim_resolution') {
                    // A refused resolution is BLOCKED and approve() will not carry out a blocked
                    // one; FAILED is the state it carries out again on the same command.
                    Db::run(
                        "UPDATE purchase_claim_resolutions SET status = 'FAILED', last_error = :note, updated_at = NOW()
                          WHERE resolution_id = :id AND cmp_id = :cmp AND status = 'BLOCKED'",
                        ['note' => mb_substr($note, 0, 480), 'id' => (int) $candidate['entity_id'], 'cmp' => (int) $candidate['cmp_id']],
                    );
                }

                return true;
            });
            if (!$rearmed) {
                return ['outcome' => 'skipped', 'detail' => 'The command changed since it was read; run again to see where it is now.', 'command_status' => self::status($commandId), 'reference' => []];
            }
            Audit::record($ctx, $auth, 'integration.key_reissued', 'integration_command', $commandId, [
                'status' => IntegrationCommand::BLOCKED, 'last_error' => $candidate['last_error'], 'refused_key' => $candidate['refused_key'],
            ], [
                'status' => IntegrationCommand::PENDING, 'wire_keys' => $candidate['wire_keys'],
                'entity_type' => $candidate['entity_type'], 'entity_id' => $candidate['entity_id'],
            ], $reason);
        }

        try {
            match ($candidate['command_type']) {
                BillService::COMMAND_BILL => (new BillService($ctx, $auth))->post((int) $candidate['entity_id']),
                ReturnClaimService::COMMAND_DEBIT_NOTE => (new ReturnClaimService($ctx, $auth))->requestDebitNote((int) $candidate['entity_id']),
                ClaimResolutionService::COMMAND_DEBIT_NOTE => (new ClaimResolutionService($ctx, $auth))->approve((int) $candidate['entity_id']),
            };
        } catch (ResponseSent $e) {
            $status = self::status($commandId);

            return [
                'outcome'        => $status === IntegrationCommand::PENDING ? 'rearmed_not_sent' : 'sent_not_accepted',
                'detail'         => $e->getMessage(),
                'command_status' => $status,
                'reference'      => [],
            ];
        }

        $command = IntegrationCommand::byId($commandId) ?? [];

        return [
            'outcome'        => ($command['status'] ?? null) === IntegrationCommand::COMPLETED ? 'reissued' : 'sent_not_accepted',
            'detail'         => ($command['status'] ?? null) === IntegrationCommand::COMPLETED
                ? 'Books accepted it under ' . $candidate['wire_keys']['draft'] . '.'
                : (string) ($command['last_error'] ?? ''),
            'command_status' => $command['status'] ?? null,
            'reference'      => is_array($command['external_reference'] ?? null) ? $command['external_reference'] : [],
        ];
    }

    private static function status(int $commandId): ?string
    {
        $status = Db::scalar('SELECT status FROM ' . IntegrationCommand::TABLE . ' WHERE command_id = :id', ['id' => $commandId]);

        return $status === null ? null : (string) $status;
    }

    /** The number a person knows the document by. */
    private static function documentNo(string $entityType, int $entityId, int $cmpId): ?string
    {
        $value = match ($entityType) {
            'bill_request'     => Db::scalar('SELECT supplier_invoice_no FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp', ['id' => $entityId, 'cmp' => $cmpId]),
            'purchase_return'  => Db::scalar('SELECT return_no FROM purchase_returns WHERE return_id = :id AND cmp_id = :cmp', ['id' => $entityId, 'cmp' => $cmpId]),
            'claim_resolution' => Db::scalar(
                "SELECT c.claim_no || ' / resolution ' || r.resolution_id FROM purchase_claim_resolutions r
                   JOIN purchase_claims c ON c.claim_id = r.claim_id WHERE r.resolution_id = :id AND r.cmp_id = :cmp",
                ['id' => $entityId, 'cmp' => $cmpId],
            ),
            default => null,
        };

        return $value === null ? null : (string) $value;
    }
}
