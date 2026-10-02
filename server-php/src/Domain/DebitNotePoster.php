<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;

/**
 * A debit note in Books, once, on the user's session — for a purchase return and for a claim
 * settled financially alike.
 *
 * The body is stored on the operation's command the first time and replayed verbatim by every
 * retry, under the operation's own key, so a lost answer is recovered by pressing the same button
 * again and cannot post a second debit note. The result is accepted only when Books names the
 * voucher it posted.
 */
final class DebitNotePoster
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $payload the Books debit note (party, bill, lines, source_*)
     * @param (callable(string, string): void)|null $onFailure told FAILED | UNCERTAIN | BLOCKED and
     *        the reason BEFORE the refusal is answered — answering ends the request, so a caller
     *        that records the outcome must be told first, not after
     * @return array{vch_txn_id: int, vch_uuid: ?string, vch_number: ?string, resolved_by: string}
     */
    public function post(string $commandType, string $entityType, int $entityId, array $payload, string $what, int $revision = 0, ?callable $onFailure = null, ?Context $scope = null): array
    {
        // The scope the note is posted in: the document's own year and branch when the caller
        // names it (a return), the request's otherwise. Stored on the command and replayed.
        $command = IntegrationCommand::ensure($scope ?? $this->ctx, 'books', $commandType, $entityType, $entityId, $payload, ['what' => $what], $revision);
        $scope = Context::of((int) $command['cmp_id'], (int) $command['fy_id'], (int) $command['bo_id']);
        $books = (new BooksClient())->withSession($this->auth->sesKey());
        $attempt = IntegrationCommand::attempt(
            $command,
            static fn (array $body, string $key) => $books->createAndPostVoucher($scope, BooksClient::VCH_DEBIT_NOTE, $body, $key),
        );

        if ($attempt['outcome'] === 'already_completed') {
            $ref = (array) ($attempt['command']['external_reference'] ?? []);

            return [
                'vch_txn_id' => (int) ($ref['books_debit_note_id'] ?? 0),
                'vch_uuid'   => isset($ref['books_debit_note_uuid']) ? (string) $ref['books_debit_note_uuid'] : null,
                'vch_number' => isset($ref['books_debit_note_no']) ? (string) $ref['books_debit_note_no'] : null,
                'resolved_by' => 'reconcile',
            ];
        }
        if ($attempt['outcome'] === 'completed') {
            $voucher = $attempt['response']['body']['data'] ?? [];
            $id = (int) ($voucher['vch_txn_id'] ?? $voucher['voucher_id'] ?? 0);
            if ($id <= 0) {
                IntegrationCommand::uncertain((int) $command['command_id'], (string) $attempt['lease'], 'Books answered without a voucher id.', (int) $attempt['response']['status']);
                if ($onFailure !== null) {
                    $onFailure('UNCERTAIN', 'Books answered without a voucher id.');
                }
                Http::error(502, 'books_uncertain', 'Smart Books did not say which debit note it posted. Retry — the same key cannot post it twice.', ['retryable' => true]);
            }
            $reference = [
                'books_debit_note_id'   => $id,
                'books_debit_note_uuid' => $voucher['vch_uuid'] ?? $voucher['voucher_uuid'] ?? null,
                'books_debit_note_no'   => $voucher['vch_number'] ?? $voucher['vch_no'] ?? null,
            ];
            IntegrationCommand::complete((int) $command['command_id'], (string) $attempt['lease'], $reference, 'response', (int) $attempt['response']['status']);

            return [
                'vch_txn_id' => $id,
                'vch_uuid'   => $reference['books_debit_note_uuid'] === null ? null : (string) $reference['books_debit_note_uuid'],
                'vch_number' => $reference['books_debit_note_no'] === null ? null : (string) $reference['books_debit_note_no'],
                'resolved_by' => 'response',
            ];
        }

        $refused = in_array($attempt['outcome'], ['blocked', 'already_blocked', 'withdrawn'], true);
        $message = (string) ($attempt['message'] ?? 'Smart Books did not accept the debit note.');
        if ($refused) {
            $sent = is_array($attempt['command']['request_payload'] ?? null) ? $attempt['command']['request_payload'] : $payload;
            $message = PlaceOfSupply::explainRefusal($message, $sent, 'Send it again');
        }
        if ($onFailure !== null && $attempt['outcome'] !== 'in_progress') {
            $onFailure($refused ? 'BLOCKED' : ($attempt['outcome'] === 'uncertain' ? 'UNCERTAIN' : 'FAILED'), $message);
        }
        Http::error(
            $refused ? 409 : ($attempt['outcome'] === 'in_progress' ? 409 : 502),
            match (true) {
                $refused => 'books_refused',
                $attempt['outcome'] === 'in_progress' => 'debit_note_in_progress',
                $attempt['outcome'] === 'uncertain' => 'books_uncertain',
                default => 'books_unavailable',
            },
            match (true) {
                $refused => $message,
                $attempt['outcome'] === 'in_progress' => 'This debit note is being posted right now. Wait a moment and refresh.',
                $attempt['outcome'] === 'uncertain' => 'Smart Books did not confirm the debit note for ' . $what . '. It may have been posted — Retry cannot post it twice.',
                default => 'Could not post the debit note for ' . $what . ' to Smart Books. Nothing has been posted — press Retry.',
            },
            ['retryable' => !$refused, 'detail' => $message, 'outcome' => $attempt['outcome']],
        );
    }
}
