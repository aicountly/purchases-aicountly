<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * How a supplier claim is actually settled — one resolution at a time, each completed only when
 * what it depends on has happened.
 *
 *   financial_adjustment  a debit note in Books on a chosen ledger; completes when Books names the
 *                         voucher it posted
 *   physical_return       goods sent back on a purchase return raised from here; completes when
 *                         that return's debit note posts
 *   replacement           the supplier sends the goods again; completes when a GRN of the claim's
 *                         order that brought them is linked and has been applied
 *   refund                the supplier pays money back. Purchase never records money: the receipt
 *                         is recorded in Books, and the resolution completes when that receipt is
 *                         linked and Books confirms it — a receipt, from this supplier or to the
 *                         agreed ledger, for at least the amount
 *   non_financial         settled without money — a credit promised on the next order, an apology
 *                         accepted — recorded with a note when approved
 *
 * Each is PROPOSED with its effect spelt out, APPROVED by someone who may settle claims, and the
 * claim is SETTLED only when completed resolutions cover its approved amount; PARTIALLY_SETTLED
 * until then. A failed step is retried on the same key; nothing is marked done on hope.
 */
final class ClaimResolutionService
{
    public const KINDS = ['financial_adjustment', 'physical_return', 'replacement', 'refund', 'non_financial'];
    public const COMMAND_DEBIT_NOTE = 'purchases.claim.debit_note';

    /** Resolutions that count against the approved amount. */
    private const ACTIVE = ['PROPOSED', 'APPROVED', 'IN_PROGRESS', 'UNCERTAIN', 'FAILED', 'BLOCKED', 'COMPLETED'];

    private const EPSILON = 0.00005;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Propose a resolution and say, before anyone approves it, what approving it will do.
     *
     * @param array<string, mixed> $input {kind, amount, adjustment_acc_id?, lines? (physical_return), note?}
     */
    public function propose(int $claimId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'claim.create');

