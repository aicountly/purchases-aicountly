<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Supplier PROCUREMENT profiles and scorecards.
 *
 * The supplier's legal identity belongs to Contacts and their account ledger to
 * Books. What lives here is only what procurement decides about them: approved
 * or not, preferred or not, how they actually perform.
 */
final class SuppliersController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.view');

        Http::data(Db::all(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :cmp ORDER BY is_preferred DESC, supplier_account_id',
            ['cmp' => $ctx->cmpId],
        ));
    }

    public static function upsert(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.manage');

        $body = Http::body();
        $supplierId = (int) ($body['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier this profile is for.', ['field' => 'supplier_account_id']);
        }

        $existing = Db::first(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier',
            ['cmp' => $ctx->cmpId, 'supplier' => $supplierId],
        );

        $fields = [
            'contact_id'            => self::text($body['contact_id'] ?? null),
            'is_preferred'          => (bool) ($body['is_preferred'] ?? ($existing['is_preferred'] ?? false)),
            'approved_categories'   => is_array($body['approved_categories'] ?? null) ? $body['approved_categories'] : Db::jsonColumn($existing['approved_categories'] ?? null),
            'operational_lead_days' => isset($body['operational_lead_days']) ? (int) $body['operational_lead_days'] : ($existing['operational_lead_days'] ?? null),
            'payment_terms'         => self::text($body['payment_terms'] ?? null) ?? ($existing['payment_terms'] ?? null),
            'incoterm'              => self::text($body['incoterm'] ?? null) ?? ($existing['incoterm'] ?? null),
            'risk_flag'             => self::text($body['risk_flag'] ?? null) ?? ($existing['risk_flag'] ?? null),
            'notes'                 => self::text($body['notes'] ?? null) ?? ($existing['notes'] ?? null),
            'updated_at'            => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            Db::insert('purchase_supplier_profiles', $fields + [
                'cmp_id'              => $ctx->cmpId,
                'supplier_account_id' => $supplierId,
                'qualification_status' => 'draft',
            ], 'profile_id');
        } else {
            Db::update('purchase_supplier_profiles', $fields, ['profile_id' => (int) $existing['profile_id']]);
        }

        Audit::record($ctx, $auth, 'supplier.profile_saved', 'supplier', $supplierId, $existing, $fields);

        Http::data(Db::first(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier',
            ['cmp' => $ctx->cmpId, 'supplier' => $supplierId],
        ) ?? []);
    }

    /**
     * Approve, suspend or blacklist a supplier.
     *
     * A separate permission from editing the profile: deciding who this company
     * is allowed to buy from is not the same job as recording their lead time.
     */
    public static function setStatus(string $supplierId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.approve');

        $status = (string) (Http::param('qualification_status') ?? '');
        if (!in_array($status, ['draft', 'pending_approval', 'approved', 'suspended', 'blacklisted'], true)) {
            Http::validationFailed('Status must be draft, pending_approval, approved, suspended or blacklisted.', ['field' => 'qualification_status']);
        }

        $existing = Db::first(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier',
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId],
        );
        if ($existing === null) {
            Http::notFound('That supplier has no procurement profile yet.');
        }

        Db::update('purchase_supplier_profiles', [
            'qualification_status' => $status,
            'approved_by'          => $status === 'approved' ? $auth->uuid : null,
            'approved_at'          => $status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ], ['profile_id' => (int) $existing['profile_id']]);

        Audit::record($ctx, $auth, 'supplier.status_changed', 'supplier', (int) $supplierId, [
            'qualification_status' => $existing['qualification_status'],
        ], ['qualification_status' => $status], (string) (Http::param('note') ?? ''));

        Http::data(Db::first(
            'SELECT * FROM purchase_supplier_profiles WHERE profile_id = :id',
            ['id' => (int) $existing['profile_id']],
        ) ?? []);
    }

    /**
     * Compute and store a supplier scorecard for a period.
     *
     * The SCORE is ours — it is a procurement judgement. The facts behind it are
     * read live from the products that own them (delivery dates from Inventory's
     * receipts, orders from ours) and are NOT stored; only the resulting metrics
     * and a summary of what went into them are.
     */
    public static function scorecard(string $supplierId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.view');

        $from = (string) (Http::param('from') ?? gmdate('Y-m-d', strtotime('-90 days')));
        $to = (string) (Http::param('to') ?? gmdate('Y-m-d'));

        // Our own facts: what we ordered and what we committed to.
        $orders = Db::first(
            "SELECT
                COUNT(*)                                                    AS po_count,
                COUNT(*) FILTER (WHERE acknowledged_at IS NOT NULL)          AS acknowledged_count,
                COALESCE(SUM(total_amount), 0)                               AS ordered_value,
                AVG(EXTRACT(EPOCH FROM (acknowledged_at - issued_at)) / 3600) AS ack_hours
             FROM purchase_orders
             WHERE cmp_id = :cmp AND supplier_account_id = :supplier
               AND po_date BETWEEN :from AND :to AND status <> 'CANCELLED'",
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId, 'from' => $from, 'to' => $to],
        ) ?? [];

        $lines = Db::first(
            "SELECT
                COALESCE(SUM(l.ordered_qty), 0)  AS ordered_qty,
                COALESCE(SUM(l.received_qty), 0) AS received_qty,
                COALESCE(SUM(l.rejected_qty), 0) AS rejected_qty
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE p.cmp_id = :cmp AND p.supplier_account_id = :supplier
               AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'",
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId, 'from' => $from, 'to' => $to],
        ) ?? [];

        // On-time is judged by comparing what we promised against when Inventory
        // recorded the receipt — the receipt date is Inventory's fact, read from
        // our own request rows which carry it as the date we asked on.
        $delivery = Db::first(
            "SELECT
                COUNT(*)                                                                  AS receipt_count,
                COUNT(*) FILTER (WHERE r.received_at <= p.promised_date)                   AS on_time_count
             FROM purchase_receipt_requests r
             JOIN purchase_orders p ON p.po_id = r.po_id
             WHERE r.cmp_id = :cmp AND p.supplier_account_id = :supplier
               AND r.status = 'ACCEPTED' AND p.promised_date IS NOT NULL
               AND r.received_at BETWEEN :from AND :to",
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId, 'from' => $from, 'to' => $to],
        ) ?? [];

        $claims = (int) Db::scalar(
            'SELECT COUNT(*) FROM purchase_claims
             WHERE cmp_id = :cmp AND supplier_account_id = :supplier AND claim_date BETWEEN :from AND :to',
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId, 'from' => $from, 'to' => $to],
        );

        $orderedQty = (float) ($lines['ordered_qty'] ?? 0);
        $receivedQty = (float) ($lines['received_qty'] ?? 0);
        $rejectedQty = (float) ($lines['rejected_qty'] ?? 0);
        $receiptCount = (int) ($delivery['receipt_count'] ?? 0);

        $onTimePc = $receiptCount > 0 ? round(((int) ($delivery['on_time_count'] ?? 0) / $receiptCount) * 100, 3) : null;
        $rejectionPc = $receivedQty > 0 ? round(($rejectedQty / $receivedQty) * 100, 3) : null;
        $fulfilmentPc = $orderedQty > 0 ? round(($receivedQty / $orderedQty) * 100, 3) : null;

        // An overall score needs at least one signal; averaging nothing gives a
        // confident-looking zero for a supplier nobody has bought from.
        $signals = array_values(array_filter([$onTimePc, $rejectionPc === null ? null : 100 - $rejectionPc, $fulfilmentPc], static fn ($v) => $v !== null));
        $overall = $signals === [] ? null : round(array_sum($signals) / count($signals), 3);

        $scorecard = [
            'cmp_id'                   => $ctx->cmpId,
            'fy_id'                    => $ctx->fyId,
            'supplier_account_id'      => (int) $supplierId,
            'period_start'             => $from,
            'period_end'               => $to,
            'on_time_delivery_pc'      => $onTimePc,
            'quality_rejection_pc'     => $rejectionPc,
            'po_acknowledgement_hours' => isset($orders['ack_hours']) ? round((float) $orders['ack_hours'], 2) : null,
            'fulfilment_pc'            => $fulfilmentPc,
            'claim_count'              => $claims,
            'overall_score'            => $overall,
            'basis_summary'            => [
                'po_count'      => (int) ($orders['po_count'] ?? 0),
                'ordered_value' => (float) ($orders['ordered_value'] ?? 0),
                'receipt_count' => $receiptCount,
                'note'          => 'Computed from Purchases documents and the receipts Inventory recorded. No stock or ledger data is stored here.',
            ],
            'computed_by'              => $auth->uuid,
        ];

        if (Http::method() === 'POST') {
            Db::run(
                'INSERT INTO purchase_supplier_scorecards
                    (cmp_id, fy_id, supplier_account_id, period_start, period_end, on_time_delivery_pc,
                     quality_rejection_pc, po_acknowledgement_hours, fulfilment_pc, claim_count,
                     overall_score, basis_summary, computed_by)
                 VALUES (:cmp, :fy, :supplier, :from, :to, :ontime, :rejection, :ack, :fulfil, :claims, :overall, :basis, :by)
                 ON CONFLICT (cmp_id, supplier_account_id, period_start, period_end)
                 DO UPDATE SET on_time_delivery_pc = EXCLUDED.on_time_delivery_pc,
                               quality_rejection_pc = EXCLUDED.quality_rejection_pc,
                               po_acknowledgement_hours = EXCLUDED.po_acknowledgement_hours,
                               fulfilment_pc = EXCLUDED.fulfilment_pc,
                               claim_count = EXCLUDED.claim_count,
                               overall_score = EXCLUDED.overall_score,
                               basis_summary = EXCLUDED.basis_summary,
                               computed_at = NOW(),
                               computed_by = EXCLUDED.computed_by',
                [
                    'cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'supplier' => (int) $supplierId,
                    'from' => $from, 'to' => $to,
                    'ontime' => $onTimePc, 'rejection' => $rejectionPc,
                    'ack' => $scorecard['po_acknowledgement_hours'], 'fulfil' => $fulfilmentPc,
                    'claims' => $claims, 'overall' => $overall,
                    'basis' => json_encode($scorecard['basis_summary']), 'by' => $auth->uuid,
                ],
            );
        }

        Http::data($scorecard);
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
