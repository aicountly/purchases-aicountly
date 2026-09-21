<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Deterministic procurement rules.
 *
 * This is what a purchase head would notice reading the same figures, written
 * down as code: no model, no sampling, no confidence percentage invented to
 * make a guess look precise. Given the same data it produces the same list,
 * which is what makes it safe to act on and possible to test.
 *
 * It is also the floor under the AI Insights screen. When the model is not
 * configured or does not answer, these rules still run and the screen says
 * plainly that what is shown is rules-based.
 */
final class InsightRules
{
    /**
     * The three-to-five ranked issues on the Overview briefing.
     *
     * @param array<string, mixed> $facts
     * @return list<array<string, mixed>>
     */
    public static function briefing(Context $ctx, Period $period, array $facts): array
    {
        $items = [];
        $currency = (string) ($facts['currency'] ?? 'INR');
        $books = $facts['books'] ?? null;
        $delayed = (array) ($facts['delayed'] ?? []);
        $commitment = (array) ($facts['commitment'] ?? []);
        $approvals = (int) ($facts['my_approvals'] ?? 0);
        $pipeline = (array) ($facts['pipeline'] ?? []);

        // 1. Money already stuck between products. Nothing else on this screen
        //    is as urgent as a document neither product agrees exists.
        $stuck = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_integration_commands
             WHERE cmp_id = :cmp AND fy_id = :fy AND status IN ('FAILED', 'BLOCKED')",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId],
        );
        if ($stuck > 0) {
            $items[] = self::item(
                'stuck-commands',
                'danger',
                $stuck . ' cross-app request did not finish',
                'Something this app asked Smart Books or Inventory to do has failed or been refused. Nothing is retried behind your back, so these sit until somebody opens them.',
                null,
                $stuck,
                'Open the documents',
                '/bills',
                ['status' => 'FAILED'],
                900,
            );
        }

        // 2. Open match exceptions, ranked by the money in dispute.
        $exceptions = Db::first(
            "SELECT COUNT(*) AS n, COALESCE(SUM(ABS(COALESCE(variance_value, 0))), 0)::text AS variance
             FROM purchase_match_exceptions WHERE cmp_id = :cmp AND status = 'OPEN'",
            ['cmp' => $ctx->cmpId],
        ) ?? [];
        $exceptionCount = (int) ($exceptions['n'] ?? 0);
        if ($exceptionCount > 0) {
            $variance = Decimal::of($exceptions['variance'] ?? '0');
            $items[] = self::item(
                'match-exceptions',
                'danger',
                $exceptionCount . ' bill' . ($exceptionCount === 1 ? '' : 's') . ' failed the three-way match',
                'A supplier bill does not agree with what was ordered or what Inventory recorded as received. None of them reach Smart Books until somebody decides what to do.',
                $variance,
                $exceptionCount,
                'Review exceptions',
                '/dashboard/bills-payables',
                ['panel' => 'matching'],
                800,
                $currency,
            );
        }

        // 3. Overdue supplier money, but only when Books actually answered.
        if (is_array($books) && ($books['ok'] ?? false)) {
            $overdue = Decimal::of(((array) $books['kpis'])['overdue_payables'] ?? '0');
            if (!Decimal::isZero($overdue)) {
                $items[] = self::item(
                    'overdue-payables',
                    'warning',
                    Format::money($overdue, $currency) . ' is past its due date',
                    'Smart Books shows open creditor balances whose due date has passed. Paying late costs the relationship before it costs anything else.',
                    $overdue,
                    null,
                    'Plan payments',
                    '/dashboard/bills-payables',
                    ['bucket' => 'overdue'],
                    700,
                    $currency,
                );
            }
        }

        // 4. Late deliveries, with the committed money behind them.
        $delayedCount = (int) ($delayed['count'] ?? 0);
        if ($delayedCount > 0) {
            $items[] = self::item(
                'late-deliveries',
                $delayedCount > 5 ? 'danger' : 'warning',
                $delayedCount . ' order' . ($delayedCount === 1 ? '' : 's') . ' past the promised date',
                ($delayed['lines'] ?? 0) . ' line' . (((int) ($delayed['lines'] ?? 0)) === 1 ? '' : 's')
                    . ' are still short. Committed value on open orders is '
                    . Format::money(Decimal::of($commitment['value'] ?? '0'), $currency) . '.',
                null,
                $delayedCount,
                'Chase deliveries',
                '/dashboard/procurement',
                ['view' => 'delayed'],
                600,
                $currency,
            );
        }

