<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * Goods received but not billed, given back — and a GRN recorded by mistake, undone.
 *
 * Before this, Purchase refused both: a recorded receipt could not be cancelled, and a return
 * was only for goods received AND billed — so goods rejected at inspection after the GRN, or a
 * GRN keyed 100 instead of 10, could be put right only by billing them first, creating a payable
 * for goods the company did not keep.
 *
 * Inventory undoes a goods receipt no bill has settled, through its own endpoints and this
 * product's own key, on the receipt it posted (docs/INVENTORY_API_CONTRACT.md, "Returning goods
 * received but not billed"):
 *
 *   all of it    POST inventory-documents/{id}/reverse — the goods leave the warehouse they were
 *                received into (inspection / quarantine included), the receipt's pending is
 *                withdrawn, and Books un-accrues the GRNI from the reversal
 *   part of it   POST inventory-documents/{id}/revise with the receipt for the quantity KEPT —
 *                one Inventory transaction; the replacement document stands in for the receipt
 *                from then on, and only the kept quantity can be billed
 *   billed       409 invalid_state, nothing changes: goods a bill has settled go back on a
 *                purchase return (debit note) after the bill, never by unpicking the receipt
 *
 * Two operations on this side:
 *
 *   reverse         the GRN was recorded by mistake: the order counts it as never received
 *                   (received and rejected both undone)
 *   returnUnbilled  goods accepted, then sent back before the bill: the order counts them as
 *                   rejected — still owed by the supplier, as goods turned away at the gate are
 *
 * ONE RECORDED STEP. The receipt is marked RETURNING under the order's lock before anything is
 * sent — so no bill can settle goods on their way back — and what the return will do is stored
 * with it. Only when Inventory confirms are the order's counters, the receipt's quantities and
 * its document (the replacement's, after a revise) changed, together, under the same lock.
 * Refused by Inventory: the receipt is billable again exactly as it was. No answer: Retry, on the
 * same key — Inventory replays a reverse and finds the replacement a revise already made.
 */
final class ReceiptReturnService
{
    public const COMMAND = 'purchases.receipt.return';

    private const EPSILON = 0.00005;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /** Undo a GRN recorded by mistake. @param array<string, mixed> $input {reason} */
    public function reverse(int $requestId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'receipt.request');

        return $this->run($requestId, 'reverse', $input);
    }

    /**
     * Give back goods received and not billed — all of them or some.
     *
     * @param array<string, mixed> $input {reason, lines?: [{line_id, qty}]} — no lines: everything
     */
    public function returnUnbilled(int $requestId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        return $this->run($requestId, 'return', $input);
    }

    /**
     * Send again a return or reversal whose answer was lost or that Inventory could not take yet —
     * on its own key and body, so it cannot be done twice. Whoever may start that kind may retry it.
     */
    public function retry(int $requestId): array
    {
        $receipt = $this->require($requestId);
        if ($receipt['status'] !== 'RETURNING') {
            Http::conflict('No return of this GRN is waiting to be sent.', ['request_id' => $requestId]);
        }
        $pending = Db::jsonColumn($receipt['pending_return']);
        Permissions::assert($this->ctx, $this->auth, ($pending['kind'] ?? null) === 'return' ? 'return.approve' : 'receipt.request');

        return $this->send($requestId);
    }

    /**
     * Abandon a return Inventory has not acted on — never sent, or sent and refused by a server
     * error — so the receipt is billable again. Not one whose outcome is unknown: Inventory may
     * have done it, and Retry finds out on the same key.
     *
     * @param array<string, mixed> $input {reason}
     */
    public function withdraw(int $requestId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'receipt.request');
        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why the return is being withdrawn.', ['field' => 'reason']);
        }
        $receipt = $this->require($requestId);

        Db::transaction(function () use ($receipt, $requestId, $reason): void {
            PoProgress::lock((int) $receipt['po_id'], $this->ctx->cmpId);
            $locked = $this->lock($requestId);
            if ($locked['status'] !== 'RETURNING') {
                Http::conflict('No return of this GRN is waiting to be sent.');
            }
            $pending = Db::jsonColumn($locked['pending_return']);
            $command = IntegrationCommand::find($this->ctx->cmpId, self::COMMAND, 'receipt_request', $requestId, (int) ($pending['revision'] ?? 0));
            if ($command !== null && $command['status'] !== IntegrationCommand::CANCELLED
                && !IntegrationCommand::withdraw((int) $command['command_id'], 'Return withdrawn: ' . $reason)) {
                Http::conflict('Inventory may already have taken these goods back. Retry the return to find out; it cannot be done twice.');
            }
            Db::update('purchase_receipt_requests', [
                'status' => 'ACCEPTED', 'pending_return' => null, 'last_error' => null, 'updated_at' => self::now(),
            ], ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);
        });
        Audit::record($this->ctx, $this->auth, 'receipt.return_withdrawn', 'receipt_request', $requestId, ['status' => 'RETURNING'], ['status' => 'ACCEPTED'], $reason);

        return $this->orderView((int) $receipt['po_id'], $requestId);
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $input */
    private function run(int $requestId, string $kind, array $input): array
    {
        $receipt = $this->require($requestId);

        // A return already on its way: this is its retry, on its own key and body — never a
        // second return made while the first may still be landing.
        if ($receipt['status'] === 'RETURNING') {
            $pending = Db::jsonColumn($receipt['pending_return']);
            if (($pending['kind'] ?? null) !== $kind || (isset($input['lines']) && self::askedLines($input['lines']) !== ($pending['asked'] ?? null))) {
                Http::conflict(
                    sprintf('A %s of this GRN is still on its way to Inventory. Retry it as it stands, or withdraw it first.', ($pending['kind'] ?? '') === 'reverse' ? 'reversal' : 'return'),
                    ['request_id' => $requestId, 'retryable' => true],
                );
            }

            return $this->send($requestId);
        }

        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed($kind === 'reverse' ? 'Say why this GRN is being reversed.' : 'Say why these goods are going back.', ['field' => 'reason']);
        }

        $local = Db::transaction(function () use ($requestId, $kind, $input, $reason, $receipt): bool {
            PoProgress::lock((int) $receipt['po_id'], $this->ctx->cmpId);
            $locked = $this->lock($requestId);
            $this->assertReturnable($locked);

            $applied = self::appliedLines($locked);
            $requested = Db::jsonColumn($locked['requested_lines']);
            [$returned, $asked] = $kind === 'reverse'
                ? [array_map(static fn (array $l) => round((float) ($l['qty'] ?? 0), 4), $applied), null]
                : self::allocate($applied, $requested, $input['lines'] ?? null);
            $kept = [];
            foreach ($applied as $i => $line) {
                $kept[$i] = round((float) ($line['qty'] ?? 0) - ($returned[$i] ?? 0.0), 4);
            }
            $rejectedUndo = $kind === 'reverse'
                ? array_map(static fn (array $l) => round((float) ($l['rejected_qty'] ?? 0), 4), $applied)
                : [];

            $pending = [
                'kind'          => $kind,
                'asked'         => $asked,
                'reason'        => $reason,
                'returned'      => $returned,
                'kept'          => $kept,
                'rejected_undo' => $rejectedUndo,
                'document_id'   => $locked['inventory_document_id'] === null ? null : (int) $locked['inventory_document_id'],
                'requested_by'  => $this->auth->uuid,
                'requested_at'  => gmdate('c'),
            ];

            if ($locked['inventory_document_id'] === null) {
                // Everything on it was turned away at the gate, so nothing reached Inventory.
                if ($kind === 'return') {
                    Http::conflict('Nothing on this GRN reached stock — everything on it was rejected at the gate — so there is nothing to give back.');
                }
                $pending['action'] = 'none';
                $pending['revision'] = null;
                $this->applyLocally($requestId, $locked, $pending, null);

                return true;
            }

            $latest = IntegrationCommand::latest($this->ctx->cmpId, self::COMMAND, 'receipt_request', $requestId);
            $revision = $latest === null ? 0 : (int) $latest['revision'] + 1;
            $whole = array_sum(array_map(static fn ($q) => max(0.0, (float) $q), $kept)) <= self::EPSILON;
            $pending['action'] = $whole ? 'reverse' : 'revise';
            $pending['revision'] = $revision;

            $body = $whole
                ? ['action' => 'reverse', 'document_id' => (int) $locked['inventory_document_id'], 'reason' => $reason]
                : [
                    'action'      => 'revise',
                    'document_id' => (int) $locked['inventory_document_id'],
                    // The whole receipt for what is kept: Inventory copies nothing from the
                    // receipt it replaces but its type.
                    'body'        => ['reason' => $reason] + (new ReceiptService($this->ctx, $this->auth))->keptPayload($locked, $kept),
                ];

            Db::update('purchase_receipt_requests', [
                'status' => 'RETURNING', 'pending_return' => $pending, 'last_error' => null, 'updated_at' => self::now(),
            ], ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);
            IntegrationCommand::ensure(
                Context::of((int) $locked['cmp_id'], (int) $locked['fy_id'], (int) $locked['bo_id']),
                'inventory',
                self::COMMAND,
                'receipt_request',
                $requestId,
                $body,
                ['receipt_no' => $locked['receipt_no'], 'kind' => $kind, 'action' => $pending['action']],
                $revision,
            );

            return false;
        });

        Audit::record($this->ctx, $this->auth, $kind === 'reverse' ? 'receipt.reversal_requested' : 'receipt.return_requested', 'receipt_request', $requestId, null, [
            'kind' => $kind, 'lines' => $input['lines'] ?? 'all',
        ], $reason);

        return $local ? $this->orderView((int) $receipt['po_id'], $requestId) : $this->send($requestId);
    }

    /**
     * Send the return in flight to Inventory, once at a time, on its own key and body, and settle
     * this side from the answer.
     */
    private function send(int $requestId): array
    {
        $receipt = $this->require($requestId);
        $poId = (int) $receipt['po_id'];
        $pending = Db::jsonColumn($receipt['pending_return']);
        $command = IntegrationCommand::find($this->ctx->cmpId, self::COMMAND, 'receipt_request', $requestId, (int) ($pending['revision'] ?? 0));
        if ($command === null) {
            Http::conflict('This return has no request on record. Withdraw it and start again.', ['request_id' => $requestId]);
        }

        $scope = Context::of((int) $command['cmp_id'], (int) $command['fy_id'], (int) $command['bo_id']);
        $inventory = (new InventoryClient())->withService($this->auth->uuid);
        $attempt = IntegrationCommand::attempt(
            $command,
            static fn (array $body, string $key) => ($body['action'] ?? '') === 'reverse'
                ? $inventory->reverseDocument($scope, (int) $body['document_id'], (string) $body['reason'], $key)
                : $inventory->reviseDocument($scope, (int) $body['document_id'], (array) ($body['body'] ?? []), $key),
        );
        $body = is_array($command['request_payload'] ?? null) ? $command['request_payload'] : [];

        switch ($attempt['outcome']) {
            case 'completed':
                $document = $attempt['response']['body']['data'] ?? null;
                $problem = is_array($document) ? self::mismatch($document, $body, $requestId, $pending) : 'Inventory answered without a document.';
                if ($problem !== null) {
                    // Inventory may have acted, on something we do not recognise: stop here, still
                    // unbillable, for a person to look at the GRN in Inventory.
                    IntegrationCommand::block((int) $command['command_id'], (string) $attempt['lease'], 'Inventory answered with something that is not this return: ' . $problem, (int) $attempt['response']['status']);
                    $this->note($requestId, 'Inventory answered with something that is not this return: ' . $problem . ' Check the GRN in Inventory.');
                    Http::error(409, 'return_mismatch', 'Inventory answered with something that is not this return: ' . $problem . ' Nothing was changed on the order; check the GRN in Inventory.', ['request_id' => $requestId, 'retryable' => false]);
                }
                $this->apply($requestId, (array) $document, (int) $command['command_id'], (string) $attempt['lease'], (int) $attempt['response']['status']);
                break;

            case 'already_completed':
                $this->apply($requestId, (array) ($attempt['command']['external_reference'] ?? []), (int) $command['command_id'], null, null);
                break;

            case 'withdrawn':
                Http::conflict('This return was withdrawn before it reached Inventory.', ['request_id' => $requestId]);

            case 'in_progress':
                Http::error(409, 'return_in_progress', 'This return is being sent to Inventory right now. Wait a moment and refresh.', ['request_id' => $requestId, 'retryable' => true]);

            case 'blocked':
            case 'already_blocked':
                $message = self::explainRefusal((string) ($attempt['message'] ?? 'Inventory refused the return.'));
                // Refused: nothing changed in Inventory, so the receipt is exactly as it was — and
                // the refused request is closed with the refusal on it, not left as unfinished work.
                Db::transaction(function () use ($requestId, $poId, $message, $command): void {
                    PoProgress::lock($poId, $this->ctx->cmpId);
                    $locked = $this->lock($requestId);
                    if ($locked['status'] === 'RETURNING') {
                        Db::update('purchase_receipt_requests', [
                            'status' => 'ACCEPTED', 'pending_return' => null, 'last_error' => mb_substr($message, 0, 480), 'updated_at' => self::now(),
                        ], ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);
                    }
                    IntegrationCommand::withdraw((int) $command['command_id'], 'Refused by Inventory; the GRN stands as it was: ' . $message, 'refused');
                });
                Audit::record($this->ctx, $this->auth, 'receipt.return_refused', 'receipt_request', $requestId, ['status' => 'RETURNING'], ['status' => 'ACCEPTED'], $message);
                Http::error(409, 'inventory_refused', $message, ['request_id' => $requestId, 'retryable' => false]);

            case 'uncertain':
                $this->note($requestId, (string) $attempt['message']);
                Http::error(502, 'inventory_uncertain', 'Inventory did not confirm the return. It may have been done: Retry — it cannot be done twice.', ['request_id' => $requestId, 'retryable' => true, 'detail' => $attempt['message']]);

            default:
                $this->note($requestId, (string) $attempt['message']);
                Http::error(502, 'inventory_unavailable', 'Could not reach Inventory for the return. Nothing has changed — press Retry, or withdraw the return.', ['request_id' => $requestId, 'retryable' => true, 'detail' => $attempt['message']]);
        }

        return $this->orderView($poId, $requestId);
    }

    /**
     * Inventory confirmed: the order's counters, the receipt's quantities and its document change
     * together, once, under the order's lock.
     *
     * @param array<string, mixed> $document Inventory's answer (or the reference stored from it)
     */
    private function apply(int $requestId, array $document, int $commandId, ?string $lease, ?int $status): void
    {
        $receipt = $this->require($requestId);
        Db::transaction(function () use ($requestId, $receipt, $document, $commandId, $lease, $status): void {
            PoProgress::lock((int) $receipt['po_id'], $this->ctx->cmpId);
            $locked = $this->lock($requestId);
            if ($locked['status'] === 'RETURNING') {
                $pending = Db::jsonColumn($locked['pending_return']);
                $this->applyLocally($requestId, $locked, $pending, ($pending['action'] ?? null) === 'revise' ? $document : null);
            }
            $reference = array_filter([
                'inventory_document_id'   => $document['document_id'] ?? $document['inventory_document_id'] ?? null,
                'inventory_document_uuid' => $document['document_uuid'] ?? $document['inventory_document_uuid'] ?? null,
                'inventory_document_no'   => $document['document_no'] ?? $document['inventory_document_no'] ?? null,
                'replaced_document_id'    => $document['replaced_document_id'] ?? null,
                'status'                  => $document['status'] ?? null,
            ], static fn ($v) => $v !== null);
            if ($lease !== null) {
                IntegrationCommand::complete($commandId, $lease, $reference, 'response', $status);
            } else {
                IntegrationCommand::completeByReconcile($commandId, $reference);
            }
        });
    }

    /**
     * The bookkeeping of a return Inventory has done (or, for a GRN rejected in full at the gate,
     * that needed nothing of Inventory), with the order and the receipt locked by the caller.
     *
     * @param array<string, mixed> $locked the receipt row
     * @param array<string, mixed> $pending what the return does
     * @param array<string, mixed>|null $replacement Inventory's replacement document, after a revise
     */
    private function applyLocally(int $requestId, array $locked, array $pending, ?array $replacement): void
    {
        $kind = (string) $pending['kind'];
        $applied = self::appliedLines($locked);
        foreach ($applied as $i => $line) {
            $back = (float) ($pending['returned'][$i] ?? 0);
            $undo = (float) ($pending['rejected_undo'][$i] ?? 0);
            $rejectedDelta = $kind === 'return' ? $back : -$undo;
            $applied[$i]['qty'] = round((float) ($line['qty'] ?? 0) - $back, 4);
            $applied[$i]['rejected_qty'] = round((float) ($line['rejected_qty'] ?? 0) + $rejectedDelta, 4);
            if ($back > self::EPSILON || abs($rejectedDelta) > self::EPSILON) {
                // Returned goods are rejected goods, still owed; a mistaken GRN was never received.
                Db::run(
                    'UPDATE purchase_order_lines
                        SET received_qty = received_qty - :back, rejected_qty = GREATEST(0, rejected_qty + :rej), updated_at = NOW()
                      WHERE line_id = :id AND po_id = :po AND cmp_id = :cmp',
                    ['back' => $back, 'rej' => $rejectedDelta, 'id' => (int) $line['line_id'], 'po' => (int) $locked['po_id'], 'cmp' => $this->ctx->cmpId],
                );
            }
        }

        $whole = ($pending['action'] ?? null) !== 'revise';
        $history = Db::jsonColumn($locked['return_history']);
        $history[] = [
            'kind'             => $kind,
            'action'           => $pending['action'] ?? null,
            'reason'           => $pending['reason'] ?? null,
            'returned'         => $pending['returned'] ?? [],
            'requested_by'     => $pending['requested_by'] ?? null,
            'applied_at'       => gmdate('c'),
            'document_before'  => $pending['document_id'] ?? null,
            'document_after'   => $replacement['document_id'] ?? null,
        ];
        $changes = [
            'status'         => $whole ? 'REVERSED' : 'ACCEPTED',
            'applied_lines'  => $applied,
            'pending_return' => null,
            'return_history' => $history,
            'last_error'     => null,
            'updated_at'     => self::now(),
        ];
        if ($whole) {
            $changes['reversed_at'] = self::now();
        } else {
            // The replacement stands in for the receipt from now on: what a bill settles, what
            // the ledger reads back.
            $changes['inventory_document_id'] = (int) $replacement['document_id'];
            $changes['inventory_document_uuid'] = self::text((string) ($replacement['document_uuid'] ?? ''));
            $changes['inventory_document_no'] = self::text((string) ($replacement['document_no'] ?? ''));
        }
        Db::update('purchase_receipt_requests', $changes, ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);
        PoProgress::recompute((int) $locked['po_id'], $this->ctx->cmpId, $this->auth->uuid);

        Audit::record($this->ctx, $this->auth, $kind === 'reverse' ? 'receipt.reversed' : 'receipt.returned', 'receipt_request', $requestId, [
            'status' => 'RETURNING', 'inventory_document_id' => $pending['document_id'] ?? null,
        ], [
            'status' => $changes['status'], 'returned' => $pending['returned'] ?? [],
            'inventory_document_id' => $changes['inventory_document_id'] ?? ($pending['document_id'] ?? null),
        ], (string) ($pending['reason'] ?? ''));
    }

    /** @param array<string, mixed> $receipt the locked row */
    private function assertReturnable(array $receipt): void
    {
        match (true) {
            $receipt['status'] === 'REVERSED' => Http::conflict('This GRN has already been reversed; nothing of it is left to give back.'),
            $receipt['status'] === 'CANCELLED' => Http::conflict('This receipt was cancelled before it reached Inventory; there is nothing to give back.'),
            $receipt['applied_at'] === null => Http::conflict('Inventory has not recorded this receipt yet. Retry or reconcile it — or withdraw it, if it should not be recorded at all.'),
            $receipt['status'] !== 'ACCEPTED' => Http::conflict('This receipt cannot be returned in its current state (' . strtolower((string) $receipt['status']) . ').'),
            $receipt['source_document_type'] === ReceiptService::LEGACY_SOURCE_TYPE => Http::conflict('This GRN was recorded under its order\'s identity before receipts had their own. Review it in the receipt repair report; it cannot be undone from here.'),
            default => null,
        };

        // Billed, even in part — or claimed by a bill on its way to Books: those goods go back on
        // a purchase return after the bill, which reduces the payable the bill created.
        if ($receipt['inventory_document_id'] === null) {
            return;
        }
        $documentId = (int) $receipt['inventory_document_id'];
        foreach (Db::all(
            "SELECT supplier_invoice_no, status, stock_settlements FROM purchase_bill_requests
              WHERE po_id = :po AND cmp_id = :cmp AND status <> 'CANCELLED' AND stock_settlements IS NOT NULL",
            ['po' => (int) $receipt['po_id'], 'cmp' => $this->ctx->cmpId],
        ) as $bill) {
            foreach (Db::jsonColumn($bill['stock_settlements']) as $settlement) {
                if ((int) ($settlement['source_document_id'] ?? 0) === $documentId && (float) ($settlement['qty'] ?? 0) > self::EPSILON) {
                    Http::conflict(
                        sprintf('Bill %s already settles goods from this GRN (%s). Billed goods go back on a purchase return, after the bill — never by unpicking the receipt.', $bill['supplier_invoice_no'], strtolower((string) $bill['status'])),
                        ['bill' => $bill['supplier_invoice_no']],
                    );
                }
            }
        }
    }

    /**
     * What goes back from each line of the receipt, by its index, for the order lines asked for:
     * an order line delivered into two warehouses on one GRN is taken back in the GRN's own order.
     *
     * @param list<array<string, mixed>> $applied
     * @param list<array<string, mixed>> $requested
     * @return array{0: array<int, float>, 1: ?array<string, float>}
     */
    private static function allocate(array $applied, array $requested, mixed $lines): array
    {
        if ($lines === null) {
            return [array_map(static fn (array $l) => round((float) ($l['qty'] ?? 0), 4), $applied), null];
        }
        $asked = self::askedLines($lines);
        if ($asked === []) {
            Http::validationFailed('Say which goods go back: a quantity on at least one line.', ['field' => 'lines']);
        }
        $returned = array_fill_keys(array_keys($applied), 0.0);
        foreach ($asked as $lineId => $qty) {
            $onReceipt = 0.0;
            foreach ($applied as $line) {
                if ((int) ($line['line_id'] ?? 0) === (int) $lineId) {
                    $onReceipt += (float) ($line['qty'] ?? 0);
                }
            }
            if ($onReceipt <= self::EPSILON) {
                Http::validationFailed('That line is not on this GRN, or nothing of it is left.', ['field' => 'lines', 'line_id' => (int) $lineId]);
            }
            if ($qty > $onReceipt + self::EPSILON) {
                Http::validationFailed(sprintf('Only %s of that line is on this GRN.', self::num($onReceipt)), ['field' => 'lines', 'line_id' => (int) $lineId, 'on_receipt' => $onReceipt]);
            }
            $left = $qty;
            foreach ($applied as $i => $line) {
                if ($left <= self::EPSILON || (int) ($line['line_id'] ?? 0) !== (int) $lineId) {
                    continue;
                }
                $take = round(min($left, (float) ($line['qty'] ?? 0)), 4);
                $returned[$i] = $take;
                $left = round($left - $take, 4);
                $serials = is_array($requested[$i]['serials'] ?? null) ? $requested[$i]['serials'] : [];
                if ($serials !== [] && $take > self::EPSILON && $take < (float) ($line['qty'] ?? 0) - self::EPSILON) {
                    Http::validationFailed('Part of a serial-numbered line cannot be returned this way: return all of that line, or reverse the GRN and record what was kept afresh.', ['field' => 'lines', 'line_id' => (int) $lineId]);
                }
            }
        }

        return [$returned, $asked];
    }

    /** @return array<string, float> order line id => quantity asked to go back */
    private static function askedLines(mixed $lines): array
    {
        $out = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineId = (int) ($line['line_id'] ?? 0);
            $qty = round((float) ($line['qty'] ?? 0), 4);
            if ($lineId > 0 && $qty > self::EPSILON) {
                $out[(string) $lineId] = round(($out[(string) $lineId] ?? 0.0) + $qty, 4);
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Why Inventory's answer is not this return, or null if it is.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $body what was sent
     * @param array<string, mixed> $pending
     */
    private static function mismatch(array $document, array $body, int $requestId, array $pending): ?string
    {
        $old = (int) ($body['document_id'] ?? 0);
        if (($body['action'] ?? '') === 'reverse') {
            if ((int) ($document['document_id'] ?? 0) !== $old) {
                return sprintf('it reversed document %d, not %d', (int) ($document['document_id'] ?? 0), $old);
            }

            return strtoupper((string) ($document['status'] ?? '')) === 'REVERSED' ? null : 'the GRN is not shown as reversed';
        }

        if ((int) ($document['replaced_document_id'] ?? 0) !== $old) {
            return sprintf('it replaced document %d, not %d', (int) ($document['replaced_document_id'] ?? 0), $old);
        }
        if ((int) ($document['document_id'] ?? 0) <= 0 || (int) ($document['document_id'] ?? 0) === $old) {
            return 'it named no replacement document';
        }
        if (isset($document['source_document_id']) && (int) $document['source_document_id'] !== $requestId) {
            return 'the replacement belongs to another receipt';
        }
        if (in_array(strtoupper((string) ($document['status'] ?? 'POSTED')), ['CANCELLED', 'REVERSED', 'FAILED', 'DRAFT'], true)) {
            return 'the replacement is not posted';
        }
        $sent = [];
        foreach ((array) ($body['body']['lines'] ?? []) as $line) {
            $ref = (int) ($line['source_line_ref'] ?? 0);
            $sent[$ref] = round(($sent[$ref] ?? 0.0) + (float) ($line['qty'] ?? 0), 4);
        }
        $got = [];
        foreach ((array) ($document['lines'] ?? []) as $line) {
            $ref = (int) ($line['source_line_ref'] ?? 0);
            $got[$ref] = round(($got[$ref] ?? 0.0) + (float) ($line['qty'] ?? 0), 4);
        }
        ksort($sent);
        ksort($got);
        if ($got !== [] && $got !== $sent) {
            return 'the replacement does not hold the quantities kept';
        }

        return null;
    }

    /** Inventory's refusal, with what to do when it is the "already billed" one. */
    private static function explainRefusal(string $message): string
    {
        if (preg_match('/settled|invalid_state/i', $message) === 1) {
            return $message . ' — A bill has already settled goods from this GRN in Inventory (perhaps one entered in Smart Books). Billed goods go back on a purchase return, after the bill.';
        }

        return $message;
    }

    /** @param array<string, mixed> $receipt @return list<array<string, mixed>> index-aligned with requested_lines */
    private static function appliedLines(array $receipt): array
    {
        return array_values(Db::jsonColumn($receipt['applied_lines'] ?? $receipt['requested_lines']));
    }

    private function note(int $requestId, string $message): void
    {
        Db::run(
            "UPDATE purchase_receipt_requests SET last_error = :err, updated_at = NOW()
              WHERE request_id = :id AND cmp_id = :cmp AND status = 'RETURNING'",
            ['err' => mb_substr($message, 0, 480), 'id' => $requestId, 'cmp' => $this->ctx->cmpId],
        );
    }

    /** @return array<string, mixed> */
    private function require(int $requestId): array
    {
        $row = Db::first('SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp', ['id' => $requestId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            Http::notFound('That receipt does not exist.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function lock(int $requestId): array
    {
        $row = Db::first('SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp FOR UPDATE', ['id' => $requestId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            Http::notFound('That receipt does not exist.');
        }

        return $row;
    }

    private function orderView(int $poId, int $requestId): array
    {
        $po = (new PurchaseOrderService($this->ctx, $this->auth))->find($poId);
        $po['receipt'] = Db::first('SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp', ['id' => $requestId, 'cmp' => $this->ctx->cmpId]);

        return $po;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
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
