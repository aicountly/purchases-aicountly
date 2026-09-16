<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Ai\AskEngine;
use Aicountly\Api\Http;

/**
 * Dashboard 5 — AI Insights.
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
 */
final class InsightsDashboard extends Dashboard
{
    /** Months of history a spend forecast needs before it will state one. */
    private const FORECAST_MIN_MONTHS = 4;

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

        // Computed once: the card and the panel must never disagree about the
        // projection, which they would if each derived its own.
        $forecastPanel = $this->forecastPanel($currency);
        $forecastSummary = ($forecastPanel['spend_forecast']['available'] ?? false) === true
            ? $forecastPanel['spend_forecast']
            : null;

        return $this->envelope(
            $this->metrics($opportunities, $anomalies, $currency, $forecastSummary),
            [
                'ask'           => $this->askPanel($ai),
                'opportunities' => $this->opportunityPanel($opportunities, $currency),
                'forecast'      => $forecastPanel,
                'anomalies'     => $this->anomalyPanel($anomalies),
                'actions'       => $this->proposedActions(),
            ],
            ['ai' => $ai],
        );
    }

    // -----------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $opportunities
     * @param list<array<string, mixed>> $anomalies
     * @return list<array<string, mixed>>
     */
    private function metrics(array $opportunities, array $anomalies, string $currency, ?array $forecast): array
    {
        // Estimates are summed only across cards that do not overlap. Two
        // opportunities describing the same rupees would otherwise be added
        // together into a saving nobody could ever realise.
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

        $stockRisk = $this->stockOutRisk();

        return [
            Metric::ready(
                'opportunity_value',
                'Identified opportunities',
                Decimal::isZero($total) ? '0' : $total,
                'The sum of the estimates on the non-overlapping opportunity cards below, for ' . $this->period->label() . '.',
                [
                    'format'    => 'currency',
                    'currency'  => $currency,
                    'direction' => Metric::NEUTRAL,
                    'explanation' => 'Each estimate carries its own baseline and assumption. '
                        . ($overlapping > 0
                            ? $overlapping . ' card' . ($overlapping === 1 ? ' was' : 's were') . ' excluded from this total because '
                              . ($overlapping === 1 ? 'it describes' : 'they describe') . ' the same spend as a card already counted.'
                            : 'No cards overlap in this period.')
                        . ' These are upper bounds under stated assumptions, not a budget.',
                    'comparison_unavailable_reason' => 'Opportunities are recomputed for each period from different underlying orders, so the totals are not comparable.',
                    'drilldown' => $this->drilldown('/dashboard/ai-insights', ['panel' => 'opportunities']),
                    'footnote'  => count($opportunities) . ' card' . (count($opportunities) === 1 ? '' : 's') . ' found.',
                ],
            ),
            Metric::ready(
                'anomalies_open',
                'Anomalies to review',
                (string) count($anomalies),
                'Review candidates found by fixed rules: near-identical invoice numbers, repeated purchases just below an approval threshold, and first orders to a supplier not bought from before.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'explanation' => 'Each is a candidate for a person to look at. None is a finding, and none is an accusation.',
                    'comparison_unavailable_reason' => 'A review list is a position as at now.',
                    'drilldown' => $this->drilldown('/dashboard/ai-insights', ['panel' => 'anomalies']),
                ],
            ),
            $stockRisk === null
                ? Metric::unavailable(
                    'stock_out_risk',
                    'Items at risk of running out',
                    'Inventory did not answer, so stock cover cannot be assessed. Nothing is estimated from purchase history in its place.',
                    'Items Inventory reports as short where nothing is on order here to cover them.',
                    ['direction' => Metric::LOWER_IS_BETTER],
                )
                : Metric::ready(
                    'stock_out_risk',
                    'Items at risk of running out',
                    (string) $stockRisk['count'],
                    'Items Inventory reports as below their reorder level where this application has no unreceived purchase order covering the shortfall.',
                    [
                        'direction' => Metric::LOWER_IS_BETTER,
                        'explanation' => 'Stock and reorder levels are Inventory\'s. What is on order is ours. An item short in Inventory but already covered by an open order is not counted.',
                        'comparison_unavailable_reason' => 'Stock cover is a position as at now.',
                        'drilldown' => $this->drilldown('/dashboard/procurement', ['panel' => 'reorder']),
                        'footnote'  => $stockRisk['covered'] . ' more ' . ($stockRisk['covered'] === 1 ? 'is' : 'are') . ' short but already on order.',
                    ],
                ),
            $forecast === null
                ? Metric::unavailable(
                    'forecast_spend',
                    'Projected monthly ordering',
                    'Fewer than ' . self::FORECAST_MIN_MONTHS . ' complete months of ordering history in this scope. Nothing is extrapolated from less.',
                    'Mean ordered value of the last three complete months.',
                    ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::NEUTRAL],
                )
                : Metric::ready(
                    'forecast_spend',
                    'Projected monthly ordering',
                    $forecast['projection'],
                    'Mean ORDERED value of the last three complete months (' . $forecast['observation_period'] . ').',
                    [
                        'format'    => 'currency',
                        'currency'  => $currency,
                        'direction' => Metric::NEUTRAL,
                        'explanation' => 'A plain three-month mean of what this application ordered — not posted purchases, which are Smart Books\' figure. '
                            . 'The range beside it in the forecast panel is the lowest and highest of those three months, not a confidence interval. '
                            . 'No seasonality and no knowledge of a decision already taken.',
                        'comparison_unavailable_reason' => 'A projection has no previous actual to compare against until the month it covers has closed.',
                        'drilldown' => $this->drilldown('/dashboard/ai-insights', ['panel' => 'forecast']),
                        'footnote'  => 'Method: mean of the last 3 complete months, over ' . $forecast['months_observed'] . ' months observed.',
                    ],
                ),
        ];
    }

    /** @return array{count: int, covered: int}|null */
    private function stockOutRisk(): array
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
            'basis'        => 'Orders raised in ' . $this->period->label() . '. Every estimate states its baseline and its assumption, '
                . 'and overlapping cards are excluded from the headline total rather than added to it.',
        ]);
    }

    /**
     * Panel C — forecasts.
     *
     * Three sections that are never merged: what is contractually committed,
     * what the arithmetic suggests, and — only where a model is configured —
     * what it had to say about the two.
     *
     * @return array<string, mixed>
     */
    private function forecastPanel(string $currency): array
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

        // 2. HISTORY, for the statistical part.
        $history = $this->rows(
            "SELECT to_char(date_trunc('month', p.po_date), 'YYYY-MM') AS month,
                    SUM(p.total_amount)::text AS amount, COUNT(*) AS orders
             FROM purchase_orders p
             WHERE {scope} AND p.status <> 'CANCELLED'
               AND p.po_date >= CURRENT_DATE - INTERVAL '12 months'
             GROUP BY 1 ORDER BY 1",
            [],
            'p',
        );

        $forecast = $this->projectSpend($history);

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
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function projectSpend(array $history): array
    {
        $currentMonth = gmdate('Y-m');
        $complete = array_values(array_filter($history, static fn (array $r) => (string) $r['month'] !== $currentMonth));

        if (count($complete) < self::FORECAST_MIN_MONTHS) {
            return [
                'kind'      => 'statistical',
                'available' => false,
                'reason'    => 'A projection needs at least ' . self::FORECAST_MIN_MONTHS . ' complete months of ordering history in this scope; there '
                    . (count($complete) === 1 ? 'is 1' : 'are ' . count($complete)) . '. Nothing is extrapolated from less.',
                'months_available' => count($complete),
                'history'   => array_map(static fn (array $r) => ['month' => $r['month'], 'amount' => Decimal::of($r['amount'])], $complete),
            ];
        }

        $window = array_slice($complete, -3);
        $amounts = array_map(static fn (array $r) => Decimal::of($r['amount']), $window);
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
            'range'     => ['low' => $low, 'high' => $high],
            'range_label' => 'Range shown is the lowest and highest of those three months, not a confidence interval.',
            'basis'     => 'ORDERED value raised per month, uncancelled. It is what this application knows; posted purchases are Smart Books\' figure and are shown on the Overview.',
            'caveat'    => 'A three-month mean carries no seasonality and no knowledge of a decision already taken. Treat it as a starting point for a conversation, not a budget.',
            'history'   => array_map(static fn (array $r) => ['month' => $r['month'], 'amount' => Decimal::of($r['amount']), 'orders' => (int) $r['orders']], $complete),
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
