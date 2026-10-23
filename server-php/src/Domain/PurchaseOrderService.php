<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;
use Aicountly\Api\PlanEntitlements;

/**
 * Purchase orders — the commitment.
 *
 * A PO line's agreed rate is stored, and that is not master duplication: it is
 * the price we committed to on this order and stays true when the supplier's
 * list changes. The item's NAME, its stock and its valuation are Inventory's,
 * and the supplier's ledger and payable are Books'.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.create');

        $supplierId = (int) ($input['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Choose a supplier for this order.', ['field' => 'supplier_account_id']);
        }

        $lines = $this->normaliseLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('A purchase order needs at least one line.', ['field' => 'lines']);
        }
        $header = $this->header($input);

        // What the order names is confirmed with its owners before anything is stored: the
        // supplier is a creditor ledger of THIS company in Books, and its name on the order is
        // Books' name, never the one typed; the items and warehouses are this company's in
        // Inventory (F176, F420, F430, F449).
        $masters = new MasterCheck($this->ctx, $this->auth);
        $supplierName = $masters->supplierName($supplierId);
        $masters->items(array_values(array_filter(array_map(static fn (array $l) => $l['item_id'], $lines))));
        $masters->warehouses(array_merge([self::id($input['delivery_warehouse_id'] ?? null)], array_map(static fn (array $l) => $l['warehouse_id'], $lines)), 'warehouse_id');

        $settings = $this->settings();
        $this->guardApprovedVendor($supplierId, $settings, $input);

        // The company's plan may cap new purchase orders a month (402 PLAN_LIMIT_REACHED); an
        // amendment of an existing order is not a new one and is never counted or refused.
        PlanEntitlements::assertWithin($this->ctx, $this->auth, PlanEntitlements::MAX_PURCHASE_ORDERS_PER_MONTH,
            fn (): int => PlanEntitlements::purchaseOrdersThisMonth($this->ctx->cmpId));

        return Db::transaction(function () use ($input, $supplierId, $lines, $settings, $header, $supplierName) {
            $lineRefs = [];
            foreach ($lines as $line) {
                if ($line['requisition_line_id'] !== null) {
                    $lineRefs[(int) $line['requisition_line_id']] = ($lineRefs[(int) $line['requisition_line_id']] ?? 0.0) + (float) $line['ordered_qty'];
                }
            }
            (new RequisitionService($this->ctx, $this->auth))->assertSourceable(self::id($input['requisition_id'] ?? null), $lineRefs, true);
            $no = NumberSeries::next($this->ctx, 'po');
            $totals = self::totals($lines, $header['freight_amount'], $header['other_charges']);

            $poId = (int) Db::insert('purchase_orders', [
                'cmp_id'                 => $this->ctx->cmpId,
                'fy_id'                  => $this->ctx->fyId,
                'bo_id'                  => $this->ctx->boId,
                'po_no'                  => $no,
                'po_date'                => $header['po_date'],
                'requisition_id'         => self::id($input['requisition_id'] ?? null),
                'rfq_id'                 => self::id($input['rfq_id'] ?? null),
                'quote_id'               => self::id($input['quote_id'] ?? null),
                'agreement_id'           => self::id($input['agreement_id'] ?? null),
                'supplier_account_id'    => $supplierId,
                // The supplier's linked Contacts contact, as Purchases last read it from Contacts —
                // never a client-asserted id (G14#10).
                'contact_id'             => Db::scalar('SELECT contact_id FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier', ['cmp' => $this->ctx->cmpId, 'supplier' => $supplierId]),
                'supplier_name_snapshot' => $supplierName ?? self::text($input['supplier_name'] ?? null),
                'status'                 => 'DRAFT',
                'delivery_warehouse_id'  => self::id($input['delivery_warehouse_id'] ?? null) ?? self::id($settings['default_warehouse_id'] ?? null),
                'delivery_address'       => is_array($input['delivery_address'] ?? null) ? $input['delivery_address'] : null,
                'promised_date'          => $header['promised_date'],
                'payment_terms'          => self::text($input['payment_terms'] ?? null),
                'incoterm'               => self::text($input['incoterm'] ?? null),
                'currency_code'          => $header['currency_code'],
                'exchange_rate'          => $header['exchange_rate'],
                'subtotal_amount'        => $totals['subtotal'],
                'discount_amount'        => $totals['discount'],
                'freight_amount'         => $totals['freight'],
                'other_charges'          => $totals['other'],
                'estimated_tax_amount'   => $totals['tax'],
                'total_amount'           => $totals['total'],
                'terms_text'             => self::text($input['terms_text'] ?? null),
                'notes'                  => self::text($input['notes'] ?? null),
                'created_by'             => $this->auth->uuid,
            ], 'po_id');

            $this->writeLines($poId, $lines);
            $this->consumeRequisitionQuantities($lines);

            Audit::record($this->ctx, $this->auth, 'po.created', 'purchase_order', $poId, null, [
                'po_no' => $no, 'total_amount' => $totals['total'], 'supplier_account_id' => $supplierId,
            ]);

            return $this->find($poId);
        });
    }

    /** Submit for approval, or approve outright when it is under the threshold. */
    public function submit(int $poId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.create');

        $po = $this->find($poId);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }
        if ($po['status'] !== 'DRAFT') {
            Http::conflict('This purchase order has already been submitted.');
        }

        $settings = $this->settings();
        // The supplier's standing is checked again at every step, not only when the draft was
        // made: a supplier blacklisted since, or a company that has started enforcing approved
        // suppliers, stops the order here (F025).
        $this->guardApprovedVendor((int) $po['supplier_account_id'], $settings, []);
        $threshold = (float) ($settings['po_approval_above_amount'] ?? 0);
        $value = (float) $po['total_amount'];
        $needsApproval = $threshold > 0 && $value > $threshold;

        Db::transaction(function () use ($poId, $needsApproval, $value, $threshold) {
            Db::update('purchase_orders', [
                'status'      => $needsApproval ? 'APPROVAL_PENDING' : 'APPROVED',
                'approved_by' => $needsApproval ? null : $this->auth->uuid,
                'approved_at' => $needsApproval ? null : self::now(),
                'updated_at'  => self::now(),
            ], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);

            if ($needsApproval) {
                Db::insert('purchase_approval_requests', [
                    'cmp_id'              => $this->ctx->cmpId,
                    'fy_id'               => $this->ctx->fyId,
                    'entity_type'         => 'purchase_order',
                    'entity_id'           => $poId,
                    'required_permission' => 'po.approve',
                    'reason_kind'         => 'value',
                    'reason_detail'       => sprintf('Order value %s is above the %s approval threshold.', self::money($value), self::money($threshold)),
                    'threshold_value'     => $threshold,
                    'actual_value'        => $value,
                    'requested_by'        => $this->auth->uuid,
                ], 'approval_id');
            }
        });

        Audit::record($this->ctx, $this->auth, 'po.submitted', 'purchase_order', $poId, ['status' => 'DRAFT'], [
            'status' => $needsApproval ? 'APPROVAL_PENDING' : 'APPROVED',
        ]);

        return $this->find($poId);
    }

    /** @param array<string, mixed> $input */
    public function decide(int $poId, string $action, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.approve');

        $po = $this->find($poId);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }
        if ($po['status'] !== 'APPROVAL_PENDING' && !empty($po['amendment_pending_id'])) {
            return $this->decideAmendment($po, (int) $po['amendment_pending_id'], $action, $input);
        }
        if ($po['status'] !== 'APPROVAL_PENDING') {
            Http::conflict('This purchase order is not waiting for approval.');
        }
        $approved = $action === 'approve';
        $note = self::text($input['note'] ?? null);
        if (!$approved && $note === null) {
            Http::validationFailed('Say why this purchase order is being rejected.', ['field' => 'note']);
        }
        if ($approved) {
            $this->guardApprovedVendor((int) $po['supplier_account_id'], $this->settings(), $input);
        }

        // Segregation of duties, as the company's stated policy (SegregationOfDuties).
        SegregationOfDuties::assertMayDecide($this->ctx, $this->auth, (string) $po['created_by'], 'purchase order', 'purchase_order', $poId, $note);

        Db::transaction(function () use ($poId, $approved, $note, $action, $po) {
            Db::update('purchase_orders', [
                'status'      => $approved ? 'APPROVED' : 'DRAFT',
                'approved_by' => $approved ? $this->auth->uuid : null,
                'approved_at' => $approved ? self::now() : null,
                'updated_at'  => self::now(),
            ], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);

            Db::run(
                "UPDATE purchase_approval_requests SET status = :status, decided_by = :by, decided_at = :at, decision_note = :note
                 WHERE cmp_id = :cmp AND entity_type = 'purchase_order' AND entity_id = :id AND status = 'PENDING'",
                [
                    'status' => $approved ? 'APPROVED' : 'REJECTED',
                    'by' => $this->auth->uuid, 'at' => self::now(), 'note' => $note,
                    'cmp' => $this->ctx->cmpId, 'id' => $poId,
                ],
            );

            // In the same transaction (fail-closed): an approval with no record of who gave it
            // does not happen.
            Audit::material($this->ctx, $this->auth, 'po.' . $action, 'purchase_order', $poId, [
                'status' => 'APPROVAL_PENDING', 'po_no' => $po['po_no'] ?? null,
                'supplier' => $po['supplier_name_snapshot'] ?? null, 'total_amount' => $po['total_amount'] ?? null,
            ], [
                'status' => $approved ? 'APPROVED' : 'DRAFT', 'po_no' => $po['po_no'] ?? null,
                'supplier' => $po['supplier_name_snapshot'] ?? null, 'total_amount' => $po['total_amount'] ?? null,
            ], $note ?? '');
        });

        return $this->find($poId);
    }

    public function issue(int $poId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.create');

        $po = $this->find($poId);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }
        if ($po['status'] !== 'APPROVED') {
            Http::conflict('Approve the purchase order before issuing it to the supplier.');
        }
        $this->guardApprovedVendor((int) $po['supplier_account_id'], $this->settings(), []);

        Db::update('purchase_orders', ['status' => 'ISSUED', 'issued_at' => self::now(), 'updated_at' => self::now()], [
            'po_id' => $poId, 'cmp_id' => $this->ctx->cmpId,
        ]);
        Audit::record($this->ctx, $this->auth, 'po.issued', 'purchase_order', $poId, ['status' => 'APPROVED'], ['status' => 'ISSUED']);

        return $this->find($poId);
    }

    public const SEND_CHANNELS = ['email', 'whatsapp', 'courier', 'hand_delivered', 'connect', 'other'];
    public const ACK_SOURCES = ['supplier_email', 'phone', 'supplier_document', 'in_person', 'other'];

    /**
     * The order's document, as the supplier would receive it, and the fact that it was prepared.
     *
     * The names on it are read now from the products that own them (Manage, Inventory) and not
     * stored. Its fingerprint — of what the order says — is recorded, so a later "sent" names
     * exactly which version went out, and an amended order is visibly a different document.
     *
     * @return array{pdf: string, fingerprint: string, file_name: string}
     */
    public function document(int $poId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.view');
        $po = $this->find($poId);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }
        if (in_array($po['status'], ['DRAFT', 'APPROVAL_PENDING', 'REJECTED'], true)) {
            Http::conflict('Only an approved order has a document to send; this one is ' . strtolower(str_replace('_', ' ', (string) $po['status'])) . '.');
        }

        $fingerprint = self::fingerprint($po);
        $company = [];
        $info = (new \Aicountly\Api\Clients\ManageClient())->withSession($this->auth->sesKey())->companyInfo($this->ctx->cmpId);
        if ($info['ok']) {
            $company = (array) ($info['body']['data'] ?? $info['body'] ?? []);
        }
        $names = [];
        $itemIds = array_values(array_filter(array_map(static fn ($l) => $l['item_id'] === null ? null : (int) $l['item_id'], $po['lines'])));
        $lookup = (new \Aicountly\Api\Clients\InventoryClient())->withSession($this->auth->sesKey())->bulkLookupItems($this->ctx, $itemIds);
        if ($lookup['ok']) {
            foreach ((array) ($lookup['body']['data'] ?? []) as $item) {
                $names[(int) ($item['item_id'] ?? 0)] = (string) ($item['item_name'] ?? '');
            }
        }
        $pdf = \Aicountly\Api\Pdf\PurchaseOrderDocument::render($po, $company, $names, $fingerprint);

        $already = Db::scalar(
            "SELECT 1 FROM purchase_po_communications WHERE cmp_id = :cmp AND po_id = :po AND kind = 'prepared' AND document_fingerprint = :fp",
            ['cmp' => $this->ctx->cmpId, 'po' => $poId, 'fp' => $fingerprint],
        );
        if ($already === null) {
            Db::insert('purchase_po_communications', [
                'cmp_id' => $this->ctx->cmpId, 'po_id' => $poId, 'kind' => 'prepared', 'channel' => 'download',
                'document_fingerprint' => $fingerprint, 'occurred_on' => gmdate('Y-m-d'), 'recorded_by' => $this->auth->uuid,
            ], 'communication_id');
        }
        Audit::record($this->ctx, $this->auth, 'po.document_downloaded', 'purchase_order', $poId, null, [
            'po_no' => $po['po_no'], 'version_no' => $po['version_no'] ?? null, 'fingerprint' => $fingerprint,
        ]);

        return ['pdf' => $pdf, 'fingerprint' => $fingerprint, 'file_name' => preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $po['po_no']) . '.pdf'];
    }

    /**
     * Record that the order reached the supplier — by which channel, to whom — and which version.
     *
     * Recorded by the buyer; nothing is sent from here. Sending through a connected service is
     * the channel's own act, and this records its outcome.
     *
     * @param array<string, mixed> $input {channel, recipient, reference?, note?, sent_on?}
     */
    public function markSent(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.create');
        $channel = self::text($input['channel'] ?? null);
        if ($channel === null || !in_array($channel, self::SEND_CHANNELS, true)) {
            Http::validationFailed('Say how the order reached the supplier: ' . implode(', ', self::SEND_CHANNELS) . '.', ['field' => 'channel']);
        }
        $recipient = self::text($input['recipient'] ?? null);
        if ($recipient === null) {
            Http::validationFailed('Say who at the supplier received it (an email address, a number, a name).', ['field' => 'recipient']);
        }

        Db::transaction(function () use ($poId, $input, $channel, $recipient) {
            $po = PoProgress::lock($poId, $this->ctx->cmpId);
            if ($po === null) {
                Http::notFound('That purchase order does not exist.');
            }
            if (!in_array($po['status'], ['ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED'], true)) {
                Http::conflict('Issue the order before recording that it was sent.');
            }
            $full = $this->find($poId);
            Db::insert('purchase_po_communications', [
                'cmp_id' => $this->ctx->cmpId, 'po_id' => $poId, 'kind' => 'sent', 'channel' => $channel, 'recipient' => $recipient,
                'evidence' => self::text($input['reference'] ?? null), 'note' => self::text($input['note'] ?? null),
                'document_fingerprint' => self::fingerprint($full), 'occurred_on' => self::validDate($input['sent_on'] ?? null) ?? gmdate('Y-m-d'),
                'recorded_by' => $this->auth->uuid,
            ], 'communication_id');
            if ($po['sent_at'] === null) {
                Db::update('purchase_orders', ['sent_at' => self::now(), 'updated_at' => self::now()], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
            }
            Audit::record($this->ctx, $this->auth, 'po.sent', 'purchase_order', $poId, null, ['channel' => $channel, 'recipient' => $recipient]);
        });

        return $this->find($poId);
    }

    /**
     * Record the supplier's acknowledgement — only of an order that was sent, and only with where
     * it came from and the evidence of it. There is no supplier portal; the buyer records what
     * the supplier sent.
     *
     * @param array<string, mixed> $input {source, evidence, promised_date?, acknowledged_on?}
     */
    public function acknowledge(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.create');

        $source = self::text($input['source'] ?? null);
        if ($source === null || !in_array($source, self::ACK_SOURCES, true)) {
            Http::validationFailed('Say how the supplier confirmed the order: ' . implode(', ', self::ACK_SOURCES) . '.', ['field' => 'source']);
        }
        $evidence = self::text($input['evidence'] ?? null);
        if ($evidence === null) {
            Http::validationFailed('Record the evidence: their email\'s subject or reference, the call, the document number.', ['field' => 'evidence']);
        }

        Db::transaction(function () use ($poId, $input, $source, $evidence) {
            $po = PoProgress::lock($poId, $this->ctx->cmpId);
            if ($po === null) {
                Http::notFound('That purchase order does not exist.');
            }
            if (!in_array($po['status'], ['ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED'], true)) {
                Http::conflict('Issue the purchase order before recording the supplier acknowledgement.');
            }
            $sent = Db::scalar("SELECT 1 FROM purchase_po_communications WHERE cmp_id = :cmp AND po_id = :po AND kind = 'sent'", ['cmp' => $this->ctx->cmpId, 'po' => $poId]);
            if ($sent === null) {
                Http::conflict('Record how the order was sent to the supplier before recording their acknowledgement.');
            }
            Db::insert('purchase_po_communications', [
                'cmp_id' => $this->ctx->cmpId, 'po_id' => $poId, 'kind' => 'acknowledged', 'source' => $source, 'evidence' => $evidence,
                'note' => self::text($input['note'] ?? null), 'occurred_on' => self::validDate($input['acknowledged_on'] ?? null) ?? gmdate('Y-m-d'),
                'recorded_by' => $this->auth->uuid,
            ], 'communication_id');
            Db::update('purchase_orders', [
                // Acknowledgement does not move an order back: one already receiving stays so.
                'status'                 => $po['status'] === 'ISSUED' ? 'ACKNOWLEDGED' : $po['status'],
                'acknowledged_at'        => $po['acknowledged_at'] ?? self::now(),
                'acknowledged_by'        => $this->auth->uuid,
                'acknowledgement_source' => $source,
                'promised_date'          => self::validDate($input['promised_date'] ?? null) ?? $po['promised_date'],
                'updated_at'             => self::now(),
            ], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
            Audit::record($this->ctx, $this->auth, 'po.acknowledged', 'purchase_order', $poId, null, ['source' => $source, 'promised_date' => $input['promised_date'] ?? null], $evidence);
        });

        return $this->find($poId);
    }

    /** What the order says, as a hash: the same order prints the same fingerprint. */
    private static function fingerprint(array $po): string
    {
        $lines = array_map(static fn (array $l) => [
            (int) $l['line_no'], $l['item_id'] === null ? null : (int) $l['item_id'], (string) ($l['description'] ?? ''),
            (string) $l['ordered_qty'], (string) ($l['agreed_rate'] ?? ''), (string) ($l['line_amount'] ?? ''),
        ], (array) ($po['lines'] ?? []));

        return hash('sha256', json_encode([
            $po['po_no'], (int) ($po['version_no'] ?? 0), (int) $po['supplier_account_id'], (string) $po['po_date'], (string) ($po['promised_date'] ?? ''),
            (string) ($po['payment_terms'] ?? ''), (string) ($po['terms_text'] ?? ''), (string) ($po['total_amount'] ?? ''), $lines,
        ]));
    }

    private static function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }

    /**
     * Cancel an order nothing has happened against yet.
     *
     * Decided with the order row locked, and receipts and bills are counted INCLUDING the
     * ones still on their way to Inventory or Books — a cancel that raced a receipt would
     * otherwise pass its check and leave a cancelled order with goods on it.
     *
     * @param array<string, mixed> $input
     */
    public function cancel(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.cancel');

        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why this purchase order is being cancelled.', ['field' => 'reason']);
        }

        $before = Db::transaction(function () use ($poId, $reason): string {
            $po = PoProgress::lock($poId, $this->ctx->cmpId);
            if ($po === null) {
                Http::notFound('That purchase order does not exist.');
            }
            if (in_array($po['status'], ['CANCELLED', 'CLOSED'], true)) {
                Http::conflict('This purchase order is already ' . strtolower((string) $po['status']) . '.');
            }

            $activity = Db::first(
                "SELECT
                    (SELECT COALESCE(SUM(received_qty), 0) FROM purchase_order_lines WHERE po_id = :po) AS received,
                    (SELECT COALESCE(SUM(billed_qty), 0) FROM purchase_order_lines WHERE po_id = :po) AS billed,
                    (SELECT COUNT(*) FROM purchase_receipt_requests
                      WHERE po_id = :po AND applied_at IS NULL AND status IN ('REQUESTED', 'POSTING', 'FAILED', 'UNCERTAIN')) AS receipts_in_flight,
                    (SELECT COUNT(*) FROM purchase_bill_requests
                      WHERE po_id = :po AND status NOT IN ('CANCELLED')) AS bills",
                ['po' => $poId],
            ) ?? [];

            // Received or billed first, because those are the reasons that tell the user
            // what to do instead.
            if ((float) $activity['received'] > 0) {
                Http::conflict('Goods have been received against this order. Short-close it to stop waiting for the rest, or raise a purchase return.');
            }
            if ((int) $activity['receipts_in_flight'] > 0) {
                Http::conflict('A receipt against this order is still on its way to Inventory. Retry, reconcile or cancel it before cancelling the order.');
            }
            if ((float) $activity['billed'] > 0 || (int) $activity['bills'] > 0) {
                Http::conflict('A bill has been entered against this order. Short-close the order, or settle the bill in Smart Books, instead of cancelling it.');
            }

            Db::update('purchase_orders', [
                'status' => 'CANCELLED', 'cancelled_at' => self::now(), 'cancel_reason' => $reason, 'updated_at' => self::now(),
                'amendment_pending_id' => null,
            ], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
            // Nothing is left waiting on a cancelled order: its approval request, and any amendment
            // waiting for one, are withdrawn — not left in the inbox to be approved into a 409 (F482).
            Db::run(
                "UPDATE purchase_approval_requests SET status = 'WITHDRAWN', decided_by = :by, decided_at = NOW(), decision_note = :note
                  WHERE cmp_id = :cmp AND entity_type = 'purchase_order' AND entity_id = :po AND status = 'PENDING'",
                ['by' => $this->auth->uuid, 'note' => 'The purchase order was cancelled: ' . $reason, 'cmp' => $this->ctx->cmpId, 'po' => $poId],
            );
            Db::run(
                "UPDATE purchase_po_amendments SET status = 'WITHDRAWN', decided_by = :by, decided_at = NOW(), decision_note = 'The purchase order was cancelled.'
                  WHERE cmp_id = :cmp AND po_id = :po AND status = 'PENDING_APPROVAL'",
                ['by' => $this->auth->uuid, 'cmp' => $this->ctx->cmpId, 'po' => $poId],
            );

            Audit::material($this->ctx, $this->auth, 'po.cancelled', 'purchase_order', $poId, ['status' => (string) $po['status']], ['status' => 'CANCELLED'], $reason);

            return (string) $po['status'];
        });

        return $this->find($poId);
    }

    /**
     * Stop waiting for what has not arrived.
     *
     * Records, per line, the quantity that will never be delivered — explicitly, with a
     * reason, in the audit trail. Nothing already received, billed or owed is touched:
     * receipts stay in Inventory and liabilities stay in Books. The order then closes by
     * the same rule as any other, once whatever did arrive is billed.
     *
     * @param array<string, mixed> $input {reason, lines?: [{line_id, qty}]} — no lines means
     *                                    every line's whole remainder
     */
    public function shortClose(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.close');

        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why the rest of this order will not be delivered.', ['field' => 'reason']);
        }

        $closed = Db::transaction(function () use ($poId, $input): array {
            $po = PoProgress::lock($poId, $this->ctx->cmpId);
            if ($po === null) {
                Http::notFound('That purchase order does not exist.');
            }
            if (!in_array($po['status'], ['APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED'], true)) {
                Http::conflict('Only an approved order that is still open can be short-closed; this one is ' . strtolower((string) $po['status']) . '.');
            }

            $lines = [];
            foreach (Db::all('SELECT * FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp FOR UPDATE', ['po' => $poId, 'cmp' => $this->ctx->cmpId]) as $line) {
                $lines[(int) $line['line_id']] = $line;
            }
            $inFlight = [];
            foreach (Db::all(
                "SELECT requested_lines FROM purchase_receipt_requests
                  WHERE po_id = :po AND applied_at IS NULL AND status IN ('REQUESTED', 'POSTING', 'FAILED', 'UNCERTAIN')",
                ['po' => $poId],
            ) as $row) {
                foreach (Db::jsonColumn($row['requested_lines']) as $l) {
                    $inFlight[(int) $l['line_id']] = ($inFlight[(int) $l['line_id']] ?? 0.0) + (float) ($l['qty'] ?? 0);
                }
            }

            $requested = is_array($input['lines'] ?? null) && $input['lines'] !== [] ? $input['lines'] : null;
            $closed = [];
            foreach ($lines as $lineId => $line) {
                $isService = self::truthy($line['is_service']) || $line['item_id'] === null;
                $done = $isService
                    ? (float) $line['billed_qty'] - (float) $line['debited_qty']
                    : (float) $line['received_qty'] - (float) $line['returned_qty'] + ($inFlight[$lineId] ?? 0.0);
                $remaining = round((float) $line['ordered_qty'] - (float) $line['short_closed_qty'] - $done, 4);
                if ($remaining <= 0) {
                    continue;
                }
                $qty = $remaining;
                if ($requested !== null) {
                    $match = null;
                    foreach ($requested as $want) {
                        if (is_array($want) && (int) ($want['line_id'] ?? 0) === $lineId) {
                            $match = $want;
                        }
                    }
                    if ($match === null) {
                        continue;
                    }
                    $qty = round((float) ($match['qty'] ?? $remaining), 4);
                    if ($qty <= 0 || $qty > $remaining + 0.00005) {
                        Http::validationFailed(
                            sprintf('Line %d has %s still to come; a short-close can cover at most that.', (int) $line['line_no'], rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.')),
                            ['field' => 'lines', 'line_id' => $lineId, 'remaining' => $remaining],
                        );
                    }
                }
                Db::run(
                    'UPDATE purchase_order_lines SET short_closed_qty = short_closed_qty + :qty, updated_at = NOW()
                      WHERE line_id = :id AND cmp_id = :cmp',
                    ['qty' => $qty, 'id' => $lineId, 'cmp' => $this->ctx->cmpId],
                );
                $closed[] = ['line_id' => $lineId, 'line_no' => (int) $line['line_no'], 'short_closed_qty' => $qty, 'remaining_before' => $remaining];
            }
            if ($closed === []) {
                Http::conflict('Nothing on this order is still waiting to be delivered.');
            }

            Db::update('purchase_orders', ['closure_reason' => self::text($input['reason'] ?? null)], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
            PoProgress::recompute($poId, $this->ctx->cmpId, $this->auth->uuid);

            return $closed;
        });

        Audit::record($this->ctx, $this->auth, 'po.short_closed', 'purchase_order', $poId, null, ['lines' => $closed], $reason);

        return $this->find($poId);
    }

    /** Orders that may be amended: before receipt, during it, and once received but not closed. */
    private const AMENDABLE = ['DRAFT', 'APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED'];

    /**
     * Amend an order: change what was agreed with the supplier — a line's quantity, rate, discount
     * or promised date, a new line, the promised date, payment terms or charges — as a new version
     * with the old and new terms kept (F167, F420).
     *
     *   - A line cannot drop below what has already arrived (or is on its way to Inventory) and
     *     been billed; receipts and bills are not rewritten, and later ones are matched against
     *     the amended terms.
     *   - A higher rate on a line is ordering above the agreed rate: it needs price.override.
     *   - An amendment that raises the order's value above the company's approval threshold waits
     *     for an approver (segregation of duties applies); until then the order works on its
     *     current terms. Anything else applies at once. A draft is simply edited.
     *
     * @param array<string, mixed> $input {reason, lines?: [{line_id?, ordered_qty?, agreed_rate?, discount_pc?,
     *                                     promised_date?, …new line fields}], promised_date?, payment_terms?,
     *                                     freight_amount?, other_charges?, terms_text?}
     */
    public function amend(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.amend');
        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why the order is being amended — what was agreed with the supplier.', ['field' => 'reason']);
        }

        $current = $this->find($poId);
        if ($current === []) {
            Http::notFound('That purchase order does not exist.');
        }
        $header = $this->header($input + ['po_date' => $current['po_date']], $current);
        $requested = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
        $newItems = [];
        foreach ($requested as $want) {
            if (is_array($want) && empty($want['line_id']) && !empty($want['item_id'])) {
                $newItems[] = (int) $want['item_id'];
            }
        }
        $masters = new MasterCheck($this->ctx, $this->auth);
        $masters->items($newItems);
        $masters->warehouses(array_map(static fn ($w) => is_array($w) ? self::id($w['warehouse_id'] ?? null) : null, $requested), 'warehouse_id');

        $outcome = Db::transaction(function () use ($poId, $input, $reason, $header, $requested): array {
            $po = PoProgress::lock($poId, $this->ctx->cmpId);
            if ($po === null) {
                Http::notFound('That purchase order does not exist.');
            }
            if (!in_array($po['status'], self::AMENDABLE, true)) {
                Http::conflict(match ((string) $po['status']) {
                    'APPROVAL_PENDING' => 'This order is waiting for approval. Approve or reject it before amending it.',
                    'CLOSED' => 'This order is closed. Reopen it first if more is still to come.',
                    'CANCELLED' => 'This order was cancelled.',
                    default => 'This order cannot be amended in its current state.',
                }, ['status' => $po['status']]);
            }
            if (!empty($po['amendment_pending_id'])) {
                Http::conflict('An earlier amendment of this order is waiting for approval. Have it decided first.', ['amendment_id' => (int) $po['amendment_pending_id']]);
            }

            $lines = [];
            foreach (Db::all('SELECT * FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp ORDER BY line_no FOR UPDATE', ['po' => $poId, 'cmp' => $this->ctx->cmpId]) as $line) {
                $lines[(int) $line['line_id']] = $line;
            }
            $inFlight = [];
            foreach (Db::all(
                "SELECT requested_lines FROM purchase_receipt_requests
                  WHERE po_id = :po AND cmp_id = :cmp AND applied_at IS NULL AND status IN ('REQUESTED', 'POSTING', 'FAILED', 'UNCERTAIN')",
                ['po' => $poId, 'cmp' => $this->ctx->cmpId],
            ) as $row) {
                foreach (Db::jsonColumn($row['requested_lines']) as $l) {
                    $inFlight[(int) ($l['line_id'] ?? 0)] = ($inFlight[(int) ($l['line_id'] ?? 0)] ?? 0.0) + (float) ($l['qty'] ?? 0);
                }
            }

            $before = self::termsOf($po, $lines);
            $after = $before;
            $rateUp = false;
            $nextNo = $lines === [] ? 0 : max(array_map(static fn ($l) => (int) $l['line_no'], $lines));
            foreach ($requested as $index => $want) {
                if (!is_array($want)) {
                    continue;
                }
                $lineId = self::id($want['line_id'] ?? null);
                if ($lineId === null) {
                    // A new line, checked as a draft's line is.
                    $fresh = $this->normaliseLines([$want])[0] ?? null;
                    if ($fresh === null) {
                        continue;
                    }
                    $fresh['line_no'] = ++$nextNo;
                    $after['lines']['new:' . $index] = self::lineTerms($fresh, null);
                    continue;
                }
                $line = $lines[$lineId] ?? null;
                if ($line === null) {
                    Http::validationFailed('That line is not on this purchase order.', ['field' => 'lines', 'index' => $index, 'line_id' => $lineId]);
                }
                $merged = $this->normaliseLines([[
                    'item_id'          => $line['item_id'],
                    'unit_id'          => $line['unit_id'],
                    'warehouse_id'     => $want['warehouse_id'] ?? $line['warehouse_id'],
                    'is_service'       => self::truthy($line['is_service']),
                    'description'      => $want['description'] ?? $line['description'],
                    'ordered_qty'      => $want['ordered_qty'] ?? $line['ordered_qty'],
                    'agreed_rate'      => $want['agreed_rate'] ?? $line['agreed_rate'],
                    'discount_pc'      => $want['discount_pc'] ?? $line['discount_pc'],
                    'tax_cat_id'       => $line['tax_cat_id'],
                    'estimated_tax_pc' => $want['estimated_tax_pc'] ?? $line['estimated_tax_pc'],
                    'promised_date'    => array_key_exists('promised_date', $want) ? $want['promised_date'] : $line['promised_date'],
                    'hsn_sac'          => $line['hsn_sac'],
                ]])[0];
                // Never below what this order has already taken: received (net of returns) plus
                // what is on its way to Inventory, short-closed, and billed.
                $floor = max(
                    (float) $line['received_qty'] - (float) $line['returned_qty'] + ($inFlight[$lineId] ?? 0.0) + (float) $line['short_closed_qty'],
                    (float) $line['billed_qty'] - (float) $line['debited_qty'],
                );
                if ($merged['ordered_qty'] + 0.00005 < $floor) {
                    Http::validationFailed(
                        sprintf('Line %d cannot be cut to %s: %s has already arrived, is on its way or is billed against it.', (int) $line['line_no'], self::qtyText($merged['ordered_qty']), self::qtyText($floor)),
                        ['field' => 'lines', 'line_id' => $lineId, 'minimum' => $floor],
                    );
                }
                if ($merged['agreed_rate'] > (float) $line['agreed_rate'] + 0.00005 || $merged['discount_pc'] + 0.0005 < (float) $line['discount_pc']) {
                    $rateUp = true;
                }
                $merged['line_no'] = (int) $line['line_no'];
                $after['lines'][(string) $lineId] = self::lineTerms($merged, $lineId);
            }
            foreach (['promised_date', 'payment_terms', 'terms_text'] as $field) {
                if (array_key_exists($field, $input)) {
                    $after['header'][$field] = $field === 'promised_date' ? $header['promised_date'] : self::text($input[$field]);
                }
            }
            if (array_key_exists('freight_amount', $input)) {
                $after['header']['freight_amount'] = $header['freight_amount'];
            }
            if (array_key_exists('other_charges', $input)) {
                $after['header']['other_charges'] = $header['other_charges'];
            }
            if ($after === $before) {
                Http::validationFailed('Nothing in this amendment changes the order.');
            }
            if ($rateUp && $po['status'] !== 'DRAFT') {
                // Ordering above the rate agreed (a higher rate, or less discount): its own authority.
                Permissions::assert($this->ctx, $this->auth, 'price.override');
            }

            $valueBefore = (float) $po['total_amount'];
            $valueAfter = self::totals(array_values($after['lines']), (float) $after['header']['freight_amount'], (float) $after['header']['other_charges'])['total'];
            $threshold = (float) ($this->settings()['po_approval_above_amount'] ?? 0);
            $needsApproval = $po['status'] !== 'DRAFT' && $threshold > 0 && $valueAfter > $threshold && $valueAfter > $valueBefore + 0.005;

            $fromVersion = (int) ($po['version_no'] ?? 0);
            $toVersion = $po['status'] === 'DRAFT' ? $fromVersion : $fromVersion + 1;
            $amendmentId = (int) Db::insert('purchase_po_amendments', [
                'cmp_id'       => $this->ctx->cmpId,
                'po_id'        => $poId,
                'from_version' => $fromVersion,
                'to_version'   => $needsApproval ? $toVersion : $toVersion,
                'status'       => $needsApproval ? 'PENDING_APPROVAL' : 'APPLIED',
                'reason'       => $reason,
                'before_terms' => $before,
                'after_terms'  => $after,
                'value_before' => $valueBefore,
                'value_after'  => $valueAfter,
                'requested_by' => $this->auth->uuid,
                'decided_by'   => $needsApproval ? null : $this->auth->uuid,
                'decided_at'   => $needsApproval ? null : self::now(),
            ], 'amendment_id');

            if ($needsApproval) {
                Db::update('purchase_orders', ['amendment_pending_id' => $amendmentId, 'updated_at' => self::now()], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
                Db::insert('purchase_approval_requests', [
                    'cmp_id'              => $this->ctx->cmpId,
                    'fy_id'               => $this->ctx->fyId,
                    'entity_type'         => 'purchase_order',
                    'entity_id'           => $poId,
                    'amendment_id'        => $amendmentId,
                    'required_permission' => 'po.approve',
                    'reason_kind'         => 'amendment',
                    'reason_detail'       => sprintf('Amendment to version %d raises the order from %s to %s, above the %s approval threshold: %s', $toVersion, self::money($valueBefore), self::money($valueAfter), self::money($threshold), $reason),
                    'threshold_value'     => $threshold,
                    'actual_value'        => $valueAfter,
                    'requested_by'        => $this->auth->uuid,
                ], 'approval_id');
            } else {
                $this->applyTerms($poId, $after, $toVersion);
            }

            return ['amendment_id' => $amendmentId, 'pending' => $needsApproval, 'to_version' => $toVersion, 'value_after' => $valueAfter];
        });

        Audit::record($this->ctx, $this->auth, $outcome['pending'] ? 'po.amendment_requested' : 'po.amended', 'purchase_order', $poId, null, $outcome, $reason);

        return $this->find($poId);
    }

    /** Approve or reject an amendment waiting for approval. @param array<string, mixed> $po */
    private function decideAmendment(array $po, int $amendmentId, string $action, array $input): array
    {
        $poId = (int) $po['po_id'];
        $approved = $action === 'approve';
        $note = self::text($input['note'] ?? null);
        if (!$approved && $note === null) {
            Http::validationFailed('Say why this amendment is being rejected.', ['field' => 'note']);
        }
        $amendment = Db::first('SELECT * FROM purchase_po_amendments WHERE amendment_id = :id AND cmp_id = :cmp', ['id' => $amendmentId, 'cmp' => $this->ctx->cmpId]);
        if ($amendment === null || $amendment['status'] !== 'PENDING_APPROVAL') {
            Http::conflict('This amendment is not waiting for approval.');
        }
        SegregationOfDuties::assertMayDecide($this->ctx, $this->auth, (string) $amendment['requested_by'], 'amendment', 'purchase_order', $poId, $note);

        Db::transaction(function () use ($poId, $amendmentId, $approved, $note, $amendment): void {
            $locked = PoProgress::lock($poId, $this->ctx->cmpId);
            $amendment = Db::first('SELECT * FROM purchase_po_amendments WHERE amendment_id = :id AND cmp_id = :cmp FOR UPDATE', ['id' => $amendmentId, 'cmp' => $this->ctx->cmpId]);
            if ($locked === null || $amendment['status'] !== 'PENDING_APPROVAL' || (int) ($locked['amendment_pending_id'] ?? 0) !== $amendmentId) {
                Http::conflict('This amendment is no longer waiting for approval.');
            }
            if ($approved) {
                // What arrived while it waited still sets the floor: re-checked under the lock.
                $after = Db::jsonColumn($amendment['after_terms']);
                foreach ($after['lines'] as $line) {
                    if (!isset($line['line_id'])) {
                        continue;
                    }
                    $now = Db::first('SELECT * FROM purchase_order_lines WHERE line_id = :id AND cmp_id = :cmp', ['id' => (int) $line['line_id'], 'cmp' => $this->ctx->cmpId]);
                    $floor = max((float) $now['received_qty'] - (float) $now['returned_qty'] + (float) $now['short_closed_qty'], (float) $now['billed_qty'] - (float) $now['debited_qty']);
                    if ((float) $line['ordered_qty'] + 0.00005 < $floor) {
                        Http::conflict(sprintf('Line %d has had %s received or billed since this amendment was asked for, more than it allows. Reject it and amend again.', (int) $now['line_no'], self::qtyText($floor)));
                    }
                }
                $this->applyTerms($poId, $after, (int) $amendment['to_version']);
            }
            Db::update('purchase_po_amendments', [
                'status' => $approved ? 'APPLIED' : 'REJECTED', 'decided_by' => $this->auth->uuid, 'decided_at' => self::now(), 'decision_note' => $note,
            ], ['amendment_id' => $amendmentId]);
            Db::update('purchase_orders', ['amendment_pending_id' => null, 'updated_at' => self::now()], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
            Db::run(
                "UPDATE purchase_approval_requests SET status = :status, decided_by = :by, decided_at = NOW(), decision_note = :note
                  WHERE cmp_id = :cmp AND entity_type = 'purchase_order' AND entity_id = :po AND amendment_id = :aid AND status = 'PENDING'",
                ['status' => $approved ? 'APPROVED' : 'REJECTED', 'by' => $this->auth->uuid, 'note' => $note, 'cmp' => $this->ctx->cmpId, 'po' => $poId, 'aid' => $amendmentId],
            );

            Audit::material($this->ctx, $this->auth, 'po.amendment_' . ($approved ? 'approved' : 'rejected'), 'purchase_order', $poId,
                ['amendment_status' => 'PENDING_APPROVAL'],
                ['amendment_status' => $approved ? 'APPLIED' : 'REJECTED', 'amendment_id' => $amendmentId, 'to_version' => $amendment['to_version'] ?? null],
                $note ?? '');
        });

        return $this->find($poId);
    }

    /**
     * Reopen what a short-close stopped waiting for: the quantity it recorded as never coming is
     * expected again (all of it, or per line), and the order's status follows (F420).
     *
     * @param array<string, mixed> $input {reason, lines?: [{line_id, qty}]}
     */
    public function reopen(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.close');
        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why more is coming after all.', ['field' => 'reason']);
        }
        $reopened = Db::transaction(function () use ($poId, $input): array {
            $po = PoProgress::lock($poId, $this->ctx->cmpId);
            if ($po === null) {
                Http::notFound('That purchase order does not exist.');
            }
            if (!in_array($po['status'], ['APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED'], true)) {
                Http::conflict('Only an approved order that was short-closed can be reopened; this one is ' . strtolower((string) $po['status']) . '.');
            }
            $requested = is_array($input['lines'] ?? null) && $input['lines'] !== [] ? $input['lines'] : null;
            $out = [];
            foreach (Db::all('SELECT * FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp ORDER BY line_no FOR UPDATE', ['po' => $poId, 'cmp' => $this->ctx->cmpId]) as $line) {
                $closed = (float) $line['short_closed_qty'];
                if ($closed <= 0.00005) {
                    continue;
                }
                $qty = $closed;
                if ($requested !== null) {
                    $match = null;
                    foreach ($requested as $want) {
                        if (is_array($want) && (int) ($want['line_id'] ?? 0) === (int) $line['line_id']) {
                            $match = $want;
                        }
                    }
                    if ($match === null) {
                        continue;
                    }
                    $qty = round((float) ($match['qty'] ?? $closed), 4);
                    if ($qty <= 0 || $qty > $closed + 0.00005) {
                        Http::validationFailed(sprintf('Line %d had %s short-closed; at most that can be reopened.', (int) $line['line_no'], self::qtyText($closed)), ['field' => 'lines', 'line_id' => (int) $line['line_id']]);
                    }
                }
                Db::run('UPDATE purchase_order_lines SET short_closed_qty = short_closed_qty - :qty, updated_at = NOW() WHERE line_id = :id AND cmp_id = :cmp', ['qty' => $qty, 'id' => (int) $line['line_id'], 'cmp' => $this->ctx->cmpId]);
                $out[] = ['line_id' => (int) $line['line_id'], 'line_no' => (int) $line['line_no'], 'reopened_qty' => $qty];
            }
            if ($out === []) {
                Http::conflict('Nothing on this order was short-closed, so there is nothing to reopen.');
            }
            PoProgress::recompute($poId, $this->ctx->cmpId, $this->auth->uuid);

            return $out;
        });
        Audit::record($this->ctx, $this->auth, 'po.reopened', 'purchase_order', $poId, null, ['lines' => $reopened], $reason);

        return $this->find($poId);
    }

    /**
     * The terms an amendment compares and changes. @param array<string, mixed> $po
     * @param array<int, array<string, mixed>> $lines
     * @return array{header: array<string, mixed>, lines: array<string, array<string, mixed>>}
     */
    private static function termsOf(array $po, array $lines): array
    {
        $out = ['header' => [
            'promised_date'  => isset($po['promised_date']) ? substr((string) $po['promised_date'], 0, 10) : null,
            'payment_terms'  => $po['payment_terms'] ?? null,
            'terms_text'     => $po['terms_text'] ?? null,
            'freight_amount' => round((float) ($po['freight_amount'] ?? 0), 4),
            'other_charges'  => round((float) ($po['other_charges'] ?? 0), 4),
        ], 'lines' => []];
        foreach ($lines as $lineId => $line) {
            $out['lines'][(string) $lineId] = self::lineTerms($line, (int) $lineId);
        }

        return $out;
    }

    /**
     * One line's terms in one shape, whether read from the table or built from an amendment, so
     * two sets of terms compare equal exactly when they say the same thing.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private static function lineTerms(array $line, ?int $lineId): array
    {
        $amount = round((float) $line['line_amount'], 4);
        $taxPc = round((float) $line['estimated_tax_pc'], 3);

        return [
            'line_id'          => $lineId,
            'line_no'          => (int) $line['line_no'],
            'item_id'          => $line['item_id'] === null ? null : (int) $line['item_id'],
            'unit_id'          => $line['unit_id'] === null ? null : (int) $line['unit_id'],
            'warehouse_id'     => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
            'is_service'       => self::truthy($line['is_service']),
            'description'      => $line['description'] === null ? null : (string) $line['description'],
            'ordered_qty'      => round((float) $line['ordered_qty'], 4),
            'agreed_rate'      => round((float) $line['agreed_rate'], 4),
            'discount_pc'      => round((float) $line['discount_pc'], 3),
            'discount_amount'  => round((float) $line['discount_amount'], 4),
            'tax_cat_id'       => $line['tax_cat_id'] === null ? null : (int) $line['tax_cat_id'],
            'estimated_tax_pc' => $taxPc,
            'estimated_tax'    => round($amount * $taxPc / 100, 4),
            'line_amount'      => $amount,
            'promised_date'    => $line['promised_date'] === null ? null : substr((string) $line['promised_date'], 0, 10),
            'hsn_sac'          => $line['hsn_sac'] === null ? null : (string) $line['hsn_sac'],
        ];
    }

    /** Write amended terms onto the order, its lines and its totals. @param array<string, mixed> $terms */
    private function applyTerms(int $poId, array $terms, int $version): void
    {
        foreach ($terms['lines'] as $key => $line) {
            $values = [
                'warehouse_id'     => $line['warehouse_id'] ?? null,
                'description'      => $line['description'] ?? null,
                'ordered_qty'      => $line['ordered_qty'],
                'agreed_rate'      => $line['agreed_rate'],
                'discount_pc'      => $line['discount_pc'],
                'discount_amount'  => $line['discount_amount'],
                'estimated_tax_pc' => $line['estimated_tax_pc'],
                'line_amount'      => $line['line_amount'],
                'promised_date'    => $line['promised_date'] ?? null,
                'updated_at'       => self::now(),
            ];
            if (!empty($line['line_id']) && !str_starts_with((string) $key, 'new:')) {
                Db::update('purchase_order_lines', $values, ['line_id' => (int) $line['line_id'], 'po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
            } else {
                $this->writeLines($poId, [$line + ['requisition_line_id' => null, 'quote_line_id' => null]]);
            }
        }
        $all = Db::all('SELECT * FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp', ['po' => $poId, 'cmp' => $this->ctx->cmpId]);
        foreach ($all as $i => $row) {
            $all[$i]['estimated_tax'] = round((float) $row['line_amount'] * (float) $row['estimated_tax_pc'] / 100, 4);
        }
        $totals = self::totals($all, (float) $terms['header']['freight_amount'], (float) $terms['header']['other_charges']);
        Db::update('purchase_orders', [
            'promised_date'        => $terms['header']['promised_date'],
            'payment_terms'        => $terms['header']['payment_terms'],
            'terms_text'           => $terms['header']['terms_text'],
            'subtotal_amount'      => $totals['subtotal'],
            'discount_amount'      => $totals['discount'],
            'freight_amount'       => $totals['freight'],
            'other_charges'        => $totals['other'],
            'estimated_tax_amount' => $totals['tax'],
            'total_amount'         => $totals['total'],
            'version_no'           => $version,
            'updated_at'           => self::now(),
        ], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
        PoProgress::recompute($poId, $this->ctx->cmpId, $this->auth->uuid);
    }

    private static function qtyText(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /** @return array<string, mixed> */
    public function find(int $poId): array
    {
        $row = Db::first('SELECT * FROM purchase_orders WHERE po_id = :id AND cmp_id = :cmp', ['id' => $poId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            return [];
        }

        $row['lines'] = Db::all('SELECT * FROM purchase_order_lines WHERE po_id = :id ORDER BY line_no', ['id' => $poId]);
        $row['schedules'] = Db::all('SELECT * FROM purchase_delivery_schedules WHERE po_id = :id ORDER BY scheduled_date, schedule_id', ['id' => $poId]);
        $row['receipts'] = Db::all('SELECT * FROM purchase_receipt_requests WHERE po_id = :id ORDER BY request_id DESC', ['id' => $poId]);
        $row['bills'] = Db::all('SELECT * FROM purchase_bill_requests WHERE po_id = :id ORDER BY request_id DESC', ['id' => $poId]);
        $row['returns'] = Db::all(
            'SELECT return_id, return_no, return_date, status, inventory_document_uuid, books_debit_note_id
               FROM purchase_returns WHERE po_id = :id AND cmp_id = :cmp ORDER BY return_id DESC',
            ['id' => $poId, 'cmp' => $this->ctx->cmpId],
        );
        $progress = PoProgress::figures($poId, $this->ctx->cmpId);
        // What a new receipt on this order will do to stock — the company's choice today.
        $row['grn_stock_effect'] = ReceiptService::stockEffectFor($this->ctx->cmpId);
        // And who last changed that choice, so the receive panel can say it (E8-INV A11).
        $mode = Db::first('SELECT receive_stock_at_grn_changed_by AS by, receive_stock_at_grn_changed_at AS at FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]);
        $row['grn_stock_effect_changed'] = ['by' => $mode['by'] ?? null, 'at' => $mode['at'] ?? null];
        $row['progress'] = [
            'receipt_status' => $progress['receipt_status'],
            'billing_status' => $progress['billing_status'],
            'bill_work_open' => $progress['bill_work_open'],
            'lines'          => $progress['lines'],
        ];

        // The order's own commands, and the ones filed under the documents raised against
        // it — a receipt's GRN, a bill's voucher, a return's dispatch and debit note. They
        // are what can be stuck, so they are what the order's strip has to show.
        $entities = [['purchase_order', $poId]];
        foreach ($row['receipts'] as $receipt) {
            $entities[] = ['receipt_request', (int) $receipt['request_id']];
        }
        foreach ($row['bills'] as $bill) {
            $entities[] = ['bill_request', (int) $bill['request_id']];
        }
        foreach ($row['returns'] as $return) {
            $entities[] = ['purchase_return', (int) $return['return_id']];
        }
        $row['commands'] = IntegrationCommand::forEntities($this->ctx, $entities);

        // A receipt left REQUESTED or POSTING by an attempt that died (a deploy restart, a worker
        // killed mid-call) holds its quantity against the order until somebody acts. Once no live
        // lease protects it, Reconcile (asks Inventory) and Retry (same key, same body) are safe
        // and offered (F301). No cron: the person on the order decides.
        $leases = [];
        foreach ($row['commands'] as $command) {
            if ($command['entity_type'] === 'receipt_request' && $command['command_type'] === ReceiptService::COMMAND_RECEIPT) {
                $leases[(int) $command['entity_id']] = $command;
            }
        }
        foreach ($row['receipts'] as $i => $receipt) {
            $stuck = false;
            if ($receipt['applied_at'] === null && in_array($receipt['status'], ['REQUESTED', 'POSTING'], true)) {
                $command = $leases[(int) $receipt['request_id']] ?? null;
                if ($command === null) {
                    $stuck = strtotime((string) $receipt['updated_at']) < time() - IntegrationCommand::LEASE_SECONDS;
                } elseif ($command['status'] === IntegrationCommand::POSTING) {
                    $stuck = $command['lease_expires_at'] === null || strtotime((string) $command['lease_expires_at']) < time();
                } else {
                    $stuck = in_array($command['status'], [IntegrationCommand::PENDING, IntegrationCommand::FAILED, IntegrationCommand::UNCERTAIN], true);
                }
            }
            $row['receipts'][$i]['recoverable'] = $stuck;
        }
        // Prepared, sent, acknowledged — three facts, each with who, when and how.
        $row['communications'] = Db::all(
            'SELECT communication_id, kind, channel, recipient, source, evidence, note, document_fingerprint, occurred_on, recorded_by, created_at
               FROM purchase_po_communications WHERE cmp_id = :cmp AND po_id = :po ORDER BY communication_id',
            ['cmp' => $this->ctx->cmpId, 'po' => $poId],
        );
        $row['approvals'] = Db::all(
            "SELECT * FROM purchase_approval_requests
             WHERE cmp_id = :cmp AND entity_type = 'purchase_order' AND entity_id = :id ORDER BY approval_id",
            ['cmp' => $this->ctx->cmpId, 'id' => $poId],
        );
        // Every amendment: what was agreed before and after, who asked, who decided.
        $row['amendments'] = array_map(static function (array $a): array {
            $a['before_terms'] = Db::jsonColumn($a['before_terms']);
            $a['after_terms'] = Db::jsonColumn($a['after_terms']);

            return $a;
        }, Db::all('SELECT * FROM purchase_po_amendments WHERE cmp_id = :cmp AND po_id = :id ORDER BY amendment_id DESC', ['cmp' => $this->ctx->cmpId, 'id' => $poId]));

        return $row;
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function search(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$scope, $params] = $this->ctx->scopeClause('p');
        $where = [$scope];

        foreach ([
            'status'              => ['p.status', 'status'],
            'supplier_account_id' => ['p.supplier_account_id', 'supplier'],
        ] as $key => [$column, $bind]) {
            if (!empty($filters[$key])) {
                $where[] = $column . ' = :' . $bind;
                $params[$bind] = $filters[$key];
            }
        }
        if (!empty($filters['from'])) {
            $where[] = 'p.po_date >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'p.po_date <= :to';
            $params['to'] = (string) $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.po_no ILIKE :term OR p.supplier_name_snapshot ILIKE :term)';
            $params['term'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['open_only'])) {
            $where[] = "p.status NOT IN ('CANCELLED', 'CLOSED')";
        }
        if (!empty($filters['overdue'])) {
            $where[] = "p.promised_date < CURRENT_DATE AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')";
        }
        // The amount range, on the order total the list shows (in the order's own currency).
        $register = $filters['register'] ?? null;
        if ($register instanceof RegisterFilter) {
            $register->apply($where, $params, 'p.total_amount');
        }

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['po_date', 'po_no', 'total_amount', 'status', 'promised_date', 'created_at'], true) ? $sort : 'po_date';

        return [
            'rows'  => Db::all("SELECT p.* FROM purchase_orders p WHERE {$clause} ORDER BY p.{$sortColumn} {$order}, p.po_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM purchase_orders p WHERE {$clause}", $params),
        ];
    }

    // -----------------------------------------------------------------------

    /** Supplier standings nobody orders from, whatever the company's enforcement setting. */
    private const BARRED = ['blacklisted', 'suspended'];

    /**
     * May this company order from this supplier now? Asked when the draft is made AND at submit,
     * approval and issue (F025):
     *
     *   blacklisted or suspended  refused always — unless somebody who may approve suppliers
     *                             overrides it for this order, saying why (audited)
     *   anything but approved     refused when the company enforces approved suppliers
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $input  vendor_override_reason, from the caller
     */
    private function guardApprovedVendor(int $supplierId, array $settings, array $input): void
    {
        $status = Db::scalar(
            'SELECT qualification_status FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier',
            ['cmp' => $this->ctx->cmpId, 'supplier' => $supplierId],
        );
        $status = $status === null ? null : (string) $status;
        $barred = $status !== null && in_array($status, self::BARRED, true);
        $enforced = !empty($settings['enforce_approved_vendors']) && $status !== 'approved';
        if (!$barred && !$enforced) {
            return;
        }

        $override = self::text($input['vendor_override_reason'] ?? null);
        if ($barred && $override !== null && Permissions::allows($this->ctx, $this->auth, 'supplier.approve')) {
            Audit::record($this->ctx, $this->auth, 'po.vendor_override', 'supplier', $supplierId, ['qualification_status' => $status], null, $override);

            return;
        }

        Http::error(
            409,
            'vendor_not_approved',
            $barred
                ? sprintf('This supplier is %s in Purchases, so it cannot be ordered from. Somebody who may approve suppliers can override this for one order, saying why.', $status)
                : 'This company only orders from approved suppliers, and this one is '
                    . ($status === null ? 'not set up in Purchases yet' : strtolower(str_replace('_', ' ', $status))) . '.',
            ['supplier_account_id' => $supplierId, 'qualification_status' => $status, 'override' => $barred ? 'vendor_override_reason with supplier.approve' : null],
        );
    }

    /**
     * The order's header values, checked before anything is stored (F266, F430): a date that is a
     * real date and inside the financial year this request is in, a promised date that is a real
     * date, charges that are not negative, a currency with a usable exchange rate.
     *
     * @param array<string, mixed> $input
     * @return array{po_date: string, promised_date: ?string, freight_amount: float, other_charges: float, currency_code: string, exchange_rate: float}
     */
    private function header(array $input, ?array $current = null): array
    {
        $poDate = self::strictDate($input['po_date'] ?? null, 'po_date') ?? ($current['po_date'] ?? null) ?? gmdate('Y-m-d');
        $range = $this->ctx->fyRange();
        if ($current === null && $range !== null && ($poDate < $range['from'] || $poDate > $range['to'])) {
            Http::validationFailed(sprintf('The order date %s is outside the financial year you are in (%s to %s). Switch to the year it belongs to, or date it in this one.', $poDate, $range['from'], $range['to']), ['field' => 'po_date', 'fy_from' => $range['from'], 'fy_to' => $range['to']]);
        }
        $promised = array_key_exists('promised_date', $input) ? self::strictDate($input['promised_date'], 'promised_date') : ($current['promised_date'] ?? null);
        $freight = self::amount($input['freight_amount'] ?? ($current['freight_amount'] ?? 0), 'freight_amount');
        $other = self::amount($input['other_charges'] ?? ($current['other_charges'] ?? 0), 'other_charges');
        $currency = strtoupper(self::text($input['currency_code'] ?? null) ?? (string) ($current['currency_code'] ?? 'INR'));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            Http::validationFailed('The currency is a three-letter code, like INR or USD.', ['field' => 'currency_code']);
        }
        $rawRate = $input['exchange_rate'] ?? ($current['exchange_rate'] ?? 1);
        if (!is_numeric($rawRate) || (float) $rawRate <= 0 || (float) $rawRate > 100000) {
            Http::validationFailed('The exchange rate must be a positive number: rupees for one unit of the order\'s currency.', ['field' => 'exchange_rate']);
        }
        $rate = round((float) $rawRate, 6);
        if ($currency === 'INR' && abs($rate - 1.0) > 0.000001) {
            Http::validationFailed('An order in rupees has an exchange rate of 1.', ['field' => 'exchange_rate']);
        }

        return ['po_date' => $poDate, 'promised_date' => $promised, 'freight_amount' => $freight, 'other_charges' => $other, 'currency_code' => $currency, 'exchange_rate' => $rate];
    }

    /** A date given that is not a real YYYY-MM-DD date is a 422, not today and not a 503. */
    private static function strictDate(mixed $value, string $field): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $text = is_string($value) ? trim($value) : '';
        $parts = explode('-', $text);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            Http::validationFailed(sprintf('"%s" is not a date (YYYY-MM-DD).', is_scalar($value) ? (string) $value : gettype($value)), ['field' => $field]);
        }

        return $text;
    }

    /** A money amount that is a number, not negative and not absurd. */
    private static function amount(mixed $value, string $field, float $max = 1e12): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > $max) {
            Http::validationFailed(sprintf('%s must be a number from 0 to %s.', str_replace('_', ' ', ucfirst($field)), number_format($max, 0, '.', '')), ['field' => $field]);
        }

        return round((float) $value, 4);
    }

    /** @param list<array<string, mixed>> $lines */
    private function consumeRequisitionQuantities(array $lines): void
    {
        foreach ($lines as $line) {
            if ($line['requisition_line_id'] === null) {
                continue;
            }
            Db::run(
                'UPDATE purchase_requisition_lines SET ordered_qty = ordered_qty + :qty WHERE line_id = :id AND cmp_id = :cmp',
                ['qty' => $line['ordered_qty'], 'id' => $line['requisition_line_id'], 'cmp' => $this->ctx->cmpId],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function normaliseLines(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $lines = [];
        $lineNo = 0;
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $isService = (bool) ($line['is_service'] ?? false);
            $itemId = self::id($line['item_id'] ?? null);
            if (!$isService && $itemId === null) {
                Http::validationFailed('Every stock line needs an item.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }
            $at = ['field' => 'lines', 'line_no' => $lineNo + 1];
            $rawQty = $line['ordered_qty'] ?? $line['quantity'] ?? 0;
            if (!is_numeric($rawQty) || (float) $rawQty <= 0 || (float) $rawQty > 1e11) {
                Http::validationFailed(sprintf('Line %d: the quantity must be a number above zero.', $lineNo + 1), $at + ['reason' => 'qty']);
            }
            $quantity = round((float) $rawQty, 4);

            // A negative rate, a discount over 100% or a negative discount amount produced orders
            // whose totals meant nothing and still went out to suppliers (F430, F266).
            $rawRate = $line['agreed_rate'] ?? $line['rate'] ?? 0;
            if (!is_numeric($rawRate) || (float) $rawRate < 0 || (float) $rawRate > 1e11) {
                Http::validationFailed(sprintf('Line %d: the rate must be a number, zero or more.', $lineNo + 1), $at + ['reason' => 'rate']);
            }
            $rate = round((float) $rawRate, 4);
            $rawDiscount = $line['discount_pc'] ?? 0;
            if (!is_numeric($rawDiscount) || (float) $rawDiscount < 0 || (float) $rawDiscount > 100) {
                Http::validationFailed(sprintf('Line %d: the discount is a percentage from 0 to 100.', $lineNo + 1), $at + ['reason' => 'discount_pc']);
            }
            $discountPc = round((float) $rawDiscount, 3);
            $gross = round($quantity * $rate, 4);
            if (isset($line['discount_amount']) && (!is_numeric($line['discount_amount']) || (float) $line['discount_amount'] < 0 || (float) $line['discount_amount'] > $gross + 0.00005)) {
                Http::validationFailed(sprintf('Line %d: the discount cannot be negative or more than the line.', $lineNo + 1), $at + ['reason' => 'discount_amount']);
            }
            $discountAmount = isset($line['discount_amount']) ? round((float) $line['discount_amount'], 4) : round($gross * $discountPc / 100, 4);
            $net = round($gross - $discountAmount, 4);
            $rawTax = $line['estimated_tax_pc'] ?? 0;
            if (!is_numeric($rawTax) || (float) $rawTax < 0 || (float) $rawTax > 100) {
                Http::validationFailed(sprintf('Line %d: the estimated tax is a percentage from 0 to 100.', $lineNo + 1), $at + ['reason' => 'estimated_tax_pc']);
            }
            $taxPc = round((float) $rawTax, 3);

            $lines[] = [
                'line_no'             => ++$lineNo,
                'requisition_line_id' => self::id($line['requisition_line_id'] ?? null),
                'quote_line_id'       => self::id($line['quote_line_id'] ?? null),
                'item_id'             => $itemId,
                'unit_id'             => self::id($line['unit_id'] ?? null),
                'warehouse_id'        => self::id($line['warehouse_id'] ?? null),
                'is_service'          => $isService,
                'description'         => self::text($line['description'] ?? null),
                'ordered_qty'         => $quantity,
                'agreed_rate'         => $rate,
                'discount_pc'         => $discountPc,
                'discount_amount'     => $discountAmount,
                'tax_cat_id'          => self::id($line['tax_cat_id'] ?? null),
                'estimated_tax_pc'    => $taxPc,
                'line_amount'         => $net,
                'estimated_tax'       => round($net * $taxPc / 100, 4),
                'promised_date'       => self::strictDate($line['promised_date'] ?? null, 'lines'),
                'hsn_sac'             => self::text($line['hsn_sac'] ?? null),
            ];
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(int $poId, array $lines): void
    {
        foreach ($lines as $line) {
            Db::insert('purchase_order_lines', [
                'po_id'               => $poId,
                'cmp_id'              => $this->ctx->cmpId,
                'line_no'             => $line['line_no'],
                'requisition_line_id' => $line['requisition_line_id'],
                'quote_line_id'       => $line['quote_line_id'],
                'item_id'             => $line['item_id'],
                'unit_id'             => $line['unit_id'],
                'warehouse_id'        => $line['warehouse_id'],
                'is_service'          => $line['is_service'],
                'description'         => $line['description'],
                'ordered_qty'         => $line['ordered_qty'],
                'agreed_rate'         => $line['agreed_rate'],
                'discount_pc'         => $line['discount_pc'],
                'discount_amount'     => $line['discount_amount'],
                'tax_cat_id'          => $line['tax_cat_id'],
                'estimated_tax_pc'    => $line['estimated_tax_pc'],
                'line_amount'         => $line['line_amount'],
                'promised_date'       => $line['promised_date'],
                'hsn_sac'             => $line['hsn_sac'],
            ], 'line_id');
        }
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $row = Db::first('SELECT * FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]);
        if ($row !== null) {
            return $row;
        }
        Db::insert('purchase_settings', ['cmp_id' => $this->ctx->cmpId], 'cmp_id');

        return Db::first('SELECT * FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]) ?? [];
    }

    /** @param list<array<string, mixed>> $lines */
    public static function totals(array $lines, float $freight = 0, float $other = 0): array
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) $line['ordered_qty'] * (float) $line['agreed_rate'];
            $discount += (float) $line['discount_amount'];
            $tax += (float) ($line['estimated_tax'] ?? 0);
        }

        return [
            'subtotal' => round($subtotal, 4),
            'discount' => round($discount, 4),
            'freight'  => round($freight, 4),
            'other'    => round($other, 4),
            'tax'      => round($tax, 4),
            'total'    => round($subtotal - $discount + $freight + $other + $tax, 4),
        ];
    }

    private static function money(float $value): string
    {
        return '₹' . number_format($value, 2);
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

    private static function date(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return trim($value);
        }

        return gmdate('Y-m-d');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
