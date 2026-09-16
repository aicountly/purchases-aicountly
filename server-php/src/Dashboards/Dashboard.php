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
        $codes = $this->rows(
            'SELECT DISTINCT currency_code FROM purchase_orders
             WHERE {scope} AND po_date BETWEEN :from AND :to AND status <> \'CANCELLED\' LIMIT 5',
            $this->period->params(),
        );

        if ($codes === []) {
            return 'INR';
        }
        if (count($codes) > 1) {
            return null;
        }

        return (string) ($codes[0]['currency_code'] ?? 'INR');
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
