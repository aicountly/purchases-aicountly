<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Permissions;

/**
 * Shared machinery for the five purchase dashboards.
 *
 * Each subclass answers one question for one audience and composes its own
 * panels; what is shared is the envelope — scope, period, source status and the
 * metric contract — so that the five screens cannot drift into five different
 * ideas of what a comparison or an unavailable figure means.
 *
 * PERMISSIONS ARE CHECKED HERE, per panel, before the query runs. A panel the
 * user may not see is absent from the response with a stated reason, not
 * fetched and then hidden in React.
 */
abstract class Dashboard
{
    protected Sources $sources;

    /** @var array<string, string> */
    protected array $scopeParams;

    protected string $scopeSql;

    private ?string $currency = null;

    private bool $currencyResolved = false;

    public function __construct(
        protected readonly Context $ctx,
        protected readonly Auth $auth,
        protected readonly Period $period,
        protected readonly Filters $filters,
    ) {
        $this->sources = new Sources();
        [$this->scopeSql, $this->scopeParams] = $ctx->scopeClause();
    }

    abstract public function view(): string;

    /** @return array<string, mixed> */
    abstract public function build(): array;

    protected function can(string $permission): bool
    {
        return Permissions::allows($this->ctx, $this->auth, $permission);
    }

    /** The money amounts on this dashboard are hidden from a user without cost.view. */
    protected function canSeeValues(): bool
    {
        return $this->can('cost.view') || $this->can('reports.view');
    }

    protected function booksReader(): BooksReader
    {
        return new BooksReader($this->ctx, $this->auth->sesKey());
    }

    protected function inventoryReader(): InventoryReader
    {
        return new InventoryReader($this->ctx, $this->auth->sesKey());
    }

    /**
     * A scoped query over one of our own tables.
     *
     * `{scope}` expands to the company / FY / branch clause, so no query in a
     * dashboard can forget it — the placeholder is the only way to write one.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function rows(string $sql, array $params = [], string $alias = ''): array
    {
        return Db::all(...$this->prepare($sql, $params, $alias));
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    protected function row(string $sql, array $params = [], string $alias = ''): ?array
    {
        return Db::first(...$this->prepare($sql, $params, $alias));
    }

    /** @param array<string, mixed> $params */
    protected function value(string $sql, array $params = [], string $alias = ''): mixed
    {
        return Db::scalar(...$this->prepare($sql, $params, $alias));
    }

    /** A COUNT(*) as an int. @param array<string, mixed> $params */
    protected function count(string $sql, array $params = [], string $alias = ''): int
    {
        return (int) $this->value($sql, $params, $alias);
    }

    /** A SUM cast to text in SQL, returned as an exact decimal string. @param array<string, mixed> $params */
    protected function amount(string $sql, array $params = [], string $alias = ''): string
    {
        return Decimal::of($this->value($sql, $params, $alias));
    }

    /**
     * Expand `{scope}` and bind only what the finished SQL actually names.
     *
     * PDO refuses a statement given a parameter it has no placeholder for, so a
     * query that scopes by company alone must not be handed the financial year
     * binding as well. Filtering here keeps every caller free to write the
     * clause it needs.
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function prepare(string $sql, array $params, string $alias): array
    {
        [$scope, $scopeParams] = $this->ctx->scopeClause($alias);
        $expanded = str_replace('{scope}', $scope, $sql);

        foreach ($scopeParams as $name => $value) {
            if (str_contains($expanded, ':' . $name)) {
                $params[$name] = $value;
            }
        }

        return [$expanded, $params];
    }

    /**
     * The response every dashboard returns.
     *
     * @param list<array<string, mixed>>   $metrics
     * @param array<string, mixed>         $panels
     * @param array<string, mixed>         $extra
     * @return array<string, mixed>
     */
    protected function envelope(array $metrics, array $panels, array $extra = []): array
    {
        return [
            'view'         => $this->view(),
            'scope'        => [
                'company_id'        => $this->ctx->cmpId,
                'financial_year_id' => $this->ctx->fyId,
                'branch_id'         => $this->ctx->boId,
                'branch_label'      => $this->ctx->boId > 0 ? 'Branch ' . $this->ctx->boId : 'All branches',
                'timezone'          => $this->period->timezone,
                // Currency is not assumed. Where every document in scope shares
                // one, it is named; where they do not, the panels say so rather
                // than adding rupees to dollars.
                'reporting_currency' => $this->documentCurrency(),
            ],
            'period'       => $this->period->toArray(),
            'filters'      => $this->filters->toArray(),
            'generated_at' => gmdate('c'),
            'sources'      => $this->sources->toArray(),
            'metrics'      => array_values($metrics),
            'panels'       => $panels,
        ] + $extra;
    }

