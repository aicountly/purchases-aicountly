<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Ai\AskEngine;
use Aicountly\Api\Http;

/**
 * Dashboard 5 — Purchase intelligence.
 *
 * Decision support that can be checked. Three kinds of statement live here and
 * they are never mixed:
 *
 *   OBLIGATION  what is already committed. A fact with a document behind it.
 *   FORECAST    arithmetic over history, with the method and the window stated.
 *   COMMENTARY  what a model said. Only present when one is configured, always
 *               labelled, and never the source of a figure.
 *
 * With no model configured, the first two still work and the screen says so.
 * "AI insights are currently unavailable" is a statement about the commentary,
 * not an empty page.
 *
 * WHAT THIS SCREEN WILL NOT DO. It will not put a probability on a rule. An
 * opportunity found by counting order lines is reported with the count, not
 * with "94% confident": a percentage invented to decorate a deterministic rule
 * is the one number on the screen nobody could ever reproduce.
 */
final class InsightsDashboard extends Dashboard
{
    /** Months of history a spend forecast needs before it will state one. */
    private const FORECAST_MIN_MONTHS = 4;

    /** Items whose group is fetched from Inventory for the category split. */
    private const CATEGORY_ITEM_CAP = 120;

    public function view(): string
    {
        return 'ai-insights';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $currency = $this->documentCurrency() ?? 'INR';
        $ai = AiClient::status();
        if (!$this->can('settings.manage')) {
            $ai['admin_hint'] = null;
        }

        $opportunities = $this->can('cost.view') || $this->can('reports.view')
            ? InsightRules::opportunities($this->ctx, $this->period, $currency)
            : [];
        $anomalies = InsightRules::anomalies($this->ctx, $this->period, $currency);
        $priceAnomalies = $this->canSeeValues()
            ? InsightRules::priceAnomalies($this->ctx, $this->period, $currency)
            : [];

        // One history query feeds the trend card, the sparklines and the
        // projection. Two would eventually disagree about the same month.
        $history = $this->monthlyHistory();

        // The period totals are asked for once and handed to everything that
        // needs them. A KPI and a sentence under it that each ran their own
        // query is how the two come to state different figures.
        $totals = [
            'now'      => $this->periodTotals($this->period->params()),
            'previous' => $this->period->compareParams() === null ? null : $this->periodTotals($this->period->compareParams()),
        ];

        // Computed once: the card and the panel must never disagree about the
        // projection, which they would if each derived its own.
        $forecastPanel = $this->forecastPanel($currency, $history);
        $forecastSummary = ($forecastPanel['spend_forecast']['available'] ?? false) === true
            ? $forecastPanel['spend_forecast']
            : null;

        // Asked once. Inventory is a separate product and a second call to it
        // inside one render is a second chance for the two halves of this
        // screen to say different things about the same stock.
        $stockRisk = $this->stockOutRisk();
        $risks = $this->risks($currency, $priceAnomalies, $anomalies, $stockRisk);

        return $this->envelope(
            $this->metrics($opportunities, $priceAnomalies, $risks, $currency, $totals, $history),
            [
                'ask'           => $this->askPanel($ai),
                'opportunities' => $this->opportunityPanel($opportunities, $currency),
                'spend_trend'   => $this->spendTrendPanel($currency, $history, $forecastSummary),
                'categories'    => $this->categoryPanel($currency),
                'risks'         => $this->riskPanel($risks),
                'insights'      => $this->insightPanel($currency, $opportunities, $priceAnomalies, $risks, $forecastSummary, $ai, $totals),
                'forecast'      => $forecastPanel,
                'anomalies'     => $this->anomalyPanel($anomalies),
                'actions'       => $this->proposedActions(),
            ],
            ['ai' => $ai],
        );
    }