        // 5. The reader's own approval queue — the one item they can clear now.
        if ($approvals > 0) {
            $items[] = self::item(
                'my-approvals',
                'info',
                $approvals . ' document' . ($approvals === 1 ? '' : 's') . ' waiting on you',
                'Approval requests whose required permission you hold. Anything you raised yourself is excluded.',
                null,
                $approvals,
                'Open approvals',
                '/approvals',
                [],
                500,
            );
        }

        // 6. Requisitions approved and never ordered — demand that quietly ages.
        $stalled = Db::first(
            "SELECT COUNT(*) AS n, COALESCE(SUM(estimated_value), 0)::text AS value
             FROM purchase_requisitions
             WHERE cmp_id = :cmp AND fy_id = :fy AND status = 'APPROVED'
               AND requisition_date < CURRENT_DATE - INTERVAL '14 days'",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId],
        ) ?? [];
        $stalledCount = (int) ($stalled['n'] ?? 0);
        if ($stalledCount > 0) {
            $items[] = self::item(
                'stalled-demand',
                'warning',
                $stalledCount . ' approved requisition' . ($stalledCount === 1 ? '' : 's') . ' not yet ordered',
                'Approved more than a fortnight ago and still not turned into a purchase order. Estimated value '
                    . Format::money(Decimal::of($stalled['value'] ?? '0'), $currency) . ' — an estimate for routing, not a price.',
                Decimal::parse($stalled['value'] ?? null),
                $stalledCount,
                'Open requisitions',
                '/requisitions',
                ['status' => 'APPROVED'],
                400,
                $currency,
            );
        }

        // 7. Quiet is also a finding. A briefing with nothing in it should say so.
        if ($items === []) {
            $items[] = self::item(
                'all-clear',
                'success',
                'Nothing needs a decision right now',
                'No open match exceptions, no failed cross-app requests, no approvals in your queue and no late orders in this scope.',
                null,
                null,
                'Open procurement',
                '/dashboard/procurement',
                [],
                100,
            );
        }

        usort($items, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return $items;
    }

