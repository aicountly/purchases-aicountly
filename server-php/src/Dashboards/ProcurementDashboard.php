<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

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
    public const WORKBENCH_VIEWS = ['needs_action', 'awaiting_approval', 'open_orders', 'expected', 'delayed', 'partially_received'];

    /** @return array<string, mixed> */
    public function build(): array
    {
        if (!$this->can('po.view') && !$this->can('requisition.view')) {
            Http::forbidden('You do not have permission to view procurement.');
        }

        $horizonDays = max(1, min(90, Http::intParam('horizon', 7) ?? 7));

        return $this->envelope(
            $this->metrics($horizonDays),
            [
                'workbench'      => $this->workbench(),
                'delivery_timeline' => $this->deliveryTimeline($horizonDays),
                'reorder'        => $this->reorder(),
                'quote_comparison' => $this->quoteComparison(),
                'approval_inbox' => $this->approvalInbox(),
            ],
            ['horizon_days' => $horizonDays],
        );
    }

    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function metrics(int $horizonDays): array
    {
        $currency = $this->documentCurrency() ?? 'INR';
        [$filterSql, $filterParams] = $this->filters->orderClause('p');
        [$reqSql, $reqParams] = $this->filters->requisitionClause('r');

        $requisitions = $this->count(
            "SELECT COUNT(*) FROM purchase_requisitions r
             WHERE {scope} AND r.status IN ('SUBMITTED', 'APPROVAL_PENDING')" . $reqSql,
            $reqParams,
            'r',
        );

        $ordersPending = $this->count(
            "SELECT COUNT(*) FROM purchase_orders p
             WHERE {scope} AND p.status = 'APPROVAL_PENDING'" . $filterSql,
            $filterParams,
            'p',
        );

        $commitment = $this->row(
            "SELECT COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text AS value,
                    COUNT(DISTINCT p.po_id) AS orders
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')" . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        $due = $this->count(
            "SELECT COUNT(DISTINCT p.po_id)
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) BETWEEN CURRENT_DATE AND CURRENT_DATE + CAST(:horizon AS INT)" . $filterSql,
            ['horizon' => $horizonDays] + $filterParams,
            'p',
        );

        $overdue = $this->row(
            "SELECT COUNT(DISTINCT p.po_id) AS orders,
                    COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text AS value
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE" . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        // Approved demand nobody has ordered: requisition lines approved and
        // not yet fully turned into purchase order lines.
        $unordered = $this->row(
            "SELECT COUNT(*) AS lines, COUNT(DISTINCT r.requisition_id) AS requisitions
             FROM purchase_requisition_lines l
             JOIN purchase_requisitions r ON r.requisition_id = l.requisition_id
             WHERE {scope} AND r.status IN ('APPROVED', 'SOURCING')
               AND l.required_qty > l.ordered_qty" . $reqSql,
            $reqParams,
            'r',
        ) ?? [];

        return [
            Metric::ready(
                'requisitions_awaiting',
                'Requisitions awaiting action',
                (string) $requisitions,
                'Requisitions in SUBMITTED or APPROVAL_PENDING, in this company, financial year and branch.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'comparison_unavailable_reason' => 'A queue is a position as at now, not a period total.',
                    'drilldown' => $this->drilldown('/requisitions', ['status' => 'APPROVAL_PENDING']),
                ],
            ),
            Metric::ready(
                'orders_pending_approval',
                'Orders pending approval',
                (string) $ordersPending,
                'Purchase orders in APPROVAL_PENDING. The approver is decided by the approval rules, not by this count.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'comparison_unavailable_reason' => 'A queue is a position as at now, not a period total.',
                    'drilldown' => $this->drilldown('/purchase-orders', ['status' => 'APPROVAL_PENDING']),
                ],
            ),
            Metric::ready(
                'open_commitment',
                'Open order commitment',
                $this->canSeeValues() ? Decimal::of($commitment['value'] ?? '0') : null,
                'Remaining quantity × agreed rate on issued, acknowledged and partly received orders. Tax excluded — the tax charged is settled when the bill arrives.',
                [
                    'format'    => 'currency',
                    'currency'  => $currency,
                    'direction' => Metric::NEUTRAL,
                    'comparison_unavailable_reason' => 'Commitment is a position, not a figure for a period.',
                    'drilldown' => $this->drilldown('/dashboard/procurement', ['view' => 'open_orders']),
                    'footnote'  => (int) ($commitment['orders'] ?? 0) . ' open orders.',
                ],
            ),
            Metric::ready(
                'due_in_horizon',
                'Deliveries due in ' . Format::days((string) $horizonDays),
                (string) $due,
                'Orders with an unreceived quantity whose line promised date — or the order\'s, where the line has none — falls between today and ' . Format::days((string) $horizonDays) . ' from now.',
                [
                    'direction' => Metric::NEUTRAL,
                    'comparison_unavailable_reason' => 'A forward window has no equivalent previous window to compare with.',
                    'drilldown' => $this->drilldown('/dashboard/procurement', ['view' => 'expected']),
                ],
            ),
            Metric::ready(
                'overdue_orders',
                'Orders with overdue quantities',
                (string) (int) ($overdue['orders'] ?? 0),
                'Orders whose promised date has passed with quantity still unreceived. Counted per order; the remaining value is in the footnote.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'comparison_unavailable_reason' => 'Lateness is measured against today.',
                    'drilldown' => $this->drilldown('/dashboard/procurement', ['view' => 'delayed']),
                    'footnote'  => $this->canSeeValues()
                        ? Format::money(Decimal::of($overdue['value'] ?? '0'), $currency) . ' still to arrive.'
                        : null,
                ],
            ),
            Metric::ready(
                'approved_not_ordered',
                'Approved demand not yet ordered',
                (string) (int) ($unordered['lines'] ?? 0),
                'Requisition lines on approved or sourcing requisitions where the required quantity exceeds what has been put on a purchase order.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'explanation' => 'Counted per line rather than per requisition, because a requisition is usually part-ordered rather than ignored entirely.',
                    'comparison_unavailable_reason' => 'Outstanding demand is a position as at now.',
                    'drilldown' => $this->drilldown('/requisitions', ['status' => 'APPROVED']),
                    'footnote'  => 'Across ' . (int) ($unordered['requisitions'] ?? 0) . ' requisitions.',
                ],
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
}
