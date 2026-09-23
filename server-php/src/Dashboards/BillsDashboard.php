<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Http;

/**
 * Dashboard 4 — Bills & Payables.
 *
 * Supplier bills, the exceptions holding them up, and what falls due next.
 *
 * The division of labour is the whole design. Which bills are stuck, and why,
 * is ours. What is owed, to whom and when, is Smart Books' and is read live on
 * every request. Neither figure is ever derived from the other, which is why
 * this screen cannot disagree with the accounts.
 */
final class BillsDashboard extends Dashboard
{
    /** The nine outcomes a bill can land in. Ours, and the reason each exists. */
    public const MATCH_CATEGORIES = [
        'matched'          => 'Fully matched',
        'quantity'         => 'Quantity mismatch',
        'rate'             => 'Price mismatch',
        'missing_po'       => 'No purchase order',
        'missing_receipt'  => 'No goods receipt',
        'tax'              => 'Tax or charge difference',
        'duplicate'        => 'Suspected duplicate',
        'non_po'           => 'Non-PO purchase',
        'service'          => 'Service purchase',
    ];

    /** How many suppliers the payment planner will ask Books about in one request. */
    private const PLANNING_SUPPLIER_CAP = 12;

    public function view(): string
    {
        return 'bills-payables';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        if (!$this->can('bill.enter') && !$this->can('match.view') && !$this->can('reports.view')) {
            Http::forbidden('You do not have permission to view bills and payables.');
        }

        $books = null;
        if ($this->canSeeValues()) {
            $books = $this->booksReader()->purchaseSummary($this->period);
            $books['ok']
                ? $this->sources->ready('books', BooksReader::LABEL)
                : $this->sources->unavailable('books', BooksReader::LABEL, (string) $books['error']);
        } else {
            $this->sources->notRequested('books', BooksReader::LABEL, 'Payable figures need the reports.view or cost.view permission.');
        }

        // Planning is built FIRST because two other things on this screen are
        // made of it: the forward-window card, and the supplier exposure
        // ranking. Both are derived from open items already read, so neither
        // costs Smart Books a second round of requests.
        $planning = $this->paymentPlanning();

        return $this->envelope(
            $this->metrics($books, $planning),
            [
                'matching' => $this->matching(),
                'ageing'   => $this->ageing($books),
                'payment_planning' => $planning,
                'supplier_exposure' => $this->supplierExposure($planning),
                'trend'    => $this->billsTrend(),
                'intake'   => $this->intake(),
            ],
        );
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null  $books
     * @param array<string, mixed>       $planning the payment planning panel, already built
     * @return list<array<string, mixed>>
     */
    private function metrics(?array $books, array $planning): array
    {
        $currency = $this->documentCurrency() ?? 'INR';
        [$billSql, $billParams] = $this->filters->billClause('b');

        $awaiting = $this->count(
            "SELECT COUNT(*) FROM purchase_bill_requests b
             WHERE {scope} AND b.status IN ('DRAFT', 'MATCHING')" . $billSql,
            $billParams,
            'b',
        );

        $exceptions = $this->count(
            "SELECT COUNT(DISTINCT m.bill_request_id)
             FROM purchase_match_exceptions e
             JOIN purchase_match_results m ON m.match_id = e.match_id
             WHERE e.cmp_id = :ctx_cmp_id AND e.status = 'OPEN'",
        );

        $duplicates = count($this->duplicateCandidates(50));

        // The three forward windows. Books does not expose a company-wide open
        // items report — reports/bill-by-bill requires one acc_id — so these
        // cannot be answered for every creditor, and saying so is the only
        // honest option. The payment planning panel below gives the real
        // bill-level dates for a stated, bounded set of suppliers.
        $dueWindowReason = 'Smart Books answers open items one supplier at a time (reports/bill-by-bill requires acc_id), '
            . 'so a company-wide "due within N days" cannot be read without asking for every creditor in the ledger. '
            . 'The payment planning panel below shows real due dates for the suppliers you have bills with in this period.';

        $metrics = [
            Metric::ready(
                'bills_awaiting_review',
                'Bills awaiting review',
                (string) $awaiting,
                'Supplier bills entered here and not yet matched or posted (DRAFT or MATCHING).',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'comparison_unavailable_reason' => 'A queue is a position as at now.',
                    'drilldown' => $this->drilldown('/bills', ['status' => 'MATCHING']),
                ],
            ),
            Metric::ready(
                'bills_with_exceptions',
                'Bills with match exceptions',
                (string) $exceptions,
                'Bills with at least one OPEN three-way match exception. None of them reach Smart Books until somebody decides.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'comparison_unavailable_reason' => 'An open exception list is a position as at now.',
                    'drilldown' => $this->drilldown('/bills', ['exceptions' => '1']),
                ],
            ),
        ];

