<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Dashboards\BooksReader;
use Aicountly\Api\Dashboards\Decimal;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * The procurement dashboard.
 *
 * Composed at read time from two sources that stay apart: our own pipeline
 * counts, and what Books says is payable. There is no analytics table and no
 * nightly roll-up — a stored payable figure would be a second answer to a
 * question the accounts already answer.
 */
final class DashboardController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();

        $from = Http::param('from') ?? gmdate('Y-m-01');
        $to = Http::param('to') ?? gmdate('Y-m-d');

        [$scope, $params] = $ctx->scopeClause();
        $rangeParams = $params + ['from' => $from, 'to' => $to];

        $requisitions = Db::first(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'APPROVAL_PENDING') AS awaiting_approval,
                    COUNT(*) FILTER (WHERE status = 'APPROVED')         AS approved,
                    COALESCE(SUM(estimated_value), 0)                   AS estimated_value
             FROM purchase_requisitions
             WHERE {$scope} AND requisition_date BETWEEN :from AND :to",
            $rangeParams,
        ) ?? [];

        $rfqs = Db::first(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status IN ('ISSUED','RESPONSES_OPEN')) AS awaiting_response,
                    COUNT(*) FILTER (WHERE status = 'EVALUATING')                 AS evaluating
             FROM purchase_rfqs
             WHERE {$scope} AND rfq_date BETWEEN :from AND :to",
            $rangeParams,
        ) ?? [];

        $orders = Db::first(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'APPROVAL_PENDING')                AS awaiting_approval,
                    COUNT(*) FILTER (WHERE status = 'ISSUED')                          AS awaiting_acknowledgement,
                    COUNT(*) FILTER (WHERE status IN ('ISSUED','ACKNOWLEDGED','PARTIALLY_RECEIVED')) AS open_orders,
                    COUNT(*) FILTER (WHERE promised_date < CURRENT_DATE
                                     AND status IN ('ISSUED','ACKNOWLEDGED','PARTIALLY_RECEIVED')) AS overdue,
                    COALESCE(SUM(total_amount), 0)                                     AS ordered_value
             FROM purchase_orders
             WHERE {$scope} AND po_date BETWEEN :from AND :to",
            $rangeParams,
        ) ?? [];

        $pipeline = Db::first(
            "SELECT
                COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0) AS awaiting_receipt_value,
                COALESCE(SUM(GREATEST(l.received_qty - l.billed_qty, 0) * l.agreed_rate), 0)  AS awaiting_bill_value
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE p.cmp_id = :ctx_cmp_id AND p.fy_id = :ctx_fy_id
               AND p.status NOT IN ('DRAFT', 'CANCELLED', 'CLOSED')",
            ['ctx_cmp_id' => $ctx->cmpId, 'ctx_fy_id' => $ctx->fyId],
        ) ?? [];

        $matchExceptions = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_match_exceptions WHERE cmp_id = :cmp AND status = 'OPEN'",
            ['cmp' => $ctx->cmpId],
        );

        $pendingApprovals = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_approval_requests WHERE cmp_id = :cmp AND status = 'PENDING'",
            ['cmp' => $ctx->cmpId],
        );

        $stuckCommands = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_integration_commands WHERE cmp_id = :cmp AND status IN ('FAILED', 'BLOCKED')",
            ['cmp' => $ctx->cmpId],
        );

        $openClaims = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_claims WHERE cmp_id = :cmp AND status NOT IN ('SETTLED', 'CLOSED', 'REJECTED')",
            ['cmp' => $ctx->cmpId],
        );

        $topSuppliers = Db::all(
            "SELECT supplier_account_id, supplier_name_snapshot, COUNT(*) AS po_count, SUM(total_amount) AS ordered_value
             FROM purchase_orders
             WHERE {$scope} AND po_date BETWEEN :from AND :to AND status <> 'CANCELLED'
             GROUP BY supplier_account_id, supplier_name_snapshot
             ORDER BY ordered_value DESC
             LIMIT 5",
            $rangeParams,
        );

        // --- Books, live. Failure degrades these cards only. ---------------
        //
        // Read from Books' own purchase dashboard. It was previously read from
        // reports/bill-by-bill with no acc_id, which that endpoint refuses with
        // a 400 (ReportsController::billByBill requires a single account id) —
        // so this card could never have shown a figure against the real Books
        // API, only the "Books did not answer" state.
        $financial = ['available' => false, 'reason' => null];
        if (Permissions::allows($ctx, $auth, 'reports.view')) {
            $summary = (new BooksReader($ctx, $auth->sesKey()))->purchaseSummary(
                Period::forDates($from, $to),
            );

            if ($summary['ok']) {
                $kpis = $summary['kpis'];
                $ageing = $summary['ageing'];
                $financial['available'] = true;
                // Exact decimal strings, not floats: these are payables.
                $financial['payable_total'] = $kpis['payables'] ?? Decimal::ZERO;
                $financial['payable_overdue'] = $kpis['overdue_payables'] ?? Decimal::ZERO;
                $financial['net_purchases'] = $kpis['total_purchases'] ?? Decimal::ZERO;
                $financial['ageing'] = $ageing;
            } else {
                $financial['reason'] = (string) $summary['error'];
            }
        } else {
            $financial['reason'] = 'Payable figures need the reports.view permission.';
        }

        Http::data([
            'period' => ['from' => $from, 'to' => $to],
            'requisitions' => [
                'total'             => (int) ($requisitions['total'] ?? 0),
                'awaiting_approval' => (int) ($requisitions['awaiting_approval'] ?? 0),
                'approved'          => (int) ($requisitions['approved'] ?? 0),
                'estimated_value'   => (float) ($requisitions['estimated_value'] ?? 0),
            ],
            'rfqs' => [
                'total'             => (int) ($rfqs['total'] ?? 0),
                'awaiting_response' => (int) ($rfqs['awaiting_response'] ?? 0),
                'evaluating'        => (int) ($rfqs['evaluating'] ?? 0),
            ],
            'orders' => [
                'total'                    => (int) ($orders['total'] ?? 0),
                'awaiting_approval'        => (int) ($orders['awaiting_approval'] ?? 0),
                'awaiting_acknowledgement' => (int) ($orders['awaiting_acknowledgement'] ?? 0),
                'open'                     => (int) ($orders['open_orders'] ?? 0),
                'overdue'                  => (int) ($orders['overdue'] ?? 0),
                'ordered_value'            => (float) ($orders['ordered_value'] ?? 0),
            ],
            'pipeline' => [
                'awaiting_receipt_value' => (float) ($pipeline['awaiting_receipt_value'] ?? 0),
                'awaiting_bill_value'    => (float) ($pipeline['awaiting_bill_value'] ?? 0),
            ],
            'attention' => [
                'match_exceptions'  => $matchExceptions,
                'pending_approvals' => $pendingApprovals,
                'stuck_commands'    => $stuckCommands,
                'open_claims'       => $openClaims,
            ],
            'top_suppliers' => $topSuppliers,
            'financial'     => $financial,
        ]);
    }

    public static function approvals(): void
    {
        [$auth, $ctx] = self::enter();

        $status = Http::param('status') ?? 'PENDING';
        $params = Http::listParams(['created_at'], 'created_at');

        $rows = Db::all(
            'SELECT a.*,
                    r.requisition_no, r.estimated_value AS requisition_value,
                    p.po_no, p.supplier_name_snapshot, p.total_amount AS po_value
             FROM purchase_approval_requests a
             LEFT JOIN purchase_requisitions r ON a.entity_type = \'requisition\'     AND r.requisition_id = a.entity_id
             LEFT JOIN purchase_orders       p ON a.entity_type = \'purchase_order\'  AND p.po_id          = a.entity_id
             WHERE a.cmp_id = :cmp AND a.status = :status
             ORDER BY a.created_at DESC
             LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            ['cmp' => $ctx->cmpId, 'status' => $status],
        );

        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM purchase_approval_requests WHERE cmp_id = :cmp AND status = :status',
            ['cmp' => $ctx->cmpId, 'status' => $status],
        );

        Http::list($rows, $total, $params['limit'], $params['offset']);
    }

    /** Open three-way match exceptions across every bill — the payables inbox. */
    public static function exceptions(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'match.view');

        $params = Http::listParams(['created_at'], 'created_at');

        Http::list(
            Db::all(
                "SELECT e.*, m.verdict, m.bill_request_id, b.supplier_invoice_no, b.supplier_account_id, p.po_no
                 FROM purchase_match_exceptions e
                 JOIN purchase_match_results m ON m.match_id = e.match_id
                 LEFT JOIN purchase_bill_requests b ON b.request_id = m.bill_request_id
                 LEFT JOIN purchase_orders p ON p.po_id = m.po_id
                 WHERE e.cmp_id = :cmp AND e.status = 'OPEN'
                 ORDER BY e.created_at DESC
                 LIMIT " . $params['limit'] . ' OFFSET ' . $params['offset'],
                ['cmp' => $ctx->cmpId],
            ),
            (int) Db::scalar("SELECT COUNT(*) FROM purchase_match_exceptions WHERE cmp_id = :cmp AND status = 'OPEN'", ['cmp' => $ctx->cmpId]),
            $params['limit'],
            $params['offset'],
        );
    }

    /**
     * Cross-service work that has not finished.
     *
     * The screen that replaces a reconciliation job: everything stuck is here,
     * with its error and a Retry, rather than swept up by a cron nobody reads.
     */
    public static function commands(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data(IntegrationCommand::outstanding($ctx, Http::intParam('limit', 100) ?? 100));
    }
}