    /**
     * The one currency in scope, or null when there is more than one.
     *
     * Null is the signal that totals must not be combined. Silently summing two
     * currencies is the sort of error that survives for months because the
     * number still looks plausible.
     */
    protected function documentCurrency(): ?string
    {
        // Memoised: every panel asks, and the answer cannot change inside one
        // request. Left un-memoised this was a dozen identical queries per
        // dashboard, all of them scanning the same orders.
        if ($this->currencyResolved) {
            return $this->currency;
        }

        $codes = $this->rows(
            'SELECT DISTINCT currency_code FROM purchase_orders
             WHERE {scope} AND po_date BETWEEN :from AND :to AND status <> \'CANCELLED\' LIMIT 5',
            $this->period->params(),
        );

        $this->currencyResolved = true;
        $this->currency = match (true) {
            $codes === []       => 'INR',
            count($codes) > 1   => null,
            default             => (string) ($codes[0]['currency_code'] ?? 'INR'),
        };

        return $this->currency;
    }

    /**
     * One supplier-performance read, shared by every dashboard that scores a
     * supplier.
     *
     * On-time is judged per RECEIPT against the order's promised date, and
     * orders still overdue with nothing received are counted separately so they
     * cannot vanish from the picture by never producing a receipt at all.
     *
     * Bounded to the hundred suppliers with the largest ordered value in the
     * period. A company with more than that has a supplier list, not a
     * dashboard panel, and the Suppliers screen is where it belongs.
     *
     * @return list<array<string, mixed>>
     */
    protected function supplierPerformance(): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');
        $params = $this->period->params() + $filterParams;