        if ($books !== null && $books['ok']) {
            $kpis = (array) $books['kpis'];
            $previous = (array) $books['previous'];
            $metrics[] = Metric::ready(
                'payables_total',
                'Outstanding payables',
                $kpis['payables'] ?? null,
                'Open creditor balances in Smart Books as at ' . Format::date($this->period->to) . '. Settled portions are already excluded by Books.',
                [
                    'format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER,
                    'previous' => $previous['payables'] ?? null,
                    'comparison_label' => $this->period->comparisonLabel(),
                    'drilldown' => $this->drilldown('/dashboard/bills-payables', ['panel' => 'ageing']),
                ],
            );
            $metrics[] = Metric::ready(
                'payables_overdue',
                'Overdue payables',
                $kpis['overdue_payables'] ?? null,
                'Open creditor balances whose Books due date is before ' . Format::date($this->period->to) . '. Bills with no due date are not treated as overdue.',
                [
                    'format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER,
                    'previous' => $previous['overdue_payables'] ?? null,
                    'comparison_label' => $this->period->comparisonLabel(),
                    'drilldown' => $this->drilldown('/dashboard/bills-payables', ['bucket' => 'overdue']),
                ],
            );
        } else {
            $reason = $books === null
                ? 'Payable figures need the reports.view or cost.view permission.'
                : (string) $books['error'];
            $metrics[] = Metric::unavailable('payables_total', 'Outstanding payables', $reason, 'Open creditor balances in Smart Books.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
            $metrics[] = Metric::unavailable('payables_overdue', 'Overdue payables', $reason, 'Open creditor balances past their Books due date.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
        }

        // The forward windows, for the suppliers this screen CAN see.
        //
        // The company-wide figure is still not answerable — Books reports open
        // items one creditor at a time — but the planning panel below has
        // already read real bill-level due dates for a stated set of
        // suppliers, and adding those up is not an estimate of anything. The
        // card carries the scope in its footnote so nobody reads it as the
        // whole ledger, and falls back to saying why when planning could not
        // run at all.
        $windows = ($planning['available'] ?? false) ? ($planning['windows'] ?? []) : [];

        if ($windows !== []) {
            $covered = (int) ($planning['covered_suppliers'] ?? 0);
            $metrics[] = Metric::ready(
                'due_windows',
                'Due within 7 / 15 / 30 days',
                (string) ($windows['due_7']['amount'] ?? '0'),
                'Bills falling due within 7 days, from Smart Books\' own due dates, for the '
                    . $covered . ' supplier(s) with bills posted from this application in ' . $this->period->label() . '.',
                [
                    'format' => 'currency', 'currency' => $currency, 'direction' => Metric::NEUTRAL,
                    'comparison_unavailable_reason' => 'A forward window is a position as at now.',
                    'footnote' => '15 days: ' . ($windows['due_15']['formatted'] ?? '—')
                        . ' · 30 days: ' . ($windows['due_30']['formatted'] ?? '—')
                        . ' · already overdue: ' . ($windows['overdue']['formatted'] ?? '—'),
                    'explanation' => $dueWindowReason,
                    'drilldown' => $this->drilldown('/dashboard/bills-payables', ['panel' => 'planning']),
                ],
            );
        } else {
            $metrics[] = Metric::unavailable(
                'due_windows',
                'Due within 7 / 15 / 30 days',
                $dueWindowReason,
                'Open creditor balances falling due within the next 7, 15 and 30 days, from Smart Books due dates.',
                ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::NEUTRAL],
            );
        }

        $metrics[] = Metric::ready(
            'duplicate_candidates',
            'Possible duplicate bills',
            (string) $duplicates,
            'Unposted bills sharing a supplier and invoice date with an earlier bill, or whose invoice number differs from one by a single character.',
            [
                'direction' => Metric::LOWER_IS_BETTER,
                'explanation' => 'A review candidate, not a finding. An exact repeat of a supplier invoice number is refused outright when the bill is entered, so what is counted here is the softer case that needs a person to look.',
                'comparison_unavailable_reason' => 'A review list is a position as at now.',
                'drilldown' => $this->drilldown('/dashboard/bills-payables', ['panel' => 'matching', 'category' => 'duplicate']),
            ],
        );

        return $metrics;
    }