    /**
     * Opportunity cards for the AI Insights screen.
     *
     * Each carries its baseline and the assumption behind the estimate, and
     * they are deliberately NOT added into a headline total: fragmentation and
     * consolidation savings describe the same rupees twice.
     *
     * @return list<array<string, mixed>>
     */
    public static function opportunities(Context $ctx, Period $period, string $currency): array
    {
        $out = [];

        // Fragmented buying: the same item bought from several suppliers, at
        // prices that differ. The saving is stated as "if every order had been
        // at the lowest observed rate", which is an upper bound and says so.
        foreach (Db::all(
            "SELECT l.item_id,
                    COUNT(DISTINCT p.supplier_account_id)            AS supplier_count,
                    COUNT(*)                                          AS line_count,
                    MIN(l.agreed_rate)::text                          AS min_rate,
                    MAX(l.agreed_rate)::text                          AS max_rate,
                    SUM(l.ordered_qty)::text                          AS total_qty,
                    SUM(l.ordered_qty * l.agreed_rate)::text          AS total_value
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE p.cmp_id = :cmp AND p.fy_id = :fy
               AND p.po_date BETWEEN :from AND :to
               AND p.status <> 'CANCELLED' AND l.item_id IS NOT NULL
               AND l.ordered_qty > 0
             GROUP BY l.item_id
             HAVING COUNT(DISTINCT p.supplier_account_id) > 1 AND MIN(l.agreed_rate) < MAX(l.agreed_rate)
             ORDER BY (MAX(l.agreed_rate) - MIN(l.agreed_rate)) * SUM(l.ordered_qty) DESC
             LIMIT 5",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $period->params(),
        ) as $row) {
            $minRate = Decimal::of($row['min_rate']);
            $qty = Decimal::of($row['total_qty']);
            $spent = Decimal::of($row['total_value']);
            $atBest = Decimal::mul($qty, $minRate);
            $saving = Decimal::sub($spent, $atBest);
            if (Decimal::isNegative($saving) || Decimal::isZero($saving)) {
                continue;
            }

            $out[] = [
                'id'        => 'consolidate-item-' . $row['item_id'],
                'kind'      => 'consolidation',
                'title'     => 'One item bought from ' . $row['supplier_count'] . ' suppliers at different rates',
                'detail'    => 'Across ' . $row['line_count'] . ' order lines the rate ranged from '
                    . Format::money($minRate, $currency) . ' to ' . Format::money(Decimal::of($row['max_rate']), $currency) . '.',
                'estimate'  => $saving,
                'estimate_formatted' => Format::money($saving, $currency),
                'baseline'  => $spent,
                'baseline_formatted' => Format::money($spent, $currency),
                'assumption' => 'Upper bound: assumes every ordered unit could have been bought at the lowest rate actually paid in this period, with no change to lead time, quality or minimum order quantity. Quoted rates are a commercial cost, not the inventory valuation.',
                'evidence'  => ['item_id' => (int) $row['item_id'], 'lines' => (int) $row['line_count'], 'period' => $period->label()],
                'route'     => '/purchase-orders',
                'filters'   => ['item_id' => (string) $row['item_id']],
                'overlaps'  => ['price-rise-item-' . $row['item_id']],
            ];
        }

        // Prices that moved against us on the same item, same unit.
        foreach (Db::all(
            "WITH ranked AS (
                SELECT l.item_id, l.unit_id, l.agreed_rate, p.po_date, p.supplier_account_id,
                       p.supplier_name_snapshot, p.currency_code,
                       ROW_NUMBER() OVER (PARTITION BY l.item_id, l.unit_id ORDER BY p.po_date ASC, l.line_id ASC)  AS first_seen,
                       ROW_NUMBER() OVER (PARTITION BY l.item_id, l.unit_id ORDER BY p.po_date DESC, l.line_id DESC) AS last_seen,
                       COUNT(*)    OVER (PARTITION BY l.item_id, l.unit_id) AS observations
                FROM purchase_order_lines l
                JOIN purchase_orders p ON p.po_id = l.po_id
                WHERE p.cmp_id = :cmp AND p.fy_id = :fy
                  AND p.po_date BETWEEN :from AND :to
                  AND p.status <> 'CANCELLED' AND l.item_id IS NOT NULL AND l.agreed_rate > 0
            )
            SELECT f.item_id, f.unit_id, f.observations,
                   f.agreed_rate::text AS first_rate, f.po_date AS first_date,
                   t.agreed_rate::text AS last_rate,  t.po_date AS last_date,
                   t.supplier_name_snapshot, t.currency_code
            FROM ranked f
            JOIN ranked t ON t.item_id = f.item_id AND t.unit_id IS NOT DISTINCT FROM f.unit_id AND t.last_seen = 1
            WHERE f.first_seen = 1 AND f.observations >= 3 AND t.agreed_rate > f.agreed_rate
            ORDER BY (t.agreed_rate - f.agreed_rate) / f.agreed_rate DESC
            LIMIT 5",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $period->params(),
        ) as $row) {
            $first = Decimal::of($row['first_rate']);
            $last = Decimal::of($row['last_rate']);
            $changePc = Decimal::percentChange($first, $last, 1);
            if ($changePc === null) {
                continue;
            }

            $out[] = [
                'id'       => 'price-rise-item-' . $row['item_id'],
                'kind'     => 'price',
                'title'    => 'Rate up ' . Format::percent($changePc, 1) . ' on a repeatedly bought item',
                'detail'   => 'Same item and same unit: ' . Format::money($first, $currency) . ' on '
                    . Format::date((string) $row['first_date']) . ' against ' . Format::money($last, $currency)
                    . ' on ' . Format::date((string) $row['last_date']) . ', over ' . $row['observations'] . ' orders.',
                'estimate' => null,
                'estimate_formatted' => null,
                'baseline' => $first,
                'baseline_formatted' => Format::money($first, $currency),
                'assumption' => 'Compares the agreed rate before discount, freight and tax, on the same item and unit. It does not account for a change in quantity break, specification or delivery terms — open the orders before raising it with the supplier.',
                'evidence' => ['item_id' => (int) $row['item_id'], 'observations' => (int) $row['observations']],
                'route'    => '/dashboard/suppliers',
                'filters'  => ['item_id' => (string) $row['item_id'], 'panel' => 'price'],
                'overlaps' => ['consolidate-item-' . $row['item_id']],
            ];
        }

