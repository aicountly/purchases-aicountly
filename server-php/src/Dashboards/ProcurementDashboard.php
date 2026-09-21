<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Dashboard 2 — Procurement.
 *
 * What must be ordered, and what is expected to arrive. This is the buyer's
 * screen rather than the owner's, so it is denser and every row ends in an
 * action.
 *
 * QUANTITIES STAY AT LINE LEVEL. Pieces, kilograms and litres are never added
 * together into one "quantity": a workbench column that says 412 across three
 * units is a number that cannot be checked against anything.
 */
final class ProcurementDashboard extends Dashboard
{
    public function view(): string
    {
        return 'procurement';
    }

    /** Views the workbench offers, resolved server-side so the SQL cannot drift from the label. */
    public const WORKBENCH_VIEWS = ['needs_action', 'awaiting_approval', 'open_orders', 'expected', 'delayed', 'partially_received', 'receipt_pending'];

    /**
     * Orders with a goods receipt this app has sent to Inventory and Inventory
     * has not confirmed.
     *
     * Written once and used twice — by the "GRN pending" card and by the
     * workbench view it opens — so the card and the rows behind it cannot
     * disagree about what "pending" means.
     */
    private const RECEIPT_PENDING = "EXISTS (SELECT 1 FROM purchase_receipt_requests rr
                                              WHERE rr.po_id = p.po_id AND rr.status IN ('REQUESTED', 'FAILED'))";

    /** @return array<string, mixed> */
    public function build(): array
    {
        if (!$this->can('po.view') && !$this->can('requisition.view')) {
            Http::forbidden('You do not have permission to view procurement.');
        }

        $horizonDays = max(1, min(90, Http::intParam('horizon', 7) ?? 7));

        // null means the orders in scope are in more than one currency. Every
        // panel below that would SUM money refuses in that case rather than
        // adding rupees to dollars behind a rupee sign; the ones that show a
        // figure per document format each one in its own currency instead.
        $reportingCurrency = $this->documentCurrency();
        $currency = $reportingCurrency ?? 'INR';
        $mixed = $reportingCurrency === null;

        $ai = AiClient::status();
        if (!$this->can('settings.manage')) {
            $ai['admin_hint'] = null;
        }

        // The centre breakdown is what reads warehouse names from Inventory. It
        // is built before the envelope so the material-centre filter list can
        // be labelled from that same answer rather than asking a second time.
        $centreSpend = $this->materialCentreSpend($currency, $mixed);

        return $this->envelope(
            $this->metrics($currency, $mixed),
            [
                'flow'                  => $this->flow($currency, $mixed),
                'spend_trend'           => $this->spendTrend($currency, $mixed),
                'supplier_performance'  => $this->supplierPerformance(),
                'material_centre_spend' => $centreSpend,
                'insights'              => $this->insights($currency, $mixed, $ai),
                'activity'              => $this->activity($currency, $mixed),
                'workbench'             => $this->workbench(),
                'delivery_timeline'     => $this->deliveryTimeline($horizonDays),
                'reorder'               => $this->reorder(),
                'quote_comparison'      => $this->quoteComparison(),
                'approval_inbox'        => $this->approvalInbox(),
            ],
            ['horizon_days' => $horizonDays, 'ai' => $ai],
        );
    }

    // -----------------------------------------------------------------------

    /**
     * The six cards along the top.
     *
     * Five of them are POSITIONS — what is open as at now — and one is a period
     * total. Only the period total carries a percentage against the previous
     * period, because only the period total has one: nothing in this schema
     * records how long a queue was a month ago, and a queue measured over an
     * older window always looks smaller simply because it has had longer to
     * clear. Each position card carries an ageing footnote instead, which is
     * the thing a buyer would actually act on.
     *
     * @return list<array<string, mixed>>
     */
    private function metrics(string $currency, bool $mixedCurrency): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');
        [$reqSql, $reqParams] = $this->filters->requisitionClause('r');
        [$rfqSql, $rfqParams] = $this->filters->rfqClause('r');

        $requisitions = $this->row(
            "SELECT COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE r.requisition_date < CURRENT_DATE - 3) AS ageing,
                    COALESCE(SUM(r.estimated_value), 0)::text AS value
             FROM purchase_requisitions r
             WHERE {scope} AND r.status IN ('SUBMITTED', 'APPROVAL_PENDING')" . $reqSql,
            $reqParams,
            'r',
        ) ?? [];

        // An RFQ is "awaiting quotes" while fewer suppliers have answered than
        // were invited. An RFQ nobody was invited to is not waiting on anyone.
        $rfqs = $this->row(
            "SELECT COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE r.response_deadline IS NOT NULL AND r.response_deadline < NOW()) AS past_deadline
             FROM purchase_rfqs r
             WHERE {scope} AND r.status IN ('ISSUED', 'RESPONSES_OPEN')
               AND (SELECT COUNT(*) FROM purchase_rfq_invitations i WHERE i.rfq_id = r.rfq_id) >
                   (SELECT COUNT(*) FROM purchase_quotes q
                     WHERE q.rfq_id = r.rfq_id AND q.status NOT IN ('WITHDRAWN', 'REJECTED'))" . $rfqSql,
            $rfqParams,
            'r',
        ) ?? [];

        $releasedSql = "SELECT COUNT(*) AS n, COALESCE(SUM(p.total_amount), 0)::text AS value
                        FROM purchase_orders p
                        WHERE {scope} AND p.status NOT IN ('DRAFT', 'APPROVAL_PENDING', 'CANCELLED')
                          AND COALESCE(p.issued_at::date, p.po_date) BETWEEN :from AND :to" . $filterSql;

        $released = $this->row($releasedSql, $this->period->params() + $filterParams, 'p') ?? [];
        $comparePeriod = $this->period->compareParams();
        $releasedBefore = $comparePeriod === null
            ? null
            : $this->row($releasedSql, $comparePeriod + $filterParams, 'p');

        // Exactly the workbench's "open orders" view, so the card and the rows
        // it opens are the same set by construction.
        $inTransit = $this->row(
            "SELECT COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE p.promised_date < CURRENT_DATE) AS overdue,
                    COUNT(*) FILTER (WHERE p.promised_date BETWEEN CURRENT_DATE AND CURRENT_DATE + 2) AS due_soon
             FROM purchase_orders p
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')" . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        $grn = $this->row(
            "SELECT COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM purchase_receipt_requests rr
                                                    WHERE rr.po_id = p.po_id AND rr.status = 'FAILED')) AS failed
             FROM purchase_orders p
             WHERE {scope} AND " . self::RECEIPT_PENDING . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        $pending = $this->row(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(p.total_amount), 0)::text AS value,
                    COUNT(*) FILTER (WHERE p.updated_at < NOW() - INTERVAL '3 days') AS ageing
             FROM purchase_orders p
             WHERE {scope} AND p.status = 'APPROVAL_PENDING'" . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        $requisitionsPending = $this->row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(r.estimated_value), 0)::text AS value
             FROM purchase_requisitions r
             WHERE {scope} AND r.status = 'APPROVAL_PENDING'" . $reqSql,
            $reqParams,
            'r',
        ) ?? [];

        $openRequisitions = (int) ($requisitions['n'] ?? 0);
        $requisitionAgeing = (int) ($requisitions['ageing'] ?? 0);
        $rfqCount = (int) ($rfqs['n'] ?? 0);
        $pastDeadline = (int) ($rfqs['past_deadline'] ?? 0);
        $openOrders = (int) ($inTransit['n'] ?? 0);
        $overdueOrders = (int) ($inTransit['overdue'] ?? 0);
        $dueSoon = (int) ($inTransit['due_soon'] ?? 0);
        $grnPending = (int) ($grn['n'] ?? 0);
        $grnFailed = (int) ($grn['failed'] ?? 0);
        $approvalCount = (int) ($pending['n'] ?? 0);
        $approvalAgeing = (int) ($pending['ageing'] ?? 0);

        $queueReason = 'A queue is a position as at now. How long the same queue was a period ago is not recorded, and measuring it over an older window would flatter it — those documents have had longer to clear.';

        return [
            Metric::ready(
                'open_requisitions',
                'Open requisitions',
                (string) $openRequisitions,
                'Requisitions submitted or waiting for an approver, as at now. A requisition names a need rather than a source, so the supplier filter cannot narrow it.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'comparison_unavailable_reason' => $queueReason,
                    'drilldown' => $this->drilldown('/requisitions', ['status' => 'APPROVAL_PENDING']),
                    'footnote'  => match (true) {
                        $openRequisitions === 0 => null,
                        $requisitionAgeing > 0  => $requisitionAgeing . ' waiting more than 3 days.',
                        default                 => 'All raised within the last 3 days.',
                    },
                ],
            ),
            Metric::ready(
                'rfqs_awaiting_quotes',
                'RFQs awaiting quotes',
                (string) $rfqCount,
                'Requests for quotation that are issued or open for responses and have had fewer replies than the number of suppliers invited.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'explanation' => 'Withdrawn and rejected quotes do not count as a reply, so an RFQ whose only response was withdrawn is still waiting.',
                    'comparison_unavailable_reason' => $queueReason,
                    'drilldown' => $this->drilldown('/rfqs'),
                    'footnote'  => $pastDeadline > 0
                        ? $pastDeadline . ' past the response deadline.'
                        : ($rfqCount > 0 ? 'All within their response deadline.' : null),
                ],
            ),
            Metric::ready(
                'purchase_orders_released',
                'Purchase orders released',
                (string) (int) ($released['n'] ?? 0),
                'Purchase orders issued to a supplier in ' . $this->period->label() . ', counted on the date they were issued. Orders still in draft or awaiting approval are not released.',
                [
                    // Releasing more orders is neither good nor bad on its own —
                    // it is throughput, and the colour would be an opinion.
                    'direction' => Metric::NEUTRAL,
                    'previous'  => $releasedBefore === null ? null : (string) (int) ($releasedBefore['n'] ?? 0),
                    'comparison_unavailable_reason' => 'Comparison is switched off for this view.',
                    'drilldown' => $this->drilldown('/purchase-orders', ['status' => 'ISSUED']),
                    'footnote'  => ($this->canSeeValues() && !$mixedCurrency)
                        ? Format::money(Decimal::of($released['value'] ?? '0'), $currency) . ' ordered.'
                        : null,
                ],
            ),
            Metric::ready(
                'in_transit_deliveries',
                'In-transit deliveries',
                (string) $openOrders,
                'Purchase orders issued, acknowledged or partly received: committed to a supplier and not yet fully in.',
                [
                    'direction' => Metric::NEUTRAL,
                    'comparison_unavailable_reason' => $queueReason,
                    'drilldown' => $this->drilldown('/dashboard/procurement', ['view' => 'open_orders']),
                    'footnote'  => match (true) {
                        $overdueOrders > 0 => $overdueOrders . ' past the promised date.',
                        $dueSoon > 0       => $dueSoon . ' due within 2 days.',
                        $openOrders > 0    => 'None past the promised date.',
                        default            => null,
                    },
                ],
            ),
            Metric::ready(
                'grn_pending',
                'GRN pending',
                (string) $grnPending,
                'Orders carrying a goods receipt this app has sent to Inventory that Inventory has not confirmed. The GRN itself is Inventory\'s document; this counts the ones still in flight.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'comparison_unavailable_reason' => $queueReason,
                    'drilldown' => $this->drilldown('/dashboard/procurement', ['view' => 'receipt_pending']),
                    'footnote'  => $grnFailed > 0
                        ? $grnFailed . ' were refused and need re-sending.'
                        : ($grnPending > 0 ? 'Awaiting Inventory.' : null),
                ],
            ),
            ($this->canSeeValues() && !$mixedCurrency)
                ? Metric::ready(
                    'spend_under_approval',
                    'Spend under approval',
                    Decimal::of($pending['value'] ?? '0'),
                    'The committed value of purchase orders waiting for an approver, as at now. Tax excluded — what is actually charged is settled when the bill arrives.',
                    [
                        'format'    => 'currency',
                        'currency'  => $currency,
                        'direction' => Metric::LOWER_IS_BETTER,
                        'comparison_unavailable_reason' => $queueReason,
                        'drilldown' => $this->drilldown('/dashboard/procurement', ['view' => 'awaiting_approval']),
                        'footnote'  => 'Across ' . $approvalCount . ' order' . ($approvalCount === 1 ? '' : 's')
                            . ($approvalAgeing > 0 ? ', ' . $approvalAgeing . ' over 3 days' : '')
                            . ((int) ($requisitionsPending['n'] ?? 0) > 0
                                ? '. Plus ' . Format::compactMoney(Decimal::of($requisitionsPending['value'] ?? '0'), $currency)
                                    . ' estimated on requisitions.'
                                : '.'),
                    ],
                )
                : Metric::unavailable(
                    'spend_under_approval',
                    'Spend under approval',
                    $mixedCurrency
                        ? 'Orders awaiting approval are in more than one currency, so there is no one total to show.'
                        : 'Purchase values are not visible to you (cost.view).',
                    'The committed value of purchase orders waiting for an approver.',
                    ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER],
                ),
        ];
    }

    /**
     * Panel A — the workbench.
     *
     * One table, six saved views, server-side paging. Every row carries the next
     * action, because a list that tells you what is wrong and not what to do
     * about it just moves the thinking somewhere else.
     *
     * @return array<string, mixed>
     */
    private function workbench(): array
    {
        if (!$this->can('po.view')) {
            return $this->withheld('po.view');
        }

        $view = (string) (Http::param('view') ?? 'needs_action');
        if (!in_array($view, self::WORKBENCH_VIEWS, true)) {
            $view = 'needs_action';
        }

        $params = Http::listParams(['po_date', 'promised_date', 'total_amount'], 'promised_date', 'asc');
        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $where = match ($view) {
            'awaiting_approval'  => "p.status = 'APPROVAL_PENDING'",
            'open_orders'        => "p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')",
            'expected'           => "p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED') AND p.promised_date >= CURRENT_DATE",
            'delayed'            => "p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED') AND p.promised_date < CURRENT_DATE",
            'partially_received' => "p.status = 'PARTIALLY_RECEIVED'",
            'receipt_pending'    => self::RECEIPT_PENDING,
            // "Needs action" is the union of everything a buyer can move today.
            default              => "(p.status IN ('DRAFT', 'APPROVAL_PENDING', 'APPROVED')
                                      OR (p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED') AND p.promised_date < CURRENT_DATE))",
        };

        $sql = "FROM purchase_orders p WHERE {scope} AND " . $where . $filterSql;

        $total = $this->count('SELECT COUNT(*) ' . $sql, $filterParams, 'p');

        $rows = $this->rows(
            "SELECT p.po_id, p.po_no, p.po_date, p.status, p.promised_date, p.currency_code,
                    p.supplier_account_id, p.supplier_name_snapshot, p.created_by,
                    p.total_amount::text AS total_amount,
                    p.delivery_warehouse_id,
                    (SELECT COUNT(*) FROM purchase_order_lines l WHERE l.po_id = p.po_id) AS line_count,
                    (SELECT COUNT(*) FROM purchase_order_lines l WHERE l.po_id = p.po_id AND l.ordered_qty > l.received_qty) AS open_lines,
                    (SELECT COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text
                       FROM purchase_order_lines l WHERE l.po_id = p.po_id) AS remaining_value
             " . $sql . '
             ORDER BY p.' . $params['sort'] . ' ' . $params['order'] . ' NULLS LAST, p.po_id DESC
             LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $filterParams,
            'p',
        );

        $today = gmdate('Y-m-d');
        $out = [];
        foreach ($rows as $row) {
            $promised = $row['promised_date'] ?? null;
            $daysLate = $promised === null ? null : BooksReader::daysBetween((string) $promised, $today);
            $currency = (string) ($row['currency_code'] ?? 'INR');

            $out[] = [
                'po_id'          => (int) $row['po_id'],
                'po_no'          => $row['po_no'],
                'po_date'        => $row['po_date'],
                'po_date_label'  => Format::date((string) $row['po_date']),
                'status'         => $row['status'],
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name'  => $row['supplier_name_snapshot'],
                'buyer_uuid'     => $row['created_by'],
                'promised_date'  => $promised,
                'promised_label' => Format::date($promised),
                'days_late'      => ($daysLate !== null && $daysLate > 0) ? $daysLate : null,
                'line_count'     => (int) $row['line_count'],
                'open_lines'     => (int) $row['open_lines'],
                'currency'       => $currency,
                'value'          => $this->canSeeValues() ? Decimal::of($row['total_amount']) : null,
                'value_formatted' => $this->canSeeValues() ? Format::money(Decimal::of($row['total_amount']), $currency) : null,
                'remaining_value' => $this->canSeeValues() ? Decimal::of($row['remaining_value']) : null,
                'remaining_formatted' => $this->canSeeValues() ? Format::money(Decimal::of($row['remaining_value']), $currency) : null,
                'next_action'    => $this->nextAction((string) $row['status'], (int) $row['open_lines'], $daysLate),
                'route'          => '/purchase-orders/' . $row['po_id'],
            ];
        }

        return $this->panel([
            'view'  => $view,
            'views' => [
                ['id' => 'needs_action', 'label' => 'Needs action'],
                ['id' => 'awaiting_approval', 'label' => 'Awaiting approval'],
                ['id' => 'open_orders', 'label' => 'Open orders'],
                ['id' => 'expected', 'label' => 'Expected deliveries'],
                ['id' => 'delayed', 'label' => 'Delayed'],
                ['id' => 'partially_received', 'label' => 'Partly received'],
                ['id' => 'receipt_pending', 'label' => 'GRN pending'],
            ],
            'rows'  => $out,
            'total' => $total,
            'limit' => $params['limit'],
            'offset' => $params['offset'],
            'values_visible' => $this->canSeeValues(),
            'basis' => 'Purchase orders in this scope. Quantities stay on their lines and are never added across units; the value columns are the commercial amounts this company committed to.',
        ]);
    }

    private function nextAction(string $status, int $openLines, ?int $daysLate): string
    {
        // Lateness is checked BEFORE status: an issued order that is a fortnight
        // overdue needs chasing, and "awaiting acknowledgement" is not the
        // sentence a buyer needs to read against it.
        return match (true) {
            $status === 'DRAFT'            => 'Submit for approval',
            $status === 'APPROVAL_PENDING' => 'Awaiting an approver',
            $status === 'APPROVED'         => 'Issue to the supplier',
            $daysLate !== null && $daysLate > 0 && $openLines > 0 => 'Chase the supplier',
            $status === 'ISSUED'           => 'Awaiting acknowledgement',
            $openLines > 0                 => 'Record the receipt when it arrives',
            default                        => 'Nothing outstanding',
        };
    }

    /**
     * Panel B — the incoming delivery timeline.
     *
     * Grouped by the date something is expected, with the order lines under it.
     * Where a promised date has been revised, both the order's date and the
     * line's are carried so the change is visible rather than overwritten.
     *
     * @return array<string, mixed>
     */
    private function deliveryTimeline(int $horizonDays): array
    {
        if (!$this->can('po.view')) {
            return $this->withheld('po.view');
        }

        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $rows = $this->rows(
            "SELECT COALESCE(l.promised_date, p.promised_date) AS expected_date,
                    p.po_id, p.po_no, p.status, p.currency_code, p.promised_date AS order_promised,
                    l.promised_date AS line_promised,
                    p.supplier_account_id, p.supplier_name_snapshot,
                    l.line_id, l.line_no, l.item_id, l.unit_id, l.is_service, l.description,
                    l.ordered_qty::text AS ordered_qty, l.received_qty::text AS received_qty,
                    l.agreed_rate::text AS agreed_rate, l.warehouse_id
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope}
               AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) <= CURRENT_DATE + CAST(:horizon AS INT)" . $filterSql . "
             ORDER BY expected_date ASC NULLS LAST, p.po_no, l.line_no
             LIMIT 120",
            ['horizon' => $horizonDays] + $filterParams,
            'p',
        );

        $itemIds = array_values(array_filter(array_map(static fn ($r) => (int) ($r['item_id'] ?? 0), $rows)));
        $items = [];
        if ($itemIds !== []) {
            $lookup = $this->inventoryReader()->items($itemIds);
            $this->sources->record('inventory', InventoryReader::LABEL, ['ok' => $lookup['ok'], 'status' => $lookup['ok'] ? 200 : 0], 'Item names could not be read from Inventory; ids are shown instead.');
            $items = $lookup['items'];
        }

        $today = gmdate('Y-m-d');
        $groups = [];
        foreach ($rows as $row) {
            $date = $row['expected_date'] === null ? 'undated' : (string) $row['expected_date'];
            $groups[$date] ??= [
                'date'        => $row['expected_date'],
                'date_label'  => $row['expected_date'] === null ? 'No promised date' : Format::date((string) $row['expected_date']),
                'is_overdue'  => $row['expected_date'] !== null && (string) $row['expected_date'] < $today,
                'lines'       => [],
            ];

            $itemId = (int) ($row['item_id'] ?? 0);
            $remaining = Decimal::sub(Decimal::of($row['ordered_qty']), Decimal::of($row['received_qty']));
            $revised = $row['line_promised'] !== null
                && $row['order_promised'] !== null
                && (string) $row['line_promised'] !== (string) $row['order_promised'];

            $groups[$date]['lines'][] = [
                'po_id'        => (int) $row['po_id'],
                'po_no'        => $row['po_no'],
                'line_id'      => (int) $row['line_id'],
                'line_no'      => (int) $row['line_no'],
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name' => $row['supplier_name_snapshot'],
                'item_id'      => $itemId > 0 ? $itemId : null,
                'item_label'   => $items[$itemId]['name'] ?? ($row['description'] ?: ($itemId > 0 ? 'Item ' . $itemId : 'Service')),
                'is_service'   => (bool) $row['is_service'],
                // Quantity and its unit travel together. A number without its
                // unit is the start of every wrong total on a procurement screen.
                'ordered_qty'  => Decimal::of($row['ordered_qty']),
                'received_qty' => Decimal::of($row['received_qty']),
                'remaining_qty' => $remaining,
                'unit'         => $items[$itemId]['uom'] ?? null,
                'remaining_label' => Format::quantity($remaining, $items[$itemId]['uom'] ?? null),
                'warehouse_id' => $row['warehouse_id'] === null ? null : (int) $row['warehouse_id'],
                'revised'      => $revised,
                'revised_from' => $revised ? Format::date((string) $row['order_promised']) : null,
                'route'        => '/purchase-orders/' . $row['po_id'],
            ];
        }

        return $this->panel([
            'horizon_days' => $horizonDays,
            'groups'       => array_values($groups),
            'basis'        => 'Order lines with quantity still to arrive, grouped by the date they are expected. Where a line carries its own promised date it is used, and the order-level date it replaced is shown beside it.',
            'item_names_from' => $this->sources->isReady('inventory') ? 'inventory' : 'unavailable',
        ]);
    }

    /**
     * Panel C — reorder review.
     *
     * Inventory decides what is short; this panel adds what is already on order
     * from our side, so the suggestion accounts for stock that is coming. The
     * result is a reviewable draft and never an order.
     *
     * @return array<string, mixed>
     */
    private function reorder(): array
    {
        if (!$this->can('requisition.create') && !$this->can('po.create')) {
            return $this->withheld('po.create');
        }

        $signal = $this->inventoryReader()->replenishment($this->filters->warehouseId, 25);
        if (!$signal['ok']) {
            $this->sources->unavailable('inventory', InventoryReader::LABEL, (string) $signal['error']);

            return $this->unavailablePanel(
                (string) $signal['error'] . ' Reorder suggestions need live stock and consumption from Inventory; nothing is estimated from purchase history in its place.',
            );
        }

        $this->sources->ready('inventory', InventoryReader::LABEL, $signal['as_of']);

        $rows = $signal['rows'];
        $itemIds = array_map(static fn ($r) => (int) $r['item_id'], $rows);

        // What we have already ordered and not yet received, per item. This is
        // ours to answer and is why a reorder screen belongs in Purchases.
        $onOrder = [];
        if ($itemIds !== []) {
            $placeholders = [];
            $params = [];
            foreach (array_values(array_unique($itemIds)) as $index => $itemId) {
                $placeholders[] = ':item' . $index;
                $params['item' . $index] = $itemId;
            }
            foreach ($this->rows(
                "SELECT l.item_id,
                        COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0)), 0)::text AS on_order,
                        MIN(COALESCE(l.promised_date, p.promised_date))                     AS next_arrival
                 FROM purchase_order_lines l
                 JOIN purchase_orders p ON p.po_id = l.po_id
                 WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
                   AND l.item_id IN (" . implode(', ', $placeholders) . ')
                 GROUP BY l.item_id',
                $params,
                'p',
            ) as $row) {
                $onOrder[(int) $row['item_id']] = [
                    'qty'  => Decimal::of($row['on_order']),
                    'next' => $row['next_arrival'],
                ];
            }
        }

        $suggestions = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $ordered = $onOrder[$itemId]['qty'] ?? Decimal::ZERO;
            $available = $row['available_qty'] ?? null;
            $suggested = $row['suggested_qty'] ?? null;

            // Inventory's suggestion less what is already coming. Never below zero.
            $adjusted = null;
            if ($suggested !== null) {
                $adjusted = Decimal::sub($suggested, $ordered);
                if (Decimal::isNegative($adjusted)) {
                    $adjusted = Decimal::ZERO;
                }
            }

            $suggestions[] = [
                'item_id'        => $itemId,
                'item_label'     => $row['item_name'] ?? ('Item ' . $itemId),
                'unit'           => $row['uom'],
                'warehouse_id'   => $row['warehouse_id'],
                'available_qty'  => $available,
                'available_label' => $available === null ? 'Not reported' : Format::quantity($available, $row['uom']),
                'reorder_level'  => $row['reorder_level'],
                'safety_stock'   => $row['safety_stock'],
                'on_order_qty'   => $ordered,
                'on_order_label' => Format::quantity($ordered, $row['uom']),
                'next_arrival'   => $onOrder[$itemId]['next'] ?? null,
                'next_arrival_label' => Format::date($onOrder[$itemId]['next'] ?? null),
                'lead_days'      => $row['lead_days'],
                'inventory_suggested_qty' => $suggested,
                'suggested_qty'  => $adjusted,
                'suggested_label' => $adjusted === null ? 'No suggestion' : Format::quantity($adjusted, $row['uom']),
                'basis'          => $suggested === null
                    ? 'Inventory reported this item as short but did not return a suggested quantity.'
                    : 'Inventory suggested ' . Format::quantity($suggested, $row['uom'])
                        . '; ' . Format::quantity($ordered, $row['uom']) . ' is already on order and has been deducted.',
                'stale'          => $row['as_of'] !== null && $row['as_of'] < gmdate('Y-m-d', strtotime('-1 day')),
                'route'          => '/requisitions/new',
                'filters'        => ['item_id' => (string) $itemId],
            ];
        }

        return $this->panel([
            'rows'  => $suggestions,
            'as_of' => $signal['as_of'],
            'basis' => 'Stock, reorder level, safety stock and lead time come from Inventory\'s replenishment report, read live. What is already on order is ours. Every suggestion is a draft for review — nothing here creates a requisition or an order.',
        ]);
    }

    /**
     * Panel D — quotation comparison.
     *
     * Comparable only where it is comparable: same RFQ line, same unit. Freight
     * and other charges are shown at quote level rather than spread across
     * lines, because spreading them invents a per-unit cost nobody quoted.
     *
     * @return array<string, mixed>
     */
    private function quoteComparison(): array
    {
        if (!$this->can('rfq.view')) {
            return $this->withheld('rfq.view');
        }

        $rfqId = Http::intParam('rfq_id');
        if ($rfqId === null || $rfqId <= 0) {
            $open = $this->rows(
                "SELECT r.rfq_id, r.rfq_no, r.title, r.status, r.response_deadline, r.currency_code,
                        (SELECT COUNT(*) FROM purchase_quotes q WHERE q.rfq_id = r.rfq_id) AS quote_count,
                        (SELECT COUNT(*) FROM purchase_rfq_invitations i WHERE i.rfq_id = r.rfq_id) AS invited_count
                 FROM purchase_rfqs r
                 WHERE {scope} AND r.status IN ('ISSUED', 'RESPONSES_OPEN', 'EVALUATING')
                 ORDER BY r.response_deadline ASC NULLS LAST
                 LIMIT 10",
                [],
                'r',
            );

            return $this->panel([
                'mode' => 'list',
                'rfqs' => array_map(static fn (array $r) => [
                    'rfq_id'   => (int) $r['rfq_id'],
                    'rfq_no'   => $r['rfq_no'],
                    'title'    => $r['title'],
                    'status'   => $r['status'],
                    'deadline' => $r['response_deadline'],
                    'deadline_label' => Format::dateTime($r['response_deadline']),
                    'quote_count'   => (int) $r['quote_count'],
                    'invited_count' => (int) $r['invited_count'],
                    'currency' => $r['currency_code'],
                    'route'    => '/rfqs/' . $r['rfq_id'],
                ], $open),
                'basis' => 'Open RFQs awaiting evaluation. Choose one to compare the quotes line by line.',
            ]);
        }

        $rfq = $this->row(
            'SELECT rfq_id, rfq_no, title, status, currency_code, required_by FROM purchase_rfqs r
             WHERE {scope} AND r.rfq_id = :id',
            ['id' => $rfqId],
            'r',
        );
        if ($rfq === null) {
            return $this->unavailablePanel('That RFQ is not in this company, financial year and branch.');
        }

        $quotes = $this->rows(
            'SELECT q.quote_id, q.supplier_account_id, q.quote_ref, q.status, q.currency_code, q.exchange_rate::text AS exchange_rate,
                    q.payment_terms, q.delivery_days, q.warranty_terms,
                    q.freight_amount::text AS freight_amount, q.other_charges::text AS other_charges, q.valid_until,
                    s.qualification_status, s.is_preferred, s.operational_lead_days
             FROM purchase_quotes q
             LEFT JOIN purchase_supplier_profiles s
               ON s.cmp_id = q.cmp_id AND s.supplier_account_id = q.supplier_account_id
             WHERE q.cmp_id = :ctx_cmp_id AND q.rfq_id = :rfq
             ORDER BY q.quote_id',
            ['rfq' => $rfqId],
        );

        $lines = $this->rows(
            'SELECT rl.line_id AS rfq_line_id, rl.line_no, rl.item_id, rl.unit_id, rl.description,
                    rl.required_qty::text AS required_qty, rl.specification,
                    ql.quote_id, ql.quoted_qty::text AS quoted_qty, ql.quoted_rate::text AS quoted_rate,
                    ql.discount_pc::text AS discount_pc, ql.estimated_tax_pc::text AS estimated_tax_pc,
                    ql.moq::text AS moq, ql.lead_days, ql.line_amount::text AS line_amount, ql.unit_id AS quote_unit_id
             FROM purchase_rfq_lines rl
             LEFT JOIN purchase_quote_lines ql ON ql.rfq_line_id = rl.line_id
             WHERE rl.cmp_id = :ctx_cmp_id AND rl.rfq_id = :rfq
             ORDER BY rl.line_no, ql.quote_id',
            ['rfq' => $rfqId],
        );

        $itemIds = array_values(array_filter(array_map(static fn ($r) => (int) ($r['item_id'] ?? 0), $lines)));
        $items = [];
        if ($itemIds !== []) {
            $lookup = $this->inventoryReader()->items($itemIds);
            $items = $lookup['items'];
            if (!$lookup['ok'] && !$this->sources->isReady('inventory')) {
                $this->sources->unavailable('inventory', InventoryReader::LABEL, (string) $lookup['error']);
            }
        }

        $currencies = array_values(array_unique(array_filter(array_map(static fn ($q) => (string) $q['currency_code'], $quotes))));
        $mixedCurrency = count($currencies) > 1;

        $grid = [];
        foreach ($lines as $row) {
            $lineId = (int) $row['rfq_line_id'];
            $itemId = (int) ($row['item_id'] ?? 0);
            $grid[$lineId] ??= [
                'rfq_line_id' => $lineId,
                'line_no'     => (int) $row['line_no'],
                'item_id'     => $itemId > 0 ? $itemId : null,
                'item_label'  => $items[$itemId]['name'] ?? ($row['description'] ?: 'Line ' . $row['line_no']),
                'unit'        => $items[$itemId]['uom'] ?? null,
                'unit_id'     => $row['unit_id'] === null ? null : (int) $row['unit_id'],
                'required_qty' => Decimal::of($row['required_qty']),
                'required_label' => Format::quantity(Decimal::of($row['required_qty']), $items[$itemId]['uom'] ?? null),
                'specification' => $row['specification'],
                'offers'      => [],
            ];

            if ($row['quote_id'] === null) {
                continue;
            }

            // A quote in a different unit is NOT comparable and is marked so
            // rather than being converted with a factor nobody agreed.
            $comparable = $row['quote_unit_id'] === null
                || $row['unit_id'] === null
                || (int) $row['quote_unit_id'] === (int) $row['unit_id'];

            $grid[$lineId]['offers'][(int) $row['quote_id']] = [
                'quote_id'   => (int) $row['quote_id'],
                'quoted_qty' => Decimal::of($row['quoted_qty']),
                'quoted_rate' => Decimal::of($row['quoted_rate']),
                'discount_pc' => Decimal::of($row['discount_pc']),
                'estimated_tax_pc' => Decimal::of($row['estimated_tax_pc']),
                'moq'        => Decimal::parse($row['moq']),
                'lead_days'  => $row['lead_days'] === null ? null : (int) $row['lead_days'],
                'line_amount' => Decimal::of($row['line_amount']),
                'comparable' => $comparable,
                'not_comparable_reason' => $comparable ? null : 'Quoted in a different unit from the RFQ line.',
            ];
        }

        // The best offer per line, on the comparable ones only.
        foreach ($grid as $lineId => $line) {
            $best = null;
            foreach ($line['offers'] as $quoteId => $offer) {
                if (!$offer['comparable'] || $mixedCurrency) {
                    continue;
                }
                if ($best === null || Decimal::cmp($offer['quoted_rate'], $grid[$lineId]['offers'][$best]['quoted_rate']) < 0) {
                    $best = $quoteId;
                }
            }
            $grid[$lineId]['best_quote_id'] = $best;
            $grid[$lineId]['offers'] = array_values($line['offers']);
        }

        return $this->panel([
            'mode'  => 'compare',
            'rfq'   => [
                'rfq_id'   => (int) $rfq['rfq_id'],
                'rfq_no'   => $rfq['rfq_no'],
                'title'    => $rfq['title'],
                'status'   => $rfq['status'],
                'currency' => $rfq['currency_code'],
                'required_by' => $rfq['required_by'],
                'route'    => '/rfqs/' . $rfq['rfq_id'],
            ],
            'quotes' => array_map(static fn (array $q) => [
                'quote_id'  => (int) $q['quote_id'],
                'supplier_account_id' => (int) $q['supplier_account_id'],
                'quote_ref' => $q['quote_ref'],
                'status'    => $q['status'],
                'currency'  => $q['currency_code'],
                'exchange_rate' => Decimal::of($q['exchange_rate']),
                'payment_terms' => $q['payment_terms'],
                'delivery_days' => $q['delivery_days'] === null ? null : (int) $q['delivery_days'],
                'warranty_terms' => $q['warranty_terms'],
                'freight_amount' => Decimal::of($q['freight_amount']),
                'other_charges'  => Decimal::of($q['other_charges']),
                'valid_until'    => $q['valid_until'],
                'qualification_status' => $q['qualification_status'],
                'is_preferred'   => (bool) ($q['is_preferred'] ?? false),
                'lead_days'      => $q['operational_lead_days'] === null ? null : (int) $q['operational_lead_days'],
            ], $quotes),
            'lines' => array_values($grid),
            'mixed_currency' => $mixedCurrency,
            'currencies'     => $currencies,
            'basis' => 'Quoted rates before tax, on the same RFQ line and the same unit. Freight and other charges stay at quote level rather than being spread across lines. These are COMMERCIAL cost estimates; the inventory valuation of what arrives is Inventory\'s and will not match line for line.',
            'caveat' => $mixedCurrency
                ? 'These quotes are in more than one currency. No exchange rate is applied here, so the cheapest rate is not marked — convert on a stated rate before deciding.'
                : null,
        ]);
    }

    /**
     * Panel E — the approval inbox.
     *
     * Only what this user can actually decide, and never their own document.
     * The same two rules are enforced again in the service when they press the
     * button; this is the courtesy version.
     *
     * @return array<string, mixed>
     */
    private function approvalInbox(): array
    {
        $granted = Permissions::granted($this->ctx, $this->auth);
        if ($granted === []) {
            return $this->panel(['rows' => [], 'total' => 0, 'basis' => 'You hold no approval permissions, so nothing is routed to you.']);
        }

        $placeholders = [];
        $params = ['me' => $this->auth->uuid];
        foreach (array_values($granted) as $index => $permission) {
            $placeholders[] = ':perm' . $index;
            $params['perm' . $index] = $permission;
        }
        $permissionClause = '(a.required_permission IS NULL OR a.required_permission IN (' . implode(', ', $placeholders) . '))';

        $rows = Db::all(
            "SELECT a.approval_id, a.entity_type, a.entity_id, a.stage_no, a.stage_name,
                    a.required_permission, a.reason_kind, a.reason_detail,
                    a.threshold_value::text AS threshold_value, a.actual_value::text AS actual_value,
                    a.requested_by, a.created_at,
                    r.requisition_no, r.requisition_date,
                    p.po_no, p.po_date, p.supplier_name_snapshot, p.currency_code
             FROM purchase_approval_requests a
             LEFT JOIN purchase_requisitions r ON a.entity_type = 'requisition'    AND r.requisition_id = a.entity_id
             LEFT JOIN purchase_orders       p ON a.entity_type = 'purchase_order' AND p.po_id          = a.entity_id
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = 'PENDING'
               AND a.requested_by <> :me AND " . $permissionClause . '
             ORDER BY a.actual_value DESC NULLS LAST, a.created_at ASC
             LIMIT 25',
            ['cmp' => $this->ctx->cmpId, 'fy' => $this->ctx->fyId] + $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency_code'] ?? $this->documentCurrency() ?? 'INR');
            $value = Decimal::parse($row['actual_value']);
            $out[] = [
                'approval_id'   => (int) $row['approval_id'],
                'entity_type'   => $row['entity_type'],
                'entity_id'     => (int) $row['entity_id'],
                'reference'     => $row['po_no'] ?? $row['requisition_no'] ?? ('#' . $row['entity_id']),
                'supplier_name' => $row['supplier_name_snapshot'],
                'stage'         => $row['stage_name'] ?? ('Stage ' . $row['stage_no']),
                'required_permission' => $row['required_permission'],
                'reason'        => $row['reason_detail'] ?? $row['reason_kind'],
                'threshold'     => Decimal::parse($row['threshold_value']),
                'threshold_formatted' => $row['threshold_value'] === null ? null : Format::money(Decimal::of($row['threshold_value']), $currency),
                'value'         => $value,
                'value_formatted' => $value === null ? null : Format::money($value, $currency),
                'currency'      => $currency,
                'requested_by'  => $row['requested_by'],
                'raised'        => $row['created_at'],
                'raised_label'  => Format::date(substr((string) $row['created_at'], 0, 10)),
                'age_days'      => BooksReader::daysBetween(substr((string) $row['created_at'], 0, 10), gmdate('Y-m-d')),
                'route'         => $row['entity_type'] === 'purchase_order'
                    ? '/purchase-orders/' . $row['entity_id']
                    : '/requisitions/' . $row['entity_id'],
                'approve_endpoint' => $row['entity_type'] === 'purchase_order'
                    ? 'v1/purchase-orders/' . $row['entity_id'] . '/approve'
                    : 'v1/requisitions/' . $row['entity_id'] . '/approve',
                'reject_endpoint' => $row['entity_type'] === 'purchase_order'
                    ? 'v1/purchase-orders/' . $row['entity_id'] . '/reject'
                    : 'v1/requisitions/' . $row['entity_id'] . '/reject',
            ];
        }

        return $this->panel([
            'rows'  => $out,
            'total' => count($out),
            'basis' => 'Pending approvals whose required permission you hold. Anything you raised yourself is excluded — that rule is enforced in the service too, not just here.',
        ]);
    }

    // -----------------------------------------------------------------------
    // The 2026 workspace panels
    // -----------------------------------------------------------------------

    /**
     * Panel F — the procurement flow, requisition to receipt.
     *
     * Six stages of ONE workflow, each with what is sitting in it and what that
     * is worth. The money changes meaning as it moves along, and each stage says
     * which meaning it is using: a requisition carries the requester's estimate,
     * an RFQ carries that same estimate against the quantity asked for, a
     * comparison carries the best offer actually received, and only from the
     * purchase order onwards is it a price anybody agreed to. Adding those four
     * together would be meaningless, so the panel never shows a total.
     *
     * @return array<string, mixed>
     */
    private function flow(string $currency, bool $mixedCurrency): array
    {
        if (!$this->can('po.view')) {
            return $this->withheld('po.view');
        }

        $money = $this->canSeeValues() && !$mixedCurrency;
        [$filterSql, $filterParams] = $this->filters->orderClause('p');
        [$reqSql, $reqParams] = $this->filters->requisitionClause('r');
        [$rfqSql, $rfqParams] = $this->filters->rfqClause('r');

        $requisition = $this->row(
            "SELECT COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE r.status = 'APPROVAL_PENDING' AND r.requisition_date < CURRENT_DATE - 3) AS ageing,
                    COALESCE(SUM(r.estimated_value), 0)::text AS value
             FROM purchase_requisitions r
             WHERE {scope} AND r.status IN ('SUBMITTED', 'APPROVAL_PENDING', 'APPROVED', 'SOURCING')" . $reqSql,
            $reqParams,
            'r',
        ) ?? [];

        $rfq = $this->row(
            "SELECT COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE r.response_deadline IS NOT NULL AND r.response_deadline < NOW()) AS past_deadline
             FROM purchase_rfqs r
             WHERE {scope} AND r.status IN ('ISSUED', 'RESPONSES_OPEN')" . $rfqSql,
            $rfqParams,
            'r',
        ) ?? [];

        // An RFQ has no price of its own — nobody has quoted yet. What it has is
        // the requisition's estimate against the quantity being asked for, and
        // the stage says so rather than presenting it as a cost. Where the RFQ
        // lines were raised directly rather than from a requisition there is no
        // estimate at all, and the sum comes back as zero: that is "not known",
        // which is a dash, not ₹0.
        $rfqValue = $money ? $this->amount(
            "SELECT COALESCE(SUM(rl.required_qty * COALESCE(pl.estimated_rate, 0)), 0)::text
             FROM purchase_rfq_lines rl
             JOIN purchase_rfqs r ON r.rfq_id = rl.rfq_id
             LEFT JOIN purchase_requisition_lines pl ON pl.line_id = rl.requisition_line_id
             WHERE {scope} AND r.status IN ('ISSUED', 'RESPONSES_OPEN')" . $rfqSql,
            $rfqParams,
            'r',
        ) : null;
        if ($rfqValue !== null && Decimal::isZero($rfqValue)) {
            $rfqValue = null;
        }

        $comparison = $this->row(
            "SELECT COUNT(*) AS n
             FROM purchase_rfqs r
             WHERE {scope} AND r.status IN ('RESPONSES_OPEN', 'EVALUATING')
               AND EXISTS (SELECT 1 FROM purchase_quotes q
                            WHERE q.rfq_id = r.rfq_id AND q.status NOT IN ('WITHDRAWN', 'REJECTED'))" . $rfqSql,
            $rfqParams,
            'r',
        ) ?? [];

        // The lowest live offer per line, summed. Not a saving and not a
        // commitment — the cheapest set of offers currently on the table.
        $comparisonValue = $money ? $this->amount(
            "SELECT COALESCE(SUM(lowest.best), 0)::text FROM (
                 SELECT MIN(ql.line_amount) AS best
                 FROM purchase_quote_lines ql
                 JOIN purchase_quotes q ON q.quote_id = ql.quote_id
                 JOIN purchase_rfqs r ON r.rfq_id = q.rfq_id
                 WHERE {scope} AND r.status IN ('RESPONSES_OPEN', 'EVALUATING')
                   AND q.status NOT IN ('WITHDRAWN', 'REJECTED')
                   AND ql.rfq_line_id IS NOT NULL" . $rfqSql . "
                 GROUP BY ql.rfq_line_id
             ) lowest",
            $rfqParams,
            'r',
        ) : null;
        // No comparable offer on the table is not an offer worth nothing.
        if ($comparisonValue !== null && Decimal::isZero($comparisonValue)) {
            $comparisonValue = null;
        }

        $order = $this->row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(p.total_amount), 0)::text AS value
             FROM purchase_orders p
             WHERE {scope} AND p.status NOT IN ('DRAFT', 'APPROVAL_PENDING', 'CANCELLED')
               AND COALESCE(p.issued_at::date, p.po_date) BETWEEN :from AND :to" . $filterSql,
            $this->period->params() + $filterParams,
            'p',
        ) ?? [];

        $delivery = $this->row(
            "SELECT COUNT(DISTINCT p.po_id) AS n,
                    COUNT(DISTINCT p.po_id) FILTER (WHERE COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE) AS overdue,
                    COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text AS value
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty" . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        $receipt = $this->row(
            "SELECT COUNT(*) AS n, COUNT(*) FILTER (WHERE rr.status = 'FAILED') AS failed
             FROM purchase_receipt_requests rr
             JOIN purchase_orders p ON p.po_id = rr.po_id
             WHERE {scope} AND rr.status IN ('REQUESTED', 'FAILED')" . $filterSql,
            $filterParams,
            'rr',
        ) ?? [];

        // Valued from the receipt's own lines against the rate on the order
        // line each one names — exact, rather than a share of the order total.
        $receiptValue = $money ? $this->amount(
            "SELECT COALESCE(SUM(x.qty * l.agreed_rate), 0)::text
             FROM purchase_receipt_requests rr
             JOIN purchase_orders p ON p.po_id = rr.po_id
             CROSS JOIN LATERAL jsonb_to_recordset(rr.requested_lines) AS x(line_id bigint, qty numeric)
             JOIN purchase_order_lines l ON l.line_id = x.line_id AND l.cmp_id = rr.cmp_id
             WHERE {scope} AND rr.status IN ('REQUESTED', 'FAILED')" . $filterSql,
            $filterParams,
            'rr',
        ) : null;

        $requisitionAgeing = (int) ($requisition['ageing'] ?? 0);
        $pastDeadline = (int) ($rfq['past_deadline'] ?? 0);
        $overdueDeliveries = (int) ($delivery['overdue'] ?? 0);
        $failedReceipts = (int) ($receipt['failed'] ?? 0);
        $pendingReceipts = (int) ($receipt['n'] ?? 0);

        $stages = [
            [
                'id'    => 'requisition',
                'label' => 'Requisition',
                'count' => (int) ($requisition['n'] ?? 0),
                'value' => $money ? Decimal::of($requisition['value'] ?? '0') : null,
                'value_label' => 'estimated',
                'tone'  => $requisitionAgeing > 0 ? 'warning' : 'success',
                'status_label' => $requisitionAgeing > 0 ? $requisitionAgeing . ' ageing' : 'On track',
                'empty_label' => 'None open',
                'detail' => 'Submitted, awaiting approval, approved or out to sourcing. The value is the requester\'s own estimate, used for approval routing — not a price.',
                'route' => '/requisitions',
                'filters' => [],
            ],
            [
                'id'    => 'rfq',
                'label' => 'RFQ',
                'count' => (int) ($rfq['n'] ?? 0),
                'value' => $rfqValue,
                'value_label' => 'estimated',
                'tone'  => $pastDeadline > 0 ? 'warning' : 'info',
                'status_label' => $pastDeadline > 0 ? 'Past deadline' : 'Pending quotes',
                'empty_label' => 'None out',
                'detail' => 'Requests for quotation issued or open for responses. Valued at the requisition\'s estimate against the quantity asked for, because no supplier has quoted yet.',
                'route' => '/rfqs',
                'filters' => [],
            ],
            [
                'id'    => 'quote_comparison',
                'label' => 'Quote comparison',
                'count' => (int) ($comparison['n'] ?? 0),
                'value' => $comparisonValue,
                'value_label' => 'best offers',
                'tone'  => 'info',
                'status_label' => 'In progress',
                'empty_label' => 'None to compare',
                'detail' => 'RFQs with at least one live quote still to be decided. Valued at the lowest offer received for each line — the cheapest set of offers on the table, not a saving and not a commitment.',
                'route' => '/rfqs',
                'filters' => [],
            ],
            [
                'id'    => 'purchase_order',
                'label' => 'Purchase order',
                'count' => (int) ($order['n'] ?? 0),
                'value' => $money ? Decimal::of($order['value'] ?? '0') : null,
                'value_label' => 'ordered',
                'tone'  => 'success',
                'status_label' => 'Released',
                'empty_label' => 'None released',
                'detail' => 'Orders issued to a supplier in ' . $this->period->label() . '. From here the value is a price somebody agreed to.',
                'route' => '/purchase-orders',
                'filters' => ['status' => 'ISSUED'],
            ],
            [
                'id'    => 'delivery',
                'label' => 'Delivery',
                'count' => (int) ($delivery['n'] ?? 0),
                'value' => $money ? Decimal::of($delivery['value'] ?? '0') : null,
                'value_label' => 'still to arrive',
                'tone'  => $overdueDeliveries > 0 ? 'danger' : 'info',
                'status_label' => $overdueDeliveries > 0 ? $overdueDeliveries . ' delayed' : 'In transit',
                'empty_label' => 'Nothing in transit',
                'detail' => 'Orders with quantity still to arrive, valued at the remaining quantity times the rate agreed on the line.',
                'route' => '/dashboard/procurement',
                'filters' => ['view' => $overdueDeliveries > 0 ? 'delayed' : 'expected'],
            ],
            [
                'id'    => 'receipt',
                'label' => 'Receipt / GRN',
                'count' => $pendingReceipts,
                'value' => $receiptValue,
                'value_label' => 'awaiting Inventory',
                'tone'  => $failedReceipts > 0 ? 'danger' : ($pendingReceipts > 0 ? 'warning' : 'success'),
                'status_label' => $failedReceipts > 0 ? $failedReceipts . ' refused' : ($pendingReceipts > 0 ? 'Pending' : 'Clear'),
                'empty_label' => 'Clear',
                'detail' => 'Goods receipts sent to Inventory and not yet confirmed. Valued from the receipt\'s own lines at the rate on the order line each one names.',
                'route' => '/dashboard/procurement',
                'filters' => ['view' => 'receipt_pending'],
            ],
        ];

        foreach ($stages as $index => $stage) {
            // "In transit" over a count of zero is a pill describing nothing.
            // An empty stage says it is empty and stops colouring itself.
            if ($stage['count'] === 0) {
                $stages[$index]['tone'] = 'neutral';
                $stages[$index]['status_label'] = $stage['empty_label'];
            }
            unset($stages[$index]['empty_label']);

            $stages[$index]['value_formatted'] = $stage['value'] === null
                ? null
                : Format::money($stage['value'], $currency);
            // Six of these sit in one row, so the row shows the short form and
            // the exact figure travels in the stage's own tooltip.
            $stages[$index]['value_compact'] = $stage['value'] === null
                ? null
                : Format::compactMoney($stage['value'], $currency);
            $stages[$index]['count_label'] = Format::count($stage['count']);
            $stages[$index]['filters'] += $this->filters->drilldown();
        }

        return $this->panel([
            'stages'  => $stages,
            'currency' => $currency,
            'values_visible' => $money,
            'values_hidden_reason' => $money ? null : ($mixedCurrency
                ? 'Orders in this period are in more than one currency, so no stage total is shown.'
                : 'Purchase values are not visible to you.'),
            'basis'   => 'Live counts from this application\'s own workflow. The money means something different at each stage and is labelled accordingly, so the six are never added together.',
        ]);
    }

    /**
     * Panel G — what was ordered, over time.
     *
     * Our own purchase orders rather than Smart Books' posted purchases: this is
     * the buyer's screen, and a buyer commits money when the order goes out, not
     * when the bill is entered a fortnight later. The Overview screen shows the
     * accounting view of the same months, and the two are deliberately different
     * questions.
     *
     * @return array<string, mixed>
     */
    private function spendTrend(string $currency, bool $mixedCurrency): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('cost.view');
        }
        if ($mixedCurrency) {
            return $this->unavailablePanel(
                'Orders in this period are in more than one currency. A single spend line would add them together, so none is drawn. Filter to one currency to see it.',
            );
        }

        $days = $this->period->days();
        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $bucketed = function (string $bucket) use ($filterSql, $filterParams): array {
            return $this->rows(
                "SELECT date_trunc(CAST(:bucket AS text), p.po_date::timestamp)::date AS bucket,
                        COALESCE(SUM(p.total_amount), 0)::text AS amount,
                        COUNT(*) AS orders
                 FROM purchase_orders p
                 WHERE {scope} AND p.status <> 'CANCELLED'
                   AND p.po_date BETWEEN :from AND :to" . $filterSql . '
                 GROUP BY 1
                 ORDER BY 1',
                ['bucket' => $bucket] + $this->period->params() + $filterParams,
                'p',
            );
        };

        $granularity = match (true) {
            $days <= 31  => 'day',
            $days <= 140 => 'week',
            default      => 'month',
        };

        // A year-to-date range whose orders all fall in one month gives one
        // monthly bar, which is not a trend — it is a number with a grid behind
        // it. Where that happens the bucket narrows until there is something to
        // draw, or until days, at which point there genuinely is not.
        $rows = $bucketed($granularity);
        foreach (['month' => 'week', 'week' => 'day'] as $from => $finer) {
            if (count($rows) >= 3 || $granularity !== $from) {
                continue;
            }
            $finerRows = $bucketed($finer);
            if (count($finerRows) > count($rows)) {
                $rows = $finerRows;
                $granularity = $finer;
            }
        }

        $points = [];
        foreach ($rows as $row) {
            $amount = Decimal::of($row['amount']);
            $points[] = [
                'date'      => (string) $row['bucket'],
                'label'     => self::bucketLabel((string) $row['bucket'], $granularity),
                'amount'    => $amount,
                'formatted' => Format::money($amount, $currency),
                'orders'    => (int) $row['orders'],
            ];
        }

        $total = Decimal::sum(array_map(static fn (array $point): string => $point['amount'], $points));

        $comparePeriod = $this->period->compareParams();
        $previous = $comparePeriod === null ? null : $this->amount(
            "SELECT COALESCE(SUM(p.total_amount), 0)::text
             FROM purchase_orders p
             WHERE {scope} AND p.status <> 'CANCELLED'
               AND p.po_date BETWEEN :from AND :to" . $filterSql,
            $comparePeriod + $filterParams,
            'p',
        );

        return $this->panel([
            'granularity'     => $granularity,
            'currency'        => $currency,
            'points'          => $points,
            'total'           => $total,
            'total_formatted' => Format::money($total, $currency),
            'total_compact'   => Format::compactMoney($total, $currency),
            'basis'           => 'Purchase orders dated in ' . $this->period->label() . ', by ' . $granularity . ', at their order value. Cancelled orders are excluded and tax is not included — what the supplier actually charges is settled on the bill.',
            'comparison'      => $previous === null ? ['available' => false, 'reason' => 'Comparison is switched off for this view.'] : [
                'available'          => true,
                'label'              => $this->period->comparisonLabel(),
                'previous'           => $previous,
                'previous_formatted' => Format::money($previous, $currency),
                'change_pc'          => Decimal::percentChange($previous, $total, 1),
            ],
        ]);
    }

    /** "14 Apr", "w/c 14 Apr" or "Apr 2026", depending on how wide each bar is. */
    private static function bucketLabel(string $date, string $granularity): string
    {
        try {
            $moment = new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return $date;
        }

        return match ($granularity) {
            'day'   => $moment->format('d M'),
            'week'  => 'w/c ' . $moment->format('d M'),
            default => $moment->format('M Y'),
        };
    }

    /**
     * Panel H — supplier on-time delivery.
     *
     * Completed deliveries only. An order that is not yet due has not been late,
     * and counting open orders as failures is the single most common way a
     * supplier scorecard ends up defaming a supplier who has done nothing wrong.
     * The sample size travels with every percentage, because 100% of one
     * delivery is not a record.
     *
     * @return array<string, mixed>
     */
    private function supplierPerformance(): array
    {
        if (!$this->can('supplier.view') && !$this->can('reports.view')) {
            return $this->withheld('supplier.view');
        }

        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $rows = $this->rows(
            "SELECT p.supplier_account_id AS id,
                    MAX(p.supplier_name_snapshot) AS name,
                    COUNT(*) AS receipts,
                    COUNT(*) FILTER (WHERE rr.received_at <= p.promised_date) AS on_time
             FROM purchase_receipt_requests rr
             JOIN purchase_orders p ON p.po_id = rr.po_id
             WHERE {scope} AND rr.status = 'ACCEPTED'
               AND rr.received_at IS NOT NULL AND p.promised_date IS NOT NULL
               AND rr.received_at BETWEEN :from AND :to" . $filterSql . '
             GROUP BY p.supplier_account_id
             ORDER BY COUNT(*) DESC
             LIMIT 8',
            $this->period->params() + $filterParams,
            'rr',
        );

        $out = [];
        foreach ($rows as $row) {
            $sample = (int) $row['receipts'];
            if ($sample === 0) {
                continue;
            }
            $onTime = (int) $row['on_time'];
            $pc = Decimal::percentOf((string) $onTime, (string) $sample, 0) ?? '0';
            $id = (int) $row['id'];

            $out[] = [
                'supplier_account_id' => $id,
                'supplier_name' => ($row['name'] === null || $row['name'] === '') ? 'Account ' . $id : (string) $row['name'],
                'on_time_pc'    => $pc,
                'on_time_label' => Format::percent($pc, 0),
                'on_time_count' => $onTime,
                'sample'        => $sample,
                'sample_label'  => $onTime . ' of ' . $sample . ' deliveries',
                // Colour is a second reading of the number, never the only one:
                // the percentage is printed beside every bar.
                'tone'          => match (true) {
                    Decimal::cmp($pc, '85') >= 0 => 'success',
                    Decimal::cmp($pc, '70') >= 0 => 'brand',
                    Decimal::cmp($pc, '60') >= 0 => 'warning',
                    default                      => 'danger',
                },
                'route'         => '/dashboard/suppliers',
                'filters'       => ['supplier_id' => (string) $id],
            ];
        }

        usort($out, static fn (array $a, array $b): int => Decimal::cmp($b['on_time_pc'], $a['on_time_pc']));

        return $this->panel([
            'rows'  => $out,
            'basis' => 'Goods receipts Inventory accepted with a date in ' . $this->period->label() . ', measured against the promised date on the order. Orders not yet due are not counted, and an order with no promised date cannot be judged either way.',
        ]);
    }

    /**
     * Panel I — where the money was ordered to.
     *
     * Grouped by the line's own material centre where it has one, and by the
     * order's delivery centre where it does not — which is the same rule the
     * receipt follows, so the two cannot disagree.
     *
     * @return array<string, mixed>
     */
    private function materialCentreSpend(string $currency, bool $mixedCurrency): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('cost.view');
        }
        if ($mixedCurrency) {
            return $this->unavailablePanel(
                'Orders in this period are in more than one currency. A share of a mixed-currency total would not mean anything, so no breakdown is shown. Filter to one currency to see it.',
            );
        }

        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $rows = $this->rows(
            "SELECT COALESCE(l.warehouse_id, p.delivery_warehouse_id) AS centre_id,
                    COALESCE(SUM(l.ordered_qty * l.agreed_rate), 0)::text AS amount,
                    COUNT(DISTINCT p.po_id) AS orders
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.status <> 'CANCELLED'
               AND p.po_date BETWEEN :from AND :to" . $filterSql . '
             GROUP BY 1
             ORDER BY SUM(l.ordered_qty * l.agreed_rate) DESC',
            $this->period->params() + $filterParams,
            'p',
        );

        // The names belong to Inventory. Asked for once, here, and left on the
        // dashboard so the material-centre filter can be labelled from the same
        // answer instead of asking again.
        $lookup = $this->inventoryReader()->warehouses();
        $this->sources->record(
            'inventory',
            InventoryReader::LABEL,
            ['ok' => $lookup['ok'], 'status' => $lookup['ok'] ? 200 : 0],
            'Material centre names could not be read from Inventory; the centre ids are shown instead.',
        );
        foreach ($lookup['rows'] as $warehouse) {
            if ($warehouse['name'] !== null) {
                $this->centreNames[(int) $warehouse['id']] = $warehouse['name'];
            }
        }

        $total = Decimal::sum(array_map(static fn (array $row): string => Decimal::of($row['amount']), $rows));

        $centres = [];
        $others = '0';
        $otherCount = 0;
        foreach ($rows as $index => $row) {
            $amount = Decimal::of($row['amount']);
            if ($index >= 5) {
                $others = Decimal::add($others, $amount);
                $otherCount++;
                continue;
            }
            $id = $row['centre_id'] === null ? null : (int) $row['centre_id'];
            $centres[] = [
                'centre_id' => $id,
                'label'     => $id === null
                    ? 'No centre recorded'
                    : ($this->centreNames[$id] ?? 'Centre ' . $id),
                'named'     => $id !== null && isset($this->centreNames[$id]),
                'amount'    => $amount,
                'formatted' => Format::money($amount, $currency),
                'share_pc'  => Decimal::isZero($total) ? null : Decimal::percentOf($amount, $total, 1),
                'orders'    => (int) $row['orders'],
                'route'     => '/purchase-orders',
                'filters'   => $id === null ? [] : ['warehouse_id' => (string) $id],
            ];
        }

        return $this->panel([
            'centres'         => $centres,
            'others'          => [
                'count'     => $otherCount,
                'amount'    => $others,
                'formatted' => Format::money($others, $currency),
                'share_pc'  => Decimal::isZero($total) ? null : Decimal::percentOf($others, $total, 1),
            ],
            'total'           => $total,
            'total_formatted' => Format::money($total, $currency),
            'total_compact'   => Format::compactMoney($total, $currency),
            'currency'        => $currency,
            'names_available' => $lookup['ok'],
            'basis'           => 'Ordered value — quantity times the agreed rate — on orders dated in ' . $this->period->label() . ', by the material centre each line is destined for. Tax and freight are not included.',
        ]);
    }

    /**
     * Panel J — what to act on.
     *
     * Deterministic rules over this company's own records. Nothing here is a
     * prediction and nothing here was written by a model: the panel says which
     * of the two it is, and when no model is configured the rules still run.
     *
     * @param array<string, mixed> $ai
     * @return array<string, mixed>
     */
    private function insights(string $currency, bool $mixedCurrency, array $ai): array
    {
        $items = InsightRules::procurement(
            $this->ctx,
            $this->period,
            $currency,
            $this->canSeeValues() && !$mixedCurrency,
            $this->filters->supplierAccountId,
        );

        return $this->panel([
            'items'  => $items,
            'method' => 'rules',
            'method_label' => 'Measured from your own records by fixed rules — none of it is a forecast.',
            // The model's availability is stated, and its absence changes
            // nothing on this panel. An insight is never invented to fill it.
            'ai'     => ['available' => (bool) ($ai['available'] ?? false), 'reason' => $ai['reason'] ?? null],
            'basis'  => 'Open orders, approval queues, quote responses and rates paid, in this company, financial year and branch.',
        ]);
    }

    /**
     * Panel K — recent activity and exceptions.
     *
     * One list across four record types, ordered by what would cost the most to
     * ignore rather than by what happened last. Routine activity is included so
     * that an empty exception list still reads as a working screen rather than a
     * broken one.
     *
     * @return array<string, mixed>
     */
    private function activity(string $currency, bool $mixedCurrency): array
    {
        if (!$this->can('po.view')) {
            return $this->withheld('po.view');
        }

        $money = $this->canSeeValues();
        [$filterSql, $filterParams] = $this->filters->orderClause('p');
        [$reqSql, $reqParams] = $this->filters->requisitionClause('r');
        $rows = [];

        $amount = static fn (mixed $value): ?string => $money ? Decimal::of($value) : null;

        // Every row is one document, so each is shown in the currency that
        // document was raised in. Nothing on this panel is totalled, which is
        // why it still works when the company buys in several currencies.
        $formatted = static function (mixed $value, ?string $rowCurrency) use ($money): ?string {
            if (!$money || $rowCurrency === null) {
                return null;
            }

            return Format::money(Decimal::of($value), $rowCurrency);
        };
        // A requisition estimate carries no currency of its own; where the
        // company buys in several, it is left unpriced rather than guessed at.
        $estimateCurrency = $mixedCurrency ? null : $currency;

        foreach ($this->rows(
            "SELECT p.po_id, p.po_no, p.supplier_name_snapshot, p.supplier_account_id, p.promised_date,
                    p.total_amount::text AS total_amount, p.currency_code,
                    (CURRENT_DATE - p.promised_date) AS days_late,
                    (SELECT COUNT(*) FROM purchase_order_lines l
                      WHERE l.po_id = p.po_id AND l.ordered_qty > l.received_qty) AS open_lines
             FROM purchase_orders p
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND p.promised_date < CURRENT_DATE
               AND EXISTS (SELECT 1 FROM purchase_order_lines l
                            WHERE l.po_id = p.po_id AND l.ordered_qty > l.received_qty)" . $filterSql . '
             ORDER BY p.promised_date ASC
             LIMIT 6',
            $filterParams,
            'p',
        ) as $row) {
            $late = (int) $row['days_late'];
            $rows[] = [
                'id'        => 'po-late-' . $row['po_id'],
                'date'      => (string) $row['promised_date'],
                'date_label' => Format::date((string) $row['promised_date']),
                'type'      => 'PO',
                'reference' => (string) $row['po_no'],
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name' => $row['supplier_name_snapshot'],
                'description' => 'Delivery late by ' . Format::days((string) $late) . ' — ' . (int) $row['open_lines'] . ' line'
                    . ((int) $row['open_lines'] === 1 ? '' : 's') . ' still short',
                'value'     => $amount($row['total_amount']),
                'value_formatted' => $formatted($row['total_amount'], (string) $row['currency_code']),
                'status'    => 'Delayed',
                'tone'      => 'danger',
                'route'     => '/purchase-orders/' . $row['po_id'],
                'rank'      => 900 + min($late, 90),
            ];
        }

        foreach ($this->rows(
            "SELECT e.exception_id, e.exception_kind, e.created_at,
                    e.variance_value::text AS variance_value,
                    b.request_id, b.supplier_invoice_no, b.supplier_account_id,
                    p.supplier_name_snapshot, p.currency_code
             FROM purchase_match_exceptions e
             JOIN purchase_match_results m ON m.match_id = e.match_id
             JOIN purchase_bill_requests b ON b.request_id = m.bill_request_id
             LEFT JOIN purchase_orders p ON p.po_id = b.po_id
             WHERE e.cmp_id = :ctx_cmp_id AND e.status = 'OPEN'
             ORDER BY e.created_at DESC
             LIMIT 4",
        ) as $row) {
            $rows[] = [
                'id'        => 'exception-' . $row['exception_id'],
                'date'      => substr((string) $row['created_at'], 0, 10),
                'date_label' => Format::date(substr((string) $row['created_at'], 0, 10)),
                'type'      => 'Invoice',
                'reference' => $row['supplier_invoice_no'] === null ? 'Bill ' . $row['request_id'] : (string) $row['supplier_invoice_no'],
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name' => $row['supplier_name_snapshot'],
                'description' => 'Three-way match failed on ' . str_replace('_', ' ', (string) $row['exception_kind'])
                    . ($money && $row['variance_value'] !== null && $row['currency_code'] !== null
                        ? ' — ' . Format::money(Decimal::of($row['variance_value']), (string) $row['currency_code']) . ' apart'
                        : ''),
                'value'     => $amount($row['variance_value'] ?? '0'),
                'value_formatted' => $formatted($row['variance_value'] ?? '0', $row['currency_code'] === null ? null : (string) $row['currency_code']),
                'status'    => 'Exception',
                'tone'      => 'danger',
                'route'     => '/bills/' . $row['request_id'],
                'rank'      => 850,
            ];
        }

        foreach ($this->rows(
            "SELECT r.requisition_id, r.requisition_no, r.requisition_date, r.estimated_value::text AS estimated_value,
                    (CURRENT_DATE - r.requisition_date) AS age_days
             FROM purchase_requisitions r
             WHERE {scope} AND r.status = 'APPROVAL_PENDING'" . $reqSql . '
             ORDER BY r.requisition_date ASC
             LIMIT 4',
            $reqParams,
            'r',
        ) as $row) {
            $age = (int) $row['age_days'];
            $rows[] = [
                'id'        => 'req-' . $row['requisition_id'],
                'date'      => (string) $row['requisition_date'],
                'date_label' => Format::date((string) $row['requisition_date']),
                'type'      => 'PR',
                'reference' => (string) $row['requisition_no'],
                'supplier_account_id' => null,
                'supplier_name' => null,
                'description' => $age === 0
                    ? 'Raised today, waiting for an approver'
                    : 'Waiting for an approver for ' . Format::days((string) $age),
                'value'     => $amount($row['estimated_value']),
                'value_formatted' => $formatted($row['estimated_value'], $estimateCurrency),
                'status'    => 'Pending approval',
                'tone'      => $age > 3 ? 'warning' : 'info',
                'route'     => '/requisitions/' . $row['requisition_id'],
                'rank'      => 700 + min($age, 60),
            ];
        }

        foreach ($this->rows(
            "SELECT p.po_id, p.po_no, p.supplier_name_snapshot, p.supplier_account_id, p.updated_at,
                    p.total_amount::text AS total_amount, p.currency_code,
                    (SELECT COUNT(*) FROM purchase_order_lines l WHERE l.po_id = p.po_id) AS line_count,
                    (SELECT COUNT(*) FROM purchase_order_lines l
                      WHERE l.po_id = p.po_id AND l.received_qty >= l.ordered_qty) AS complete_lines
             FROM purchase_orders p
             WHERE {scope} AND p.status = 'PARTIALLY_RECEIVED'" . $filterSql . '
             ORDER BY p.updated_at DESC
             LIMIT 4',
            $filterParams,
            'p',
        ) as $row) {
            $rows[] = [
                'id'        => 'po-partial-' . $row['po_id'],
                'date'      => substr((string) $row['updated_at'], 0, 10),
                'date_label' => Format::date(substr((string) $row['updated_at'], 0, 10)),
                'type'      => 'GRN',
                'reference' => (string) $row['po_no'],
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name' => $row['supplier_name_snapshot'],
                // Lines, not quantities: pieces and kilograms do not add up.
                'description' => 'Partly received — ' . (int) $row['complete_lines'] . ' of ' . (int) $row['line_count'] . ' lines complete',
                'value'     => $amount($row['total_amount']),
                'value_formatted' => $formatted($row['total_amount'], (string) $row['currency_code']),
                'status'    => 'Partial',
                'tone'      => 'warning',
                'route'     => '/purchase-orders/' . $row['po_id'],
                'rank'      => 600,
            ];
        }

        foreach ($this->rows(
            "SELECT p.po_id, p.po_no, p.supplier_name_snapshot, p.supplier_account_id, p.promised_date,
                    p.total_amount::text AS total_amount, p.currency_code,
                    COALESCE(p.issued_at::date, p.po_date) AS released_on,
                    (p.promised_date - CURRENT_DATE) AS days_to_go
             FROM purchase_orders p
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED')
               AND (p.promised_date IS NULL OR p.promised_date >= CURRENT_DATE)
               AND COALESCE(p.issued_at::date, p.po_date) BETWEEN :from AND :to" . $filterSql . '
             ORDER BY COALESCE(p.issued_at::date, p.po_date) DESC
             LIMIT 5',
            $this->period->params() + $filterParams,
            'p',
        ) as $row) {
            $toGo = $row['days_to_go'] === null ? null : (int) $row['days_to_go'];
            $rows[] = [
                'id'        => 'po-ok-' . $row['po_id'],
                'date'      => (string) $row['released_on'],
                'date_label' => Format::date((string) $row['released_on']),
                'type'      => 'PO',
                'reference' => (string) $row['po_no'],
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name' => $row['supplier_name_snapshot'],
                'description' => $toGo === null
                    ? 'Released — no promised date recorded'
                    : ($toGo === 0 ? 'On track — due today' : 'On track — delivery in ' . Format::days((string) $toGo)),
                'value'     => $amount($row['total_amount']),
                'value_formatted' => $formatted($row['total_amount'], (string) $row['currency_code']),
                'status'    => $toGo === null ? 'No date' : 'On track',
                'tone'      => $toGo === null ? 'neutral' : 'success',
                'route'     => '/purchase-orders/' . $row['po_id'],
                'rank'      => 200,
            ];
        }

        // Worst first, then most recent within a severity. A feed sorted purely
        // by time buries a week-old exception under this morning's routine.
        usort($rows, static function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $b['rank'] <=> $a['rank'];
            }

            return strcmp((string) $b['date'], (string) $a['date']);
        });

        $rows = array_slice($rows, 0, 12);
        foreach ($rows as $index => $row) {
            $rows[$index]['actions'] = $this->activityActions($row);
        }

        return $this->panel([
            'rows'  => $rows,
            'values_visible' => $money,
            'basis' => 'Late orders, failed three-way matches, requisitions waiting for an approver, partly received orders and orders released in ' . $this->period->label() . '. Ordered by what costs most to ignore, then by date.',
        ]);
    }

    /**
     * The row menu.
     *
     * Only things this application can actually do. A menu entry that opens
     * nothing is worse than no menu at all.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, string>>
     */
    private function activityActions(array $row): array
    {
        $actions = [['label' => 'Open the document', 'route' => (string) $row['route']]];

        if (($row['supplier_account_id'] ?? null) !== null && $this->can('supplier.view')) {
            $actions[] = [
                'label' => 'Supplier record',
                'route' => '/dashboard/suppliers?supplier_id=' . $row['supplier_account_id'],
            ];
        }

        if ($row['type'] === 'PO' && $row['status'] === 'Delayed') {
            $actions[] = ['label' => 'Chase this delivery', 'route' => '/dashboard/procurement?view=delayed'];
        }
        if ($row['type'] === 'PR' && $row['status'] === 'Pending approval') {
            $actions[] = ['label' => 'Open approvals', 'route' => '/approvals'];
        }
        if ($row['type'] === 'Invoice') {
            $actions[] = ['label' => 'Matching workbench', 'route' => '/dashboard/bills-payables?panel=matching'];
        }

        return $actions;
    }
}
