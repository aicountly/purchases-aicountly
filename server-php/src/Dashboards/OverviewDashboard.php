<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Db;
use Aicountly\Api\Permissions;

/**
 * Dashboard 1 — Overview.
 *
 * For the owner or purchase head at nine in the morning: what needs a decision
 * today, what is stuck, and what did we actually spend.
 *
 * Two sources, kept apart on purpose. The posted money figures are Books' and
 * disappear honestly when Books cannot be reached; the workflow figures are
 * ours and keep working when it cannot. A screen where those two degrade
 * together is a screen that stops being useful for the half of it that was fine.
 */
final class OverviewDashboard extends Dashboard
{
    public function view(): string
    {
        return 'overview';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $books = null;
        if ($this->canSeeValues()) {
            $books = $this->booksReader()->purchaseSummary($this->period);
            $books['ok']
                ? $this->sources->ready('books', BooksReader::LABEL)
                : $this->sources->unavailable('books', BooksReader::LABEL, (string) $books['error']);
        } else {
            $this->sources->notRequested('books', BooksReader::LABEL, 'Posted purchase values need the reports.view or cost.view permission.');
        }

        $commitment = $this->openCommitment();
        $delayed = $this->delayedOrders();
        $myApprovals = $this->myApprovalQueue();
        $pipeline = $this->pipeline();

        return $this->envelope(
            $this->metrics($books, $commitment, $delayed, $myApprovals),
            [
                'briefing'      => $this->briefing($books, $commitment, $delayed, $myApprovals, $pipeline),
                'trend'         => $this->trend($books),
                'pipeline'      => $pipeline,
                'priority_inbox' => $this->priorityInbox(),
                'concentration' => $this->concentration($books),
                'quick_actions' => $this->quickActions(),
            ],
        );
    }