        // Many small orders to one supplier: a consolidation and a freight case.
        foreach (Db::all(
            "SELECT p.supplier_account_id, p.supplier_name_snapshot,
                    COUNT(*) AS order_count,
                    SUM(p.total_amount)::text AS total_value,
                    SUM(p.freight_amount)::text AS freight
             FROM purchase_orders p
             WHERE p.cmp_id = :cmp AND p.fy_id = :fy
               AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'
             GROUP BY p.supplier_account_id, p.supplier_name_snapshot
             HAVING COUNT(*) >= 5 AND SUM(p.freight_amount) > 0
             ORDER BY SUM(p.freight_amount) DESC
             LIMIT 3",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $period->params(),
        ) as $row) {
            $freight = Decimal::of($row['freight']);
            $orders = (int) $row['order_count'];
            // Halving the number of deliveries is the stated assumption, not a
            // prediction. It is what makes the number arguable rather than magic.
            $estimate = Decimal::div($freight, '2', 2);
            if ($estimate === null || Decimal::isZero($estimate)) {
                continue;
            }

            $out[] = [
                'id'       => 'freight-' . $row['supplier_account_id'],
                'kind'     => 'fragmentation',
                'title'    => $orders . ' separate orders to ' . ($row['supplier_name_snapshot'] ?? 'one supplier'),
                'detail'   => 'Freight and delivery charges on those orders came to ' . Format::money($freight, $currency) . '.',
                'estimate' => $estimate,
                'estimate_formatted' => Format::money($estimate, $currency),
                'baseline' => $freight,
                'baseline_formatted' => Format::money($freight, $currency),
                'assumption' => 'Assumes consolidating to half as many deliveries and that freight scales with the number of deliveries rather than the weight. Check the delivery schedule before acting: fewer deliveries means more stock held.',
                'evidence' => ['supplier_account_id' => (int) $row['supplier_account_id'], 'orders' => $orders],
                'route'    => '/purchase-orders',
                'filters'  => ['supplier_id' => (string) $row['supplier_account_id']],
                'overlaps' => [],
            ];
        }