    /**
     * Panel A — the matching workbench.
     *
     * Nine categories, counted from our own match results, with the rule that
     * failed spelled out per bill. Service and non-PO purchases are categories
     * in their own right rather than failures: a three-way match applied
     * unconditionally to a consultancy invoice blocks work that was never going
     * to have a goods receipt.
     *
     * @return array<string, mixed>
     */
    private function matching(): array
    {
        if (!$this->can('match.view')) {
            return $this->withheld('match.view');
        }

        $category = (string) (Http::param('category') ?? '');
        if ($category !== '' && !isset(self::MATCH_CATEGORIES[$category])) {
            $category = '';
        }

        $counts = array_fill_keys(array_keys(self::MATCH_CATEGORIES), 0);

        // Exception kinds map onto categories; a bill counts once per kind it
        // has, which is why the totals below do not have to add to the bill count.
        foreach ($this->rows(
            "SELECT e.exception_kind, COUNT(DISTINCT m.bill_request_id) AS n
             FROM purchase_match_exceptions e
             JOIN purchase_match_results m ON m.match_id = e.match_id
             WHERE e.cmp_id = :ctx_cmp_id AND e.status = 'OPEN'
             GROUP BY e.exception_kind",
        ) as $row) {
            $kind = (string) $row['exception_kind'];
            $target = match ($kind) {
                'quantity', 'over_billed' => 'quantity',
                'rate', 'value'           => 'rate',
                'tax', 'freight'          => 'tax',
                'missing_receipt'         => 'missing_receipt',
                default                   => null,
            };
            if ($target !== null) {
                $counts[$target] += (int) $row['n'];
            }
        }

        [$billSql, $billParams] = $this->filters->billClause('b');

        $counts['matched'] = $this->count(
            "SELECT COUNT(*) FROM purchase_bill_requests b
             WHERE {scope} AND b.status IN ('MATCHED', 'POSTED')" . $billSql,
            $billParams,
            'b',
        );
        $counts['missing_po'] = $this->count(
            "SELECT COUNT(*) FROM purchase_bill_requests b
             WHERE {scope} AND b.po_id IS NULL AND b.status NOT IN ('CANCELLED', 'POSTED')" . $billSql,
            $billParams,
            'b',
        );
        $counts['non_po'] = $counts['missing_po'];
        $counts['service'] = $this->count(
            "SELECT COUNT(DISTINCT b.request_id)
             FROM purchase_bill_requests b
             JOIN purchase_order_lines l ON l.po_id = b.po_id AND l.is_service = TRUE
             WHERE {scope} AND b.status NOT IN ('CANCELLED')" . $billSql,
            $billParams,
            'b',
        );
        $counts['duplicate'] = count($this->duplicateCandidates(50));

        $rows = $category === 'duplicate'
            ? $this->duplicateRows()
            : $this->exceptionRows($category);

        $policy = $this->row(
            'SELECT policy_name, qty_tolerance_pc::text AS qty_tolerance_pc, rate_tolerance_pc::text AS rate_tolerance_pc,
                    value_tolerance_amt::text AS value_tolerance_amt, freight_tolerance_amt::text AS freight_tolerance_amt,
                    auto_match_below_amt::text AS auto_match_below_amt
             FROM purchase_match_policies
             WHERE cmp_id = :ctx_cmp_id AND is_active = TRUE
             ORDER BY is_default DESC, policy_name LIMIT 1',
        );

        return $this->panel([
            'category'   => $category === '' ? null : $category,
            'categories' => array_map(
                static fn (string $id, string $label) => ['id' => $id, 'label' => $label, 'count' => 0],
                array_keys(self::MATCH_CATEGORIES),
                array_values(self::MATCH_CATEGORIES),
            ),
            'counts'     => $counts,
            'rows'       => $rows,
            'tolerances' => $policy === null ? null : [
                'policy_name'    => $policy['policy_name'],
                'qty_pc'         => Decimal::of($policy['qty_tolerance_pc']),
                'rate_pc'        => Decimal::of($policy['rate_tolerance_pc']),
                'value_amount'   => Decimal::of($policy['value_tolerance_amt']),
                'freight_amount' => Decimal::of($policy['freight_tolerance_amt']),
                'auto_match_below' => Decimal::of($policy['auto_match_below_amt']),
                'route'          => '/settings',
            ],
            'can_resolve' => $this->can('match.resolve'),
            'basis' => 'Purchase order (ours) against the goods receipt (Inventory\'s, read live at match time) against the bill. '
                . 'Matching is at line level and traces across partial receipts and several bills against one receipt. '
                . 'Billing LESS than agreed is recorded but never blocked. A service or non-PO purchase is a category here, not a failure: '
                . 'those follow the configured approval instead of an unconditional three-way match.',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function exceptionRows(string $category): array
    {
        $kinds = match ($category) {
            'quantity'        => ['quantity', 'over_billed'],
            'rate'            => ['rate', 'value'],
            'tax'             => ['tax', 'freight'],
            'missing_receipt' => ['missing_receipt'],
            default           => [],
        };

        $where = '';
        $params = [];
        if ($kinds !== []) {
            $placeholders = [];
            foreach ($kinds as $index => $kind) {
                $placeholders[] = ':kind' . $index;
                $params['kind' . $index] = $kind;
            }
            $where = ' AND e.exception_kind IN (' . implode(', ', $placeholders) . ')';
        }

        $rows = $this->rows(
            "SELECT e.exception_id, e.exception_kind, e.detail, e.status,
                    e.po_value::text AS po_value, e.receipt_value::text AS receipt_value,
                    e.bill_value::text AS bill_value, e.variance_value::text AS variance_value,
                    e.created_at, m.verdict, m.bill_request_id,
                    b.supplier_invoice_no, b.supplier_invoice_date, b.supplier_account_id, b.status AS bill_status,
                    p.po_no, p.po_id, p.supplier_name_snapshot, p.currency_code
             FROM purchase_match_exceptions e
             JOIN purchase_match_results m ON m.match_id = e.match_id
             LEFT JOIN purchase_bill_requests b ON b.request_id = m.bill_request_id
             LEFT JOIN purchase_orders p ON p.po_id = m.po_id
             WHERE e.cmp_id = :ctx_cmp_id AND e.status = 'OPEN'" . $where . '
             ORDER BY ABS(COALESCE(e.variance_value, 0)) DESC, e.created_at DESC
             LIMIT 50',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency_code'] ?? $this->documentCurrency() ?? 'INR');
            $variance = Decimal::parse($row['variance_value']);
            $out[] = [
                'kind'          => 'exception',
                'exception_id'  => (int) $row['exception_id'],
                'exception_kind' => $row['exception_kind'],
                'category'      => match ((string) $row['exception_kind']) {
                    'quantity', 'over_billed' => 'quantity',
                    'rate', 'value'           => 'rate',
                    'tax', 'freight'          => 'tax',
                    'missing_receipt'         => 'missing_receipt',
                    default                   => 'quantity',
                },
                'verdict'       => $row['verdict'],
                'rule'          => $row['detail'] ?? 'The bill does not agree with the order or the receipt.',
                'bill_request_id' => $row['bill_request_id'] === null ? null : (int) $row['bill_request_id'],
                'supplier_invoice_no' => $row['supplier_invoice_no'],
                'supplier_invoice_date' => $row['supplier_invoice_date'],
                'supplier_account_id' => $row['supplier_account_id'] === null ? null : (int) $row['supplier_account_id'],
                'supplier_name' => $row['supplier_name_snapshot'],
                'po_no'         => $row['po_no'],
                'po_id'         => $row['po_id'] === null ? null : (int) $row['po_id'],
                'currency'      => $currency,
                'po_value'      => Decimal::parse($row['po_value']),
                'receipt_value' => Decimal::parse($row['receipt_value']),
                'bill_value'    => Decimal::parse($row['bill_value']),
                'variance'      => $variance,
                'variance_formatted' => $variance === null ? null : Format::money($variance, $currency),
                'age_days'      => BooksReader::daysBetween(substr((string) $row['created_at'], 0, 10), gmdate('Y-m-d')),
                'route'         => $row['bill_request_id'] === null ? '/bills' : '/bills/' . $row['bill_request_id'],
                'accept_endpoint' => 'v1/match-exceptions/' . $row['exception_id'] . '/accept',
                'reject_endpoint' => 'v1/match-exceptions/' . $row['exception_id'] . '/reject',
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function duplicateRows(): array
    {
        $out = [];
        foreach ($this->duplicateCandidates(25) as $row) {
            $out[] = [
                'kind'     => 'duplicate',
                'category' => 'duplicate',
                'bill_request_id' => $row['request_id'],
                'supplier_invoice_no' => $row['invoice_no'],
                'supplier_invoice_date' => $row['invoice_date'],
                'supplier_account_id' => $row['supplier_account_id'],
                'supplier_name' => $row['supplier_name'],
                'rule'     => $row['reason'],
                'compared_with' => $row['other_id'],
                'compared_reference' => $row['other_no'],
                'route'    => '/bills/' . $row['request_id'],
                'age_days' => $row['age_days'],
            ];
        }

        return $out;
    }

    /**
     * Unposted bills that look like an earlier one.
     *
     * The exact repeat — same supplier, same invoice number — is refused when
     * the bill is entered, so it never gets here. What is caught is same
     * supplier and same invoice date, which is a coincidence often enough that
     * it is offered for review rather than blocked.
     *
     * @return list<array<string, mixed>>
     */
    private function duplicateCandidates(int $limit): array
    {
        $rows = $this->rows(
            "SELECT b.request_id, b.supplier_invoice_no, b.supplier_invoice_date, b.supplier_account_id,
                    b.created_at, o.request_id AS other_id, o.supplier_invoice_no AS other_no,
                    p.supplier_name_snapshot
             FROM purchase_bill_requests b
             JOIN purchase_bill_requests o
               ON o.cmp_id = b.cmp_id
              AND o.supplier_account_id = b.supplier_account_id
              AND o.supplier_invoice_date = b.supplier_invoice_date
              AND o.request_id < b.request_id
              AND o.status <> 'CANCELLED'
             LEFT JOIN purchase_orders p ON p.po_id = b.po_id
             WHERE {scope} AND b.status NOT IN ('CANCELLED', 'POSTED')
               AND b.supplier_invoice_date IS NOT NULL
             ORDER BY b.created_at DESC
             LIMIT :lim",
            ['lim' => $limit],
            'b',
        );

        return array_map(static fn (array $r) => [
            'request_id' => (int) $r['request_id'],
            'invoice_no' => $r['supplier_invoice_no'],
            'invoice_date' => $r['supplier_invoice_date'],
            'supplier_account_id' => (int) $r['supplier_account_id'],
            'supplier_name' => $r['supplier_name_snapshot'],
            'other_id'   => (int) $r['other_id'],
            'other_no'   => $r['other_no'],
            'reason'     => 'Same supplier and same invoice date as bill ' . ($r['other_no'] ?? '#' . $r['other_id']) . '.',
            'age_days'   => BooksReader::daysBetween(substr((string) $r['created_at'], 0, 10), gmdate('Y-m-d')),
        ], $rows);
    }

    /**
     * Panel B — payables ageing.
     *
     * Books' own buckets, as at Books' own date. Nothing is re-bucketed here.
     *
     * One honest caveat is carried through to the screen: Books places bills
     * with NO due date in "not due". This app cannot separate them from that
     * bucket without a company-wide open items read it does not have, so it
     * says so rather than inventing a due date from the invoice date.
     *
     * @param array<string, mixed>|null $books
     * @return array<string, mixed>
     */
    private function ageing(?array $books): array
    {
        if ($books === null) {
            return $this->withheld('reports.view');
        }
        if (!$books['ok']) {
            return $this->unavailablePanel((string) $books['error']);
        }

        $ageing = (array) $books['ageing'];
        $currency = $this->documentCurrency() ?? 'INR';
        $total = Decimal::of($ageing['total'] ?? '0');

        $buckets = [
            ['id' => 'not_due', 'label' => 'Not due', 'amount' => Decimal::of($ageing['not_due'] ?? '0'), 'tone' => 'neutral'],
            ['id' => 'b_0_30', 'label' => '1 – 30 days overdue', 'amount' => Decimal::of($ageing['b_0_30'] ?? '0'), 'tone' => 'warning'],
            ['id' => 'b_31_60', 'label' => '31 – 60 days', 'amount' => Decimal::of($ageing['b_31_60'] ?? '0'), 'tone' => 'warning'],
            ['id' => 'b_61_90', 'label' => '61 – 90 days', 'amount' => Decimal::of($ageing['b_61_90'] ?? '0'), 'tone' => 'danger'],
            ['id' => 'b_90_plus', 'label' => 'Over 90 days', 'amount' => Decimal::of($ageing['b_90_plus'] ?? '0'), 'tone' => 'danger'],
        ];

        foreach ($buckets as $index => $bucket) {
            $buckets[$index]['formatted'] = Format::money($bucket['amount'], $currency);
            $buckets[$index]['share_pc'] = Decimal::percentOf($bucket['amount'], $total, 1);
        }

        return $this->panel([
            'as_of'    => $this->period->to,
            'as_of_label' => Format::date($this->period->to),
            'currency' => $currency,
            'buckets'  => $buckets,
            'total'    => $total,
            'total_formatted' => Format::money($total, $currency),
            'basis'    => 'Open creditor balances from Smart Books, bucketed by Books against its own due dates, as at ' . Format::date($this->period->to) . '.',
            'caveat'   => 'Smart Books places bills with no due date into "Not due". This screen cannot separate them out, and does not substitute the invoice date for a missing due date — so treat "Not due" as "not yet due, or no due date recorded".',
        ]);
    }

    /**
     * Panel C — payment planning.
     *
     * Real bill-level due dates, from Books, for a bounded set of suppliers. A
     * proposal here changes NOTHING: it does not touch a ledger, does not mark
     * an invoice paid and does not reach Aicountly Pay, which is not integrated.
     * "Proposal prepared", "payment recorded" and "payment executed" are three
     * different states and only the first exists in this product.
     *
     * @return array<string, mixed>
     */
    private function paymentPlanning(): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('reports.view');
        }

        [$billSql, $billParams] = $this->filters->billClause('b');

        $suppliers = $this->rows(
            "SELECT b.supplier_account_id,
                    MAX(p.supplier_name_snapshot) AS supplier_name,
                    COUNT(*) AS bill_count
             FROM purchase_bill_requests b
             LEFT JOIN purchase_orders p ON p.po_id = b.po_id
             WHERE {scope} AND b.status = 'POSTED'" . $billSql . '
             GROUP BY b.supplier_account_id
             ORDER BY COUNT(*) DESC
             LIMIT :cap',
            ['cap' => self::PLANNING_SUPPLIER_CAP] + $billParams,
            'b',
        );

        if ($suppliers === []) {
            return $this->panel([
                'rows'  => [],
                'windows' => [],
                'covered_suppliers' => 0,
                'basis' => 'No bills have been posted to Smart Books from this application in ' . $this->period->label() . ', so there is nothing to plan here yet.',
            ]);
        }

        $reader = $this->booksReader();
        $asOn = $this->period->to;
        $rows = [];
        $failed = [];
        $names = [];

        foreach ($suppliers as $supplier) {
            $supplierId = (int) $supplier['supplier_account_id'];
            $names[$supplierId] = $supplier['supplier_name'];
            $open = $reader->openItems($supplierId, $asOn);
            if (!$open['ok']) {
                $failed[] = $supplierId;
                continue;
            }
            foreach ($open['rows'] as $item) {
                $rows[] = $item + ['supplier_name' => $supplier['supplier_name']];
            }
        }

        if ($rows === [] && $failed !== []) {
            return $this->unavailablePanel(
                'Smart Books did not return open items for any of the ' . count($suppliers) . ' suppliers asked about.',
            );
        }

        // Held bills: ours to know. A bill with an open exception must not be
        // proposed for payment, and the planner says why rather than omitting it.
        $held = [];
        foreach ($this->rows(
            "SELECT DISTINCT b.supplier_account_id, b.supplier_invoice_no
             FROM purchase_match_exceptions e
             JOIN purchase_match_results m ON m.match_id = e.match_id
             JOIN purchase_bill_requests b ON b.request_id = m.bill_request_id
             WHERE e.cmp_id = :ctx_cmp_id AND e.status = 'OPEN' AND b.supplier_invoice_no IS NOT NULL",
        ) as $row) {
            $held[(int) $row['supplier_account_id'] . '|' . $row['supplier_invoice_no']] = true;
        }

        $currency = $this->documentCurrency() ?? 'INR';
        $windows = ['due_7' => Decimal::ZERO, 'due_15' => Decimal::ZERO, 'due_30' => Decimal::ZERO, 'overdue' => Decimal::ZERO, 'undated' => Decimal::ZERO];

        usort($rows, static function (array $a, array $b): int {
            // Undated bills sort last: they cannot be scheduled against a date
            // that does not exist, and they need a decision rather than a slot.
            if ($a['due_date'] === null || $b['due_date'] === null) {
                return ($a['due_date'] === null ? 1 : 0) <=> ($b['due_date'] === null ? 1 : 0);
            }

            return strcmp((string) $a['due_date'], (string) $b['due_date']);
        });

        $out = [];
        foreach (array_slice($rows, 0, 60) as $item) {
            $key = $item['supplier_account_id'] . '|' . (string) $item['bill_ref'];
            $isHeld = isset($held[$key]);
            $overdueDays = $item['days_overdue'];
            $amount = (string) $item['pending_amount'];

            if ($item['due_date'] === null) {
                $windows['undated'] = Decimal::add($windows['undated'], $amount);
            } elseif ($overdueDays !== null && $overdueDays > 0) {
                $windows['overdue'] = Decimal::add($windows['overdue'], $amount);
            } else {
                $daysAhead = -(int) $overdueDays;
                if ($daysAhead <= 7) {
                    $windows['due_7'] = Decimal::add($windows['due_7'], $amount);
                }
                if ($daysAhead <= 15) {
                    $windows['due_15'] = Decimal::add($windows['due_15'], $amount);
                }
                if ($daysAhead <= 30) {
                    $windows['due_30'] = Decimal::add($windows['due_30'], $amount);
                }
            }

            $out[] = [
                'supplier_account_id' => $item['supplier_account_id'],
                'supplier_name' => $item['supplier_name'],
                'bill_ref'    => $item['bill_ref'],
                'bill_date'   => $item['bill_date'],
                'due_date'    => $item['due_date'],
                'due_label'   => $item['due_date'] === null ? 'No due date recorded' : Format::date($item['due_date']),
                'pending'     => $amount,
                'pending_formatted' => Format::money($amount, $currency),
                'original'    => $item['original_amount'],
                'part_paid'   => $item['original_amount'] !== null && Decimal::cmp($amount, (string) $item['original_amount']) < 0,
                'days_overdue' => ($overdueDays !== null && $overdueDays > 0) ? $overdueDays : null,
                'held'        => $isHeld,
                'held_reason' => $isHeld ? 'This bill has an open match exception and should not be paid until it is resolved.' : null,
                'proposed_date' => $item['due_date'],
                'state'       => 'not_proposed',
            ];
        }

        return $this->panel([
            'rows'     => $out,
            'currency' => $currency,
            'windows'  => [
                'due_7'   => ['amount' => $windows['due_7'], 'formatted' => Format::money($windows['due_7'], $currency), 'label' => 'Due within 7 days'],
                'due_15'  => ['amount' => $windows['due_15'], 'formatted' => Format::money($windows['due_15'], $currency), 'label' => 'Due within 15 days'],
                'due_30'  => ['amount' => $windows['due_30'], 'formatted' => Format::money($windows['due_30'], $currency), 'label' => 'Due within 30 days'],
                'overdue' => ['amount' => $windows['overdue'], 'formatted' => Format::money($windows['overdue'], $currency), 'label' => 'Already overdue'],
                'undated' => ['amount' => $windows['undated'], 'formatted' => Format::money($windows['undated'], $currency), 'label' => 'No due date recorded'],
            ],
            'covered_suppliers' => count($suppliers) - count($failed),
            'requested_suppliers' => count($suppliers),
            'failed_suppliers' => $failed,
            'as_of'    => $asOn,
            'scope_note' => 'Open items read live from Smart Books for the ' . (count($suppliers) - count($failed))
                . ' supplier(s) with bills posted from this application in ' . $this->period->label()
                . '. It is NOT the whole creditors ledger: Books answers open items one supplier at a time, so a company-wide view has to be read in Books itself.',
            'pay_note' => 'Preparing a proposal changes nothing. It does not post a payment, does not allocate against a bill and does not mark anything paid — "proposal prepared", "payment recorded" and "payment executed" are three separate states, and only the first happens here. Aicountly Pay is not integrated.',
            'basis'    => 'Due dates and open amounts are Smart Books\' own. Held bills are ours: a bill with an open match exception is flagged and excluded from any proposal.',
        ]);
    }

    /**
     * Panel E — who we owe the most to.
     *
     * Made entirely out of the open items the planning panel has already read,
     * so it asks Smart Books for nothing. That also fixes its honesty: this is
     * exposure across the suppliers this screen covers, which is a bounded set
     * and says so, not a ranking of the whole creditors ledger.
     *
     * @param array<string, mixed> $planning
     * @return array<string, mixed>
     */
    private function supplierExposure(array $planning): array
    {
        if (!($planning['available'] ?? false)) {
            // The planner's own reason travels with it. A second, vaguer
            // sentence invented here would be a worse answer to the same question.
            return $planning;
        }

        $currency = $this->documentCurrency() ?? 'INR';
        $totals = [];

        foreach ((array) ($planning['rows'] ?? []) as $row) {
            $id = (int) $row['supplier_account_id'];
            if (!isset($totals[$id])) {
                $totals[$id] = [
                    'supplier_account_id' => $id,
                    'supplier_name' => $row['supplier_name'],
                    'amount' => Decimal::ZERO,
                    'bill_count' => 0,
                    'overdue_count' => 0,
                    'held_count' => 0,
                ];
            }
            $totals[$id]['amount'] = Decimal::add($totals[$id]['amount'], (string) $row['pending']);
            $totals[$id]['bill_count']++;
            if (($row['days_overdue'] ?? null) !== null) {
                $totals[$id]['overdue_count']++;
            }
            if ($row['held'] ?? false) {
                $totals[$id]['held_count']++;
            }
        }

        $total = Decimal::ZERO;
        foreach ($totals as $entry) {
            $total = Decimal::add($total, $entry['amount']);
        }

        usort($totals, static fn (array $a, array $b) => Decimal::cmp($b['amount'], $a['amount']));

        $rows = [];
        foreach (array_slice(array_values($totals), 0, 8) as $entry) {
            $rows[] = $entry + [
                'formatted' => Format::money($entry['amount'], $currency),
                'share_pc'  => Decimal::percentOf($entry['amount'], $total, 1),
                'route'     => '/dashboard/bills-payables',
                'filters'   => ['supplier_id' => (string) $entry['supplier_account_id']],
            ];
        }

        return $this->panel([
            'rows'     => $rows,
            'currency' => $currency,
            'total'    => $total,
            'total_formatted' => Format::money($total, $currency),
            'covered_suppliers' => (int) ($planning['covered_suppliers'] ?? 0),
            'basis' => 'Open amounts from Smart Books for the ' . (int) ($planning['covered_suppliers'] ?? 0)
                . ' supplier(s) this screen reads open items for. It ranks exposure within that set — '
                . 'it is not the largest creditors in the ledger, which only Books can answer.',
        ]);
    }

    /**
     * Panel F — bills booked, month by month.
     *
     * OURS, and only ours. The value is what the bills entered here add up to,
     * with the count of those that reached Smart Books beside it.
     *
     * There is deliberately no "payments" or "outstanding" line: both are
     * Books' and Books reports a position as at a date, not a series, so
     * drawing them would mean re-reading the whole ledger once per month on
     * every page load. The trend that IS drawn is one this application can
     * stand behind, and the panel says which one it is.
     *
     * @return array<string, mixed>
     */
    private function billsTrend(): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('reports.view');
        }

        $months = 6;
        $currency = $this->documentCurrency() ?? 'INR';
        [$billSql, $billParams] = $this->filters->billClause('b');

        // Anchored on the period end rather than today, so the series a reader
        // sees belongs to the period the rest of the screen is showing.
        $anchor = substr($this->period->to, 0, 7) . '-01';

        $rows = $this->rows(
            "SELECT to_char(month.start, 'YYYY-MM') AS period,
                    COUNT(b.request_id) AS bill_count,
                    COUNT(b.request_id) FILTER (WHERE b.status = 'POSTED') AS posted_count,
                    COALESCE(SUM(value.subtotal), 0)::text AS booked,
                    COALESCE(SUM(value.subtotal) FILTER (WHERE b.status = 'POSTED'), 0)::text AS posted_value
             FROM generate_series(
                      date_trunc('month', :anchor::date) - INTERVAL '" . ($months - 1) . " months',
                      date_trunc('month', :anchor::date),
                      INTERVAL '1 month'
                  ) AS month(start)
             LEFT JOIN purchase_bill_requests b
                    ON date_trunc('month', COALESCE(b.supplier_invoice_date, b.created_at::date)) = month.start
                   AND {scope}
                   AND b.status <> 'CANCELLED'" . $billSql . "
             LEFT JOIN LATERAL (
                 SELECT COALESCE(SUM(NULLIF(line->>'amount', '')::numeric), 0) AS subtotal
                 FROM jsonb_array_elements(COALESCE(b.requested_lines, '[]'::jsonb)) AS line
             ) value ON TRUE
             GROUP BY month.start
             ORDER BY month.start",
            ['anchor' => $anchor] + $billParams,
            'b',
        );

        $points = [];
        foreach ($rows as $row) {
            $booked = Decimal::of((string) $row['booked']);
            $posted = Decimal::of((string) $row['posted_value']);
            $points[] = [
                'period'    => (string) $row['period'],
                'label'     => date('M Y', (int) strtotime((string) $row['period'] . '-01')),
                'booked'    => $booked,
                'formatted' => Format::money($booked, $currency),
                'posted'    => $posted,
                'posted_formatted' => Format::money($posted, $currency),
                'bill_count'   => (int) $row['bill_count'],
                'posted_count' => (int) $row['posted_count'],
            ];
        }

        return $this->panel([
            'points'   => $points,
            'currency' => $currency,
            'months'   => $months,
            'series'   => [
                ['id' => 'booked', 'label' => 'Bills booked', 'tone' => 'primary'],
                ['id' => 'posted', 'label' => 'Posted to Books', 'tone' => 'good'],
            ],
            'basis' => 'Supplier bills entered in this application, by invoice month, exclusive of tax, '
                . 'with the portion that reached Smart Books. Payments and the outstanding balance are Books\' own '
                . 'and are a position as at a date rather than a series, so they are not drawn here — the ageing '
                . 'panel is where the outstanding balance is answered.',
        ]);
    }