    // -----------------------------------------------------------------------
    // Metrics
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $books
     * @param array<string, string>     $commitment
     * @param array<string, mixed>      $delayed
     * @return list<array<string, mixed>>
     */
    private function metrics(?array $books, array $commitment, array $delayed, int $myApprovals): array
    {
        $currency = $this->documentCurrency() ?? 'INR';
        $comparisonLabel = $this->period->comparisonLabel();

        $netBasis = 'Posted purchase vouchers less posted debit notes for ' . $this->period->label()
            . ', inclusive of tax, on the accounting date. Smart Books is the authority for this figure.';

        if ($books === null) {
            $net = Metric::unavailable('net_purchases', 'Net posted purchases', 'Posted purchase values need the reports.view or cost.view permission.', $netBasis, ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::NEUTRAL]);
            $dues = Metric::unavailable('supplier_dues', 'Outstanding supplier dues', 'Payable figures need the reports.view or cost.view permission.', 'Open creditor balances in Smart Books.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
            $overdue = Metric::unavailable('overdue_dues', 'Overdue supplier dues', 'Payable figures need the reports.view or cost.view permission.', 'Open creditor balances past their due date in Smart Books.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
        } elseif (!$books['ok']) {
            $reason = (string) $books['error'];
            $net = Metric::unavailable('net_purchases', 'Net posted purchases', $reason, $netBasis, ['format' => 'currency', 'currency' => $currency]);
            $dues = Metric::unavailable('supplier_dues', 'Outstanding supplier dues', $reason, 'Open creditor balances in Smart Books, as at ' . Format::date($this->period->to) . '.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
            $overdue = Metric::unavailable('overdue_dues', 'Overdue supplier dues', $reason, 'Open creditor balances past their due date in Smart Books.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
        } else {
            $kpis = (array) $books['kpis'];
            $previous = (array) $books['previous'];

            $net = Metric::ready(
                'net_purchases',
                'Net posted purchases',
                $kpis['total_purchases'] ?? null,
                $netBasis,
                [
                    'format'           => 'currency',
                    'currency'         => $currency,
                    'direction'        => Metric::NEUTRAL,
                    'previous'         => $previous['total_purchases'] ?? null,
                    'comparison_label' => $comparisonLabel,
                    'explanation'      => $netBasis . ' Purchase returns of '
                        . Format::money($kpis['purchase_returns'] ?? '0', $currency) . ' are already deducted.',
                    'drilldown'        => $this->drilldown('/bills', ['status' => 'POSTED']),
                    'footnote'         => 'Includes tax. Input GST in the period: ' . Format::money($kpis['input_gst'] ?? '0', $currency) . '.',
                ],
            );

            $dues = Metric::ready(
                'supplier_dues',
                'Outstanding supplier dues',
                $kpis['payables'] ?? null,
                'Open creditor balances in Smart Books as at ' . Format::date($this->period->to) . '. Settled portions are excluded by Books.',
                [
                    'format'    => 'currency',
                    'currency'  => $currency,
                    'direction' => Metric::LOWER_IS_BETTER,
                    'previous'  => $previous['payables'] ?? null,
                    'comparison_label' => $comparisonLabel,
                    'drilldown' => $this->drilldown('/dashboard/bills-payables'),
                ],
            );

            $overdue = Metric::ready(
                'overdue_dues',
                'Overdue supplier dues',
                $kpis['overdue_payables'] ?? null,
                'Open creditor balances whose Books due date is before ' . Format::date($this->period->to) . '. Bills with no due date are not counted as overdue.',
                [
                    'format'    => 'currency',
                    'currency'  => $currency,
                    'direction' => Metric::LOWER_IS_BETTER,
                    'previous'  => $previous['overdue_payables'] ?? null,
                    'comparison_label' => $comparisonLabel,
                    'drilldown' => $this->drilldown('/dashboard/bills-payables', ['bucket' => 'overdue']),
                ],
            );
        }

        return [
            $net,
            Metric::ready(
                'open_commitment',
                'Open order commitment',
                $this->canSeeValues() ? $commitment['value'] : null,
                'Remaining quantity × agreed rate on purchase orders that are issued, acknowledged or partly received, plus their unspent freight and charges. Excludes cancelled and closed orders.',
                [
                    'format'    => 'currency',
                    'currency'  => $currency,
                    'direction' => Metric::NEUTRAL,
                    'explanation' => 'What this company has committed to buy and not yet received. Tax is excluded: the tax actually charged is decided when the bill is entered.',
                    'comparison_unavailable_reason' => 'Commitment is a position as at today, not a figure for a period, so there is no previous period to compare it with.',
                    'drilldown' => $this->drilldown('/purchase-orders', ['status' => 'open']),
                    'footnote'  => $commitment['order_count'] . ' open order' . ($commitment['order_count'] === 1 ? '' : 's') . '.',
                ],
            ),
            $dues,
            $overdue,
            Metric::ready(
                'delayed_orders',
                'Orders with delayed quantities',
                (string) $delayed['count'],
                'Orders whose promised date has passed and whose ordered quantity has not been fully received, as at ' . Format::date(gmdate('Y-m-d')) . '.',
                [
                    'format'    => 'count',
                    'direction' => Metric::LOWER_IS_BETTER,
                    'explanation' => 'Counted per order. The still-unreceived quantity stays visible here rather than dropping out of the picture when a line is partly delivered.',
                    'comparison_unavailable_reason' => 'Delay is measured against today, so a previous-period comparison would not mean the same thing.',
                    'drilldown' => $this->drilldown('/purchase-orders', ['view' => 'delayed']),
                    'footnote'  => $delayed['lines'] . ' delayed line' . ($delayed['lines'] === 1 ? '' : 's') . ' across them.',
                ],
            ),
            Metric::ready(
                'my_approvals',
                'Awaiting your approval',
                (string) $myApprovals,
                'Pending approval requests whose required permission you hold, excluding anything you raised yourself.',
                [
                    'format'    => 'count',
                    'direction' => Metric::LOWER_IS_BETTER,
                    'explanation' => 'Segregation of duties is applied here as well as on the approve button: a document you raised never appears in your own queue.',
                    'comparison_unavailable_reason' => 'A queue is a position, not a period total.',
                    'drilldown' => $this->drilldown('/approvals'),
                ],
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Our own figures
    // -----------------------------------------------------------------------

    /** @return array{value: string, order_count: int} */
    private function openCommitment(): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $row = $this->row(
            "SELECT
                COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text AS value,
                COUNT(DISTINCT p.po_id)                                                            AS order_count
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope}
               AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               " . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        return [
            'value'       => Decimal::of($row['value'] ?? '0'),
            'order_count' => (int) ($row['order_count'] ?? 0),
        ];
    }

    /** @return array{count: int, lines: int} */
    private function delayedOrders(): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $row = $this->row(
            "SELECT COUNT(DISTINCT p.po_id) AS orders, COUNT(*) AS lines
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope}
               AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE
               " . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        return ['count' => (int) ($row['orders'] ?? 0), 'lines' => (int) ($row['lines'] ?? 0)];
    }

    /**
     * How many pending approvals are actually THIS user's to decide.
     *
     * A count of every pending approval in the company would be a number the
     * reader can do nothing about. Two narrowings make it actionable: the
     * permission the stage requires, and the rule that nobody approves their own.
     */
    private function myApprovalQueue(): int
    {
        $granted = Permissions::granted($this->ctx, $this->auth);
        if ($granted === []) {
            return 0;
        }

        $placeholders = [];
        $params = ['me' => $this->auth->uuid];
        foreach (array_values($granted) as $index => $permission) {
            $placeholders[] = ':perm' . $index;
            $params['perm' . $index] = $permission;
        }

        return $this->count(
            'SELECT COUNT(*) FROM purchase_approval_requests
             WHERE cmp_id = :ctx_cmp_id AND fy_id = :ctx_fy_id
               AND status = \'PENDING\'
               AND requested_by <> :me
               AND (required_permission IS NULL OR required_permission IN (' . implode(', ', $placeholders) . '))',
            $params,
        );
    }

    // -----------------------------------------------------------------------
    // Panels
    // -----------------------------------------------------------------------

    /**
     * Panel A — the briefing.
     *
     * Deterministic rules over the figures already on this page, ranked by the
     * money or the delay at stake. It is labelled as rules-based, because an
     * unlabelled list of "insights" invites the reader to assume a model looked
     * at their data when nothing of the sort happened.
     *
     * @param array<string, mixed>|null $books
     * @param array<string, string>     $commitment
     * @param array<string, mixed>      $delayed
     * @param array<string, mixed>      $pipeline
     * @return array<string, mixed>
     */
    private function briefing(?array $books, array $commitment, array $delayed, int $myApprovals, array $pipeline): array
    {
        $items = InsightRules::briefing(
            $this->ctx,
            $this->period,
            [
                'books'        => $books,
                'commitment'   => $commitment,
                'delayed'      => $delayed,
                'my_approvals' => $myApprovals,
                'pipeline'     => $pipeline,
                'currency'     => $this->documentCurrency() ?? 'INR',
            ],
        );

        return $this->panel([
            'method'      => 'rules',
            'method_label' => 'Rules-based briefing — no AI model was consulted.',
            'items'       => array_slice($items, 0, 5),
            'as_of'       => gmdate('c'),
        ]);
    }

    /**
     * Panel B — the trend.
     *
     * @param array<string, mixed>|null $books
     * @return array<string, mixed>
     */
    private function trend(?array $books): array
    {
        if ($books === null) {
            return $this->withheld('reports.view');
        }
        if (!$books['ok']) {
            return $this->unavailablePanel((string) $books['error']);
        }

        $points = (array) $books['trend'];
        $total = Decimal::sum(array_map(static fn ($p) => (string) $p['amount'], $points));

        return $this->panel([
            'granularity' => 'day',
            'currency'    => $this->documentCurrency() ?? 'INR',
            'points'      => $points,
            'total'       => $total,
            'basis'       => 'Net posted purchases per day from Smart Books, on the accounting date, for ' . $this->period->label() . '.',
            'comparison'  => [
                'label'    => $this->period->comparisonLabel(),
                'previous' => ((array) $books['previous'])['total_purchases'] ?? null,
                'current'  => ((array) $books['kpis'])['total_purchases'] ?? null,
            ],
        ]);
    }

    /**
     * Panel C — the pipeline, requisition to posted bill.
     *
     * Six counts, each a click away from the rows behind it. They are stages of
     * our own workflow and are unaffected by Books or Inventory being down.
     *
     * @return array<string, mixed>
     */
    private function pipeline(): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');
        [$reqSql, $reqParams] = $this->filters->requisitionClause('r');

        $requisitions = $this->count(
            "SELECT COUNT(*) FROM purchase_requisitions r
             WHERE {scope} AND r.status IN ('SUBMITTED', 'APPROVAL_PENDING')" . $reqSql,
            $reqParams,
            'r',
        );

        $quotes = $this->count(
            "SELECT COUNT(*) FROM purchase_rfqs r
             WHERE {scope} AND r.status IN ('ISSUED', 'RESPONSES_OPEN', 'EVALUATING')",
            [],
            'r',
        );

        $awaitingDispatch = $this->count(
            "SELECT COUNT(*) FROM purchase_orders p
             WHERE {scope} AND p.status IN ('APPROVED', 'ISSUED')" . $filterSql,
            $filterParams,
            'p',
        );

        $partiallyReceived = $this->count(
            "SELECT COUNT(*) FROM purchase_orders p
             WHERE {scope} AND p.status = 'PARTIALLY_RECEIVED'" . $filterSql,
            $filterParams,
            'p',
        );

        $awaitingMatch = $this->count(
            "SELECT COUNT(*) FROM purchase_bill_requests b
             WHERE {scope} AND b.status IN ('DRAFT', 'MATCHING', 'EXCEPTION')",
            [],
            'b',
        );

        $postingExceptions = $this->count(
            "SELECT COUNT(*) FROM purchase_integration_commands c
             WHERE c.cmp_id = :ctx_cmp_id AND c.fy_id = :ctx_fy_id AND c.status IN ('FAILED', 'BLOCKED')",
        );

        return $this->panel([
            'basis'  => 'Live counts from this application\'s own workflow. Unaffected by Smart Books or Inventory availability.',
            'stages' => [
                ['id' => 'requisitions', 'label' => 'Requisition review', 'count' => $requisitions, 'route' => '/requisitions', 'filters' => ['status' => 'APPROVAL_PENDING']],
                ['id' => 'quotes', 'label' => 'Quotation evaluation', 'count' => $quotes, 'route' => '/rfqs', 'filters' => []],
                ['id' => 'dispatch', 'label' => 'Orders awaiting dispatch', 'count' => $awaitingDispatch, 'route' => '/purchase-orders', 'filters' => ['status' => 'ISSUED']],
                ['id' => 'partial', 'label' => 'Partly received', 'count' => $partiallyReceived, 'route' => '/purchase-orders', 'filters' => ['status' => 'PARTIALLY_RECEIVED']],
                ['id' => 'matching', 'label' => 'Bills awaiting matching', 'count' => $awaitingMatch, 'route' => '/bills', 'filters' => ['status' => 'MATCHING']],
                ['id' => 'posting', 'label' => 'Posting exceptions', 'count' => $postingExceptions, 'route' => '/approvals', 'filters' => ['tab' => 'commands']],
            ],
        ]);
    }

    /**
     * Panel D — the priority inbox.
     *
     * @return array<string, mixed>
     */
    private function priorityInbox(): array
    {
        $items = [];

        foreach ($this->rows(
            "SELECT a.approval_id, a.entity_type, a.entity_id, a.reason_kind, a.reason_detail,
                    a.actual_value::text AS actual_value, a.created_at,
                    r.requisition_no, p.po_no, p.supplier_name_snapshot
             FROM purchase_approval_requests a
             LEFT JOIN purchase_requisitions r ON a.entity_type = 'requisition'    AND r.requisition_id = a.entity_id
             LEFT JOIN purchase_orders       p ON a.entity_type = 'purchase_order' AND p.po_id          = a.entity_id
             WHERE a.cmp_id = :ctx_cmp_id AND a.fy_id = :ctx_fy_id AND a.status = 'PENDING'
               AND a.requested_by <> :me
             ORDER BY a.actual_value DESC NULLS LAST, a.created_at ASC
             LIMIT 5",
            ['me' => $this->auth->uuid],
        ) as $row) {
            $reference = $row['po_no'] ?? $row['requisition_no'] ?? ('#' . $row['entity_id']);
            $items[] = [
                'id'        => 'approval-' . $row['approval_id'],
                'kind'      => 'approval',
                'severity'  => 'warning',
                'severity_label' => 'Approval',
                'title'     => $reference . ' needs your approval',
                'detail'    => (string) ($row['reason_detail'] ?? $row['reason_kind']),
                'amount'    => Decimal::parse($row['actual_value'] ?? null),
                'source'    => 'Purchases',
                'age_days'  => BooksReader::daysBetween(substr((string) $row['created_at'], 0, 10), gmdate('Y-m-d')),
                'route'     => '/approvals',
                'filters'   => [],
                'action_label' => 'Review',
            ];
        }

        foreach ($this->rows(
            "SELECT p.po_id, p.po_no, p.supplier_name_snapshot, p.promised_date,
                    SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate)::text AS remaining_value
             FROM purchase_orders p
             JOIN purchase_order_lines l ON l.po_id = p.po_id
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND p.promised_date < CURRENT_DATE AND l.ordered_qty > l.received_qty
             GROUP BY p.po_id, p.po_no, p.supplier_name_snapshot, p.promised_date
             ORDER BY p.promised_date ASC
             LIMIT 5",
            [],
            'p',
        ) as $row) {
            $late = BooksReader::daysBetween((string) $row['promised_date'], gmdate('Y-m-d'));
            $items[] = [
                'id'       => 'late-' . $row['po_id'],
                'kind'     => 'delivery',
                'severity' => $late > 14 ? 'danger' : 'warning',
                'severity_label' => 'Late delivery',
                'title'    => $row['po_no'] . ' is ' . Format::days((string) $late) . ' past its promised date',
                'detail'   => ($row['supplier_name_snapshot'] ?? 'This supplier') . ' promised ' . Format::date((string) $row['promised_date']) . ' and part of the order has not arrived.',
                'amount'   => Decimal::parse($row['remaining_value'] ?? null),
                'source'   => 'Purchases',
                'age_days' => $late,
                'route'    => '/purchase-orders/' . $row['po_id'],
                'filters'  => [],
                'action_label' => 'Open order',
            ];
        }

        if ($this->can('match.view')) {
            foreach ($this->rows(
                "SELECT e.exception_id, e.exception_kind, e.detail, e.variance_value::text AS variance_value,
                        e.created_at, b.supplier_invoice_no, b.request_id
                 FROM purchase_match_exceptions e
                 JOIN purchase_match_results m ON m.match_id = e.match_id
                 LEFT JOIN purchase_bill_requests b ON b.request_id = m.bill_request_id
                 WHERE e.cmp_id = :ctx_cmp_id AND e.status = 'OPEN'
                 ORDER BY ABS(COALESCE(e.variance_value, 0)) DESC
                 LIMIT 5",
            ) as $row) {
                $items[] = [
                    'id'       => 'exception-' . $row['exception_id'],
                    'kind'     => 'match',
                    'severity' => 'danger',
                    'severity_label' => 'Match exception',
                    'title'    => 'Bill ' . ($row['supplier_invoice_no'] ?? '#' . $row['request_id']) . ' failed the ' . str_replace('_', ' ', (string) $row['exception_kind']) . ' check',
                    'detail'   => (string) ($row['detail'] ?? 'The bill does not agree with the order or the receipt.'),
                    'amount'   => Decimal::parse($row['variance_value'] ?? null),
                    'source'   => 'Purchases',
                    'age_days' => BooksReader::daysBetween(substr((string) $row['created_at'], 0, 10), gmdate('Y-m-d')),
                    'route'    => '/bills/' . $row['request_id'],
                    'filters'  => [],
                    'action_label' => 'Resolve',
                ];
            }
        }

        foreach ($this->duplicateCandidates(3) as $candidate) {
            $items[] = $candidate;
        }

        // Rank by money at stake, then by age. An inbox in insertion order is an
        // inbox nobody reads to the bottom of.
        usort($items, static function (array $a, array $b): int {
            $amountA = $a['amount'] ?? null;
            $amountB = $b['amount'] ?? null;
            if ($amountA !== null && $amountB !== null) {
                $cmp = Decimal::cmp($amountB, $amountA);
                if ($cmp !== 0) {
                    return $cmp;
                }
            } elseif ($amountA !== $amountB) {
                return $amountA === null ? 1 : -1;
            }

            return ($b['age_days'] ?? 0) <=> ($a['age_days'] ?? 0);
        });

        return $this->panel([
            'items'    => array_slice($items, 0, 12),
            'currency' => $this->documentCurrency() ?? 'INR',
            'basis'    => 'Approvals you can decide, late deliveries, open match exceptions and possible duplicate bills, ranked by the amount at stake.',
        ]);
    }

    /**
     * Bills that look like one another.
     *
     * Same supplier and same invoice number is a hard duplicate and the bill
     * service already refuses it. What is caught here is the softer case — same
     * supplier, same date, same total, different reference — which is a review
     * candidate and is worded as one.
     *
     * @return list<array<string, mixed>>
     */
    private function duplicateCandidates(int $limit): array
    {
        $rows = $this->rows(
            "SELECT b.request_id, b.supplier_invoice_no, b.supplier_account_id, b.supplier_invoice_date,
                    b.created_at, o.request_id AS other_id, o.supplier_invoice_no AS other_no
             FROM purchase_bill_requests b
             JOIN purchase_bill_requests o
               ON o.cmp_id = b.cmp_id
              AND o.supplier_account_id = b.supplier_account_id
              AND o.supplier_invoice_date = b.supplier_invoice_date
              AND o.request_id < b.request_id
              AND o.status <> 'CANCELLED'
             WHERE {scope} AND b.status NOT IN ('CANCELLED', 'POSTED')
               AND b.supplier_invoice_date IS NOT NULL
             ORDER BY b.created_at DESC
             LIMIT :lim",
            ['lim' => $limit],
            'b',
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'       => 'duplicate-' . $row['request_id'],
                'kind'     => 'duplicate',
                'severity' => 'warning',
                'severity_label' => 'Possible duplicate',
                'title'    => 'Bill ' . ($row['supplier_invoice_no'] ?? '#' . $row['request_id']) . ' may duplicate ' . ($row['other_no'] ?? '#' . $row['other_id']),
                'detail'   => 'Same supplier and same invoice date as an earlier bill. This is a review candidate, not a finding.',
                'amount'   => null,
                'source'   => 'Purchases',
                'age_days' => BooksReader::daysBetween(substr((string) $row['created_at'], 0, 10), gmdate('Y-m-d')),
                'route'    => '/bills/' . $row['request_id'],
                'filters'  => [],
                'action_label' => 'Compare',
            ];
        }

        return $out;
    }

