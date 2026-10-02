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
 * Goods receipt — ORCHESTRATION ONLY.
 *
 * THE GRN IS INVENTORY'S. Purchases says "these goods arrived against this order";
 * Inventory records the goods, the batch, the serials and the valuation, and hands back
 * its document. What is kept here is the request, its outcome and the references.
 *
 * ONE RECEIPT, ONE IDENTITY. Every delivery is its own receipt with its own uuid and
 * number, and that is the source identity Inventory is given (`purchases.receipt`,
 * this row's id). The order it arrived against travels beside it as a reference. The
 * order's identity used to be the source identity, so Inventory — which keeps exactly
 * one live document per source, deliberately — answered the second delivery with the
 * first delivery's document, and this product added the first delivery's quantities a
 * second time. Inventory's guard is right and is left alone; the identity was wrong.
 *
 * GOODS ON HAND AT THE GRN, AT A PROVISIONAL COST. The GRN is an inward challan that
 * moves the goods into stock (`stock_effect: physical`), valued at the rate agreed on
 * the order. The supplier's bill later settles that challan without receiving the goods
 * again and trues the cost up to what was billed (BillService, `from_physical_challan`).
 *
 * EXACTLY ONCE. A receipt's quantities are added to the order in the same transaction
 * that stamps `applied_at`, and only while it is still empty — so whether the
 * confirmation comes from Inventory's answer, from a replay of that answer after a lost
 * response, or from a reconcile that finds the document by our identity, it counts once.
 *
 * CUMULATIVE LIMITS, ATOMICALLY. A new receipt is admitted with the order row locked, so
 * two deliveries recorded at the same moment are checked one after the other, each
 * against what is already received AND what other receipts are still on their way to
 * Inventory. A replayed submission of the same form is recognised by its client token
 * and gets its own receipt back; a genuine second delivery is a second form and a second
 * receipt.
 */
final class ReceiptService
{
    public const COMMAND_RECEIPT = 'purchases.receipt.request';

    /** The source identity Inventory is given for a receipt. */
    public const SOURCE_TYPE = 'purchases.receipt';

    /** The identity receipts were posted under before each had its own. */
    public const LEGACY_SOURCE_TYPE = 'purchases.order';

    /** Receipts whose quantities may still reach the order: they count against its limit. */
    private const IN_FLIGHT = ['REQUESTED', 'POSTING', 'FAILED', 'UNCERTAIN'];

    private const RECEIVABLE_PO = ['APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED'];

    private const EPSILON = 0.00005;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * How this company's goods receipts move stock — its own choice
     * (purchase_settings.receive_stock_at_grn, migration 006, off by default):
     *
     *   off  `challan_only`: the receipt only notes the goods; the bill receives them.
     *   on   `physical`: the goods are on hand at the order rate and Books accrues them
     *        (Goods Received Not Invoiced); the bill settles the receipt without receiving
     *        again — Inventory sees the receipt moved stock — and trues the cost up.
     *
     * Decided when the receipt is recorded and stored on it, so a later change of the
     * setting never changes how an existing receipt is billed.
     */
    public function grnStockEffect(): string
    {
        return self::stockEffectFor($this->ctx->cmpId);
    }

    /** The same answer for a company, for screens that describe it (the order's receive panel). */
    public static function stockEffectFor(int $cmpId): string
    {
        $on = Db::scalar('SELECT receive_stock_at_grn FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $cmpId]);

        return $on === true || $on === 't' || $on === 1 || $on === '1' ? 'physical' : 'challan_only';
    }

    /**
     * Record a delivery against an order and send it to Inventory.
     *
     * @param array<string, mixed> $input
     *   {lines: [{line_id, qty, rejected_qty?, rejection_reason?, warehouse_id?, batch_id?,
     *             batch_no?, serials?, inspection_note?}],
     *    received_at?, supplier_dc_no?, supplier_dc_date?, vehicle_no?, inspection_note?,
     *    client_token?, over_receipt_reason?}
     */
    public function request(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'receipt.request');

        $clientToken = self::text($input['client_token'] ?? null) ?? self::text(Http::header('Idempotency-Key'));
        if ($clientToken !== null && mb_strlen($clientToken) > 120) {
            Http::validationFailed('The submission token is too long.', ['field' => 'client_token']);
        }

        // Serial numbers Inventory would refuse — on an item that does not track them, or not one
        // per base unit — are refused before anything is recorded (Inventory C6).
        (new ReceiptTracking($this->ctx, $this->auth))->check($poId, $input);

        $requestId = Db::transaction(function () use ($poId, $input, $clientToken): int {
            $po = PoProgress::lock($poId, $this->ctx->cmpId);
            if ($po === null) {
                Http::notFound('That purchase order does not exist.');
            }

            if ($clientToken !== null) {
                $existing = Db::scalar(
                    'SELECT request_id FROM purchase_receipt_requests
                      WHERE cmp_id = :cmp AND po_id = :po AND client_token = :token',
                    ['cmp' => $this->ctx->cmpId, 'po' => $poId, 'token' => $clientToken],
                );
                if ($existing !== null) {
                    // The same submission again — a double click, a retried request, a
                    // browser that resent the form. Its receipt, not a second one.
                    return -((int) $existing);
                }
            }

            if (!in_array($po['status'], self::RECEIVABLE_PO, true)) {
                Http::conflict(match ((string) $po['status']) {
                    'DRAFT', 'APPROVAL_PENDING' => 'Approve the purchase order before receiving goods against it.',
                    'CANCELLED' => 'This purchase order is cancelled; goods cannot be received against it.',
                    'CLOSED' => 'This purchase order is closed. Reopen it, or raise a new order for these goods.',
                    default => 'Goods cannot be received against this purchase order in its current state.',
                }, ['status' => $po['status']]);
            }

            $lines = $this->admitLines($po, $input);

            $settings = Db::first('SELECT * FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]);
            if ($settings === null) {
                Db::insert('purchase_settings', ['cmp_id' => $this->ctx->cmpId], 'cmp_id');
            }

            $overReason = self::text($input['over_receipt_reason'] ?? null);
            $over = array_filter($lines, static fn (array $l) => !empty($l['over_receipt']));

            return (int) Db::insert('purchase_receipt_requests', [
                'cmp_id'               => $this->ctx->cmpId,
                'fy_id'                => $this->ctx->fyId,
                'bo_id'                => $this->ctx->boId,
                'po_id'                => $poId,
                'receipt_no'           => NumberSeries::next($this->ctx, 'receipt'),
                'client_token'         => $clientToken,
                'source_document_type' => self::SOURCE_TYPE,
                'document_type'        => 'INWARD_CHALLAN',
                'stock_effect'         => $this->grnStockEffect(),
                'status'               => 'REQUESTED',
                'received_at'          => self::date($input['received_at'] ?? null),
                'warehouse_id'         => self::id($input['warehouse_id'] ?? $po['delivery_warehouse_id'] ?? null),
                'supplier_dc_no'       => self::text($input['supplier_dc_no'] ?? null),
                'supplier_dc_date'     => self::validDate($input['supplier_dc_date'] ?? null),
                'vehicle_no'           => self::text($input['vehicle_no'] ?? null),
                'inspection_note'      => self::text($input['inspection_note'] ?? null),
                'over_receipt_reason'  => $over !== [] ? $overReason : null,
                'over_receipt_by'      => $over !== [] ? $this->auth->uuid : null,
                'requested_lines'      => array_values(array_map(static function (array $l): array {
                    unset($l['over_receipt']);

                    return $l;
                }, $lines)),
                'requested_by'         => $this->auth->uuid,
            ], 'request_id');
        });

        if ($requestId < 0) {
            $requestId = -$requestId;
            $receipt = $this->findReceipt($requestId);
            if ($receipt !== null && $receipt['applied_at'] === null && in_array($receipt['status'], ['REQUESTED', 'FAILED', 'UNCERTAIN', 'POSTING'], true)) {
                return $this->dispatch($requestId);
            }

            return $this->orderView((int) $receipt['po_id'], $requestId);
        }

        Audit::record($this->ctx, $this->auth, 'receipt.requested', 'receipt_request', $requestId, null, [
            'po_id' => $poId,
        ]);

        return $this->dispatch($requestId);
    }

    /** Send a failed or uncertain receipt again, on its original key and body. */
    public function retry(int $requestId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'receipt.request');

        $receipt = $this->findReceipt($requestId);
        if ($receipt === null) {
            Http::notFound('That receipt does not exist.');
        }
        if ($receipt['applied_at'] !== null) {
            Http::conflict('That receipt has already been recorded in Inventory.');
        }
        if ($receipt['status'] === 'CANCELLED') {
            Http::conflict('That receipt was cancelled.');
        }
        if ($receipt['status'] === 'BLOCKED') {
            Http::conflict('Inventory refused this receipt, so sending it again would be refused again. '
                . ($receipt['last_error'] ?? '') . ' Cancel it and record the delivery afresh once the cause is fixed.');
        }

        return $this->dispatch($requestId);
    }

    /**
     * Ask Inventory whether it holds this receipt, by our source identity, and settle
     * our side from its answer — without sending anything.
     *
     * The answer to UNCERTAIN: the response was lost, so we do not know whether the GRN
     * exists. If Inventory has it, the receipt is applied once from what Inventory holds;
     * if not, it goes back to FAILED and Retry will send it.
     */
    public function reconcile(int $requestId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'receipt.request');

        $receipt = $this->findReceipt($requestId);
        if ($receipt === null) {
            Http::notFound('That receipt does not exist.');
        }
        if ($receipt['applied_at'] !== null) {
            return $this->orderView((int) $receipt['po_id'], $requestId);
        }
        if ($receipt['source_document_type'] !== self::SOURCE_TYPE) {
            Http::conflict('This receipt was recorded under its order\'s identity before receipts had their own. Use the receipt repair report to review it.');
        }

        $response = $this->inventory()->documentBySource(
            Context::of((int) $receipt['cmp_id'], (int) $receipt['fy_id'], (int) $receipt['bo_id']),
            'purchases',
            self::SOURCE_TYPE,
            $requestId,
        );
        if (!$response['ok'] && (int) $response['status'] !== 404) {
            Http::error(503, 'inventory_unavailable', 'Inventory could not be asked about this receipt just now. Nothing has changed; try again.');
        }

        $document = self::documentFrom($response);
        $command = IntegrationCommand::find($this->ctx->cmpId, self::COMMAND_RECEIPT, 'receipt_request', $requestId);

        if ($document === null) {
            // Inventory has nothing under this receipt's identity: it was not recorded.
            // Back to FAILED, so Retry sends it — on the same key and body.
            if ($command !== null && in_array($command['status'], [IntegrationCommand::UNCERTAIN, IntegrationCommand::POSTING], true)
                && ($command['status'] !== IntegrationCommand::POSTING || self::leaseExpired($command))) {
                Db::run(
                    "UPDATE purchase_integration_commands SET status = 'FAILED', lease_token = NULL, lease_expires_at = NULL,
                            last_error = :err, updated_at = NOW() WHERE command_id = :id AND status IN ('UNCERTAIN', 'POSTING')",
                    ['err' => 'Reconciled: Inventory has no document for this receipt.', 'id' => (int) $command['command_id']],
                );
            }
            Db::update('purchase_receipt_requests', [
                'status' => 'FAILED', 'last_error' => 'Inventory has no record of this receipt. Retry will send it.', 'updated_at' => self::now(),
            ], ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);

            return $this->orderView((int) $receipt['po_id'], $requestId);
        }

        $problem = $this->mismatch($document, $receipt);
        if ($problem !== null) {
            Http::error(409, 'receipt_mismatch', $problem, ['request_id' => $requestId]);
        }

        $this->applyOnce($requestId, $document, 'reconcile');
        if ($command !== null) {
            IntegrationCommand::completeByReconcile((int) $command['command_id'], self::reference($document));
        }

        return $this->orderView((int) $receipt['po_id'], $requestId);
    }

    /**
     * Withdraw a receipt Inventory never recorded, so its quantity stops counting
     * against the order. Not one whose outcome is unknown — that must be reconciled
     * first, because Inventory may hold it.
     *
     * @param array<string, mixed> $input
     */
    public function cancel(int $requestId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'receipt.request');

        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why this receipt is being cancelled.', ['field' => 'reason']);
        }

        $receipt = $this->findReceipt($requestId);
        if ($receipt === null) {
            Http::notFound('That receipt does not exist.');
        }

        Db::transaction(function () use ($requestId, $reason, &$receipt): void {
            PoProgress::lock((int) $receipt['po_id'], $this->ctx->cmpId);
            $receipt = Db::first(
                'SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp FOR UPDATE',
                ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
            );
            if ($receipt['status'] === 'CANCELLED') {
                return;
            }
            if ($receipt['applied_at'] !== null || $receipt['status'] === 'ACCEPTED') {
                Http::conflict('Inventory has recorded this receipt. Raise a purchase return to send goods back.');
            }
            // Withdrawn, not merely checked: a Retry pressed at this moment would otherwise
            // claim the command after this read and send a GRN for a cancelled receipt.
            $command = IntegrationCommand::find($this->ctx->cmpId, self::COMMAND_RECEIPT, 'receipt_request', $requestId);
            if ($command !== null && $command['status'] !== IntegrationCommand::CANCELLED
                && !IntegrationCommand::withdraw((int) $command['command_id'], 'Receipt cancelled: ' . $reason)) {
                Http::conflict('Inventory may already hold this receipt. Reconcile it first; if Inventory has no record of it, it can then be cancelled.');
            }

            Db::update('purchase_receipt_requests', [
                'status' => 'CANCELLED', 'cancelled_at' => self::now(), 'cancel_reason' => $reason, 'updated_at' => self::now(),
            ], ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);
        });

        Audit::record($this->ctx, $this->auth, 'receipt.cancelled', 'receipt_request', $requestId, ['status' => $receipt['status']], ['status' => 'CANCELLED'], $reason);

        return $this->orderView((int) $receipt['po_id'], $requestId);
    }

    // -----------------------------------------------------------------------

    /**
     * Send a stored receipt to Inventory, once at a time, on its own key and body.
     *
     * No transaction is open across the call: the command is claimed with a lease, the
     * call is made, and the outcome is recorded against that lease.
     */
    private function dispatch(int $requestId): array
    {
        $receipt = $this->findReceipt($requestId);
        if ($receipt === null) {
            Http::notFound('That receipt does not exist.');
        }
        $poId = (int) $receipt['po_id'];
        if ($receipt['applied_at'] !== null) {
            return $this->orderView($poId, $requestId);
        }

        $scope = Context::of((int) $receipt['cmp_id'], (int) $receipt['fy_id'], (int) $receipt['bo_id']);

        $accepted = array_filter(Db::jsonColumn($receipt['requested_lines']), static fn (array $l) => (float) ($l['qty'] ?? 0) > 0);
        if ($accepted === []) {
            // Everything that arrived was rejected at the gate: nothing enters stock, so
            // there is nothing for Inventory to record. The rejection is ours to keep.
            $this->applyOnce($requestId, ['lines' => []], 'rejected_in_full');

            return $this->orderView($poId, $requestId);
        }

        // Serial numbers and batches named by id, as Inventory takes them — registered before the
        // body is first stored, so every retry of it sends ids (Inventory C6).
        if (IntegrationCommand::find($this->ctx->cmpId, self::COMMAND_RECEIPT, 'receipt_request', $requestId) === null) {
            $tracked = (new ReceiptTracking($this->ctx, $this->auth))->register($receipt, $scope, self::COMMAND_RECEIPT);
            if ($tracked['problem'] !== null) {
                $this->markReceipt($requestId, 'FAILED', $tracked['problem']['message']);
                Http::error($tracked['problem']['status'], $tracked['problem']['code'], $tracked['problem']['message'], ['request_id' => $requestId, 'retryable' => $tracked['problem']['retryable']]);
            }
            $receipt = $tracked['receipt'];
        }

        $command = IntegrationCommand::ensure(
            $scope,
            'inventory',
            self::COMMAND_RECEIPT,
            'receipt_request',
            $requestId,
            $this->payload($receipt),
            ['po_id' => $poId, 'receipt_no' => $receipt['receipt_no'], 'lines' => count(Db::jsonColumn($receipt['requested_lines']))],
        );
        $commandId = (int) $command['command_id'];

        if ($command['status'] === IntegrationCommand::COMPLETED) {
            // Inventory accepted it earlier and our side was not finished — a crash
            // between the two. Settle from what Inventory holds.
            return $this->reconcile($requestId);
        }
        if ($command['status'] === IntegrationCommand::BLOCKED) {
            Http::error(409, 'inventory_refused', (string) ($command['last_error'] ?? 'Inventory refused this receipt.'), ['request_id' => $requestId, 'retryable' => false]);
        }

        $claim = IntegrationCommand::claim($commandId);
        if (!$claim['claimed'] && ($claim['command']['status'] ?? '') === IntegrationCommand::CANCELLED) {
            Http::conflict('This receipt was cancelled before it reached Inventory.', ['request_id' => $requestId]);
        }
        if (!$claim['claimed']) {
            Http::error(409, 'receipt_in_progress', 'This receipt is being sent to Inventory right now. Wait a moment and refresh.', ['request_id' => $requestId, 'retryable' => true]);
        }
        $lease = (string) $claim['command']['lease_token'];
        $body = $claim['command']['request_payload'] ?? $this->payload($receipt);

        Db::update('purchase_receipt_requests', ['status' => 'POSTING', 'updated_at' => self::now()], ['request_id' => $requestId]);

        $response = $this->inventory()->postDocument($scope, $body, (string) $claim['command']['idempotency_key']);
        $outcome = IntegrationCommand::classify($response);

        if ($outcome === 'completed') {
            $document = self::documentFrom($response);
            $problem = $document === null ? 'Inventory answered without a document.' : $this->mismatch($document, $receipt);
            if ($problem !== null) {
                // Not ours, or not what we sent. Nothing is added to the order; the
                // command stops here for a person to look at.
                IntegrationCommand::block($commandId, $lease, 'Inventory returned a document that does not match this receipt: ' . $problem, (int) $response['status']);
                $this->markReceipt($requestId, 'BLOCKED', $problem);
                Http::error(409, 'receipt_mismatch', 'Inventory returned a document that does not match this receipt, so nothing was added to the order. ' . $problem, ['request_id' => $requestId, 'retryable' => false]);
            }

            $replayed = !empty($response['body']['duplicate']) || !empty($document['duplicate']);
            $this->applyOnce($requestId, $document, $replayed ? 'replay' : 'response');
            IntegrationCommand::complete($commandId, $lease, self::reference($document), $replayed ? 'replay' : 'response', (int) $response['status']);

            Audit::record($this->ctx, $this->auth, 'po.received', 'purchase_order', $poId, null, [
                'receipt_no'              => $receipt['receipt_no'],
                'inventory_document_uuid' => $document['document_uuid'] ?? null,
                'replayed'                => $replayed,
            ]);

            return $this->orderView($poId, $requestId);
        }

        $message = (string) ($response['error'] ?? 'Inventory did not accept the receipt.');
        $status = (int) $response['status'];
        match ($outcome) {
            'blocked'   => IntegrationCommand::block($commandId, $lease, $message, $status),
            'uncertain' => IntegrationCommand::uncertain($commandId, $lease, $message, $status),
            default     => IntegrationCommand::fail($commandId, $lease, $message, $status),
        };
        $this->markReceipt($requestId, strtoupper($outcome), $message);

        Http::error(
            $outcome === 'blocked' ? 409 : 502,
            match ($outcome) {
                'blocked'   => 'inventory_refused',
                'uncertain' => 'inventory_uncertain',
                default     => 'inventory_unavailable',
            },
            match ($outcome) {
                'blocked'   => $message,
                'uncertain' => 'Inventory did not confirm this receipt. It may have been recorded: press Reconcile to check, or Retry — neither can record it twice.',
                default     => 'Could not reach Inventory to record this receipt. Nothing has been received — press Retry.',
            },
            ['request_id' => $requestId, 'retryable' => $outcome !== 'blocked', 'detail' => $message],
        );
    }

    /**
     * Admit the requested lines against what the order still wants, with the order row
     * locked by the caller.
     *
     * @param array<string, mixed> $po
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function admitLines(array $po, array $input): array
    {
        $poId = (int) $po['po_id'];
        $lines = Db::all(
            'SELECT * FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp ORDER BY line_no FOR UPDATE',
            ['po' => $poId, 'cmp' => $this->ctx->cmpId],
        );
        $byId = [];
        foreach ($lines as $line) {
            $byId[(int) $line['line_id']] = $line;
        }

        // What other receipts of this order are still carrying towards Inventory.
        $inFlight = [];
        foreach (Db::all(
            "SELECT requested_lines FROM purchase_receipt_requests
              WHERE po_id = :po AND cmp_id = :cmp AND applied_at IS NULL
                AND status IN ('" . implode("', '", self::IN_FLIGHT) . "')",
            ['po' => $poId, 'cmp' => $this->ctx->cmpId],
        ) as $row) {
            foreach (Db::jsonColumn($row['requested_lines']) as $l) {
                // Accepted quantity only: goods rejected at the gate go back and are still owed.
                $inFlight[(int) ($l['line_id'] ?? 0)] = ($inFlight[(int) ($l['line_id'] ?? 0)] ?? 0.0) + (float) ($l['qty'] ?? 0);
            }
        }

        $settings = Db::first('SELECT over_receipt_tolerance_pc FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]);
        $tolerancePc = max(0.0, (float) ($settings['over_receipt_tolerance_pc'] ?? 0));

        $requested = $input['lines'] ?? null;
        if (!is_array($requested) || $requested === []) {
            // "Everything outstanding" — the whole remainder of every stock line.
            $requested = [];
            foreach ($lines as $line) {
                $requested[] = ['line_id' => (int) $line['line_id']];
            }
        }

        $overReason = self::text($input['over_receipt_reason'] ?? null);
        $out = [];
        foreach ($requested as $index => $want) {
            if (!is_array($want)) {
                continue;
            }
            $lineId = (int) ($want['line_id'] ?? 0);
            $line = $byId[$lineId] ?? null;
            if ($line === null) {
                Http::validationFailed('That line is not on this purchase order.', ['field' => 'lines', 'index' => $index, 'line_id' => $lineId]);
            }
            if (self::truthy($line['is_service']) || $line['item_id'] === null) {
                if (isset($want['qty'])) {
                    Http::validationFailed(sprintf('Line %d is a service; services are billed, not received.', (int) $line['line_no']), ['field' => 'lines', 'line_id' => $lineId]);
                }
                continue;
            }

            $wanted = (float) $line['ordered_qty'] - (float) $line['short_closed_qty'];
            // Received and kept, plus what other receipts are still carrying. Rejected goods
            // are not in it: the supplier still owes them.
            $taken = (float) $line['received_qty'] - (float) $line['returned_qty'] + ($inFlight[$lineId] ?? 0.0);
            $outstanding = round(max(0.0, $wanted - $taken), 4);

            $qty = round((float) ($want['qty'] ?? $outstanding), 4);
            $rejected = round((float) ($want['rejected_qty'] ?? 0), 4);
            if ($qty < 0 || $rejected < 0) {
                Http::validationFailed('Quantities cannot be negative.', ['field' => 'lines', 'line_id' => $lineId]);
            }
            if ($qty <= 0 && $rejected <= 0) {
                continue;
            }

            // What is accepted into stock is what counts against the order.
            $arriving = $qty;
            // The tolerance is on the order's total, not on each delivery: once it has been
            // used, a second small delivery cannot claim it again.
            $allowed = round(max(0.0, $wanted * (1 + $tolerancePc / 100) - $taken), 4);
            $over = $arriving > $outstanding + self::EPSILON;
            if ($arriving > $allowed + self::EPSILON) {
                // Beyond the order and its tolerance: a commercial decision, not a
                // data-entry detail. Only somebody who may make it, with a reason.
                if (!Permissions::allows($this->ctx, $this->auth, 'receipt.over_tolerance') || $overReason === null) {
                    Http::validationFailed(
                        sprintf(
                            'Line %d has %s outstanding%s. Receiving %s would exceed the order%s.',
                            (int) $line['line_no'],
                            self::num($outstanding),
                            ($inFlight[$lineId] ?? 0) > 0 ? sprintf(' (%s more is already on its way to Inventory)', self::num((float) $inFlight[$lineId])) : '',
                            self::num($arriving),
                            $tolerancePc > 0 ? ' and its ' . self::num($tolerancePc) . '% tolerance' : '',
                        ),
                        ['field' => 'lines', 'line_id' => $lineId, 'outstanding' => $outstanding, 'in_flight' => $inFlight[$lineId] ?? 0.0, 'tolerance_pc' => $tolerancePc, 'needs' => 'receipt.over_tolerance and over_receipt_reason'],
                    );
                }
            }

            // The serial NUMBERS typed at the gate; checked against the item's tracking and its
            // base unit before this (ReceiptTracking::check), registered in Inventory and named
            // by id before the receipt is first sent (ReceiptTracking::register).
            $serials = ReceiptTracking::serialNumbers($want['serials'] ?? null);

            $out[] = [
                'line_id'          => $lineId,
                'line_no'          => (int) $line['line_no'],
                'item_id'          => (int) $line['item_id'],
                'unit_id'          => self::id($want['unit_id'] ?? $line['unit_id']),
                'warehouse_id'     => self::id($want['warehouse_id'] ?? $line['warehouse_id'] ?? $input['warehouse_id'] ?? null),
                'batch_id'         => self::id($want['batch_id'] ?? null),
                'batch_no'         => self::text($want['batch_no'] ?? null),
                'serials'          => $serials,
                'qty'              => $qty,
                'rejected_qty'     => $rejected,
                'rejection_reason' => self::text($want['rejection_reason'] ?? null),
                'inspection_note'  => self::text($want['inspection_note'] ?? null),
                // The provisional cost: the rate agreed on this order, net of its discount.
                // The bill trues it up to what the supplier actually charged.
                'rate'             => self::netRate($line),
                'hsn_sac'          => $line['hsn_sac'],
                'over_receipt'     => $over,
            ];
        }

        if ($out === []) {
            Http::validationFailed('There is nothing outstanding to receive on this order.');
        }

        return $out;
    }

    /**
     * The body sent to Inventory: built once from the stored receipt, then kept on the
     * command and replayed verbatim by every retry.
     *
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function payload(array $receipt, ?array $kept = null): array
    {
        $po = Db::first('SELECT * FROM purchase_orders WHERE po_id = :id AND cmp_id = :cmp', ['id' => (int) $receipt['po_id'], 'cmp' => (int) $receipt['cmp_id']]) ?? [];
        $lines = [];
        foreach (Db::jsonColumn($receipt['requested_lines']) as $i => $line) {
            if ($kept !== null) {
                $line['qty'] = (float) ($kept[$i] ?? 0);
            }
            if ((float) ($line['qty'] ?? 0) > 0) {
                $lines[] = $line;
            }
        }

        return [
            'document_type'        => (string) ($receipt['document_type'] ?? 'INWARD_CHALLAN'),
            'stock_effect'         => (string) ($receipt['stock_effect'] ?? $this->grnStockEffect()),
            'document_date'        => (string) ($receipt['received_at'] ?? gmdate('Y-m-d')),
            'source_app'           => 'purchases',
            // The receipt is the source; the order is a reference beside it.
            'source_document_type' => self::SOURCE_TYPE,
            'source_document_id'   => (int) $receipt['request_id'],
            'source_document_uuid' => (string) $receipt['receipt_uuid'],
            'source_document_no'   => (string) $receipt['receipt_no'],
            'source_document_date' => (string) ($receipt['received_at'] ?? gmdate('Y-m-d')),
            'party_ref'            => (string) ($po['supplier_account_id'] ?? ''),
            'party_name'           => $po['supplier_name_snapshot'] ?? null,
            'narration'            => 'GRN ' . $receipt['receipt_no'] . ' against ' . ($po['po_no'] ?? '')
                . ($receipt['supplier_dc_no'] !== null ? ' (DC ' . $receipt['supplier_dc_no'] . ')' : ''),
            'currency_code'        => (string) ($po['currency_code'] ?? 'INR'),
            'exchange_rate'        => (float) ($po['exchange_rate'] ?? 1),
            'metadata'             => array_filter([
                'purchase_order_id'   => (int) $receipt['po_id'],
                'purchase_order_uuid' => (string) ($po['po_uuid'] ?? ''),
                'purchase_order_no'   => (string) ($po['po_no'] ?? ''),
                'receipt_uuid'        => (string) $receipt['receipt_uuid'],
                'supplier_dc_no'      => $receipt['supplier_dc_no'],
                'supplier_dc_date'    => $receipt['supplier_dc_date'],
                'vehicle_no'          => $receipt['vehicle_no'],
                'inspection_note'     => $receipt['inspection_note'],
                'provisional_cost'    => 'purchase_order_rate',
            ], static fn ($v) => $v !== null && $v !== ''),
            'lines'                => array_map(static fn (array $line) => array_filter([
                'source_line_ref' => (int) $line['line_id'],
                'item_id'         => (int) $line['item_id'],
                'warehouse_id'    => $line['warehouse_id'] ?? null,
                // A batch is its batch_id, a serial its serial_id (Inventory C6): never the
                // numbers as text, which Inventory refuses. ReceiptTracking registered them.
                'batch_id'        => $line['batch_id'] ?? null,
                'serials'         => is_array($line['serial_ids'] ?? null) ? array_map('intval', $line['serial_ids']) : [],
                'unit_id'         => $line['unit_id'] ?? null,
                'qty'             => (float) $line['qty'],
                // The provisional cost (the order's net rate). Inventory values a physical
                // inward challan at its own rate; the bill trues it up.
                'rate'            => (float) $line['rate'],
                'amount'          => round((float) $line['qty'] * (float) $line['rate'], 4),
                'direction'       => 'in',
                'hsn_sac'         => $line['hsn_sac'] ?? null,
                'metadata'        => array_filter([
                    'purchase_order_line_id' => (int) $line['line_id'],
                    'batch_no'               => empty($line['batch_id']) ? ($line['batch_no'] ?? null) : null,
                    'rejected_qty'           => (float) ($line['rejected_qty'] ?? 0) > 0 ? (float) $line['rejected_qty'] : null,
                    'rejection_reason'       => $line['rejection_reason'] ?? null,
                    'inspection_note'        => $line['inspection_note'] ?? null,
                ], static fn ($v) => $v !== null && $v !== ''),
            ], static fn ($value) => $value !== null && $value !== []), $lines),
        ];
    }

    /**
     * The receipt as Inventory is sent it — for a receipt being restated to the quantities KEPT
     * (ReceiptReturnService), the same document with each requested line's quantity replaced by
     * $kept[index] and the lines kept at nothing left out. Nothing else changes: Inventory copies
     * nothing from the receipt it replaces but its type.
     *
     * @param array<string, mixed> $receipt
     * @param array<int, float> $kept quantity kept per requested line, by index
     * @return array<string, mixed>
     */
    public function keptPayload(array $receipt, array $kept): array
    {
        return $this->payload($receipt, $kept);
    }

    /**
     * Why a document Inventory handed back is not this receipt's GRN, or null if it is.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $receipt
     */
    private function mismatch(array $document, array $receipt): ?string
    {
        foreach ([
            'source_app'           => 'purchases',
            'source_document_type' => self::SOURCE_TYPE,
            'source_document_id'   => (string) $receipt['request_id'],
        ] as $field => $expected) {
            if (array_key_exists($field, $document) && $document[$field] !== null && (string) $document[$field] !== $expected) {
                return sprintf('its %s is %s, not %s', $field, (string) $document[$field], $expected);
            }
        }
        if (!empty($document['source_document_uuid']) && (string) $document['source_document_uuid'] !== (string) $receipt['receipt_uuid']) {
            return 'it belongs to a different receipt (uuid ' . $document['source_document_uuid'] . ')';
        }
        $status = strtoupper((string) ($document['status'] ?? 'POSTED'));
        if (in_array($status, ['CANCELLED', 'REVERSED', 'FAILED', 'DRAFT'], true)) {
            return 'it is ' . strtolower($status) . ', not posted';
        }

        $expected = [];
        foreach (Db::jsonColumn($receipt['requested_lines']) as $line) {
            if ((float) ($line['qty'] ?? 0) > 0) {
                $expected[(string) $line['line_id']] = $line;
            }
        }
        $seen = [];
        foreach ((array) ($document['lines'] ?? []) as $line) {
            $ref = (string) ($line['source_line_ref'] ?? '');
            if (!isset($expected[$ref])) {
                return 'it carries a line (' . ($ref === '' ? 'no reference' : $ref) . ') this receipt did not send';
            }
            if ((int) ($line['item_id'] ?? 0) !== (int) $expected[$ref]['item_id']) {
                return sprintf('its line %s is item %d, not %d', $ref, (int) ($line['item_id'] ?? 0), (int) $expected[$ref]['item_id']);
            }
            if (abs((float) ($line['qty'] ?? 0) - (float) $expected[$ref]['qty']) > self::EPSILON) {
                return sprintf('its line %s is for %s, not %s', $ref, self::num((float) ($line['qty'] ?? 0)), self::num((float) $expected[$ref]['qty']));
            }
            $seen[$ref] = true;
        }
        if (count($seen) !== count($expected)) {
            return 'it is missing ' . (count($expected) - count($seen)) . ' of the receipt\'s lines';
        }

        return null;
    }

    /**
     * Add a confirmed receipt to its order, once.
     *
     * @param array<string, mixed> $document Inventory's document for this receipt
     */
    private function applyOnce(int $requestId, array $document, string $resolvedBy): bool
    {
        return Db::transaction(function () use ($requestId, $document, $resolvedBy): bool {
            // Locks in the one order every writer uses — the order, then its lines, then
            // the receipt — so two deliveries being applied and admitted at once queue
            // rather than deadlock. The order a receipt belongs to never changes, so it is
            // safe to read before either lock is taken.
            $poId = (int) Db::scalar(
                'SELECT po_id FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp',
                ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
            );
            if ($poId === 0 || PoProgress::lock($poId, $this->ctx->cmpId) === null) {
                return false;
            }
            $receipt = Db::first(
                'SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp FOR UPDATE',
                ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
            );
            if ($receipt === null || $receipt['applied_at'] !== null || $receipt['status'] === 'CANCELLED') {
                return false;
            }

            $lines = Db::jsonColumn($receipt['requested_lines']);
            foreach ($lines as $line) {
                Db::run(
                    'UPDATE purchase_order_lines
                        SET received_qty = received_qty + :qty, rejected_qty = rejected_qty + :rejected, updated_at = NOW()
                      WHERE line_id = :id AND po_id = :po AND cmp_id = :cmp',
                    [
                        'qty' => (float) ($line['qty'] ?? 0), 'rejected' => (float) ($line['rejected_qty'] ?? 0),
                        'id' => (int) $line['line_id'], 'po' => $poId, 'cmp' => $this->ctx->cmpId,
                    ],
                );
            }

            Db::update('purchase_receipt_requests', [
                'status'                  => 'ACCEPTED',
                'applied_at'              => self::now(),
                'applied_lines'           => array_map(static fn (array $l) => [
                    'line_id' => (int) $l['line_id'], 'qty' => (float) ($l['qty'] ?? 0), 'rejected_qty' => (float) ($l['rejected_qty'] ?? 0),
                ], $lines),
                'inventory_document_id'   => self::id($document['document_id'] ?? null),
                'inventory_document_uuid' => self::text((string) ($document['document_uuid'] ?? '')),
                'inventory_document_no'   => self::text((string) ($document['document_no'] ?? '')),
                'document_type'           => self::text((string) ($document['document_type'] ?? '')) ?? $receipt['document_type'],
                'stock_effect'            => self::text((string) ($document['stock_effect'] ?? '')) ?? $receipt['stock_effect'],
                'last_error'              => null,
                'updated_at'              => self::now(),
            ], ['request_id' => $requestId]);

            PoProgress::recompute($poId, $this->ctx->cmpId, $this->auth->uuid);

            Audit::record($this->ctx, $this->auth, 'receipt.applied', 'receipt_request', $requestId, null, [
                'po_id' => $poId, 'resolved_by' => $resolvedBy, 'inventory_document_id' => $document['document_id'] ?? null,
            ]);

            return true;
        });
    }

    private function markReceipt(int $requestId, string $status, string $message): void
    {
        Db::run(
            "UPDATE purchase_receipt_requests SET status = :status, last_error = :err, updated_at = NOW()
              WHERE request_id = :id AND cmp_id = :cmp AND applied_at IS NULL AND status <> 'CANCELLED'",
            ['status' => $status, 'err' => mb_substr($message, 0, 480), 'id' => $requestId, 'cmp' => $this->ctx->cmpId],
        );
    }

    /** @return array<string, mixed>|null */
    private function findReceipt(int $requestId): ?array
    {
        return Db::first(
            'SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp',
            ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
        );
    }

    /** The order as its screen shows it, with the receipt this call was about. */
    private function orderView(int $poId, int $requestId): array
    {
        $po = (new PurchaseOrderService($this->ctx, $this->auth))->find($poId);
        $po['receipt'] = $this->findReceipt($requestId);

        return $po;
    }

    /**
     * Inventory is written by this product's service key with the person behind it as the
     * actor — the fleet's contract for product backends, and the only way to create the
     * stock half of a purchase. The company was verified with Manage for this session
     * before any of this runs (Context::assertAllowed).
     */
    private function inventory(): InventoryClient
    {
        return (new InventoryClient())->withService($this->auth->uuid);
    }

    /**
     * The document in an Inventory answer: `data` for create/post and by-source, which
     * may also answer with a list.
     *
     * @param array{ok:bool, status:int, body:?array, error:?string} $response
     * @return array<string, mixed>|null
     */
    private static function documentFrom(array $response): ?array
    {
        $data = $response['body']['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return null;
        }
        if (isset($data['document_id'])) {
            return $data;
        }
        $live = array_values(array_filter($data, static fn ($d) => is_array($d)
            && !in_array(strtoupper((string) ($d['status'] ?? '')), ['CANCELLED', 'REVERSED', 'FAILED'], true)));

        return $live[0] ?? null;
    }

    /** @param array<string, mixed> $document */
    private static function reference(array $document): array
    {
        return array_filter([
            'inventory_document_id'   => $document['document_id'] ?? null,
            'inventory_document_uuid' => $document['document_uuid'] ?? null,
            'inventory_document_no'   => $document['document_no'] ?? null,
            'document_type'           => $document['document_type'] ?? null,
            'stock_effect'            => $document['stock_effect'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $command */
    private static function leaseExpired(array $command): bool
    {
        $expires = $command['lease_expires_at'] ?? null;

        return $expires === null || strtotime((string) $expires) < time();
    }

    /** @param array<string, mixed> $line a purchase order line */
    public static function netRate(array $line): float
    {
        $qty = (float) $line['ordered_qty'];
        if ($qty > 0 && (float) $line['line_amount'] > 0) {
            return round((float) $line['line_amount'] / $qty, 4);
        }

        return round((float) $line['agreed_rate'] * (1 - (float) ($line['discount_pc'] ?? 0) / 100), 4);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['t', 'true', '1', 'yes'], true);
        }

        return (bool) $value;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
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

    private static function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }

    private static function date(mixed $value): string
    {
        return self::validDate($value) ?? gmdate('Y-m-d');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
