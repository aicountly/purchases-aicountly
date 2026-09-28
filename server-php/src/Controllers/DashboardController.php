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
    /**
     * The first dashboard, retired.
     *
     * It checked no permission at all: anybody with access to the company saw ordered value,
     * the pipeline in rupees and the top suppliers by spend. Its successors under
     * v1/dashboards/{view} (overview, procurement, suppliers, bills, insights) hold each figure
     * back from a caller without the permission for it, and they are what this app and
     * Aicountly Insights read. Nothing calls this one; it answers 410 with where to go.
     */
    public static function index(): void
    {
        self::enter();
        Http::error(410, 'retired', 'This dashboard has been replaced by v1/dashboards/overview, which shows each figure only to those allowed to see it.', ['use' => 'v1/dashboards/overview']);
    }

    /**
     * The approvals inbox: what the caller may approve, and only that.
     *
     * It used to list every pending approval in the company, with the requisition's and the
     * order's value, to anybody who could open the company. Now a row is listed only when the
     * caller may approve its kind of document (requisition.approve, po.approve) and the stage's
     * own required permission, in this financial year; its value only to a caller who may view
     * that document.
     */
    public static function approvals(): void
    {
        [$auth, $ctx] = self::enter();

        $types = [];
        if (Permissions::allows($ctx, $auth, 'requisition.approve')) {
            $types[] = 'requisition';
        }
        if (Permissions::allows($ctx, $auth, 'po.approve')) {
            $types[] = 'purchase_order';
        }
        if ($types === []) {
            Http::forbidden('You do not approve requisitions or purchase orders in this company.');
        }

        $status = Http::param('status') ?? 'PENDING';
        $params = Http::listParams(['created_at'], 'created_at');
        // Approvals carry no branch: company and year. A stage that names the permission it
        // needs is listed only to those who hold it.
        $typeList = "'" . implode("', '", $types) . "'";
        $granted = Permissions::granted($ctx, $auth);
        $bind = ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'status' => $status];
        $grantedSql = [];
        foreach (array_values($granted) as $i => $permission) {
            $grantedSql[] = ':perm' . $i;
            $bind['perm' . $i] = $permission;
        }
        $where = "a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = :status AND a.entity_type IN ({$typeList})
                  AND (a.required_permission IS NULL" . ($grantedSql === [] ? '' : ' OR a.required_permission IN (' . implode(', ', $grantedSql) . ')') . ')';

        $rows = Db::all(
            "SELECT a.*,
                    r.requisition_no, r.estimated_value AS requisition_value,
                    p.po_no, p.supplier_name_snapshot, p.total_amount AS po_value
             FROM purchase_approval_requests a
             LEFT JOIN purchase_requisitions r ON a.entity_type = 'requisition'     AND r.requisition_id = a.entity_id AND r.cmp_id = a.cmp_id
             LEFT JOIN purchase_orders       p ON a.entity_type = 'purchase_order'  AND p.po_id          = a.entity_id AND p.cmp_id = a.cmp_id
             WHERE {$where}
             ORDER BY a.created_at DESC
             LIMIT " . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bind,
        );

        // A document's own amounts go with permission to view that document — the same rule
        // as its own screen. Approving a requisition without seeing it is not a thing.
        $seesRequisitions = Permissions::allows($ctx, $auth, 'requisition.view');
        $seesOrders = Permissions::allows($ctx, $auth, 'po.view');
        foreach ($rows as $i => $row) {
            $sees = $row['entity_type'] === 'requisition' ? $seesRequisitions : $seesOrders;
            if (!$sees) {
                $rows[$i]['requisition_value'] = null;
                $rows[$i]['po_value'] = null;
                $rows[$i]['threshold_value'] = null;
                $rows[$i]['actual_value'] = null;
                $rows[$i]['values_withheld'] = $row['entity_type'] === 'requisition' ? 'requisition.view' : 'po.view';
            }
        }

        $total = (int) Db::scalar("SELECT COUNT(*) FROM purchase_approval_requests a WHERE {$where}", $bind);

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