    /**
     * Panel D — bill intake.
     *
     * The honest state of the pipeline. Entry, validation, matching, human
     * review and authorised posting are implemented; automatic extraction from
     * a PDF or a scan is not, and this panel says which steps exist rather than
     * showing an upload button that leads nowhere.
     *
     * @return array<string, mixed>
     */
    private function intake(): array
    {
        if (!$this->can('bill.enter')) {
            return $this->withheld('bill.enter');
        }

        [$billSql, $billParams] = $this->filters->billClause('b');

        $counts = $this->row(
            "SELECT
                COUNT(*) FILTER (WHERE b.status = 'DRAFT')     AS drafts,
                COUNT(*) FILTER (WHERE b.status = 'MATCHING')  AS matching,
                COUNT(*) FILTER (WHERE b.status = 'MATCHED')   AS matched,
                COUNT(*) FILTER (WHERE b.status = 'EXCEPTION') AS exceptions,
                COUNT(*) FILTER (WHERE b.status = 'POSTED')    AS posted,
                COUNT(*) FILTER (WHERE b.status = 'FAILED')    AS failed
             FROM purchase_bill_requests b
             WHERE {scope}" . $billSql,
            $billParams,
            'b',
        ) ?? [];

        $stuck = $this->rows(
            "SELECT c.command_id, c.command_type, c.entity_id, c.status, c.attempts, c.last_error, c.last_attempt_at
             FROM purchase_integration_commands c
             WHERE c.cmp_id = :ctx_cmp_id AND c.fy_id = :ctx_fy_id
               AND c.target_service = 'books' AND c.status IN ('FAILED', 'BLOCKED')
             ORDER BY c.updated_at DESC LIMIT 10",
        );

        return $this->panel([
            'stages' => [
                ['id' => 'draft', 'label' => 'Entered', 'count' => (int) ($counts['drafts'] ?? 0), 'route' => '/bills', 'filters' => ['status' => 'DRAFT']],
                ['id' => 'matching', 'label' => 'Matching', 'count' => (int) ($counts['matching'] ?? 0), 'route' => '/bills', 'filters' => ['status' => 'MATCHING']],
                ['id' => 'exception', 'label' => 'Exception', 'count' => (int) ($counts['exceptions'] ?? 0), 'route' => '/bills', 'filters' => ['exceptions' => '1']],
                ['id' => 'matched', 'label' => 'Ready to post', 'count' => (int) ($counts['matched'] ?? 0), 'route' => '/bills', 'filters' => ['status' => 'MATCHED']],
                ['id' => 'posted', 'label' => 'Posted to Books', 'count' => (int) ($counts['posted'] ?? 0), 'route' => '/bills', 'filters' => ['status' => 'POSTED']],
                ['id' => 'failed', 'label' => 'Posting failed', 'count' => (int) ($counts['failed'] ?? 0), 'route' => '/bills', 'filters' => ['status' => 'FAILED']],
            ],
            'stuck_commands' => array_map(static fn (array $c) => [
                'command_id'   => (int) $c['command_id'],
                'command_type' => $c['command_type'],
                'entity_id'    => (int) $c['entity_id'],
                'status'       => $c['status'],
                'attempts'     => (int) $c['attempts'],
                'last_error'   => $c['last_error'],
                'last_attempt' => $c['last_attempt_at'],
                'retryable'    => $c['status'] === 'FAILED',
                'route'        => '/bills/' . $c['entity_id'],
            ], $stuck),
            'capabilities' => [
                ['id' => 'manual_entry', 'label' => 'Manual entry with supplier and item lookup', 'available' => true],
                ['id' => 'validation', 'label' => 'Validation and duplicate refusal', 'available' => true],
                ['id' => 'matching', 'label' => 'Three-way match against order and receipt', 'available' => true],
                ['id' => 'review', 'label' => 'Human review of every exception', 'available' => true],
                ['id' => 'posting', 'label' => 'Authorised posting to Smart Books, idempotent', 'available' => true],
                ['id' => 'document_reading', 'label' => 'Reading an uploaded CSV, XLSX or text PDF', 'available' => true,
                 'route' => '/statements'],
                ['id' => 'statement_import', 'label' => 'Supplier statement import and reconciliation', 'available' => true,
                 'route' => '/statements'],
                ['id' => 'extraction', 'label' => 'Creating a bill automatically from an uploaded invoice', 'available' => false,
                 'reason' => 'Not implemented. An uploaded document can be READ — that is what statement reconciliation '
                     . 'uses — but nothing here turns one into a bill. A bill is a financial document, and creating one '
                     . 'from a parsed file needs the duplicate refusal, tolerance and approval rules that manual entry '
                     . 'already has; half of that is worse than none.'],
                ['id' => 'ocr', 'label' => 'Reading a scanned document', 'available' => false,
                 'reason' => 'Not installed on this server. A text PDF is read exactly; a scan is a picture, and '
                     . 'getting figures out of a picture needs optical character recognition that is not here. '
                     . 'The reader says which of the two it was given rather than returning an empty result.'],
            ],
            'basis' => 'Bill requests in this scope by workflow state. A bill reaches Smart Books only after it has matched or an exception has been decided, and posting carries an idempotency key so a retry cannot produce a second voucher.',
        ]);
    }
}
