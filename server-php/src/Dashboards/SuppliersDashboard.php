<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Http;

/**
 * Dashboard 3 — Suppliers.
 *
 * Whether a supplier is worth buying from, and what to do when they are not.
 *
 * Three rules run through the whole screen:
 *
 *  - SAMPLE SIZE IS SHOWN. One late delivery out of one is not a 0% on-time
 *    supplier, and a rating that says so will be ignored the first time it is
 *    checked. Every rate carries the count it came from.
 *  - PRICES ARE COMPARED LIKE WITH LIKE. Same item, same unit, same currency,
 *    rate before discount, freight and tax. Anything else is not a price move.
 *  - THE SCORE SHOWS ITS WORKING. Components, weights, what was missing and the
 *    period. A supplier score nobody can reconstruct is a score nobody trusts.
 */
final class SuppliersDashboard extends Dashboard
{
    /** Weights for the composite score. Published to the client with the score. */
    private const WEIGHTS = [
        'on_time'    => 40,
        'acceptance' => 35,
        'fulfilment' => 25,
    ];

    /** Below this many observations a component is reported but not scored. */
    private const MIN_SAMPLE = 3;

    public function view(): string
    {
        return 'suppliers';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        if (!$this->can('supplier.view')) {
            Http::forbidden('You do not have permission to view suppliers.');
        }

        $performance = $this->performance();

        return $this->envelope(
            $this->metrics($performance),
            [
                'matrix'        => $this->matrix($performance),
                'price_movement' => $this->priceMovement(),
                'delivery_trend' => $this->deliveryTrend(),
                'concentration' => $this->concentration($performance),
                'detail'        => $this->detail(),
            ],
            ['score_model' => [
                'weights'      => self::WEIGHTS,
                'min_sample'   => self::MIN_SAMPLE,
                'period'       => $this->period->label(),
                'description'  => 'A weighted average of the components that have at least ' . self::MIN_SAMPLE
                    . ' observations in this period. A component with fewer is shown but not scored, and the weights are '
                    . 're-normalised over the components that did count, so a supplier is never penalised for data that does not exist.',
            ]],
        );
    }

    // -----------------------------------------------------------------------