        return $out;
    }

    /**
     * What the opportunity cards add up to, without counting anything twice.
     *
     * Two cards can describe the same rupees — a fragmented-buying card and a
     * price-rise card on the same item are one saving seen from two angles —
     * and adding both produces a total nobody could ever realise. A card that
     * overlaps one already counted is excluded and SAID to be excluded, so the
     * headline figure and the cards under it can be reconciled by hand.
     *
     * The Overview's savings card and the AI Insights total are the same
     * number because they are this function.
     *
     * @param list<array<string, mixed>> $opportunities
     * @return array{total: string, counted: list<string>, overlapping: int}
     */
    public static function opportunityTotal(array $opportunities): array
    {
        $counted = [];
        $total = Decimal::ZERO;
        $overlapping = 0;

        foreach ($opportunities as $opportunity) {
            if (($opportunity['estimate'] ?? null) === null) {
                continue;
            }

            $overlapsCounted = false;
            foreach ((array) ($opportunity['overlaps'] ?? []) as $other) {
                if (isset($counted[$other])) {
                    $overlapsCounted = true;
                    break;
                }
            }
            if ($overlapsCounted) {
                $overlapping++;
                continue;
            }

            $counted[(string) $opportunity['id']] = true;
            $total = Decimal::add($total, (string) $opportunity['estimate']);
        }

        return ['total' => $total, 'counted' => array_keys($counted), 'overlapping' => $overlapping];
    }

    /**
     * Anomalies worth a human look. A review candidate, never an accusation.
     *
     * @return list<array<string, mixed>>
     */
    public static function anomalies(Context $ctx, Period $period, string $currency): array
    {
        $out = [];

        // Supplier invoice numbers that differ by very little. Near-identical
        // references are how the same bill gets entered twice.
        //
        // Compared in PHP over a bounded, recent set rather than in SQL:
        // levenshtein() lives in the fuzzystrmatch extension, which is not
        // installed on every managed PostgreSQL, and an anomaly rule that
        // disappears depending on the host is worse than no rule.
        foreach (self::nearIdenticalInvoiceNumbers($ctx) as $pair) {
            $out[] = self::anomaly(
                'similar-invoice-' . $pair['request_id'],
                'duplicate',
                'Invoice numbers one character apart',
                $pair['invoice_no'] . ' and ' . $pair['other_no']
                    . ' are from the same supplier and differ by a single character.',
                null,
                '/bills/' . $pair['request_id'],
                ['request_id' => $pair['request_id'], 'compared_with' => $pair['other_id']],
                $currency,
            );
        }

        // Orders that sit just under an approval threshold. Repeatedly is the
        // signal; once is a coincidence and is not reported.
        $threshold = Db::scalar(
            'SELECT po_approval_above_amount::text FROM purchase_settings WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        );
        $thresholdValue = Decimal::of($threshold);
        if (!Decimal::isZero($thresholdValue)) {
            $floor = Decimal::div(Decimal::mulInt($thresholdValue, 9), '10', 4) ?? $thresholdValue;
            foreach (Db::all(
                "SELECT p.supplier_account_id, p.supplier_name_snapshot, COUNT(*) AS n,
                        SUM(p.total_amount)::text AS total
                 FROM purchase_orders p
                 WHERE p.cmp_id = :cmp AND p.fy_id = :fy
                   AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'
                   AND p.total_amount >= CAST(:floor AS NUMERIC) AND p.total_amount < CAST(:threshold AS NUMERIC)
                 GROUP BY p.supplier_account_id, p.supplier_name_snapshot
                 HAVING COUNT(*) >= 3
                 ORDER BY COUNT(*) DESC
                 LIMIT 3",
                ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'floor' => $floor, 'threshold' => $thresholdValue] + $period->params(),
            ) as $row) {
                $out[] = self::anomaly(
                    'threshold-' . $row['supplier_account_id'],
                    'threshold',
                    $row['n'] . ' orders just below the approval threshold',
                    'Orders to ' . ($row['supplier_name_snapshot'] ?? 'this supplier') . ' totalling '
                        . Format::money(Decimal::of($row['total']), $currency) . ' each fell within 10% below '
                        . Format::money($thresholdValue, $currency) . ', the value at which a purchase order needs approval.',
                    Decimal::of($row['total']),
                    '/purchase-orders',
                    ['supplier_account_id' => (int) $row['supplier_account_id'], 'orders' => (int) $row['n']],
                    $currency,
                );
            }
        }

        // A new supplier taking a large share quickly.
        foreach (Db::all(
            "SELECT p.supplier_account_id, p.supplier_name_snapshot, COUNT(*) AS n,
                    SUM(p.total_amount)::text AS total, MIN(p.po_date) AS first_order
             FROM purchase_orders p
             WHERE p.cmp_id = :cmp AND p.fy_id = :fy
               AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'
               AND NOT EXISTS (
                   SELECT 1 FROM purchase_orders q
                   WHERE q.cmp_id = p.cmp_id AND q.supplier_account_id = p.supplier_account_id
                     AND q.po_date < :from
               )
             GROUP BY p.supplier_account_id, p.supplier_name_snapshot
             ORDER BY SUM(p.total_amount) DESC
             LIMIT 3",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $period->params(),
        ) as $row) {
            $out[] = self::anomaly(
                'new-supplier-' . $row['supplier_account_id'],
                'supplier',
                'First orders to a supplier not bought from before',
                ($row['supplier_name_snapshot'] ?? 'A supplier') . ' received ' . $row['n'] . ' order'
                    . (((int) $row['n']) === 1 ? '' : 's') . ' worth ' . Format::money(Decimal::of($row['total']), $currency)
                    . ', starting ' . Format::date((string) $row['first_order']) . '. There is no earlier order to them in this financial year.',
                Decimal::of($row['total']),
                '/suppliers',
                ['supplier_account_id' => (int) $row['supplier_account_id']],
                $currency,
            );
        }

        return $out;
    }

    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function item(
        string $id,
        string $severity,
        string $title,
        string $explanation,
        ?string $amount,
        ?int $count,
        string $actionLabel,
        string $route,
        array $filters,
        int $rank,
        string $currency = 'INR',
    ): array {
        return [
            'id'            => $id,
            'severity'      => $severity,
            'severity_label' => match ($severity) {
                'danger'  => 'Needs action',
                'warning' => 'Watch',
                'success' => 'Clear',
                default   => 'For information',
            },
            'title'         => $title,
            'explanation'   => $explanation,
            'amount'        => $amount,
            'impact_label'  => $amount !== null
                ? Format::money($amount, $currency)
                : ($count !== null ? Format::count((string) $count) . ' item' . ($count === 1 ? '' : 's') : ''),
            'count'         => $count,
            'source_label'  => 'Purchases records',
            'action_label'  => $actionLabel,
            'route'         => $route,
            'filters'       => $filters,
            'rank'          => $rank,
        ];
    }

    /** @return array<string, mixed> */
    private static function anomaly(
        string $id,
        string $kind,
        string $title,
        string $detail,
        ?string $amount,
        string $route,
        array $evidence,
        string $currency,
    ): array {
        return [
            'id'      => $id,
            'kind'    => $kind,
            'title'   => $title,
            'detail'  => $detail,
            'amount'  => $amount,
            'amount_formatted' => $amount === null ? null : Format::money($amount, $currency),
            'status'  => 'open',
            'route'   => $route,
            'evidence' => $evidence,
            'note'    => 'A review candidate found by a fixed rule. It is not a finding and not an accusation.',
        ];
    }

    /**
     * Bills whose supplier invoice numbers differ by exactly one character.
     *
     * Bounded to the most recent bills per company, and compared only within
     * the same supplier, so the comparison stays O(n) in practice rather than
     * quadratic over the whole ledger.
     *
     * @return list<array{request_id:int, other_id:int, invoice_no:string, other_no:string}>
     */
    private static function nearIdenticalInvoiceNumbers(Context $ctx, int $scan = 300): array
    {
        $rows = Db::all(
            "SELECT request_id, supplier_account_id, supplier_invoice_no
             FROM purchase_bill_requests
             WHERE cmp_id = :cmp AND fy_id = :fy AND status <> 'CANCELLED'
               AND supplier_invoice_no IS NOT NULL AND supplier_invoice_no <> ''
             ORDER BY request_id DESC
             LIMIT :scan",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'scan' => $scan],
        );

        /** @var array<int, list<array{id:int, no:string}>> $bySupplier */
        $bySupplier = [];
        foreach ($rows as $row) {
            $bySupplier[(int) $row['supplier_account_id']][] = [
                'id' => (int) $row['request_id'],
                'no' => (string) $row['supplier_invoice_no'],
            ];
        }

        $out = [];
        // A run of references like 4460, 4461, 4462 is one thing to look at,
        // not three: once a bill has been reported it is not paired again.
        $reported = [];

        foreach ($bySupplier as $bills) {
            $count = count($bills);
            for ($i = 0; $i < $count && count($out) < 5; $i++) {
                if (isset($reported[$bills[$i]['id']])) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if (isset($reported[$bills[$j]['id']])) {
                        continue;
                    }
                    if (!self::differsByOneCharacter($bills[$i]['no'], $bills[$j]['no'])) {
                        continue;
                    }
                    // The newer bill is the one worth opening.
                    [$newer, $older] = $bills[$i]['id'] > $bills[$j]['id']
                        ? [$bills[$i], $bills[$j]]
                        : [$bills[$j], $bills[$i]];
                    $reported[$newer['id']] = true;
                    $reported[$older['id']] = true;
                    $out[] = [
                        'request_id' => $newer['id'],
                        'other_id'   => $older['id'],
                        'invoice_no' => $newer['no'],
                        'other_no'   => $older['no'],
                    ];
                    break;
                }
            }
        }

        return array_slice($out, 0, 5);
    }

    /**
     * True when one substitution, insertion or deletion turns a into b.
     *
     * A bounded edit-distance test rather than a full Levenshtein: it is the
     * only distance this rule cares about, and it stops early.
     */
    private static function differsByOneCharacter(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));
        if ($a === $b) {
            return false;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);
        if (abs($lenA - $lenB) > 1) {
            return false;
        }

        if ($lenA === $lenB) {
            $differences = 0;
            for ($i = 0; $i < $lenA; $i++) {
                if ($a[$i] !== $b[$i] && ++$differences > 1) {
                    return false;
                }
            }

            return $differences === 1;
        }

        // One is longer by exactly one character: walk both, allowing a single skip.
        [$short, $long] = $lenA < $lenB ? [$a, $b] : [$b, $a];
        $i = 0;
        $j = 0;
        $skipped = false;
        while ($i < strlen($short) && $j < strlen($long)) {
            if ($short[$i] === $long[$j]) {
                $i++;
                $j++;
                continue;
            }
            if ($skipped) {
                return false;
            }
            $skipped = true;
            $j++;
        }

        return true;
    }
}