        return $this->rows(
            "WITH orders AS (
                SELECT p.supplier_account_id,
                       COUNT(*)                                            AS po_count,
                       COUNT(*) FILTER (WHERE p.acknowledged_at IS NOT NULL) AS acknowledged,
                       COALESCE(SUM(p.total_amount), 0)                    AS ordered_value,
                       AVG(EXTRACT(EPOCH FROM (p.acknowledged_at - p.issued_at)) / 3600) AS ack_hours,
                       MAX(p.supplier_name_snapshot)                       AS supplier_name,
                       MAX(p.currency_code)                                AS currency_code,
                       COUNT(DISTINCT p.currency_code)                     AS currency_count
                FROM purchase_orders p
                WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $filterSql . "
                GROUP BY p.supplier_account_id
            ),
            lines AS (
                SELECT p.supplier_account_id,
                       COUNT(*)                                             AS line_count,
                       COUNT(*) FILTER (WHERE l.received_qty >= l.ordered_qty) AS complete_lines,
                       COUNT(*) FILTER (WHERE l.received_qty > 0)            AS inspected_lines,
                       COUNT(*) FILTER (WHERE l.rejected_qty > 0)            AS rejected_lines,
                       COUNT(*) FILTER (WHERE l.ordered_qty > l.received_qty
                                        AND COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE
                                        AND p.status IN ('ISSUED','ACKNOWLEDGED','PARTIALLY_RECEIVED')) AS overdue_lines,
                       COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0) AS open_exposure
                FROM purchase_order_lines l
                JOIN purchase_orders p ON p.po_id = l.po_id
                WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $filterSql . "
                GROUP BY p.supplier_account_id
            ),
            receipts AS (
                SELECT p.supplier_account_id,
                       COUNT(*)                                                  AS receipt_count,
                       COUNT(*) FILTER (WHERE r.received_at <= p.promised_date)   AS on_time_count,
                       AVG(r.received_at - p.po_date)                             AS avg_lead_days
                FROM purchase_receipt_requests r
                JOIN purchase_orders p ON p.po_id = r.po_id
                WHERE {scope} AND r.status = 'ACCEPTED' AND p.promised_date IS NOT NULL
                  AND r.received_at BETWEEN :from AND :to" . $filterSql . "
                GROUP BY p.supplier_account_id
            ),
            claims AS (
                SELECT supplier_account_id, COUNT(*) AS claim_count,
                       COUNT(*) FILTER (WHERE status NOT IN ('SETTLED','CLOSED','REJECTED')) AS open_claims
                FROM purchase_claims
                WHERE cmp_id = :ctx_cmp_id AND fy_id = :ctx_fy_id AND claim_date BETWEEN :from AND :to
                GROUP BY supplier_account_id
            )
            SELECT o.supplier_account_id,
                   o.supplier_name, o.currency_code, o.currency_count,
                   o.po_count, o.acknowledged, o.ordered_value::text AS ordered_value, o.ack_hours,
                   COALESCE(l.line_count, 0)      AS line_count,
                   COALESCE(l.complete_lines, 0)  AS complete_lines,
                   COALESCE(l.inspected_lines, 0) AS inspected_lines,
                   COALESCE(l.rejected_lines, 0)  AS rejected_lines,
                   COALESCE(l.overdue_lines, 0)   AS overdue_lines,
                   COALESCE(l.open_exposure, 0)::text AS open_exposure,
                   COALESCE(r.receipt_count, 0)   AS receipt_count,
                   COALESCE(r.on_time_count, 0)   AS on_time_count,
                   r.avg_lead_days,
                   COALESCE(c.claim_count, 0)     AS claim_count,
                   COALESCE(c.open_claims, 0)     AS open_claims,
                   s.qualification_status, s.is_preferred, s.operational_lead_days, s.payment_terms, s.risk_flag
            FROM orders o
            LEFT JOIN lines    l ON l.supplier_account_id = o.supplier_account_id
            LEFT JOIN receipts r ON r.supplier_account_id = o.supplier_account_id
            LEFT JOIN claims   c ON c.supplier_account_id = o.supplier_account_id
            LEFT JOIN purchase_supplier_profiles s
                   ON s.cmp_id = :ctx_cmp_id AND s.supplier_account_id = o.supplier_account_id
            ORDER BY o.ordered_value DESC
            LIMIT 100",
            $params,
            'p',
        );
    }

    /**
     * Payables ageing, as Books buckets it.
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
    protected function payablesAgeing(?array $books): array
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
            // The short form is for the label above the bar; the exact figure
            // is the one beside it and in the table under the chart.
            $buckets[$index]['compact'] = Format::compactMoney($bucket['amount'], $currency);
            $buckets[$index]['share_pc'] = Decimal::percentOf($bucket['amount'], $total, 1);
            $buckets[$index]['route'] = '/dashboard/bills-payables';
            $buckets[$index]['filters'] = ['bucket' => (string) $bucket['id']];
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

    /** A panel the user may not open, stated rather than silently missing. @return array<string, mixed> */
    protected function withheld(string $permission): array
    {
        return [
            'available' => false,
            'reason'    => 'You do not have permission to see this (' . $permission . ').',
            'kind'      => 'permission',
        ];
    }

    /** A panel whose upstream did not answer. @return array<string, mixed> */
    protected function unavailablePanel(string $reason): array
    {
        return ['available' => false, 'reason' => $reason, 'kind' => 'source'];
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    protected function panel(array $body): array
    {
        return ['available' => true] + $body;
    }

    /**
     * A drill-down target: a route in this app plus the filters that reproduce
     * the figure. Every KPI that can be opened carries one.
     *
     * @param array<string, string> $filters
     * @return array{route: string, filters: array<string, string>}
     */
    protected function drilldown(string $route, array $filters = []): array
    {
        return [
            'route'   => $route,
            'filters' => $filters + $this->filters->drilldown(),
        ];
    }
}