    /**
     * The performance facts, computed once and reused by the cards, the matrix
     * and the concentration panel.
     *
     * On-time is judged per RECEIPT against the order's promised date, and
     * orders still overdue with nothing received are counted separately so they
     * cannot vanish from the picture by never producing a receipt at all.
     *
     * @return list<array<string, mixed>>
     */
    private function performance(): array
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
     * @param list<array<string, mixed>> $performance
     * @return list<array<string, mixed>>
     */
    private function metrics(array $performance): array
    {
        $currency = $this->documentCurrency() ?? 'INR';

        $receipts = 0;
        $onTime = 0;
        $inspected = 0;
        $accepted = 0;
        $issues = 0;
        $totalValue = Decimal::ZERO;
        $top = Decimal::ZERO;

        foreach ($performance as $row) {
            $receipts += (int) $row['receipt_count'];
            $onTime += (int) $row['on_time_count'];
            $inspected += (int) $row['inspected_lines'];
            $accepted += (int) $row['inspected_lines'] - (int) $row['rejected_lines'];
            $issues += (int) $row['open_claims'];
            $value = Decimal::of($row['ordered_value']);
            $totalValue = Decimal::add($totalValue, $value);
            $top = Decimal::max($top, $value);
        }

        $onTimePc = $receipts >= self::MIN_SAMPLE ? Decimal::percentOf((string) $onTime, (string) $receipts, 1) : null;
        $acceptancePc = $inspected >= self::MIN_SAMPLE ? Decimal::percentOf((string) $accepted, (string) $inspected, 1) : null;
        $concentrationPc = Decimal::isZero($totalValue) ? null : Decimal::percentOf($top, $totalValue, 1);

        $terms = $this->weightedPaymentTerms($performance, $totalValue);

        return [
            Metric::ready(
                'active_suppliers',
                'Suppliers bought from',
                (string) count($performance),
                'Suppliers with at least one uncancelled purchase order dated in ' . $this->period->label() . '.',
                [
                    'direction' => Metric::NEUTRAL,
                    'comparison_unavailable_reason' => 'A supplier count for a previous period is not a like-for-like comparison unless the same suppliers are in both.',
                    'drilldown' => $this->drilldown('/suppliers'),
                ],
            ),
            $onTimePc === null
                ? Metric::unavailable(
                    'on_time_delivery',
                    'On-time delivery',
                    'Only ' . $receipts . ' receipt' . ($receipts === 1 ? '' : 's') . ' in this period — fewer than the ' . self::MIN_SAMPLE . ' needed to state a rate.',
                    'Receipts recorded on or before the order\'s promised date, as a share of receipts against orders that had one.',
                    ['format' => 'percent', 'direction' => Metric::HIGHER_IS_BETTER],
                )
                : Metric::ready(
                    'on_time_delivery',
                    'On-time delivery',
                    $onTimePc,
                    'Receipts on or before the promised date, as a share of ' . $receipts . ' receipts against orders that carried a promised date.',
                    [
                        'format'    => 'percent',
                        'direction' => Metric::HIGHER_IS_BETTER,
                        'explanation' => 'Measured per receipt. Orders that are overdue with nothing received produce no receipt and so cannot flatter this figure — they are counted separately as overdue lines.',
                        'comparison_unavailable_reason' => 'Comparing rates across periods needs both to clear the minimum sample; the previous period is shown in the trend panel instead.',
                        'drilldown' => $this->drilldown('/dashboard/suppliers', ['panel' => 'delivery']),
                        'footnote'  => $onTime . ' of ' . $receipts . ' receipts.',
                    ],
                ),
            $acceptancePc === null
                ? Metric::unavailable(
                    'acceptance_rate',
                    'Receipt acceptance',
                    'Only ' . $inspected . ' inspected line' . ($inspected === 1 ? '' : 's') . ' in this period — fewer than the ' . self::MIN_SAMPLE . ' needed to state a rate.',
                    'Order lines received without a rejected quantity, as a share of lines with any quantity received.',
                    ['format' => 'percent', 'direction' => Metric::HIGHER_IS_BETTER],
                )
                : Metric::ready(
                    'acceptance_rate',
                    'Receipt acceptance',
                    $acceptancePc,
                    'Order lines received with nothing rejected, as a share of ' . $inspected . ' lines with quantity received.',
                    [
                        'format'    => 'percent',
                        'direction' => Metric::HIGHER_IS_BETTER,
                        'explanation' => 'Counted per LINE, not per unit. Pieces, kilograms and litres cannot be added together, so a unit-weighted acceptance rate across mixed items would not be a number.',
                        'comparison_unavailable_reason' => 'Rates are compared in the delivery trend panel, where the sample size for each period is visible.',
                        'drilldown' => $this->drilldown('/dashboard/suppliers', ['panel' => 'delivery']),
                        'footnote'  => $accepted . ' of ' . $inspected . ' lines.',
                    ],
                ),
            $terms['metric'],
            $concentrationPc === null
                ? Metric::unavailable(
                    'concentration',
                    'Largest supplier share',
                    'No uncancelled orders in this period, so there is no base to take a share of.',
                    'The largest single supplier\'s ordered value as a share of all ordered value in the period.',
                    ['format' => 'percent', 'direction' => Metric::LOWER_IS_BETTER],
                )
                : Metric::ready(
                    'concentration',
                    'Largest supplier share',
                    $concentrationPc,
                    'The largest single supplier\'s ordered value as a share of ' . Format::money($totalValue, $currency) . ' ordered in ' . $this->period->label() . '.',
                    [
                        'format'    => 'percent',
                        'direction' => Metric::LOWER_IS_BETTER,
                        'explanation' => 'Ordered value, not posted purchases: this measures dependence on a supplier for what has been committed, which is the exposure a buyer can still act on.',
                        'comparison_unavailable_reason' => 'The largest supplier may differ between periods, so the two shares would not describe the same thing.',
                        'drilldown' => $this->drilldown('/dashboard/suppliers', ['panel' => 'concentration']),
                    ],
                ),
            Metric::ready(
                'supplier_issues',
                'Supplier issues open',
                (string) $issues,
                'Claims raised against suppliers that are not settled, closed or rejected.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'comparison_unavailable_reason' => 'An open queue is a position, not a period total.',
                    'drilldown' => $this->drilldown('/claims'),
                ],
            ),
        ];
    }

    /**
     * Payment terms, weighted by ordered value — but only when the inputs
     * support it.
     *
     * Terms are free text on the supplier profile. Where they are not written
     * as a number of days, they cannot be averaged, and the honest answer is to
     * say what share could not be read rather than to average the ones that
     * could and present it as the company's terms.
     *
     * @param list<array<string, mixed>> $performance
     * @return array{metric: array<string, mixed>}
     */
    private function weightedPaymentTerms(array $performance, string $totalValue): array
    {
        $weighted = Decimal::ZERO;
        $covered = Decimal::ZERO;

        foreach ($performance as $row) {
            $days = self::daysFromTerms($row['payment_terms'] ?? null);
            if ($days === null) {
                continue;
            }
            $value = Decimal::of($row['ordered_value']);
            $covered = Decimal::add($covered, $value);
            $weighted = Decimal::add($weighted, Decimal::mul($value, (string) $days));
        }

        $basis = 'Payment terms on the supplier procurement profile, weighted by ordered value in ' . $this->period->label() . '.';

        if (Decimal::isZero($covered) || Decimal::isZero($totalValue)) {
            return ['metric' => Metric::unavailable(
                'payment_terms',
                'Weighted payment terms',
                'No supplier in this period has payment terms recorded as a number of days, so there is nothing to weight.',
                $basis,
                ['format' => 'days', 'direction' => Metric::HIGHER_IS_BETTER],
            )];
        }

        $coveragePc = Decimal::percentOf($covered, $totalValue, 0) ?? '0';
        if (Decimal::cmp($coveragePc, '60') < 0) {
            return ['metric' => Metric::unavailable(
                'payment_terms',
                'Weighted payment terms',
                'Only ' . Format::percent($coveragePc, 0) . ' of ordered value has readable payment terms. An average over the rest would not describe this company\'s terms.',
                $basis,
                ['format' => 'days', 'direction' => Metric::HIGHER_IS_BETTER],
            )];
        }

        $average = Decimal::div($weighted, $covered, 1);

        return ['metric' => Metric::ready(
            'payment_terms',
            'Weighted payment terms',
            $average,
            $basis,
            [
                'format'    => 'days',
                'direction' => Metric::HIGHER_IS_BETTER,
                'explanation' => 'Covers ' . Format::percent($coveragePc, 0) . ' of ordered value — suppliers whose terms are not written as a number of days are excluded rather than assumed.',
                'comparison_unavailable_reason' => 'Terms change rarely; a period-on-period change would usually reflect which suppliers were bought from, not a renegotiation.',
                'drilldown' => $this->drilldown('/suppliers'),
            ],
        )];
    }

    /** "45 days", "Net 30", "30" → 45, 30, 30. Anything else → null. */
    private static function daysFromTerms(mixed $terms): ?int
    {
        if (!is_string($terms) || trim($terms) === '') {
            return null;
        }
        if (preg_match('/(\d{1,3})\s*(?:days?|d\b)?/i', $terms, $matches) !== 1) {
            return null;
        }
        $days = (int) $matches[1];

        return ($days >= 0 && $days <= 365) ? $days : null;
    }

    /**
     * Panel A — the performance matrix.
     *
     * @param list<array<string, mixed>> $performance
     * @return array<string, mixed>
     */
    private function matrix(array $performance): array
    {
        $currency = $this->documentCurrency() ?? 'INR';
        $rows = [];

        foreach ($performance as $row) {
            $receipts = (int) $row['receipt_count'];
            $inspected = (int) $row['inspected_lines'];
            $lines = (int) $row['line_count'];

            $onTime = $receipts >= self::MIN_SAMPLE
                ? Decimal::percentOf((string) $row['on_time_count'], (string) $receipts, 1)
                : null;
            $acceptance = $inspected >= self::MIN_SAMPLE
                ? Decimal::percentOf((string) ($inspected - (int) $row['rejected_lines']), (string) $inspected, 1)
                : null;
            $fulfilment = $lines >= self::MIN_SAMPLE
                ? Decimal::percentOf((string) $row['complete_lines'], (string) $lines, 1)
                : null;

            $score = $this->score($onTime, $acceptance, $fulfilment);
            $value = Decimal::of($row['ordered_value']);
            $rowCurrency = ((int) $row['currency_count']) > 1 ? null : (string) ($row['currency_code'] ?? $currency);

            $rows[] = [
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name'   => $row['supplier_name'],
                'qualification_status' => $row['qualification_status'] ?? 'none',
                'is_preferred'    => (bool) ($row['is_preferred'] ?? false),
                'risk_flag'       => $row['risk_flag'],
                'currency'        => $rowCurrency,
                'ordered_value'   => $this->canSeeValues() ? $value : null,
                'ordered_formatted' => $this->canSeeValues()
                    ? ($rowCurrency === null ? 'Mixed currencies' : Format::money($value, $rowCurrency))
                    : null,
                'po_count'        => (int) $row['po_count'],
                'on_time_pc'      => $onTime,
                'on_time_sample'  => $receipts,
                'on_time_label'   => $onTime === null
                    ? ($receipts === 0 ? 'No receipts yet' : $receipts . ' receipt' . ($receipts === 1 ? '' : 's') . ' — too few to rate')
                    : Format::percent($onTime, 1) . ' of ' . $receipts,
                'acceptance_pc'   => $acceptance,
                'acceptance_sample' => $inspected,
                'acceptance_label' => $acceptance === null
                    ? ($inspected === 0 ? 'Nothing received yet' : $inspected . ' line' . ($inspected === 1 ? '' : 's') . ' — too few to rate')
                    : Format::percent($acceptance, 1) . ' of ' . $inspected,
                'fulfilment_pc'   => $fulfilment,
                'fulfilment_sample' => $lines,
                'avg_lead_days'   => $row['avg_lead_days'] === null ? null : Decimal::round(Decimal::of((string) (float) $row['avg_lead_days']), 0),
                'ack_hours'       => $row['ack_hours'] === null ? null : Decimal::round(Decimal::of((string) (float) $row['ack_hours']), 1),
                'profile_lead_days' => $row['operational_lead_days'] === null ? null : (int) $row['operational_lead_days'],
                'overdue_lines'   => (int) $row['overdue_lines'],
                'open_exposure'   => $this->canSeeValues() ? Decimal::of($row['open_exposure']) : null,
                'open_exposure_formatted' => ($this->canSeeValues() && $rowCurrency !== null)
                    ? Format::money(Decimal::of($row['open_exposure']), $rowCurrency)
                    : null,
                'claim_count'     => (int) $row['claim_count'],
                'open_claims'     => (int) $row['open_claims'],
                'score'           => $score['value'],
                'score_components' => $score['components'],
                'score_missing'   => $score['missing'],
                'next_action'     => $this->supplierAction($row, $onTime, $acceptance),
                'route'           => '/suppliers',
                'filters'         => ['supplier_id' => (string) $row['supplier_account_id']],
            ];
        }

        return $this->panel([
            'rows'     => $rows,
            'currency' => $currency,
            'values_visible' => $this->canSeeValues(),
            'basis'    => 'Orders raised in ' . $this->period->label()
                . '. Delivery and acceptance are computed from this application\'s receipt records against the promised dates on the orders; the ledger figures beside a supplier are read live from Smart Books in the detail drawer.',
        ]);
    }

    /**
     * The composite score, with its working.
     *
     * Components below the minimum sample are excluded and the remaining
     * weights re-normalised, so a supplier with one delivery is not scored as
     * if the one delivery were the whole picture.
     *
     * @return array{value: ?string, components: list<array<string, mixed>>, missing: list<string>}
     */
    private function score(?string $onTime, ?string $acceptance, ?string $fulfilment): array
    {
        $inputs = [
            'on_time'    => ['label' => 'On-time delivery', 'value' => $onTime],
            'acceptance' => ['label' => 'Acceptance', 'value' => $acceptance],
            'fulfilment' => ['label' => 'Line fulfilment', 'value' => $fulfilment],
        ];

        $weightTotal = 0;
        $components = [];
        $missing = [];

        foreach ($inputs as $key => $input) {
            if ($input['value'] === null) {
                $missing[] = $input['label'];
                $components[] = ['key' => $key, 'label' => $input['label'], 'weight' => self::WEIGHTS[$key], 'value' => null, 'counted' => false];
                continue;
            }
            $weightTotal += self::WEIGHTS[$key];
            $components[] = ['key' => $key, 'label' => $input['label'], 'weight' => self::WEIGHTS[$key], 'value' => $input['value'], 'counted' => true];
        }

        if ($weightTotal === 0) {
            return ['value' => null, 'components' => $components, 'missing' => $missing];
        }

        $weighted = Decimal::ZERO;
        foreach ($components as $component) {
            if (!$component['counted']) {
                continue;
            }
            $weighted = Decimal::add($weighted, Decimal::mul((string) $component['value'], (string) $component['weight']));
        }

        return [
            'value'      => Decimal::div($weighted, (string) $weightTotal, 1),
            'components' => $components,
            'missing'    => $missing,
        ];
    }

    /** @param array<string, mixed> $row */
    private function supplierAction(array $row, ?string $onTime, ?string $acceptance): string
    {
        if ((int) $row['open_claims'] > 0) {
            return 'Settle the open claim';
        }
        if ((int) $row['overdue_lines'] > 0) {
            return 'Chase ' . (int) $row['overdue_lines'] . ' overdue line' . (((int) $row['overdue_lines']) === 1 ? '' : 's');
        }
        if ($onTime !== null && Decimal::cmp($onTime, '80') < 0) {
            return 'Review delivery performance';
        }
        if ($acceptance !== null && Decimal::cmp($acceptance, '95') < 0) {
            return 'Raise the quality issue';
        }
        if (($row['qualification_status'] ?? 'none') !== 'approved') {
            return 'Qualify this supplier';
        }

        return 'Nothing outstanding';
    }

    /**
     * Panel B — the detail drawer.
     *
     * Only fetched when a supplier is chosen, and it is the one place a live
     * Books read happens per supplier: their open items, by their own due dates.
     *
     * @return array<string, mixed>
     */
    private function detail(): array
    {
        $supplierId = Http::intParam('supplier_id') ?? $this->filters->supplierAccountId;
        if ($supplierId === null || $supplierId <= 0) {
            return $this->panel(['loaded' => false, 'basis' => 'Choose a supplier to load their orders, prices and live Books position.']);
        }

        $profile = $this->row(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :ctx_cmp_id AND supplier_account_id = :id',
            ['id' => $supplierId],
        );

        $orders = $this->rows(
            "SELECT p.po_id, p.po_no, p.po_date, p.status, p.promised_date, p.currency_code,
                    p.total_amount::text AS total_amount,
                    (SELECT COUNT(*) FROM purchase_order_lines l WHERE l.po_id = p.po_id AND l.ordered_qty > l.received_qty) AS open_lines
             FROM purchase_orders p
             WHERE {scope} AND p.supplier_account_id = :id
             ORDER BY p.po_date DESC LIMIT 15",
            ['id' => $supplierId],
            'p',
        );

        $claims = $this->rows(
            'SELECT claim_id, claim_no, claim_date, claim_kind, status,
                    claimed_amount::text AS claimed_amount, settled_amount::text AS settled_amount
             FROM purchase_claims
             WHERE cmp_id = :ctx_cmp_id AND fy_id = :ctx_fy_id AND supplier_account_id = :id
             ORDER BY claim_date DESC LIMIT 10',
            ['id' => $supplierId],
        );

        $dues = ['available' => false, 'reason' => 'Not requested.'];
        if ($this->canSeeValues()) {
            $open = $this->booksReader()->openItems($supplierId, $this->period->to);
            if ($open['ok']) {
                $total = Decimal::sum(array_map(static fn ($r) => (string) $r['pending_amount'], $open['rows']));
                $overdue = Decimal::ZERO;
                $undated = 0;
                foreach ($open['rows'] as $item) {
                    if (!$item['has_due_date']) {
                        $undated++;
                        continue;
                    }
                    if (($item['days_overdue'] ?? 0) > 0) {
                        $overdue = Decimal::add($overdue, (string) $item['pending_amount']);
                    }
                }
                $dues = [
                    'available' => true,
                    'as_of'     => $this->period->to,
                    'total'     => $total,
                    'overdue'   => $overdue,
                    'rows'      => array_slice($open['rows'], 0, 25),
                    'undated_count' => $undated,
                    'basis'     => 'Open items from Smart Books as at ' . Format::date($this->period->to)
                        . '. Purchases holds no payable figure of its own, so this can never disagree with the accounts.',
                ];
            } else {
                $dues = ['available' => false, 'reason' => (string) $open['error']];
            }
        } else {
            $dues = ['available' => false, 'reason' => 'Supplier balances need the reports.view or cost.view permission.'];
        }

        return $this->panel([
            'loaded'  => true,
            'supplier_account_id' => $supplierId,
            'profile' => $profile === null ? null : [
                'qualification_status' => $profile['qualification_status'],
                'is_preferred'    => (bool) $profile['is_preferred'],
                'contact_id'      => $profile['contact_id'],
                'operational_lead_days' => $profile['operational_lead_days'] === null ? null : (int) $profile['operational_lead_days'],
                'payment_terms'   => $profile['payment_terms'],
                'incoterm'        => $profile['incoterm'],
                'risk_flag'       => $profile['risk_flag'],
                'notes'           => $profile['notes'],
                'approved_at'     => $profile['approved_at'],
            ],
            'identity_note' => 'The supplier\'s legal identity belongs to Contacts and their ledger account to Smart Books. What is editable here is the procurement profile only.',
            'orders'  => array_map(fn (array $o) => [
                'po_id'   => (int) $o['po_id'],
                'po_no'   => $o['po_no'],
                'po_date' => $o['po_date'],
                'po_date_label' => Format::date((string) $o['po_date']),
                'status'  => $o['status'],
                'promised_date' => $o['promised_date'],
                'open_lines' => (int) $o['open_lines'],
                'value'   => $this->canSeeValues() ? Decimal::of($o['total_amount']) : null,
                'value_formatted' => $this->canSeeValues() ? Format::money(Decimal::of($o['total_amount']), (string) $o['currency_code']) : null,
                'route'   => '/purchase-orders/' . $o['po_id'],
            ], $orders),
            'claims'  => array_map(static fn (array $c) => [
                'claim_id'   => (int) $c['claim_id'],
                'claim_no'   => $c['claim_no'],
                'claim_date' => $c['claim_date'],
                'claim_kind' => $c['claim_kind'],
                'status'     => $c['status'],
                'claimed'    => Decimal::of($c['claimed_amount']),
                'settled'    => Decimal::of($c['settled_amount']),
            ], $claims),
            'dues'    => $dues,
            'price_history' => $this->supplierPrices($supplierId),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function supplierPrices(int $supplierId): array
    {
        $rows = $this->rows(
            "SELECT l.item_id, l.unit_id, p.currency_code,
                    COUNT(*) AS observations,
                    MIN(l.agreed_rate)::text AS min_rate,
                    MAX(l.agreed_rate)::text AS max_rate,
                    MIN(p.po_date) AS first_date, MAX(p.po_date) AS last_date
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.supplier_account_id = :id AND l.item_id IS NOT NULL
               AND p.status <> 'CANCELLED' AND l.agreed_rate > 0
             GROUP BY l.item_id, l.unit_id, p.currency_code
             ORDER BY COUNT(*) DESC LIMIT 15",
            ['id' => $supplierId],
            'p',
        );

        $itemIds = array_map(static fn ($r) => (int) $r['item_id'], $rows);
        $items = $itemIds === [] ? [] : $this->inventoryReader()->items($itemIds)['items'];

        return array_map(static fn (array $r) => [
            'item_id'   => (int) $r['item_id'],
            'item_label' => $items[(int) $r['item_id']]['name'] ?? ('Item ' . $r['item_id']),
            'unit'      => $items[(int) $r['item_id']]['uom'] ?? null,
            'currency'  => $r['currency_code'],
            'observations' => (int) $r['observations'],
            'min_rate'  => Decimal::of($r['min_rate']),
            'max_rate'  => Decimal::of($r['max_rate']),
            'first_date' => $r['first_date'],
            'last_date' => $r['last_date'],
        ], $rows);
    }

    /**
     * Panel C — price movement.
     *
     * Same item, same unit, same currency, at least three observations. The
     * comparison is first-seen against last-seen agreed rate, before discount,
     * freight and tax, and the panel says so.
     *
     * @return array<string, mixed>
     */
    private function priceMovement(): array
    {
        if (!$this->can('cost.view') && !$this->can('reports.view')) {
            return $this->withheld('cost.view');
        }

        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $rows = $this->rows(
            "WITH observations AS (
                SELECT l.item_id, l.unit_id, p.currency_code, l.agreed_rate, p.po_date,
                       p.supplier_account_id, p.supplier_name_snapshot,
                       ROW_NUMBER() OVER (PARTITION BY l.item_id, l.unit_id, p.currency_code ORDER BY p.po_date ASC,  l.line_id ASC)  AS first_seen,
                       ROW_NUMBER() OVER (PARTITION BY l.item_id, l.unit_id, p.currency_code ORDER BY p.po_date DESC, l.line_id DESC) AS last_seen,
                       COUNT(*)    OVER (PARTITION BY l.item_id, l.unit_id, p.currency_code) AS observations
                FROM purchase_order_lines l
                JOIN purchase_orders p ON p.po_id = l.po_id
                WHERE {scope} AND p.po_date BETWEEN :from AND :to
                  AND p.status <> 'CANCELLED' AND l.item_id IS NOT NULL AND l.agreed_rate > 0" . $filterSql . "
            ),
            -- PostgreSQL has no COUNT(DISTINCT x) OVER (), so how many
            -- suppliers quoted an item is counted in its own aggregate.
            suppliers_per_item AS (
                SELECT item_id, unit_id, currency_code, COUNT(DISTINCT supplier_account_id) AS supplier_count
                FROM observations
                GROUP BY item_id, unit_id, currency_code
            )
            SELECT f.item_id, f.unit_id, f.currency_code, f.observations, s.supplier_count,
                   f.agreed_rate::text AS first_rate, f.po_date AS first_date,
                   t.agreed_rate::text AS last_rate,  t.po_date AS last_date,
                   t.supplier_name_snapshot AS last_supplier, t.supplier_account_id AS last_supplier_id
            FROM observations f
            JOIN observations t
              ON t.item_id = f.item_id
             AND t.unit_id IS NOT DISTINCT FROM f.unit_id
             AND t.currency_code = f.currency_code
             AND t.last_seen = 1
            JOIN suppliers_per_item s
              ON s.item_id = f.item_id
             AND s.unit_id IS NOT DISTINCT FROM f.unit_id
             AND s.currency_code = f.currency_code
            WHERE f.first_seen = 1 AND f.observations >= :min_sample
            ORDER BY ABS((t.agreed_rate - f.agreed_rate) / f.agreed_rate) DESC
            LIMIT 20",
            $this->period->params() + $filterParams + ['min_sample' => self::MIN_SAMPLE],
            'p',
        );

        $itemIds = array_map(static fn ($r) => (int) $r['item_id'], $rows);
        $items = [];
        if ($itemIds !== []) {
            $lookup = $this->inventoryReader()->items($itemIds);
            $items = $lookup['items'];
            if ($lookup['ok']) {
                $this->sources->ready('inventory', InventoryReader::LABEL);
            } elseif (!$this->sources->isReady('inventory')) {
                $this->sources->unavailable('inventory', InventoryReader::LABEL, (string) $lookup['error']);
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $first = Decimal::of($row['first_rate']);
            $last = Decimal::of($row['last_rate']);
            $itemId = (int) $row['item_id'];
            $out[] = [
                'item_id'    => $itemId,
                'item_label' => $items[$itemId]['name'] ?? ('Item ' . $itemId),
                'unit'       => $items[$itemId]['uom'] ?? null,
                'currency'   => $row['currency_code'],
                'observations' => (int) $row['observations'],
                'supplier_count' => (int) $row['supplier_count'],
                'first_rate' => $first,
                'first_formatted' => Format::money($first, (string) $row['currency_code']),
                'first_date' => $row['first_date'],
                'last_rate'  => $last,
                'last_formatted' => Format::money($last, (string) $row['currency_code']),
                'last_date'  => $row['last_date'],
                'last_supplier' => $row['last_supplier'],
                'last_supplier_id' => (int) $row['last_supplier_id'],
                'change_pc'  => Decimal::percentChange($first, $last, 1),
                'direction'  => Decimal::cmp($last, $first) > 0 ? 'up' : (Decimal::cmp($last, $first) < 0 ? 'down' : 'flat'),
                'route'      => '/purchase-orders',
                'filters'    => ['item_id' => (string) $itemId],
            ];
        }

        return $this->panel([
            'rows'  => $out,
            'observation_period' => $this->period->label(),
            'min_sample' => self::MIN_SAMPLE,
            'basis' => 'First and last agreed rate for the SAME item, unit and currency, over at least ' . self::MIN_SAMPLE
                . ' orders in ' . $this->period->label() . '. Rates are before line discount, freight and tax — the tax actually charged is decided when the bill is entered, and the inventory cost of what arrived is Inventory\'s figure, not this one.',
        ]);
    }

    /**
     * Panel D — delivery and quality trend.
     *
     * Per month, with the sample size against every point. A trend line drawn
     * through months of one delivery each is a drawing, not a trend.
     *
     * @return array<string, mixed>
     */
    private function deliveryTrend(): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $rows = $this->rows(
            "SELECT to_char(date_trunc('month', r.received_at), 'YYYY-MM') AS month,
                    COUNT(*)                                                AS receipts,
                    COUNT(*) FILTER (WHERE r.received_at <= p.promised_date) AS on_time
             FROM purchase_receipt_requests r
             JOIN purchase_orders p ON p.po_id = r.po_id
             WHERE {scope} AND r.status = 'ACCEPTED' AND p.promised_date IS NOT NULL
               AND r.received_at >= CURRENT_DATE - INTERVAL '12 months'" . $filterSql . "
             GROUP BY 1 ORDER BY 1",
            $filterParams,
            'p',
        );

        $points = array_map(static fn (array $r) => [
            'period'  => $r['month'],
            'sample'  => (int) $r['receipts'],
            'on_time' => (int) $r['on_time'],
            'on_time_pc' => ((int) $r['receipts']) >= self::MIN_SAMPLE
                ? Decimal::percentOf((string) $r['on_time'], (string) $r['receipts'], 1)
                : null,
            'rated'   => ((int) $r['receipts']) >= self::MIN_SAMPLE,
        ], $rows);

        // Overdue lines with no receipt at all. These never appear in an on-time
        // rate because they never produced a receipt, so they are reported here
        // rather than being allowed to improve the picture by their absence.
        $stillWaiting = $this->row(
            "SELECT COUNT(*) AS lines, COUNT(DISTINCT p.po_id) AS orders
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.status IN ('ISSUED','ACKNOWLEDGED','PARTIALLY_RECEIVED')
               AND l.received_qty = 0 AND COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE" . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        return $this->panel([
            'points' => $points,
            'min_sample' => self::MIN_SAMPLE,
            'still_waiting' => [
                'lines'  => (int) ($stillWaiting['lines'] ?? 0),
                'orders' => (int) ($stillWaiting['orders'] ?? 0),
                'note'   => 'Overdue lines with nothing received. They produce no receipt, so they cannot appear in an on-time rate — they are counted here instead of quietly improving it.',
            ],
            'basis'  => 'Accepted receipts per month over the last twelve months, against the promised date on the order. A month with fewer than ' . self::MIN_SAMPLE . ' receipts is shown with its count but is not given a rate.',
        ]);
    }

    /**
     * Panel E — concentration exposure.
     *
     * @param list<array<string, mixed>> $performance
     * @return array<string, mixed>
     */
    private function concentration(array $performance): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('cost.view');
        }

        $currency = $this->documentCurrency();
        if ($currency === null) {
            return $this->unavailablePanel('Orders in this period are in more than one currency; shares of a mixed total are not shown.');
        }

        $total = Decimal::sum(array_map(static fn ($r) => Decimal::of($r['ordered_value']), $performance));

        $suppliers = [];
        foreach (array_slice($performance, 0, 8) as $row) {
            $value = Decimal::of($row['ordered_value']);
            $suppliers[] = [
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name' => $row['supplier_name'],
                'amount'    => $value,
                'formatted' => Format::money($value, $currency),
                'share_pc'  => Decimal::percentOf($value, $total, 1),
                'qualification_status' => $row['qualification_status'] ?? 'none',
            ];
        }

        // Items bought from exactly one supplier and worth enough to matter.
        $soleSource = $this->rows(
            "SELECT l.item_id,
                    MIN(p.supplier_account_id)      AS supplier_account_id,
                    MIN(p.supplier_name_snapshot)   AS supplier_name,
                    SUM(l.ordered_qty * l.agreed_rate)::text AS value,
                    COUNT(*)                        AS line_count
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'
               AND l.item_id IS NOT NULL
             GROUP BY l.item_id
             HAVING COUNT(DISTINCT p.supplier_account_id) = 1 AND COUNT(*) >= 2
             ORDER BY SUM(l.ordered_qty * l.agreed_rate) DESC
             LIMIT 8",
            $this->period->params(),
            'p',
        );

        $itemIds = array_map(static fn ($r) => (int) $r['item_id'], $soleSource);
        $items = $itemIds === [] ? [] : $this->inventoryReader()->items($itemIds)['items'];

        return $this->panel([
            'currency'  => $currency,
            'total'     => $total,
            'total_formatted' => Format::money($total, $currency),
            'suppliers' => $suppliers,
            'sole_source' => array_map(static fn (array $r) => [
                'item_id'    => (int) $r['item_id'],
                'item_label' => $items[(int) $r['item_id']]['name'] ?? ('Item ' . $r['item_id']),
                'supplier_account_id' => (int) $r['supplier_account_id'],
                'supplier_name' => $r['supplier_name'],
                'value'      => Decimal::of($r['value']),
                'formatted'  => Format::money(Decimal::of($r['value']), $currency),
                'line_count' => (int) $r['line_count'],
            ], $soleSource),
            'basis'     => 'Share of ordered value in ' . $this->period->label()
                . '. Sole-source items are those bought two or more times in the period and always from the same supplier — evidence of dependence, not proof that no alternative exists.',
        ]);
    }
}
