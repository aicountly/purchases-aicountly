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

    /**
     * The action list on the Procurement workspace.
     *
     * Five kinds of finding, each one a measurement rather than a guess: an
     * order is late or it is not, an approval has been waiting three days or it
     * has not, a rate is higher than the rate paid before or it is not. Nothing
     * here is phrased as a prediction, because none of it is one.
     *
     * A finding is only raised when it clears a stated threshold. "Approval
     * bottleneck" over a queue that is two hours old would be an insight nobody
     * can act on and everybody learns to ignore.
     *
     * @return list<array<string, mixed>>
     */
    public static function procurement(
        Context $ctx,
        Period $period,
        string $currency,
        bool $money,
        ?int $supplierAccountId = null,
    ): array {
        $scope = ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId];
        $supplierSql = $supplierAccountId === null ? '' : ' AND p.supplier_account_id = :supplier';
        $supplierParam = $supplierAccountId === null ? [] : ['supplier' => $supplierAccountId];
        $items = [];

        // 1. The supplier holding up the most money, right now.
        $late = Db::first(
            "SELECT p.supplier_account_id,
                    MAX(p.supplier_name_snapshot) AS supplier_name,
                    COUNT(DISTINCT p.po_id) AS orders,
                    MAX(CURRENT_DATE - p.promised_date) AS worst_days,
                    COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text AS exposure
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE p.cmp_id = :cmp AND p.fy_id = :fy
               AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty
               AND p.promised_date < CURRENT_DATE" . $supplierSql . '
             GROUP BY p.supplier_account_id
             ORDER BY COUNT(DISTINCT p.po_id) DESC, MAX(CURRENT_DATE - p.promised_date) DESC
             LIMIT 1',
            $scope + $supplierParam,
        );
        if ($late !== null && (int) $late['orders'] > 0) {
            $orders = (int) $late['orders'];
            $worst = (int) $late['worst_days'];
            $supplier = $late['supplier_name'] ?? ('Account ' . (int) $late['supplier_account_id']);
            $items[] = self::item(
                'delay-risk',
                $worst > 7 ? 'danger' : 'warning',
                $orders . ' order' . ($orders === 1 ? '' : 's') . ' from ' . $supplier . ' past the promised date',
                'The worst is ' . Format::days((string) $worst) . ' late.'
                    . ($money ? ' ' . Format::money(Decimal::of($late['exposure']), $currency) . ' of ordered value is still to arrive.' : ''),
                $money ? Decimal::of($late['exposure']) : null,
                $orders,
                'Chase',
                '/dashboard/procurement',
                ['view' => 'delayed', 'supplier_id' => (string) (int) $late['supplier_account_id']],
                900,
                $currency,
            ) + ['category' => 'delay_risk'];
        }

        // 2. Receipts Inventory refused. Nothing retries these behind anyone's
        //    back, so they sit until a person opens them.
        $refused = Db::first(
            "SELECT COUNT(*) AS n, MIN(rr.created_at) AS oldest
             FROM purchase_receipt_requests rr
             JOIN purchase_orders p ON p.po_id = rr.po_id
             WHERE rr.cmp_id = :cmp AND rr.fy_id = :fy AND rr.status = 'FAILED'" . $supplierSql,
            $scope + $supplierParam,
        );
        if ($refused !== null && (int) $refused['n'] > 0) {
            $n = (int) $refused['n'];
            $items[] = self::item(
                'receipt-refused',
                'danger',
                $n . ' goods receipt' . ($n === 1 ? '' : 's') . ' Inventory did not accept',
                'A receipt this app sent to Inventory was refused. The stock has not been recorded and the order still shows the quantity as outstanding until somebody re-sends it.',
                null,
                $n,
                'Resend',
                '/dashboard/procurement',
                ['view' => 'receipt_pending'],
                880,
                $currency,
            ) + ['category' => 'integration'];
        }

        // 3. Approvals that have stopped moving. Three days is the threshold, and
        //    the finding says so rather than calling any queue a bottleneck.
        $stuck = Db::first(
            "SELECT COUNT(*) AS n,
                    MAX(EXTRACT(DAY FROM NOW() - a.created_at))::int AS worst_days,
                    COALESCE(SUM(a.actual_value), 0)::text AS value
             FROM purchase_approval_requests a
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = 'PENDING'
               AND a.created_at < NOW() - INTERVAL '3 days'",
            $scope,
        );
        if ($stuck !== null && (int) $stuck['n'] > 0) {
            $n = (int) $stuck['n'];
            $items[] = self::item(
                'approval-ageing',
                (int) $stuck['worst_days'] > 7 ? 'danger' : 'warning',
                $n . ' approval' . ($n === 1 ? '' : 's') . ' waiting more than 3 days',
                'The oldest has been waiting ' . Format::days((string) (int) $stuck['worst_days'])
                    . '. Who may decide is set by the approval rules, not by this list.'
                    . ($money && !Decimal::isZero(Decimal::of($stuck['value']))
                        ? ' ' . Format::money(Decimal::of($stuck['value']), $currency) . ' is held up behind them.'
                        : ''),
                $money ? Decimal::parse($stuck['value']) : null,
                $n,
                'Resolve',
                '/approvals',
                [],
                800,
                $currency,
            ) + ['category' => 'approval_bottleneck'];
        }

        // 4. A rate that moved against us on the SAME item and the SAME unit.
        //    Comparing an item to itself in another unit, or across currencies,
        //    produces a percentage that means nothing, so neither is compared.
        $variance = Db::first(
            "WITH history AS (
                SELECT l.item_id, l.unit_id, l.agreed_rate, p.po_date, p.po_no, p.po_id, p.currency_code,
                       p.supplier_name_snapshot, l.description,
                       ROW_NUMBER() OVER (PARTITION BY l.item_id, l.unit_id ORDER BY p.po_date DESC, l.line_id DESC) AS recency,
                       AVG(l.agreed_rate) OVER (PARTITION BY l.item_id, l.unit_id) AS mean_rate,
                       COUNT(*)           OVER (PARTITION BY l.item_id, l.unit_id) AS observations
                FROM purchase_order_lines l
                JOIN purchase_orders p ON p.po_id = l.po_id
                WHERE p.cmp_id = :cmp AND p.fy_id = :fy
                  AND p.status <> 'CANCELLED'
                  AND l.item_id IS NOT NULL AND l.unit_id IS NOT NULL
                  AND l.agreed_rate > 0
                  AND p.po_date >= CURRENT_DATE - 90" . $supplierSql . "
            )
            SELECT item_id, po_no, po_id, supplier_name_snapshot, description, currency_code,
                   agreed_rate::text AS latest_rate,
                   mean_rate::text   AS mean_rate,
                   observations,
                   ROUND(((agreed_rate - mean_rate) / NULLIF(mean_rate, 0)) * 100, 1)::text AS change_pc
            FROM history
            WHERE recency = 1 AND observations >= 3
              AND mean_rate > 0
              AND ((agreed_rate - mean_rate) / mean_rate) >= 0.10
            ORDER BY ((agreed_rate - mean_rate) / mean_rate) DESC
            LIMIT 1",
            $scope + $supplierParam,
        );
        if ($variance !== null && $money) {
            $changePc = Decimal::of($variance['change_pc']);
            $label = $variance['description'] !== null && $variance['description'] !== ''
                ? (string) $variance['description']
                : 'Item ' . (int) $variance['item_id'];
            $items[] = self::item(
                'price-variance',
                'warning',
                Format::percent($changePc, 1) . ' above the recent average on ' . $label,
                'The latest rate on ' . $variance['po_no'] . ' is ' . Format::money(Decimal::of($variance['latest_rate']), (string) $variance['currency_code'])
                    . ' against an average of ' . Format::money(Decimal::of($variance['mean_rate']), (string) $variance['currency_code'])
                    . ' across ' . (int) $variance['observations'] . ' orders in the last 90 days, same item and same unit.',
                null,
                null,
                'Review',
                '/purchase-orders/' . (int) $variance['po_id'],
                [],
                700,
                $currency,
            ) + ['category' => 'price_variance'];
        }

        // 5. Suppliers who were asked and have not answered.
        $silent = Db::first(
            "SELECT COUNT(*) AS n, MAX(EXTRACT(DAY FROM NOW() - i.invited_at))::int AS worst_days
             FROM purchase_rfq_invitations i
             JOIN purchase_rfqs r ON r.rfq_id = i.rfq_id
             WHERE i.cmp_id = :cmp AND r.fy_id = :fy
               AND r.status IN ('ISSUED', 'RESPONSES_OPEN')
               AND i.status IN ('INVITED', 'VIEWED')
               AND i.invited_at < NOW() - INTERVAL '5 days'
               AND NOT EXISTS (SELECT 1 FROM purchase_quotes q
                                WHERE q.rfq_id = i.rfq_id AND q.supplier_account_id = i.supplier_account_id
                                  AND q.status NOT IN ('WITHDRAWN', 'REJECTED'))"
                . ($supplierAccountId === null ? '' : ' AND i.supplier_account_id = :supplier'),
            $scope + $supplierParam,
        );
        if ($silent !== null && (int) $silent['n'] > 0) {
            $n = (int) $silent['n'];
            $items[] = self::item(
                'rfq-no-response',
                'info',
                $n . ' supplier' . ($n === 1 ? '' : 's') . ' invited to quote and still silent',
                'Invited more than 5 days ago with no quote against the RFQ. The oldest invitation has been open '
                    . Format::days((string) (int) $silent['worst_days'])
                    . '. Awarding on two quotes where five were invited is a decision worth making on purpose.',
                null,
                $n,
                'Follow up',
                '/rfqs',
                [],
                600,
                $currency,
            ) + ['category' => 'follow_up'];
        }

        // 6. The clearest saving on the table, borrowed from the same rules the
        //    AI Insights screen uses so the two screens cannot disagree.
        if ($money) {
            $opportunities = self::opportunities($ctx, $period, $currency);
            if ($opportunities !== []) {
                $best = $opportunities[0];
                $items[] = self::item(
                    'savings-' . $best['id'],
                    'info',
                    (string) $best['title'],
                    $best['detail'] . ' ' . $best['assumption'],
                    Decimal::parse($best['estimate'] ?? null),
                    null,
                    'Explore',
                    (string) $best['route'],
                    (array) $best['filters'],
                    500,
                    $currency,
                ) + ['category' => 'savings'];
            }
        }

        // 7. Quiet is a finding too, and it is stated rather than left blank.
        if ($items === []) {
            $items[] = self::item(
                'procurement-clear',
                'success',
                'Nothing is holding procurement up',
                'No late deliveries, no refused receipts, no approval older than 3 days and no supplier silent on an RFQ, in this company, financial year and branch.',
                null,
                null,
                'Open',
                '/dashboard/procurement',
                ['view' => 'needs_action'],
                100,
            ) + ['category' => 'clear'];
        }

        usort($items, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return $items;
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