        $kind = (string) ($input['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            Http::validationFailed('A resolution is one of: ' . implode(', ', self::KINDS) . '.', ['field' => 'kind']);
        }
        $amount = round((float) ($input['amount'] ?? 0), 4);
        if ($kind !== 'non_financial' && $amount <= 0) {
            Http::validationFailed('Say how much of the claim this resolves.', ['field' => 'amount']);
        }
        $adjustmentAcc = self::id($input['adjustment_acc_id'] ?? null);
        if ($kind === 'financial_adjustment' && $adjustmentAcc === null) {
            Http::validationFailed('Choose the ledger the debit note is booked to.', ['field' => 'adjustment_acc_id']);
        }
        $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
        if ($kind === 'physical_return' && $lines === []) {
            Http::validationFailed('Say which goods go back.', ['field' => 'lines']);
        }
        $note = self::text($input['note'] ?? null);
        if ($kind === 'non_financial' && $note === null) {
            Http::validationFailed('Say what the supplier agreed to instead of money.', ['field' => 'note']);
        }

        return Db::transaction(function () use ($claimId, $kind, $amount, $adjustmentAcc, $lines, $note) {
            $claim = $this->lockClaim($claimId);
            if (!in_array($claim['status'], ['APPROVED', 'PARTIALLY_SETTLED'], true)) {
                Http::conflict('Approve the claim, for the amount agreed, before resolving it.');
            }
            if (in_array($kind, ['replacement', 'physical_return'], true) && $claim['po_id'] === null) {
                Http::validationFailed('Goods can only be returned or replaced against the order they came on; this claim names none.', ['field' => 'kind']);
            }
            $committed = (float) Db::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM purchase_claim_resolutions
                  WHERE claim_id = :id AND status IN ('" . implode("', '", self::ACTIVE) . "')",
                ['id' => $claimId],
            );
            $approved = (float) ($claim['approved_amount'] ?? $claim['claimed_amount']);
            if ($committed + $amount > $approved + self::EPSILON) {
                Http::validationFailed(sprintf('Only %s of the approved %s is left to resolve.', self::money(max(0.0, $approved - $committed)), self::money($approved)), ['field' => 'amount']);
            }

            $id = (int) Db::insert('purchase_claim_resolutions', [
                'cmp_id'            => $this->ctx->cmpId,
                'fy_id'             => $this->ctx->fyId,
                'bo_id'             => $this->ctx->boId,
                'claim_id'          => $claimId,
                'kind'              => $kind,
                'amount'            => $amount,
                'status'            => 'PROPOSED',
                'proposed_effect'   => $this->effectOf($claim, $kind, $amount, $adjustmentAcc, $lines),
                'adjustment_acc_id' => $adjustmentAcc,
                'lines'             => $lines === [] ? null : $lines,
                'note'              => $note,
                'proposed_by'       => $this->auth->uuid,
            ], 'resolution_id');

            Audit::record($this->ctx, $this->auth, 'claim.resolution_proposed', 'claim', $claimId, null, ['resolution_id' => $id, 'kind' => $kind, 'amount' => $amount]);

            return $this->find($id);
        });
    }

    /**
     * Approve and carry out a resolution — or, for one that failed or whose outcome was lost,
     * carry it out again on the same key.
     */
    public function approve(int $resolutionId, array $input = []): array
    {
        Permissions::assert($this->ctx, $this->auth, 'claim.settle');

        $resolution = Db::transaction(function () use ($resolutionId) {
            $r = $this->lock($resolutionId);
            if (!in_array($r['status'], ['PROPOSED', 'FAILED', 'UNCERTAIN', 'APPROVED'], true)) {
                if ($r['status'] === 'COMPLETED' || $r['status'] === 'IN_PROGRESS') {
                    return $r;
                }
                Http::conflict('This resolution is ' . strtolower((string) $r['status']) . '.');
            }
            if ($r['status'] === 'PROPOSED') {
                Db::update('purchase_claim_resolutions', [
                    'status' => 'APPROVED', 'approved_by' => $this->auth->uuid, 'approved_at' => self::now(), 'updated_at' => self::now(),
                ], ['resolution_id' => $resolutionId]);
                Audit::record($this->ctx, $this->auth, 'claim.resolution_approved', 'claim', (int) $r['claim_id'], null, ['resolution_id' => $resolutionId, 'kind' => $r['kind']]);
            }

            return $this->lock($resolutionId);
        });
        if (in_array($resolution['status'], ['COMPLETED', 'IN_PROGRESS'], true)) {
            return $this->find($resolutionId);
        }

        $claim = Db::first('SELECT * FROM purchase_claims WHERE claim_id = :id AND cmp_id = :cmp', ['id' => (int) $resolution['claim_id'], 'cmp' => $this->ctx->cmpId]);

        switch ($resolution['kind']) {
            case 'non_financial':
                $this->complete($resolutionId, ['note' => $resolution['note']]);
                break;

            case 'financial_adjustment':
                $payload = [
                    'vch_date'     => gmdate('Y-m-d'),
                    'party'        => ['acc_id' => (int) $claim['supplier_account_id']],
                    'bill'         => ['bill_ref' => $claim['claim_no'] . '/' . $resolutionId, 'bill_date' => gmdate('Y-m-d'), 'dr_cr' => 1],
                    'narration'    => 'Debit note settling supplier claim ' . $claim['claim_no'] . ' (' . str_replace('_', ' ', (string) $claim['claim_kind']) . ')',
                    'reference_no' => (string) $claim['claim_no'],
                    'service_lines' => [[
                        'description'     => 'Claim ' . $claim['claim_no'],
                        'purchase_acc_id' => (int) $resolution['adjustment_acc_id'],
                        'qty'             => 1,
                        'rate'            => (float) $resolution['amount'],
                        'amount'          => (float) $resolution['amount'],
                    ]],
                    'source_app'           => 'purchases',
                    'source_document_type' => 'purchases.claim_resolution',
                    'source_document_id'   => $resolutionId,
                ];
                $voucher = (new DebitNotePoster($this->ctx, $this->auth))->post(
                    self::COMMAND_DEBIT_NOTE, 'claim_resolution', $resolutionId, $payload, 'claim ' . $claim['claim_no'], 0,
                    fn (string $status, string $message) => $this->mark($resolutionId, $status, $message),
                );
                $this->complete($resolutionId, $voucher);
                break;

            case 'physical_return':
                $return = (new ReturnClaimService($this->ctx, $this->auth))->createReturn([
                    'supplier_account_id' => (int) $claim['supplier_account_id'],
                    'po_id'               => (int) $claim['po_id'],
                    'return_kind'         => 'physical',
                    'claim_id'            => (int) $claim['claim_id'],
                    'reason_code'         => 'claim',
                    'reason_note'         => 'Settling claim ' . $claim['claim_no'],
                    'lines'               => Db::jsonColumn($resolution['lines']),
                ]);
                $this->progress($resolutionId, ['return_id' => (int) $return['return_id'], 'return_no' => $return['return_no']]);
                break;

            case 'replacement':
            case 'refund':
                // Waits for the GRN or the Books receipt to be linked (link()).
                $this->progress($resolutionId, []);
                break;
        }

        return $this->find($resolutionId);
    }

    /**
     * Link the evidence a replacement or refund waits for, and complete it if the evidence holds.
     *
     * @param array<string, mixed> $input {receipt_request_id} (replacement) | {books_voucher_id} (refund)
     */
    public function link(int $resolutionId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'claim.settle');
        $r = $this->find($resolutionId);
        if ($r === []) {
            Http::notFound('That resolution does not exist.');
        }
        if ($r['status'] === 'COMPLETED') {
            return $r;
        }
        if ($r['status'] !== 'IN_PROGRESS' || !in_array($r['kind'], ['replacement', 'refund'], true)) {
            Http::conflict('Only an approved replacement or refund waits for evidence to be linked.');
        }
        $claim = Db::first('SELECT * FROM purchase_claims WHERE claim_id = :id AND cmp_id = :cmp', ['id' => (int) $r['claim_id'], 'cmp' => $this->ctx->cmpId]);

        if ($r['kind'] === 'replacement') {
            $receiptId = (int) ($input['receipt_request_id'] ?? 0);
            $receipt = Db::first('SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp', ['id' => $receiptId, 'cmp' => $this->ctx->cmpId]);
            if ($receipt === null || (int) $receipt['po_id'] !== (int) $claim['po_id']) {
                Http::validationFailed('Link a goods receipt of the claim\'s order.', ['field' => 'receipt_request_id']);
            }
            if ($receipt['applied_at'] === null) {
                Http::conflict('That goods receipt has not been recorded in Inventory yet; link it once it has.');
            }
            $used = Db::scalar("SELECT resolution_id FROM purchase_claim_resolutions WHERE cmp_id = :cmp AND kind = 'replacement' AND reference->>'receipt_request_id' = :rid AND resolution_id <> :me", ['cmp' => $this->ctx->cmpId, 'rid' => (string) $receiptId, 'me' => $resolutionId]);
            if ($used !== null) {
                Http::conflict('That goods receipt already settles another replacement.');
            }
            $this->complete($resolutionId, ['receipt_request_id' => $receiptId, 'receipt_no' => $receipt['receipt_no'], 'inventory_document_id' => $receipt['inventory_document_id']]);

            return $this->find($resolutionId);
        }

        // Refund: the money came back in Books. Ask Books what it holds, and believe only that.
        $voucherId = (int) ($input['books_voucher_id'] ?? 0);
        if ($voucherId <= 0) {
            Http::validationFailed('Link the receipt voucher in Books that recorded the refund.', ['field' => 'books_voucher_id']);
        }
        $used = Db::scalar("SELECT resolution_id FROM purchase_claim_resolutions WHERE cmp_id = :cmp AND kind = 'refund' AND reference->>'books_voucher_id' = :vid AND resolution_id <> :me", ['cmp' => $this->ctx->cmpId, 'vid' => (string) $voucherId, 'me' => $resolutionId]);
        if ($used !== null) {
            Http::conflict('That receipt already settles another refund.');
        }
        $response = (new BooksClient())->withSession($this->auth->sesKey())->voucher($this->ctx, $voucherId);
        if (!$response['ok']) {
            Http::error($response['status'] === 404 ? 422 : 502, 'books_voucher_unreadable', $response['status'] === 404 ? 'Smart Books has no voucher with that id.' : 'Could not read that voucher from Smart Books. Try again.', ['retryable' => $response['status'] !== 404]);
        }
        $voucher = $response['body']['data'] ?? [];
        $problem = self::refundProblem($voucher, (int) $claim['supplier_account_id'], self::id($r['adjustment_acc_id']), (float) $r['amount']);
        if ($problem !== null) {
            Http::validationFailed($problem, ['field' => 'books_voucher_id']);
        }
        $this->complete($resolutionId, ['books_voucher_id' => $voucherId, 'vch_number' => $voucher['vch_number'] ?? null, 'vch_date' => $voucher['vch_date'] ?? null]);

        return $this->find($resolutionId);
    }

    /** Cancel a resolution that has not been carried out (or that failed and will not be retried). */
    public function cancel(int $resolutionId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'claim.settle');
        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why this resolution is dropped.', ['field' => 'reason']);
        }
        Db::transaction(function () use ($resolutionId, $reason) {
            $r = $this->lock($resolutionId);
            if (!in_array($r['status'], ['PROPOSED', 'APPROVED', 'FAILED', 'BLOCKED', 'IN_PROGRESS'], true)) {
                Http::conflict($r['status'] === 'UNCERTAIN'
                    ? 'Smart Books may already hold this debit note. Retry it to learn the outcome before dropping it.'
                    : 'This resolution is ' . strtolower((string) $r['status']) . '.');
            }
            if ($r['kind'] === 'physical_return' && $r['status'] === 'IN_PROGRESS') {
                $ref = Db::jsonColumn($r['reference']);
                $status = Db::scalar('SELECT status FROM purchase_returns WHERE return_id = :id', ['id' => (int) ($ref['return_id'] ?? 0)]);
                if ($status !== null && $status !== 'CANCELLED' && $status !== 'RECALLED') {
                    Http::conflict('Cancel or recall the return raised for this resolution first.');
                }
            }
            $command = IntegrationCommand::find($this->ctx->cmpId, self::COMMAND_DEBIT_NOTE, 'claim_resolution', $resolutionId);
            if ($command !== null && $command['status'] !== IntegrationCommand::CANCELLED
                && !IntegrationCommand::withdraw((int) $command['command_id'], 'Resolution dropped: ' . $reason)) {
                Http::conflict('Smart Books may already hold this debit note. Retry it to learn the outcome before dropping it.');
            }
            Db::update('purchase_claim_resolutions', [
                'status' => 'CANCELLED', 'cancelled_at' => self::now(), 'cancel_reason' => $reason, 'updated_at' => self::now(),
            ], ['resolution_id' => $resolutionId]);
            $this->recomputeClaim((int) $r['claim_id']);
            Audit::record($this->ctx, $this->auth, 'claim.resolution_cancelled', 'claim', (int) $r['claim_id'], null, ['resolution_id' => $resolutionId], $reason);
        });

        return $this->find($resolutionId);
    }

    /** A return raised by a physical_return resolution has had its debit note posted. */
    public function returnDebited(int $returnId): void
    {
        $r = Db::first(
            "SELECT resolution_id FROM purchase_claim_resolutions
              WHERE cmp_id = :cmp AND kind = 'physical_return' AND status = 'IN_PROGRESS' AND reference->>'return_id' = :rid",
            ['cmp' => $this->ctx->cmpId, 'rid' => (string) $returnId],
        );
        if ($r === null) {
            return;
        }
        $return = Db::first('SELECT return_no, books_debit_note_id, books_debit_note_uuid FROM purchase_returns WHERE return_id = :id', ['id' => $returnId]);
        $this->complete((int) $r['resolution_id'], ['return_id' => $returnId, 'return_no' => $return['return_no'], 'books_debit_note_id' => $return['books_debit_note_id']]);
    }

    /** @return list<array<string, mixed>> */
    public function forClaim(int $claimId): array
    {
        return array_map([self::class, 'decode'], Db::all(
            'SELECT * FROM purchase_claim_resolutions WHERE claim_id = :id AND cmp_id = :cmp ORDER BY resolution_id',
            ['id' => $claimId, 'cmp' => $this->ctx->cmpId],
        ));
    }

    /** @return array<string, mixed> */
    public function find(int $resolutionId): array
    {
        $row = Db::first('SELECT * FROM purchase_claim_resolutions WHERE resolution_id = :id AND cmp_id = :cmp', ['id' => $resolutionId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            return [];
        }
        $row = self::decode($row);
        $row['commands'] = IntegrationCommand::forEntity($this->ctx, 'claim_resolution', $resolutionId);

        return $row;
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $claim
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function effectOf(array $claim, string $kind, float $amount, ?int $adjustmentAcc, array $lines): array
    {
        $supplier = 'the supplier';

        return match ($kind) {
            'financial_adjustment' => [
                'summary' => sprintf('A debit note to %s for %s, booked to ledger #%d. It reduces what is owed to the supplier.', $supplier, self::money($amount), (int) $adjustmentAcc),
                'books'   => ['voucher_type' => 'debit_note', 'amount' => $amount, 'ledger_acc_id' => $adjustmentAcc],
                'stock'   => null,
            ],
            'physical_return' => [
                'summary' => sprintf('A purchase return of %d line(s) on the claim\'s order. The goods leave stock when its debit note posts in Books; the claim counts %s when it does.', count($lines), self::money($amount)),
                'books'   => ['voucher_type' => 'debit_note', 'amount' => $amount, 'when' => 'after dispatch'],
                'stock'   => ['movement' => 'out', 'lines' => count($lines)],
            ],
            'replacement' => [
                'summary' => sprintf('The supplier sends the goods again. Nothing is posted now; it completes when a goods receipt of the order is linked, and counts %s.', self::money($amount)),
                'books'   => null,
                'stock'   => ['movement' => 'in', 'on' => 'a goods receipt of the order'],
            ],
            'refund' => [
                'summary' => sprintf('The supplier pays %s back. The money is recorded in Books as a receipt; this completes when that receipt is linked and Books confirms it.', self::money($amount)),
                'books'   => ['voucher_type' => 'receipt', 'amount' => $amount, 'recorded_in' => 'Books'],
                'stock'   => null,
            ],
            default => [
                'summary' => 'Resolved without money or goods; recorded with the note given.',
                'books'   => null,
                'stock'   => null,
            ],
        };
    }

    /** @param array<string, mixed> $voucher */
    private static function refundProblem(array $voucher, int $supplierId, ?int $ledgerId, float $amount): ?string
    {
        if ((int) ($voucher['vch_type_id'] ?? 0) !== BooksClient::VCH_RECEIPT) {
            return 'That voucher is not a receipt. A refund is recorded in Books as a receipt of money.';
        }
        $credited = 0.0;
        foreach ((array) ($voucher['lines'] ?? []) as $line) {
            $acc = (int) ($line['acc_id'] ?? 0);
            if ((int) ($line['dr_cr'] ?? 0) === 2 && ($acc === $supplierId || ($ledgerId !== null && $acc === $ledgerId))) {
                $credited += (float) ($line['amount'] ?? 0);
            }
        }
        if ($credited <= 0) {
            return 'That receipt does not credit this supplier (or the ledger the refund was agreed to). It is not this refund.';
        }
        if ($credited + 0.01 < $amount) {
            return sprintf('That receipt records %s, less than the %s this refund is for.', self::money($credited), self::money($amount));
        }

        return null;
    }

    /** @param array<string, mixed> $reference */
    private function complete(int $resolutionId, array $reference): void
    {
        Db::transaction(function () use ($resolutionId, $reference) {
            $r = $this->lock($resolutionId);
            if ($r['status'] === 'COMPLETED') {
                return;
            }
            Db::update('purchase_claim_resolutions', [
                'status' => 'COMPLETED', 'reference' => $reference + Db::jsonColumn($r['reference']), 'last_error' => null,
                'completed_at' => self::now(), 'updated_at' => self::now(),
            ], ['resolution_id' => $resolutionId]);
            $this->recomputeClaim((int) $r['claim_id']);
            Audit::record($this->ctx, $this->auth, 'claim.resolution_completed', 'claim', (int) $r['claim_id'], null, ['resolution_id' => $resolutionId, 'kind' => $r['kind'], 'amount' => (float) $r['amount']] + $reference);
        });
    }

    /** @param array<string, mixed> $reference */
    private function progress(int $resolutionId, array $reference): void
    {
        Db::update('purchase_claim_resolutions', ['status' => 'IN_PROGRESS', 'reference' => $reference === [] ? null : $reference, 'updated_at' => self::now()], ['resolution_id' => $resolutionId, 'cmp_id' => $this->ctx->cmpId]);
    }

    private function mark(int $resolutionId, string $status, string $error): void
    {
        Db::update('purchase_claim_resolutions', ['status' => $status, 'last_error' => mb_substr($error, 0, 480), 'updated_at' => self::now()], ['resolution_id' => $resolutionId, 'cmp_id' => $this->ctx->cmpId]);
    }

    /** SETTLED when completed resolutions cover the approved amount; PARTIALLY_SETTLED before. */
    private function recomputeClaim(int $claimId): void
    {
        $claim = $this->lockClaim($claimId);
        if (in_array($claim['status'], ['CLOSED', 'REJECTED'], true)) {
            return;
        }
        $done = (float) Db::scalar("SELECT COALESCE(SUM(amount), 0) FROM purchase_claim_resolutions WHERE claim_id = :id AND status = 'COMPLETED'", ['id' => $claimId]);
        $nonFinancial = (int) Db::scalar("SELECT COUNT(*) FROM purchase_claim_resolutions WHERE claim_id = :id AND status = 'COMPLETED' AND kind = 'non_financial'", ['id' => $claimId]);
        $approved = (float) ($claim['approved_amount'] ?? $claim['claimed_amount']);
        $status = $done + self::EPSILON >= $approved ? 'SETTLED' : (($done > self::EPSILON || $nonFinancial > 0) ? 'PARTIALLY_SETTLED' : 'APPROVED');
        Db::update('purchase_claims', [
            'status' => $status, 'settled_amount' => round($done, 4), 'settled_at' => $status === 'SETTLED' ? self::now() : null, 'updated_at' => self::now(),
        ], ['claim_id' => $claimId, 'cmp_id' => $this->ctx->cmpId]);
    }

    /** @return array<string, mixed> */
    private function lock(int $resolutionId): array
    {
        $row = Db::first('SELECT * FROM purchase_claim_resolutions WHERE resolution_id = :id AND cmp_id = :cmp FOR UPDATE', ['id' => $resolutionId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            Http::notFound('That resolution does not exist.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function lockClaim(int $claimId): array
    {
        $row = Db::first('SELECT * FROM purchase_claims WHERE claim_id = :id AND cmp_id = :cmp FOR UPDATE', ['id' => $claimId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            Http::notFound('That claim does not exist.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        foreach (['proposed_effect', 'reference', 'lines'] as $k) {
            $row[$k] = $row[$k] === null ? null : Db::jsonColumn($row[$k]);
        }

        return $row;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private static function id(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value === 0) ? null : (int) $value;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