    /**
     * Panel E — supplier concentration.
     *
     * The denominator is stated, because a share of an unstated base is not a
     * share of anything. Where Books is reachable the base is posted purchases;
     * where it is not, the base is our own ordered value and the panel says so
     * rather than quietly changing what the percentage means.
     *
     * @param array<string, mixed>|null $books
     * @return array<string, mixed>
     */
    private function concentration(?array $books): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('cost.view');
        }

        $currency = $this->documentCurrency();
        if ($currency === null) {
            return $this->unavailablePanel(
                'Orders in this period are in more than one currency. A share of a mixed-currency total would not mean anything, so no concentration is shown. Filter to one currency to see it.',
            );
        }

        if ($books !== null && $books['ok'] && $books['top_suppliers'] !== []) {
            $rows = (array) $books['top_suppliers'];
            $base = Decimal::of(((array) $books['kpis'])['total_purchases'] ?? '0');
            $basis = 'Share of net posted purchases from Smart Books for ' . $this->period->label() . '.';
            $source = 'books';
        } else {
            [$filterSql, $filterParams] = $this->filters->orderClause('p');
            $rows = array_map(
                static fn (array $r) => [
                    'supplier_account_id' => (int) $r['supplier_account_id'],
                    'supplier_name'       => $r['supplier_name_snapshot'] ?? null,
                    'amount'              => Decimal::of($r['amount'] ?? '0'),
                    'order_count'         => (int) $r['order_count'],
                ],
                $this->rows(
                    "SELECT p.supplier_account_id, p.supplier_name_snapshot,
                            SUM(p.total_amount)::text AS amount, COUNT(*) AS order_count
                     FROM purchase_orders p
                     WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $filterSql . "
                     GROUP BY p.supplier_account_id, p.supplier_name_snapshot
                     ORDER BY SUM(p.total_amount) DESC
                     LIMIT 6",
                    $this->period->params() + $filterParams,
                    'p',
                ),
            );
            $base = $this->amount(
                "SELECT COALESCE(SUM(p.total_amount), 0)::text FROM purchase_orders p
                 WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $filterSql,
                $this->period->params() + $filterParams,
                'p',
            );
            $basis = 'Share of ORDERED value raised in ' . $this->period->label()
                . ' — Smart Books was not available, so posted purchases could not be used as the base.';
            $source = 'purchases';
        }

        $items = [];
        $named = Decimal::ZERO;
        foreach ($rows as $row) {
            $amount = Decimal::of($row['amount'] ?? '0');
            $named = Decimal::add($named, $amount);
            $items[] = [
                'supplier_account_id' => $row['supplier_account_id'] ?? null,
                'supplier_name'       => $row['supplier_name'] ?? null,
                'amount'              => $amount,
                'formatted_amount'    => Format::money($amount, $currency),
                'share_pc'            => Decimal::percentOf($amount, $base, 1),
                'order_count'         => $row['order_count'] ?? null,
            ];
        }

        $others = Decimal::sub($base, $named);

        return $this->panel([
            'basis'         => $basis,
            'base_source'   => $source,
            'base_amount'   => $base,
            'base_formatted' => Format::money($base, $currency),
            'currency'      => $currency,
            'suppliers'     => $items,
            'others'        => [
                'amount'    => Decimal::isNegative($others) ? Decimal::ZERO : $others,
                'share_pc'  => Decimal::isNegative($others) ? '0' : Decimal::percentOf($others, $base, 1),
            ],
        ]);
    }

    /** Panel F — the four things a buyer starts here. @return array<string, mixed> */
    private function quickActions(): array
    {
        $actions = [];

        if ($this->can('requisition.create')) {
            $actions[] = ['id' => 'new-requisition', 'label' => 'New requisition', 'route' => '/requisitions/new', 'tone' => 'secondary'];
        }
        if ($this->can('po.create')) {
            $actions[] = ['id' => 'new-po', 'label' => 'New purchase order', 'route' => '/purchase-orders/new', 'tone' => 'primary'];
        }
        if ($this->can('bill.enter')) {
            $actions[] = ['id' => 'new-bill', 'label' => 'Enter supplier bill', 'route' => '/bills/new', 'tone' => 'secondary'];
        }
        $actions[] = ['id' => 'approvals', 'label' => 'Review approvals', 'route' => '/approvals', 'tone' => 'secondary'];

        return $this->panel(['actions' => $actions]);
    }
}
