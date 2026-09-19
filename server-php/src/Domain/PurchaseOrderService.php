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

        $settings = $this->settings();
        $this->guardApprovedVendor($supplierId, $settings);

        return Db::transaction(function () use ($input, $supplierId, $lines, $settings) {
            $no = NumberSeries::next($this->ctx, 'po');
            $totals = self::totals($lines, (float) ($input['freight_amount'] ?? 0), (float) ($input['other_charges'] ?? 0));

            $poId = (int) Db::insert('purchase_orders', [
                'cmp_id'                 => $this->ctx->cmpId,
                'fy_id'                  => $this->ctx->fyId,
                'bo_id'                  => $this->ctx->boId,
                'po_no'                  => $no,
                'po_date'                => self::date($input['po_date'] ?? null),
                'requisition_id'         => self::id($input['requisition_id'] ?? null),
                'rfq_id'                 => self::id($input['rfq_id'] ?? null),
                'quote_id'               => self::id($input['quote_id'] ?? null),
                'agreement_id'           => self::id($input['agreement_id'] ?? null),
                'supplier_account_id'    => $supplierId,
                'contact_id'             => self::text($input['contact_id'] ?? null),
                'supplier_name_snapshot' => self::text($input['supplier_name'] ?? null),
                'status'                 => 'DRAFT',
                'delivery_warehouse_id'  => self::id($input['delivery_warehouse_id'] ?? null) ?? self::id($settings['default_warehouse_id'] ?? null),
                'delivery_address'       => is_array($input['delivery_address'] ?? null) ? $input['delivery_address'] : null,
                'promised_date'          => self::text($input['promised_date'] ?? null),
                'payment_terms'          => self::text($input['payment_terms'] ?? null),
                'incoterm'               => self::text($input['incoterm'] ?? null),
                'currency_code'          => self::text($input['currency_code'] ?? null) ?? 'INR',
                'exchange_rate'          => (float) ($input['exchange_rate'] ?? 1),
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
        if ($po['status'] !== 'APPROVAL_PENDING') {
            Http::conflict('This purchase order is not waiting for approval.');
        }
        // Segregation of duties: the buyer who raised it may not approve it.
        if ((string) $po['created_by'] === $this->auth->uuid && !$this->auth->ownsCompany($this->ctx->cmpId)) {
            Http::forbidden('You raised this purchase order, so somebody else has to approve it.');
        }

        $approved = $action === 'approve';
        $note = self::text($input['note'] ?? null);
        if (!$approved && $note === null) {
            Http::validationFailed('Say why this purchase order is being rejected.', ['field' => 'note']);
        }

        Db::transaction(function () use ($poId, $approved, $note) {
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
        });

        Audit::record($this->ctx, $this->auth, 'po.' . $action, 'purchase_order', $poId, ['status' => 'APPROVAL_PENDING'], [
            'status' => $approved ? 'APPROVED' : 'DRAFT',
        ], $note ?? '');

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

        Db::update('purchase_orders', ['status' => 'ISSUED', 'issued_at' => self::now(), 'updated_at' => self::now()], [
            'po_id' => $poId, 'cmp_id' => $this->ctx->cmpId,
        ]);
        Audit::record($this->ctx, $this->auth, 'po.issued', 'purchase_order', $poId, ['status' => 'APPROVED'], ['status' => 'ISSUED']);

        return $this->find($poId);
    }

    public function acknowledge(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.create');

        $po = $this->find($poId);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }
        if (!in_array($po['status'], ['ISSUED', 'ACKNOWLEDGED'], true)) {
            Http::conflict('Issue the purchase order before recording the supplier acknowledgement.');
        }

        Db::update('purchase_orders', [
            'status'          => 'ACKNOWLEDGED',
            'acknowledged_at' => self::now(),
            'promised_date'   => self::text($input['promised_date'] ?? null) ?? $po['promised_date'],
            'updated_at'      => self::now(),
        ], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'po.acknowledged', 'purchase_order', $poId, null, [
            'promised_date' => $input['promised_date'] ?? null,
        ]);

        return $this->find($poId);
    }

    /** @param array<string, mixed> $input */
    public function cancel(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.cancel');

        $po = $this->find($poId);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }
        if (in_array($po['status'], ['CANCELLED', 'CLOSED'], true)) {
            Http::conflict('This purchase order is already ' . strtolower((string) $po['status']) . '.');
        }
        // Received or billed first, because those are the reasons that tell the
        // user what to do instead.
        if ((float) array_sum(array_column($po['lines'], 'received_qty')) > 0) {
            Http::conflict('Goods have been received against this order. Raise a purchase return instead of cancelling it.');
        }
        if ((float) array_sum(array_column($po['lines'], 'billed_qty')) > 0) {
            Http::conflict('This order has been billed. Raise a debit note in Smart Books instead of cancelling it.');
        }

        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why this purchase order is being cancelled.', ['field' => 'reason']);
        }

        Db::update('purchase_orders', [
            'status' => 'CANCELLED', 'cancelled_at' => self::now(), 'cancel_reason' => $reason, 'updated_at' => self::now(),
        ], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'po.cancelled', 'purchase_order', $poId, ['status' => $po['status']], ['status' => 'CANCELLED'], $reason);

        return $this->find($poId);
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
        $row['commands'] = IntegrationCommand::forEntity($this->ctx, 'purchase_order', $poId);
        $row['approvals'] = Db::all(
            "SELECT * FROM purchase_approval_requests
             WHERE cmp_id = :cmp AND entity_type = 'purchase_order' AND entity_id = :id ORDER BY approval_id",
            ['cmp' => $this->ctx->cmpId, 'id' => $poId],
        );

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

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['po_date', 'po_no', 'total_amount', 'status', 'promised_date', 'created_at'], true) ? $sort : 'po_date';

        return [
            'rows'  => Db::all("SELECT p.* FROM purchase_orders p WHERE {$clause} ORDER BY p.{$sortColumn} {$order}, p.po_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM purchase_orders p WHERE {$clause}", $params),
        ];
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $settings */
    private function guardApprovedVendor(int $supplierId, array $settings): void
    {
        if (empty($settings['enforce_approved_vendors'])) {
            return;
        }

        $status = Db::scalar(
            'SELECT qualification_status FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier',
            ['cmp' => $this->ctx->cmpId, 'supplier' => $supplierId],
        );

        if ($status !== 'approved') {
            Http::error(
                409,
                'vendor_not_approved',
                'This company only orders from approved suppliers, and this one is '
                . ($status === null ? 'not set up in Purchases yet' : strtolower(str_replace('_', ' ', (string) $status))) . '.',
                ['supplier_account_id' => $supplierId, 'qualification_status' => $status],
            );
        }
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
            $quantity = round((float) ($line['ordered_qty'] ?? $line['quantity'] ?? 0), 4);
            if ($quantity <= 0) {
                Http::validationFailed('Quantity must be more than zero.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }

            $rate = round((float) ($line['agreed_rate'] ?? $line['rate'] ?? 0), 4);
            $discountPc = round((float) ($line['discount_pc'] ?? 0), 3);
            $gross = round($quantity * $rate, 4);
            $discountAmount = isset($line['discount_amount']) ? round((float) $line['discount_amount'], 4) : round($gross * $discountPc / 100, 4);
            $net = round($gross - $discountAmount, 4);
            $taxPc = round((float) ($line['estimated_tax_pc'] ?? 0), 3);

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
                'promised_date'       => self::text($line['promised_date'] ?? null),
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