    // -----------------------------------------------------------------------
    // The six figures at the top
    // -----------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $opportunities
     * @param list<array<string, mixed>> $priceAnomalies
     * @param list<array<string, mixed>> $risks
     * @param array{now: array{value: string, orders: int}, previous: array{value: string, orders: int}|null} $totals
     * @param list<array{month: string, value: string, orders: int}> $history
     * @return list<array<string, mixed>>
     */
    private function metrics(array $opportunities, array $priceAnomalies, array $risks, string $currency, array $totals, array $history): array
    {
        $now = $totals['now'];
        $previous = $totals['previous'];
        $months = $this->trendSeries($history);

        $values = $this->canSeeValues();
        $noComparison = $this->period->compareParams() === null
            ? 'No comparison period is selected.'
            : 'Nothing comparable in the previous period.';

        return [
            $values
                ? Metric::ready(
                    'purchase_value',
                    'Purchase value',
                    $now['value'],
                    'Order value raised in ' . $this->period->label() . ', excluding cancelled orders. Tax is an estimate on an order and is excluded.',
                    [
                        'format'    => 'currency',
                        'currency'  => $currency,
                        'compact'   => true,
                        'direction' => Metric::NEUTRAL,
                        'previous'  => $previous['value'] ?? null,
                        'explanation' => 'What this application committed in the period. Posted purchases are Smart Books\' figure and can differ — a bill lands in the month it is entered, not the month the order was raised.',
                        'comparison_unavailable_reason' => $noComparison,
                        'drilldown' => $this->drilldown('/purchase-orders'),
                        'footer'    => 'Ordered value, excluding tax',
                        'trend'     => array_map(static fn (array $m) => ['period' => $m['label'], 'value' => $m['value']], $months),
                    ],
                )
                : Metric::unavailable(
                    'purchase_value',
                    'Purchase value',
                    'You do not have permission to see purchase values (cost.view).',
                    'Order value raised in the period.',
                    ['format' => 'currency', 'currency' => $currency],
                ),

            Metric::ready(
                'purchase_orders',
                'Purchase orders',
                (string) $now['orders'],
                'Orders raised in ' . $this->period->label() . ', excluding cancelled orders. A revision is a version of an order, not another order.',
                [
                    'direction' => Metric::NEUTRAL,
                    'previous'  => $previous === null ? null : (string) $previous['orders'],
                    'explanation' => 'Counted by order, not by line. Draft orders are included: they are work raised, whether or not they have been issued yet.',
                    'comparison_unavailable_reason' => $noComparison,
                    'drilldown' => $this->drilldown('/purchase-orders'),
                    'footer'    => 'Counted by order, not by line',
                    'trend'     => array_map(static fn (array $m) => ['period' => $m['label'], 'value' => (string) $m['orders']], $months),
                ],
            ),

            $values
                ? Metric::ready(
                    'avg_po_value',
                    'Average PO value',
                    $now['orders'] === 0 ? null : (Decimal::div($now['value'], (string) $now['orders'], 2) ?? Decimal::ZERO),
                    'Order value divided by the number of orders raised in ' . $this->period->label() . '.',
                    [
                        'format'    => 'currency',
                        'currency'  => $currency,
                        'compact'   => true,
                        'direction' => Metric::NEUTRAL,
                        'previous'  => ($previous === null || $previous['orders'] === 0)
                            ? null
                            : Decimal::div($previous['value'], (string) $previous['orders'], 2),
                        'explanation' => 'A mean, so one large order moves it. It is a measure of how purchasing is shaped, not of how much was bought.',
                        'comparison_unavailable_reason' => $noComparison,
                        'drilldown' => $this->drilldown('/purchase-orders'),
                        'footer'    => 'A mean, so one large order moves it',
                        'trend'     => array_map(
                            static fn (array $m) => [
                                'period' => $m['label'],
                                'value'  => $m['orders'] === 0 ? null : Decimal::div($m['value'], (string) $m['orders'], 2),
                            ],
                            $months,
                        ),
                    ],
                )
                : Metric::unavailable(
                    'avg_po_value',
                    'Average PO value',
                    'You do not have permission to see purchase values (cost.view).',
                    'Order value divided by the number of orders.',
                    ['format' => 'currency', 'currency' => $currency],
                ),

            $values
                ? Metric::ready(
                    'price_anomalies',
                    'Price anomalies',
                    (string) count($priceAnomalies),
                    'Items whose latest agreed rate in ' . $this->period->label() . ' is more than 10% above the median of their own earlier rates in the same period, in the same unit.',
                    [
                        'direction' => Metric::LOWER_IS_BETTER,
                        'explanation' => 'Compared against a median rather than the previous rate, so one unusual order cannot make the next ordinary one look like a correction. '
                            . 'Three observations of the same item and unit is the floor: a pair of rates is not a distribution. '
                            . 'Rates are compared before discount, freight and tax, so a change in quantity break or delivery terms can show here legitimately.',
                        'comparison_unavailable_reason' => 'The rule compares rates within the selected period, so a count from another period is not the same measurement.',
                        'drilldown' => $this->drilldown('/dashboard/ai-insights', ['panel' => 'risks']),
                        'footer'    => count($priceAnomalies) === 0
                            ? 'No item moved that far against its own median.'
                            : Format::money($this->sumOf($priceAnomalies, 'affected_value'), $currency) . ' of affected order value',
                    ],
                )
                : Metric::unavailable(
                    'price_anomalies',
                    'Price anomalies',
                    'You do not have permission to see purchase rates (cost.view).',
                    'Rates that stand out against their own recent history.',
                ),

            $this->savingsMetric($opportunities, $currency, $now['value']),

            Metric::ready(
                'purchase_risks_open',
                'Purchase risks open',
                (string) count($risks),
                'Open risk signals on this screen: late and due deliveries, unusual rates, possible duplicate bills, match exceptions, supplier concentration and stock cover.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'explanation' => 'A position as at now, not a count over the period. Each row states which rule produced it and opens the records behind it.',
                    'comparison_unavailable_reason' => 'A list of open risks is a position as at now; there is no previous position stored to compare it against.',
                    'drilldown' => $this->drilldown('/dashboard/ai-insights', ['panel' => 'risks']),
                    'footer'    => $risks === []
                        ? 'Nothing is waiting on a decision.'
                        : $this->countBySeverity($risks),
                ],
            ),
        ];
    }

    /**
     * Potential savings.
     *
     * Estimates are summed only across cards that do not overlap. Two
     * opportunities describing the same rupees would otherwise be added
     * together into a saving nobody could ever realise.
     *
     * @param list<array<string, mixed>> $opportunities
     * @return array<string, mixed>
     */
    private function savingsMetric(array $opportunities, string $currency, string $spend): array
    {
        if (!$this->canSeeValues()) {
            return Metric::unavailable(
                'opportunity_value',
                'Potential savings',
                'You do not have permission to see purchase values (cost.view).',
                'The sum of the estimates on the non-overlapping opportunity cards below.',
                ['format' => 'currency', 'currency' => $currency],
            );
        }

        $counted = [];
        $total = Decimal::ZERO;
        $overlapping = 0;

        foreach ($opportunities as $opportunity) {
            if ($opportunity['estimate'] === null) {
                continue;
            }
            $overlapsCounted = false;
            foreach ((array) $opportunity['overlaps'] as $other) {
                if (isset($counted[$other])) {
                    $overlapsCounted = true;
                    break;
                }
            }
            if ($overlapsCounted) {
                $overlapping++;
                continue;
            }
            $counted[$opportunity['id']] = true;
            $total = Decimal::add($total, (string) $opportunity['estimate']);
        }

        $share = Decimal::isZero($spend) ? null : Decimal::percentOf($total, $spend, 1);

        return Metric::ready(
            'opportunity_value',
            'Potential savings',
            Decimal::isZero($total) ? '0' : $total,
            'The sum of the estimates on the non-overlapping opportunity cards below, for ' . $this->period->label() . '.',
            [
                'format'    => 'currency',
                'currency'  => $currency,
                'compact'   => true,
                'direction' => Metric::NEUTRAL,
                'explanation' => 'An ESTIMATE, not a budget. Each card carries its own baseline and assumption. '
                    . ($overlapping > 0
                        ? $overlapping . ' card' . ($overlapping === 1 ? ' was' : 's were') . ' excluded from this total because '
                          . ($overlapping === 1 ? 'it describes' : 'they describe') . ' the same spend as a card already counted.'
                        : 'No cards overlap in this period.')
                    . ' These are upper bounds under stated assumptions.',
                'comparison_unavailable_reason' => 'Opportunities are recomputed for each period from different underlying orders, so the totals are not comparable.',
                'drilldown' => $this->drilldown('/dashboard/ai-insights', ['panel' => 'opportunities']),
                'footer'    => $share === null
                    ? count($opportunities) . ' card' . (count($opportunities) === 1 ? '' : 's') . ' found'
                    : Format::percent($share, 1) . ' of purchase value in this period',
                'footnote'  => count($opportunities) . ' card' . (count($opportunities) === 1 ? '' : 's') . ' found.',
            ],
        );
    }

    /**
     * Ordered value and order count for a date range, with the screen's filters.
     *
     * @param array<string, string> $range
     * @return array{value: string, orders: int}
     */
    private function periodTotals(array $range): array
    {
        [$clause, $params] = $this->filters->orderClause('p');

        $row = $this->row(
            'SELECT COALESCE(SUM(p.total_amount), 0)::text AS value, COUNT(*) AS orders
             FROM purchase_orders p
             WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> \'CANCELLED\'' . $clause,
            $range + $params,
            'p',
        ) ?? [];

        return ['value' => Decimal::of($row['value'] ?? '0'), 'orders' => (int) ($row['orders'] ?? 0)];
    }

    /**
     * Twelve months of ordered value and order count, oldest first.
     *
     * @return list<array{month: string, value: string, orders: int}>
     */
    private function monthlyHistory(): array
    {
        [$clause, $params] = $this->filters->orderClause('p');

        return array_map(
            static fn (array $row) => [
                'month'  => (string) $row['month'],
                'value'  => Decimal::of($row['amount']),
                'orders' => (int) $row['orders'],
            ],
            $this->rows(
                "SELECT to_char(date_trunc('month', p.po_date), 'YYYY-MM') AS month,
                        COALESCE(SUM(p.total_amount), 0)::text AS amount, COUNT(*) AS orders
                 FROM purchase_orders p
                 WHERE {scope} AND p.status <> 'CANCELLED'
                   AND p.po_date >= date_trunc('month', CURRENT_DATE) - INTERVAL '11 months'" . $clause . '
                 GROUP BY 1 ORDER BY 1',
                $params,
                'p',
            ),
        );
    }

    /**
     * The last six months, with the months that had nothing included as zero.
     *
     * A sparkline that silently drops an empty month draws a shape that never
     * happened, so the gaps are filled here rather than in the browser.
     *
     * @param list<array{month: string, value: string, orders: int}> $history
     * @return list<array{month: string, label: string, value: string, orders: int}>
     */
    private function trendSeries(array $history, int $months = 6): array
    {
        $byMonth = [];
        foreach ($history as $row) {
            $byMonth[$row['month']] = $row;
        }

        $cursor = new \DateTimeImmutable('first day of this month', new \DateTimeZone($this->period->timezone));
        $out = [];
        for ($back = $months - 1; $back >= 0; $back--) {
            $month = $cursor->modify('-' . $back . ' months');
            $key = $month->format('Y-m');
            $out[] = [
                'month'  => $key,
                'label'  => $month->format('M y'),
                'value'  => $byMonth[$key]['value'] ?? Decimal::ZERO,
                'orders' => $byMonth[$key]['orders'] ?? 0,
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumOf(array $rows, string $key): string
    {
        return Decimal::sum(array_map(static fn (array $row) => Decimal::of($row[$key] ?? '0'), $rows));
    }

    /** @param list<array<string, mixed>> $risks */
    private function countBySeverity(array $risks): string
    {
        $critical = 0;
        $warning = 0;
        foreach ($risks as $risk) {
            if ($risk['severity'] === 'critical') {
                $critical++;
            } elseif ($risk['severity'] === 'warning') {
                $warning++;
            }
        }

        $parts = [];
        if ($critical > 0) {
            $parts[] = $critical . ' needing attention now';
        }
        if ($warning > 0) {
            $parts[] = $warning . ' to watch';
        }

        return $parts === [] ? 'All informational' : implode(', ', $parts);
    }

    // -----------------------------------------------------------------------
    // Panels
    // -----------------------------------------------------------------------

    /**
     * Panel A — Ask Purchases.
     *
     * The catalogue of questions this screen can answer, with the security
     * position stated on the screen rather than only in a document: approved
     * queries, permissions checked before retrieval, no model-written SQL.
     *
     * @param array<string, mixed> $ai
     * @return array<string, mixed>
     */
    private function askPanel(array $ai): array
    {
        $catalogue = array_map(
            static fn (array $intent) => [
                'id'          => $intent['id'],
                'question'    => $intent['question'],
                'description' => $intent['description'],
                'permission'  => $intent['permission'],
            ],
            AskEngine::catalogue(),
        );

        $allowed = array_values(array_filter(
            $catalogue,
            fn (array $intent) => $intent['permission'] === null || $this->can((string) $intent['permission']),
        ));

        return $this->panel([
            'available'  => true,
            'ai'         => $ai,
            'questions'  => $allowed,
            'withheld_count' => count($catalogue) - count($allowed),
            'endpoint'   => 'v1/insights/ask',
            'notice'     => $ai['available']
                ? 'A model is configured. It picks which of the approved questions you meant and writes the summary sentence; every figure comes from your records.'
                : (string) $ai['reason'] . ' The questions below still work — they are answered by fixed queries over your own records.',
            'security'   => 'Questions run approved, parameterised queries. The model never writes a query, never reaches the database, and cannot take an action. Your permissions are applied before any record is fetched, not before it is displayed.',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $opportunities
     * @return array<string, mixed>
     */
    private function opportunityPanel(array $opportunities, string $currency): array
    {
        if (!$this->can('cost.view') && !$this->can('reports.view')) {
            return $this->withheld('cost.view');
        }

        return $this->panel([
            'method'       => 'rules',
            'method_label' => 'Found by fixed rules over your purchase orders. No AI model was consulted.',
            'currency'     => $currency,
            'cards'        => $opportunities,
            'ranking'      => 'Ranked by estimated impact, then by weight of evidence. Evidence strength is a count of observations, not a probability.',
            'basis'        => 'Orders raised in ' . $this->period->label() . '. Every estimate states its baseline and its assumption, '
                . 'and overlapping cards are excluded from the headline total rather than added to it.',
        ]);
    }

    /**
     * Panel — purchase spend trend.
     *
     * Two series on two axes: money and a count. They are not normalised onto
     * one scale, because an index of rupees against an index of orders reads as
     * a comparison and is not one.
     *
     * @param list<array{month: string, value: string, orders: int}> $history
     * @param array<string, mixed>|null                              $forecast
     * @return array<string, mixed>
     */
    private function spendTrendPanel(string $currency, array $history, ?array $forecast): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('reports.view');
        }

        $byMonth = [];
        foreach ($history as $row) {
            $byMonth[$row['month']] = $row;
        }

        $cursor = new \DateTimeImmutable('first day of this month', new \DateTimeZone($this->period->timezone));
        $months = [];
        for ($back = 11; $back >= 0; $back--) {
            $month = $cursor->modify('-' . $back . ' months');
            $key = $month->format('Y-m');
            $value = $byMonth[$key]['value'] ?? Decimal::ZERO;
            $months[] = [
                'month'     => $key,
                'label'     => $month->format('M y'),
                'value'     => $value,
                'formatted' => Format::money($value, $currency),
                'compact'   => Format::compactMoney($value, $currency),
                'orders'    => $byMonth[$key]['orders'] ?? 0,
            ];
        }

        return $this->panel([
            'currency'   => $currency,
            'months'     => $months,
            'ranges'     => [
                ['id' => '6', 'label' => 'Last 6 months'],
                ['id' => '12', 'label' => 'Last 12 months'],
            ],
            'series'     => [
                ['id' => 'value', 'label' => 'Purchase value', 'axis' => 'money'],
                ['id' => 'orders', 'label' => 'Purchase orders', 'axis' => 'count'],
            ],
            'projection' => $forecast === null
                ? ['available' => false, 'reason' => 'A projection needs at least ' . self::FORECAST_MIN_MONTHS . ' complete months of ordering history in this scope.']
                : [
                    'available' => true,
                    'label'     => 'Next month, projected',
                    'value'     => $forecast['projection'],
                    'formatted' => Format::money((string) $forecast['projection'], $currency),
                    'method'    => (string) $forecast['method_label'],
                ],
            'basis'      => 'Ordered value and order count per month, uncancelled, in the selected scope. A month with no orders is shown as zero, not skipped. '
                . 'The period filter above narrows every other panel; this one deliberately shows twelve months so the period can be read in context.',
        ]);
    }

    /**
     * Panel — spend concentration by category.
     *
     * Categories are Inventory's item groups, read live. This product does not
     * keep a copy of them and does not invent one: with Inventory unavailable
     * the panel says so rather than grouping by something else and calling it a
     * category.
     *
     * @return array<string, mixed>
     */
    private function categoryPanel(string $currency): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('reports.view');
        }

        [$clause, $params] = $this->filters->orderClause('p');

        $lines = $this->rows(
            "SELECT l.item_id, l.is_service, COALESCE(SUM(l.line_amount), 0)::text AS amount
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $clause . '
             GROUP BY l.item_id, l.is_service
             ORDER BY SUM(l.line_amount) DESC
             LIMIT 400',
            $this->period->params() + $params,
            'p',
        );

        if ($lines === []) {
            return $this->panel([
                'currency'        => $currency,
                'total'           => Decimal::ZERO,
                'total_formatted' => Format::money(Decimal::ZERO, $currency),
                'total_compact'   => Format::compactMoney(Decimal::ZERO, $currency),
                'categories'      => [],
                'source'          => 'inventory',
                'basis'           => 'No order lines in ' . $this->period->label() . '.',
            ]);
        }

        $itemIds = [];
        foreach ($lines as $line) {
            if ($line['item_id'] !== null) {
                $itemIds[] = (int) $line['item_id'];
            }
        }
        $itemIds = array_slice(array_values(array_unique($itemIds)), 0, self::CATEGORY_ITEM_CAP);

        $lookup = $this->inventoryReader()->items($itemIds);
        if (!$lookup['ok'] && $itemIds !== []) {
            $this->sources->unavailable('inventory', InventoryReader::LABEL, (string) $lookup['error']);

            return $this->unavailablePanel(
                (string) $lookup['error'] . ' Categories are Inventory\'s item groups, so nothing is shown here rather than grouping the spend by something else.',
            );
        }
        if ($itemIds !== [] && !$this->sources->isReady('inventory')) {
            $this->sources->ready('inventory', InventoryReader::LABEL);
        }

        $buckets = [];
        $total = Decimal::ZERO;
        foreach ($lines as $line) {
            $amount = Decimal::of($line['amount']);
            if (Decimal::cmp($amount, '0') <= 0) {
                continue;
            }
            $total = Decimal::add($total, $amount);

            $itemId = $line['item_id'] === null ? null : (int) $line['item_id'];
            $name = match (true) {
                (bool) $line['is_service'] => 'Services',
                $itemId === null           => 'Other charges',
                default                    => ($lookup['items'][$itemId]['group'] ?? null) ?? 'Ungrouped items',
            };

            $key = strtolower($name);
            $buckets[$key] = [
                'id'     => $key,
                'name'   => $name,
                'amount' => Decimal::add($buckets[$key]['amount'] ?? Decimal::ZERO, $amount),
            ];
        }

        usort($buckets, static fn (array $a, array $b) => Decimal::cmp($b['amount'], $a['amount']));

        // Five named categories and one honest tail. A sixth hue would be a
        // colour nobody can name against a slice nobody can read.
        $head = array_slice($buckets, 0, 5);
        $tail = array_slice($buckets, 5);
        if ($tail !== []) {
            $head[] = [
                'id'     => 'others',
                'name'   => count($tail) . ' other categor' . (count($tail) === 1 ? 'y' : 'ies'),
                'amount' => Decimal::sum(array_map(static fn (array $bucket) => $bucket['amount'], $tail)),
            ];
        }

        return $this->panel([
            'currency'        => $currency,
            'total'           => $total,
            'total_formatted' => Format::money($total, $currency),
            'total_compact'   => Format::compactMoney($total, $currency),
            'categories'      => array_map(
                static fn (array $bucket) => [
                    'id'        => $bucket['id'],
                    'name'      => $bucket['name'],
                    'amount'    => $bucket['amount'],
                    'formatted' => Format::money($bucket['amount'], $currency),
                    'share_pc'  => Decimal::isZero($total) ? null : Decimal::percentOf($bucket['amount'], $total, 1),
                ],
                $head,
            ),
            'source'          => 'inventory',
            'basis'           => 'Line value on orders raised in ' . $this->period->label() . ', before tax. '
                . 'Categories are Inventory\'s item groups, read live for the ' . count($itemIds) . ' item'
                . (count($itemIds) === 1 ? '' : 's') . ' with the most spend. Services and charges without an item are their own rows.',
        ]);
    }

    /**
     * Purchase risks, as a list a person can work through.
     *
     * Every row is a count or an amount from a query, with the route to the
     * records behind it. None of them is a prediction.
     *
     * @param list<array<string, mixed>>   $priceAnomalies
     * @param list<array<string, mixed>>   $anomalies
     * @param array{count: int, covered: int}|null $stockRisk
     * @return list<array<string, mixed>>
     */
    private function risks(string $currency, array $priceAnomalies, array $anomalies, ?array $stockRisk): array
    {
        $out = [];
        [$clause, $params] = $this->filters->orderClause('p');
        $open = "p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')";

        $due = $this->row(
            'SELECT COUNT(DISTINCT p.po_id) AS orders,
                    COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text AS value
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND ' . $open . ' AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL \'7 days\'' . $clause,
            $params,
            'p',
        ) ?? [];
        if ((int) ($due['orders'] ?? 0) > 0) {
            $out[] = $this->risk(
                'due-soon',
                'warning',
                (int) $due['orders'] . ' order' . (((int) $due['orders']) === 1 ? '' : 's') . ' due within seven days',
                ($this->canSeeValues() ? Format::money(Decimal::of($due['value']), $currency) . ' of undelivered value' : 'Undelivered lines') . ' against the promised date.',
                '/dashboard/procurement',
                ['view' => 'due'],
                (int) $due['orders'],
                'Open orders with a promised date between today and seven days from now, and a quantity still outstanding.',
            );
        }

        $late = $this->row(
            'SELECT COUNT(DISTINCT p.po_id) AS orders,
                    COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text AS value
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND ' . $open . ' AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE' . $clause,
            $params,
            'p',
        ) ?? [];
        if ((int) ($late['orders'] ?? 0) > 0) {
            $out[] = $this->risk(
                'late-receipts',
                'critical',
                (int) $late['orders'] . ' order' . (((int) $late['orders']) === 1 ? '' : 's') . ' past the promised date',
                ($this->canSeeValues() ? Format::money(Decimal::of($late['value']), $currency) . ' still not received' : 'Quantities still not received') . '. Receipts are Inventory\'s record, not ours.',
                '/dashboard/procurement',
                ['view' => 'delayed'],
                (int) $late['orders'],
                'Open orders whose promised date has passed with a quantity still outstanding.',
            );
        }

        if ($priceAnomalies !== []) {
            $average = Decimal::div($this->sumOf($priceAnomalies, 'change_pc'), (string) count($priceAnomalies), 1) ?? Decimal::ZERO;
            $out[] = $this->risk(
                'price-anomalies',
                'warning',
                count($priceAnomalies) . ' unusual rate increase' . (count($priceAnomalies) === 1 ? '' : 's'),
                'Average ' . Format::percent($average, 1) . ' above each item\'s own median rate, on '
                    . Format::money($this->sumOf($priceAnomalies, 'affected_value'), $currency) . ' of order value.',
                '/dashboard/ai-insights',
                ['panel' => 'risks'],
                count($priceAnomalies),
                'Latest agreed rate more than 10% above the median of the same item\'s earlier rates in this period, same unit, at least three observations.',
            );
        }

        $duplicates = 0;
        foreach ($anomalies as $anomaly) {
            if (($anomaly['kind'] ?? '') === 'duplicate') {
                $duplicates++;
            }
        }
        if ($duplicates > 0) {
            $out[] = $this->risk(
                'duplicate-bills',
                'warning',
                $duplicates . ' possible duplicate bill' . ($duplicates === 1 ? '' : 's'),
                'Supplier invoice numbers a single character apart from another bill on the same account. A review candidate, not a finding.',
                '/dashboard/ai-insights',
                ['panel' => 'anomalies'],
                $duplicates,
                'Near-identical supplier invoice references within the recent set of bills for one supplier.',
            );
        }

        if ($this->can('match.view')) {
            $exceptions = $this->count(
                "SELECT COUNT(*) FROM purchase_match_exceptions WHERE cmp_id = :cmp AND status = 'OPEN'",
                ['cmp' => $this->ctx->cmpId],
            );
            if ($exceptions > 0) {
                $out[] = $this->risk(
                    'match-exceptions',
                    'critical',
                    $exceptions . ' bill' . ($exceptions === 1 ? '' : 's') . ' held by a match exception',
                    'A supplier bill does not agree with the order or with what was received. None of them reach Smart Books until somebody decides.',
                    '/dashboard/bills-payables',
                    ['panel' => 'matching'],
                    $exceptions,
                    'Open rows in this application\'s three-way match exception log.',
                );
            }
        }

        $concentration = $this->topSupplierShare($currency);
        if ($concentration !== null) {
            $out[] = $concentration;
        }

        if ($stockRisk === null) {
            $out[] = $this->risk(
                'stock-cover',
                'info',
                'Stock cover could not be assessed',
                'Inventory did not answer, so nothing is estimated from purchase history in its place.',
                '/dashboard/procurement',
                ['panel' => 'reorder'],
                null,
                'Items short in Inventory with nothing on order here to cover them. Inventory is the authority for stock; this application never guesses it.',
            );
        } elseif ($stockRisk['count'] > 0) {
            $out[] = $this->risk(
                'stock-cover',
                'critical',
                $stockRisk['count'] . ' item' . ($stockRisk['count'] === 1 ? '' : 's') . ' at risk of running out',
                'Below the reorder level Inventory holds, with no unreceived order here covering the shortfall. '
                    . $stockRisk['covered'] . ' more ' . ($stockRisk['covered'] === 1 ? 'is' : 'are') . ' short but already on order.',
                '/dashboard/procurement',
                ['panel' => 'reorder'],
                $stockRisk['count'],
                'Inventory\'s replenishment report, less anything this application already has on order for the same item.',
            );
        }

        return $out;
    }

    /**
     * Dependence on one supplier, where there is enough spend to mean anything.
     *
     * @return array<string, mixed>|null
     */
    private function topSupplierShare(string $currency): ?array
    {
        if (!$this->canSeeValues()) {
            return null;
        }

        [$clause, $params] = $this->filters->orderClause('p');
        $range = $this->period->params();

        $total = $this->amount(
            'SELECT COALESCE(SUM(p.total_amount), 0) FROM purchase_orders p
             WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> \'CANCELLED\'' . $clause,
            $range + $params,
            'p',
        );
        if (Decimal::isZero($total)) {
            return null;
        }

        $top = $this->row(
            'SELECT p.supplier_account_id, p.supplier_name_snapshot,
                    COALESCE(SUM(p.total_amount), 0)::text AS value
             FROM purchase_orders p
             WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> \'CANCELLED\'' . $clause . '
             GROUP BY p.supplier_account_id, p.supplier_name_snapshot
             ORDER BY SUM(p.total_amount) DESC LIMIT 1',
            $range + $params,
            'p',
        );
        if ($top === null) {
            return null;
        }

        $share = Decimal::percentOf(Decimal::of($top['value']), $total, 1);
        // Thirty per cent is this screen's stated threshold, not a standard.
        if ($share === null || Decimal::cmp($share, '30') < 0) {
            return null;
        }

        return $this->risk(
            'supplier-concentration',
            'warning',
            Format::percent($share, 1) . ' of spend sits with one supplier',
            ($top['supplier_name_snapshot'] ?? 'One supplier') . ' accounts for '
                . Format::money(Decimal::of($top['value']), $currency) . ' of ' . Format::money($total, $currency) . ' in this period.',
            '/dashboard/suppliers',
            ['panel' => 'concentration'],
            null,
            'Ordered value by supplier over the selected period. The 30% threshold is this screen\'s, not an accounting standard, and a single-source item can be a sound decision.',
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function risk(
        string $id,
        string $severity,
        string $title,
        string $detail,
        string $route,
        array $filters,
        ?int $count,
        string $basis,
    ): array {
        return [
            'id'             => $id,
            'severity'       => $severity,
            'severity_label' => match ($severity) {
                'critical' => 'Needs attention',
                'warning'  => 'Worth a look',
                default    => 'For information',
            },
            'title'   => $title,
            'detail'  => $detail,
            'count'   => $count,
            'basis'   => $basis,
            'route'   => $route,
            'filters' => $filters + $this->filters->drilldown(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $risks
     * @return array<string, mixed>
     */
    private function riskPanel(array $risks): array
    {
        return $this->panel([
            'method'       => 'rules',
            'method_label' => 'Fixed rules over your own records. No AI model was consulted.',
            'rows'         => $risks,
            'basis'        => 'A position as at now, except the rate and concentration rules, which read the selected period. '
                . 'Each row opens the records behind it.',
        ]);
    }

    /**
     * Panel — the short reads, each labelled with what kind of statement it is.
     *
     * @param list<array<string, mixed>> $opportunities
     * @param list<array<string, mixed>> $priceAnomalies
     * @param list<array<string, mixed>> $risks
     * @param array<string, mixed>|null  $forecast
     * @param array<string, mixed>       $ai
     * @param array{now: array{value: string, orders: int}, previous: array{value: string, orders: int}|null} $totals
     * @return array<string, mixed>
     */
    private function insightPanel(
        string $currency,
        array $opportunities,
        array $priceAnomalies,
        array $risks,
        ?array $forecast,
        array $ai,
        array $totals,
    ): array {
        $rows = [];

        // 1. The movement in spend against the comparison period.
        if ($this->canSeeValues() && $totals['previous'] !== null) {
            $now = $totals['now'];
            $before = $totals['previous'];
            $changePc = Decimal::percentChange($before['value'], $now['value'], 1);

            if ($changePc !== null && Decimal::cmp(Decimal::isNegative($changePc) ? Decimal::negate($changePc) : $changePc, '10') >= 0) {
                $up = !Decimal::isNegative($changePc);
                $rows[] = $this->insight(
                    'spend-movement',
                    $up ? 'warning' : 'info',
                    'observation',
                    'Purchase value ' . ($up ? 'rose' : 'fell') . ' '
                        . Format::percent(Decimal::isNegative($changePc) ? Decimal::negate($changePc) : $changePc, 1),
                    Format::money($now['value'], $currency) . ' this period against '
                        . Format::money($before['value'], $currency) . ' in the comparison period.',
                    'Ordered value in the selected period against the same number of days immediately before it.',
                    '/purchase-orders',
                    [],
                );
            }
        }

        // 2. The largest quantified opportunity.
        $best = null;
        foreach ($opportunities as $card) {
            if ($card['estimate'] === null) {
                continue;
            }
            if ($best === null || Decimal::cmp((string) $card['estimate'], (string) $best['estimate']) > 0) {
                $best = $card;
            }
        }
        if ($best !== null) {
            $rows[] = $this->insight(
                'best-opportunity',
                'success',
                'estimate',
                (string) $best['title'],
                'Estimated at ' . (string) $best['estimate_formatted'] . '. ' . (string) $best['detail'],
                (string) $best['assumption'],
                (string) $best['route'],
                (array) $best['filters'],
            );
        }

        // 3. The sharpest rate movement, with both rates on the row.
        if ($priceAnomalies !== []) {
            $sharpest = $priceAnomalies[0];
            $rows[] = $this->insight(
                'sharpest-rate',
                'warning',
                'observation',
                'Rate up ' . (string) $sharpest['change_label'] . ' against its own median',
                (string) $sharpest['latest_formatted'] . ' on ' . (string) $sharpest['latest_date_label']
                    . ' against a median of ' . (string) $sharpest['median_formatted'] . ' over '
                    . (int) $sharpest['observations'] . ' orders.',
                (string) $sharpest['basis'],
                (string) $sharpest['route'],
                [],
            );
        }

        // 4. What the arithmetic says about next month.
        if ($forecast !== null) {
            $rows[] = $this->insight(
                'projection',
                'info',
                'projection',
                'Next month projects at ' . Format::money((string) $forecast['projection'], $currency),
                'Observed ' . (string) $forecast['observation_period'] . '. Range '
                    . Format::money((string) $forecast['range']['low'], $currency) . ' to '
                    . Format::money((string) $forecast['range']['high'], $currency) . '.',
                (string) $forecast['method_label'] . ' ' . (string) $forecast['range_label'],
                '/dashboard/ai-insights',
                ['panel' => 'forecast'],
            );
        }

        // 5. The risk that most needs a decision, if one does.
        foreach ($risks as $risk) {
            if ($risk['severity'] === 'critical') {
                $rows[] = $this->insight(
                    'top-risk',
                    'danger',
                    'observation',
                    (string) $risk['title'],
                    (string) $risk['detail'],
                    (string) $risk['basis'],
                    (string) $risk['route'],
                    (array) $risk['filters'],
                );
                break;
            }
        }

        return $this->panel([
            'method'       => $ai['available'] ? 'rules_with_commentary' : 'rules',
            'method_label' => $ai['available']
                ? 'Each read below is arithmetic over your records. A model can comment on them in Ask Aicountly AI; it writes none of these figures.'
                : 'Each read below is arithmetic over your records. No model is configured, and none is needed for any of them.',
            'ai'           => ['available' => (bool) $ai['available'], 'reason' => $ai['reason'] ?? null],
            'rows'         => $rows,
            'basis'        => 'Every row states whether it is an observation, an estimate or a projection, and opens the records behind it.',
        ]);
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function insight(
        string $id,
        string $tone,
        string $kind,
        string $title,
        string $detail,
        string $basis,
        string $route,
        array $filters,
    ): array {
        return [
            'id'         => $id,
            'tone'       => $tone,
            'kind'       => $kind,
            'kind_label' => match ($kind) {
                'estimate'   => 'Estimate',
                'projection' => 'Projection',
                default      => 'Observed',
            },
            'title'   => $title,
            'detail'  => $detail,
            'basis'   => $basis,
            'route'   => $route,
            'filters' => $filters + $this->filters->drilldown(),
        ];
    }

    /** @return array{count: int, covered: int}|null */
    private function stockOutRisk(): array|null
    {
        $signal = $this->inventoryReader()->replenishment($this->filters->warehouseId, 50);
        if (!$signal['ok']) {
            $this->sources->unavailable('inventory', InventoryReader::LABEL, (string) $signal['error']);

            return null;
        }
        $this->sources->ready('inventory', InventoryReader::LABEL, $signal['as_of']);

        $rows = $signal['rows'];
        if ($rows === []) {
            return ['count' => 0, 'covered' => 0];
        }

        $itemIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['item_id'], $rows)));
        $placeholders = [];
        $params = [];
        foreach ($itemIds as $index => $itemId) {
            $placeholders[] = ':item' . $index;
            $params['item' . $index] = $itemId;
        }

        $onOrder = [];
        foreach ($this->rows(
            "SELECT l.item_id, COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0)), 0)::text AS qty
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.item_id IN (" . implode(', ', $placeholders) . ')
             GROUP BY l.item_id',
            $params,
            'p',
        ) as $row) {
            $onOrder[(int) $row['item_id']] = Decimal::of($row['qty']);
        }

        $atRisk = 0;
        $covered = 0;
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $ordered = $onOrder[$itemId] ?? Decimal::ZERO;
            $needed = $row['suggested_qty'];

            if ($needed !== null && Decimal::cmp($ordered, $needed) >= 0) {
                $covered++;
                continue;
            }
            if (!Decimal::isZero($ordered) && $needed === null) {
                $covered++;
                continue;
            }
            $atRisk++;
        }

        return ['count' => $atRisk, 'covered' => $covered];
    }

    /**
     * Panel C — forecasts.
     *
     * Three sections that are never merged: what is contractually committed,
     * what the arithmetic suggests, and — only where a model is configured —
     * what it had to say about the two.
     *
     * @param list<array{month: string, value: string, orders: int}> $history
     * @return array<string, mixed>
     */
    private function forecastPanel(string $currency, array $history): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('reports.view');
        }

        // 1. OBLIGATIONS. Not a forecast: these are dated commitments.
        $obligations = $this->rows(
            "SELECT to_char(date_trunc('month', COALESCE(l.promised_date, p.promised_date)), 'YYYY-MM') AS month,
                    SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate)::text AS amount,
                    COUNT(DISTINCT p.po_id) AS orders
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) IS NOT NULL
             GROUP BY 1 ORDER BY 1 LIMIT 12",
            [],
            'p',
        );

        $forecast = $this->projectSpend($history, $currency);

        return $this->panel([
            'currency'    => $currency,
            'obligations' => [
                'kind'   => 'contractual',
                'label'  => 'Committed and dated',
                'months' => array_map(static fn (array $r) => [
                    'month'     => $r['month'],
                    'amount'    => Decimal::of($r['amount']),
                    'formatted' => Format::money(Decimal::of($r['amount']), $currency),
                    'orders'    => (int) $r['orders'],
                ], $obligations),
                'basis'  => 'Remaining quantity × agreed rate on open orders, placed in the month they are promised for. '
                    . 'These are commitments already made, not a prediction. Tax is excluded.',
            ],
            'spend_forecast' => $forecast,
            'commentary'  => AiClient::isConfigured()
                ? ['available' => true, 'note' => 'Commentary can be requested on the Ask panel. It is written over these same figures and adds none of its own.']
                : ['available' => false, 'note' => 'AI insights are currently unavailable. The obligations and the projection above are unaffected — both are arithmetic over your own records.'],
            'payables_note' => 'Upcoming payment obligations come from Smart Books due dates and are shown on Bills & Payables, not projected here.',
        ]);
    }

    /**
     * A deliberately plain projection.
     *
     * The mean of the last three complete months, with a range taken from the
     * highest and lowest of those months. It is NOT a confidence interval and
     * does not claim to be one — a percentage attached to a three-point average
     * would be precision this data cannot support.
     *
     * @param list<array{month: string, value: string, orders: int}> $history
     * @return array<string, mixed>
     */
    private function projectSpend(array $history, string $currency): array
    {
        $currentMonth = gmdate('Y-m');
        $complete = array_values(array_filter($history, static fn (array $r) => $r['month'] !== $currentMonth));

        if (count($complete) < self::FORECAST_MIN_MONTHS) {
            return [
                'kind'      => 'statistical',
                'available' => false,
                'reason'    => 'A projection needs at least ' . self::FORECAST_MIN_MONTHS . ' complete months of ordering history in this scope; there '
                    . (count($complete) === 1 ? 'is 1' : 'are ' . count($complete)) . '. Nothing is extrapolated from less.',
                'months_available' => count($complete),
                'history'   => array_map(static fn (array $r) => ['month' => $r['month'], 'amount' => $r['value']], $complete),
            ];
        }

        $window = array_slice($complete, -3);
        $amounts = array_map(static fn (array $r) => $r['value'], $window);
        $mean = Decimal::div(Decimal::sum($amounts), (string) count($amounts), 2) ?? Decimal::ZERO;

        $low = $amounts[0];
        $high = $amounts[0];
        foreach ($amounts as $amount) {
            if (Decimal::cmp($amount, $low) < 0) {
                $low = $amount;
            }
            if (Decimal::cmp($amount, $high) > 0) {
                $high = $amount;
            }
        }

        return [
            'kind'      => 'statistical',
            'available' => true,
            'method'    => 'mean_of_last_3_complete_months',
            'method_label' => 'Mean of the last three complete months of ordered value.',
            'observation_period' => ($window[0]['month'] ?? '') . ' to ' . ($window[count($window) - 1]['month'] ?? ''),
            'months_observed' => count($complete),
            'projection' => $mean,
            // Formatted on the server, like every other figure, so the panel
            // cannot print a bare decimal where a rupee amount belongs.
            'projection_formatted' => Format::money($mean, $currency),
            'range'     => ['low' => $low, 'high' => $high],
            'range_formatted' => ['low' => Format::money($low, $currency), 'high' => Format::money($high, $currency)],
            'range_label' => 'Range shown is the lowest and highest of those three months, not a confidence interval.',
            'basis'     => 'ORDERED value raised per month, uncancelled. It is what this application knows; posted purchases are Smart Books\' figure and are shown on the Overview.',
            'caveat'    => 'A three-month mean carries no seasonality and no knowledge of a decision already taken. Treat it as a starting point for a conversation, not a budget.',
            'history'   => array_map(
                static fn (array $r) => [
                    'month'     => $r['month'],
                    'amount'    => $r['value'],
                    'formatted' => Format::money($r['value'], $currency),
                    'orders'    => $r['orders'],
                ],
                $complete,
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $anomalies
     * @return array<string, mixed>
     */
    private function anomalyPanel(array $anomalies): array
    {
        return $this->panel([
            'method'       => 'rules',
            'method_label' => 'Found by fixed rules. No AI model was consulted.',
            'rows'         => $anomalies,
            'disclaimer'   => 'Each row is a review candidate. It is not a finding, not a conclusion and not an accusation of fraud — '
                . 'a supplier with two similar invoice numbers usually has two similar invoice numbers.',
            'basis'        => 'Bills and orders in ' . $this->period->label() . ', against this company\'s own approval threshold where one is set.',
        ]);
    }

    /**
     * Panel E — proposed actions.
     *
     * Every one of these opens a screen where a person completes the work. None
     * of them issues an order, posts a voucher, changes a master or sends a
     * supplier anything.
     *
     * @return array<string, mixed>
     */
    private function proposedActions(): array
    {
        $actions = [];

        if ($this->can('requisition.create')) {
            $actions[] = [
                'id' => 'draft-requisition', 'label' => 'Draft a requisition from the reorder signal',
                'route' => '/dashboard/procurement', 'filters' => ['panel' => 'reorder'],
                'effect' => 'Opens the reorder review. You choose the quantities and a requisition is created only when you save it.',
            ];
        }
        if ($this->can('po.create')) {
            $actions[] = [
                'id' => 'draft-po', 'label' => 'Start a purchase order',
                'route' => '/purchase-orders/new', 'filters' => [],
                'effect' => 'Opens a blank order. Nothing is sent to a supplier until it is approved and issued.',
            ];
        }
        $actions[] = [
            'id' => 'follow-up', 'label' => 'Chase a late delivery',
            'route' => '/dashboard/procurement', 'filters' => ['view' => 'delayed'],
            'effect' => 'Opens the delayed orders. This application does not send supplier emails.',
        ];
        if ($this->canSeeValues()) {
            $actions[] = [
                'id' => 'payment-proposal', 'label' => 'Prepare a payment proposal',
                'route' => '/dashboard/bills-payables', 'filters' => ['panel' => 'planning'],
                'effect' => 'Opens payment planning. A proposal records an intention: it does not post a payment, allocate against a bill or mark anything paid.',
            ];
        }
        if ($this->can('match.view')) {
            $actions[] = [
                'id' => 'exception-workbench', 'label' => 'Open the exception workbench',
                'route' => '/dashboard/bills-payables', 'filters' => ['panel' => 'matching'],
                'effect' => 'Opens the bills held by a match exception, each with the rule that failed.',
            ];
        }

        return $this->panel([
            'actions' => $actions,
            'notice'  => 'Every action here opens a screen for a person to complete. Nothing on this dashboard issues an order, posts a voucher, changes a master record or contacts a supplier.',
        ]);
    }

    /**
     * The Ask endpoint's handler, kept beside the panel that documents it.
     *
     * @return array<string, mixed>
     */
    public function ask(): array
    {
        $question = trim((string) (Http::param('question') ?? ''));
        $intent = Http::param('intent');

        if ($question === '' && ($intent === null || $intent === '')) {
            Http::validationFailed('Ask a question, or choose one from the list.', ['field' => 'question']);
        }

        $answer = AskEngine::answer(
            $this->ctx,
            $this->auth,
            $this->period,
            $question === '' ? (string) $intent : $question,
            ($intent === null || $intent === '') ? null : (string) $intent,
        );

        return $answer + ['ai' => AiClient::status()];
    }
}
