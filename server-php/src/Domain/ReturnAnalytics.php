<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Dashboards\Decimal;
use Aicountly\Api\Dashboards\Format;
use Aicountly\Api\Db;

/**
 * What the returns workspace shows above the register.
 *
 * THE FILTERS ARE THE POINT. Every figure here is produced by the same WHERE
 * clause the register uses — one clause, built once in ReturnClaimService and
 * handed to both. A summary computed from its own conditions is a summary that
 * disagrees with the list underneath it the first time either changes, and a
 * summary computed from the page that happened to be fetched is worse: it
 * reports twenty-five returns as the company's total.
 *
 * NOTHING HERE IS A FORECAST AND NOTHING IS A MODEL. The comparison is the
 * immediately preceding window of the same length, and it is omitted rather
 * than invented when there is no such window — a card that reports "↑ 0%"
 * against a period that does not exist is a card that has made something up.
 *
 * Money is exact decimal strings from first read to last, formatted here so the
 * register, the export and the cards are all formatted by one piece of code.
 */
final class ReturnAnalytics
{
    /** Months on the trend chart. Six is what fits, and what the eye reads. */
    private const TREND_MONTHS = 6;

    /** A supplier credit older than this, still unreceived, is worth saying out loud. */
    private const CREDIT_OVERDUE_DAYS = 15;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
        private readonly string $currency = 'INR',
    ) {
    }

    /**
     * Totals, the comparison, the trend, the reason split and the insights.
     *
     * One endpoint rather than four because the four are drawn together, in one
     * band of the screen, from one set of filters. Four calls would mean four
     * chances for the cards and the donut to disagree about which period they
     * are describing.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $service = new ReturnClaimService($this->ctx, $this->auth);

        $current = $this->totals($service, $filters);
        $previous = $this->previousWindow($filters);
        $comparison = $previous === null ? null : $this->totals($service, $previous);

        return [
            'period' => [
                'from'       => $filters['from'] ?? null,
                'to'         => $filters['to'] ?? null,
                'comparable' => $comparison !== null,
                'previous'   => $previous === null ? null : ['from' => $previous['from'], 'to' => $previous['to']],
            ],
            'currency' => $this->currency,
            'totals'   => $current,
            'previous' => $comparison,
            'deltas'   => $comparison === null ? null : [
                'returns'          => self::delta((string) $current['returns'], (string) $comparison['returns']),
                'return_value'     => self::delta($current['return_value'], $comparison['return_value']),
                'credits_received' => self::delta((string) $current['credits_received'], (string) $comparison['credits_received']),
                'credits_pending'  => self::delta((string) $current['credits_pending'], (string) $comparison['credits_pending']),
            ],
            'trend'    => $this->trend($service, $filters),
            'reasons'  => $this->reasons($service, $filters),
            'insights' => $this->insights($service, $filters, $current),
        ];
    }

    /**
     * The four cards, over whatever is currently filtered.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function totals(ReturnClaimService $service, array $filters): array
    {
        [$clause, $params] = $service->registerClause($filters);

        $row = Db::first(
            "SELECT COUNT(*)::int AS returns,
                    COALESCE(SUM(agg.return_value), 0)::text AS return_value,
                    COUNT(*) FILTER (WHERE r.supplier_credit_status = 'RECEIVED')::int AS credits_received,
                    COUNT(*) FILTER (WHERE r.supplier_credit_status = 'PENDING'
                                       AND r.status NOT IN ('DRAFT', 'CANCELLED'))::int AS credits_pending,
                    COALESCE(SUM(agg.return_value) FILTER (WHERE r.supplier_credit_status = 'PENDING'
                                       AND r.status NOT IN ('DRAFT', 'CANCELLED')), 0)::text AS credits_pending_value,
                    COUNT(*) FILTER (WHERE r.status = 'DRAFT')::int AS drafts,
                    COUNT(*) FILTER (WHERE r.inventory_document_uuid IS NULL
                                       AND r.status NOT IN ('DRAFT', 'CANCELLED'))::int AS inventory_pending,
                    COUNT(*) FILTER (WHERE r.books_debit_note_uuid IS NULL
                                       AND r.status NOT IN ('DRAFT', 'CANCELLED'))::int AS books_pending
               FROM purchase_returns r
               LEFT JOIN purchase_orders p ON p.po_id = r.po_id
               LEFT JOIN LATERAL (
                   SELECT COALESCE(SUM(l.line_amount), 0) AS return_value
                   FROM purchase_return_lines l WHERE l.return_id = r.return_id
               ) agg ON TRUE
              WHERE {$clause}",
            $params,
        ) ?? [];

        $value = Decimal::of($row['return_value'] ?? '0');
        $pendingValue = Decimal::of($row['credits_pending_value'] ?? '0');

        return [
            'returns'                  => (int) ($row['returns'] ?? 0),
            'return_value'             => $value,
            'return_value_formatted'   => Format::money($value, $this->currency),
            'return_value_compact'     => Format::compactMoney($value, $this->currency),
            'credits_received'         => (int) ($row['credits_received'] ?? 0),
            'credits_pending'          => (int) ($row['credits_pending'] ?? 0),
            'credits_pending_value'    => $pendingValue,
            'credits_pending_formatted' => Format::money($pendingValue, $this->currency),
            'drafts'                   => (int) ($row['drafts'] ?? 0),
            'inventory_pending'        => (int) ($row['inventory_pending'] ?? 0),
            'books_pending'            => (int) ($row['books_pending'] ?? 0),
        ];
    }

    /**
     * The same filters over the window immediately before this one.
     *
     * Null when the current filters name no window to step back from. "All
     * time" has no previous, and saying so is the honest answer.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private function previousWindow(array $filters): ?array
    {
        $from = is_string($filters['from'] ?? null) ? $filters['from'] : null;
        $to   = is_string($filters['to'] ?? null) ? $filters['to'] : null;
        if ($from === null || $to === null) {
            return null;
        }

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end   = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        // Inclusive length, so a 30-day window is compared with the 30 days
        // before it rather than with 29 of them.
        $days = (int) $start->diff($end)->days + 1;

        return [
            'from' => $start->modify('-' . $days . ' days')->format('Y-m-d'),
            'to'   => $start->modify('-1 day')->format('Y-m-d'),
        ] + $filters;
    }

    /**
     * Six calendar months of returns, counted and valued.
     *
     * Every filter applies EXCEPT the date range, which the six months replace —
     * a "last six months" chart that also obeyed a one-month filter would draw
     * one bar and five blanks.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function trend(ReturnClaimService $service, array $filters): array
    {
        $anchor = is_string($filters['to'] ?? null)
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $filters['to']) ?: new \DateTimeImmutable('today'))
            : new \DateTimeImmutable('today');

        $first = $anchor->modify('first day of this month')->modify('-' . (self::TREND_MONTHS - 1) . ' months');

        $window = $filters;
        $window['from'] = $first->format('Y-m-d');
        $window['to'] = $anchor->modify('last day of this month')->format('Y-m-d');

        [$clause, $params] = $service->registerClause($window);

        $rows = Db::all(
            "SELECT to_char(r.return_date, 'YYYY-MM') AS month,
                    COUNT(*)::int AS count,
                    COALESCE(SUM(agg.return_value), 0)::text AS value
               FROM purchase_returns r
               LEFT JOIN purchase_orders p ON p.po_id = r.po_id
               LEFT JOIN LATERAL (
                   SELECT COALESCE(SUM(l.line_amount), 0) AS return_value
                   FROM purchase_return_lines l WHERE l.return_id = r.return_id
               ) agg ON TRUE
              WHERE {$clause}
              GROUP BY 1",
            $params,
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['month']] = $row;
        }

        // Every month in the window is emitted, including the empty ones. A
        // chart that simply omits a quiet month redraws its own x-axis and
        // reads as though returns were continuous.
        $points = [];
        for ($index = 0; $index < self::TREND_MONTHS; $index++) {
            $month = $first->modify('+' . $index . ' months');
            $key = $month->format('Y-m');
            $row = $byMonth[$key] ?? null;
            $value = Decimal::of($row['value'] ?? '0');

            $points[] = [
                'month'           => $key,
                'label'           => $month->format('M Y'),
                'short_label'     => $month->format('M'),
                'count'           => (int) ($row['count'] ?? 0),
                'value'           => $value,
                'value_formatted' => Format::money($value, $this->currency),
                'value_compact'   => Format::compactMoney($value, $this->currency),
            ];
        }

        return $points;
    }

    /**
     * Why the goods went back, as a share of the filtered set.
     *
     * Shares are worked out on the server so the donut, the legend and any
     * export of the same figures agree to the last decimal place.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function reasons(ReturnClaimService $service, array $filters): array
    {
        [$clause, $params] = $service->registerClause($filters);

        $rows = Db::all(
            "SELECT COALESCE(NULLIF(r.reason_code, ''), 'unspecified') AS reason_code,
                    COUNT(*)::int AS count,
                    COALESCE(SUM(agg.return_value), 0)::text AS value
               FROM purchase_returns r
               LEFT JOIN purchase_orders p ON p.po_id = r.po_id
               LEFT JOIN LATERAL (
                   SELECT COALESCE(SUM(l.line_amount), 0) AS return_value
                   FROM purchase_return_lines l WHERE l.return_id = r.return_id
               ) agg ON TRUE
              WHERE {$clause}
              GROUP BY 1
              ORDER BY 2 DESC, 1",
            $params,
        );

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['count'];
        }
        if ($total === 0) {
            return [];
        }

        $reasons = [];
        foreach ($rows as $row) {
            $code = (string) $row['reason_code'];
            $count = (int) $row['count'];
            $value = Decimal::of($row['value'] ?? '0');

            $reasons[] = [
                'reason_code'     => $code,
                'label'           => ReturnClaimService::REASONS[$code] ?? ($code === 'unspecified' ? 'Not stated' : ucfirst(str_replace('_', ' ', $code))),
                'count'           => $count,
                'value'           => $value,
                'value_formatted' => Format::money($value, $this->currency),
                // One decimal place, as a string: the share is a figure, not a
                // float to be re-rounded by whatever draws it.
                'percentage'      => number_format($count * 100 / $total, 1, '.', ''),
            ];
        }

        return $reasons;
    }

    /**
     * What a purchase head would notice reading the same figures.
     *
     * DETERMINISTIC RULES, NOT A MODEL. Given the same data this produces the
     * same list, which is what makes it safe to act on and possible to test.
     * The shape is the one an insights endpoint would answer with, so the day
     * one exists this method is replaced rather than the screen rebuilt.
     *
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $totals
     * @return list<array<string, mixed>>
     */
    private function insights(ReturnClaimService $service, array $filters, array $totals): array
    {
        $items = [];

        // 1. Money the supplier has not credited back, waiting longest.
        $overdue = Db::first(
            "SELECT COUNT(*)::int AS count, COALESCE(SUM(agg.return_value), 0)::text AS value,
                    MAX((CURRENT_DATE - r.return_date))::int AS oldest_days
               FROM purchase_returns r
               LEFT JOIN purchase_orders p ON p.po_id = r.po_id
               LEFT JOIN LATERAL (
                   SELECT COALESCE(SUM(l.line_amount), 0) AS return_value
                   FROM purchase_return_lines l WHERE l.return_id = r.return_id
               ) agg ON TRUE
              WHERE " . $this->clauseWith($service, $filters, [
                  "r.supplier_credit_status = 'PENDING'",
                  "r.status NOT IN ('DRAFT', 'CANCELLED')",
                  '(CURRENT_DATE - r.return_date) > ' . self::CREDIT_OVERDUE_DAYS,
              ], $params),
            $params,
        ) ?? [];

        if ((int) ($overdue['count'] ?? 0) > 0) {
            $count = (int) $overdue['count'];
            $value = Decimal::of($overdue['value'] ?? '0');
            $items[] = self::insight(
                'credit-overdue',
                'warning',
                sprintf(
                    '%d return%s worth %s %s been awaiting supplier credit for more than %d days.',
                    $count,
                    $count === 1 ? '' : 's',
                    Format::money($value, $this->currency),
                    $count === 1 ? 'has' : 'have',
                    self::CREDIT_OVERDUE_DAYS,
                ),
                ['supplier_credit' => 'PENDING'],
                ['label' => 'Draft follow-up emails', 'action_type' => 'draft_credit_followup'],
            );
        }

        // 2. A request to Inventory or Books that did not finish. Nothing
        //    retries this behind anyone's back, so it sits until it is opened.
        $stuck = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_integration_commands
              WHERE cmp_id = :cmp AND fy_id = :fy AND entity_type = 'purchase_return'
                AND status IN ('FAILED', 'BLOCKED')",
            ['cmp' => $this->ctx->cmpId, 'fy' => $this->ctx->fyId],
        );
        if ($stuck > 0) {
            $items[] = self::insight(
                'stuck-commands',
                'critical',
                sprintf(
                    '%d cross-app request on a return did not finish. Nothing is retried in the background, so %s waiting.',
                    $stuck,
                    $stuck === 1 ? 'it is' : 'they are',
                ),
                ['status' => 'APPROVED,DISPATCHED'],
            );
        }

        // 3. Goods gone, books not told. The gap between the two products is
        //    exactly what this screen exists to make visible.
        $unbooked = (int) Db::scalar(
            'SELECT COUNT(*) FROM purchase_returns r
               LEFT JOIN purchase_orders p ON p.po_id = r.po_id
              WHERE ' . $this->clauseWith($service, $filters, [
                  'r.inventory_document_uuid IS NOT NULL',
                  'r.books_debit_note_uuid IS NULL',
              ], $unbookedParams),
            $unbookedParams,
        );
        if ($unbooked > 0) {
            $items[] = self::insight(
                'books-pending',
                'warning',
                sprintf(
                    '%d return%s left the warehouse but %s no debit note in Smart Books yet.',
                    $unbooked,
                    $unbooked === 1 ? '' : 's',
                    $unbooked === 1 ? 'has' : 'have',
                ),
                ['books' => 'PENDING', 'inventory' => 'POSTED'],
            );
        }

        // 4. The reason that dominates. Worth saying only when one actually does.
        $reasons = $this->reasons($service, $filters);
        if ($reasons !== [] && (float) $reasons[0]['percentage'] >= 25.0 && $reasons[0]['reason_code'] !== 'unspecified') {
            $items[] = self::insight(
                'top-reason',
                'info',
                sprintf('Most returns are due to %s (%s%%).', strtolower((string) $reasons[0]['label']), $reasons[0]['percentage']),
                ['reason' => (string) $reasons[0]['reason_code']],
            );
        }

        // 5. A supplier well outside the pattern. Two returns is not a pattern,
        //    so the rule will not call one.
        $concentration = $this->supplierConcentration($service, $filters);
        if ($concentration !== null) {
            $items[] = $concentration;
        }

        // 6. The direction of travel, when there is a window to compare with.
        $previous = $this->previousWindow($filters);
        if ($previous !== null) {
            $before = $this->totals($service, $previous);
            $delta = self::delta((string) $totals['returns'], (string) $before['returns']);
            if ($delta['direction'] !== 'flat' && $before['returns'] >= 3) {
                $items[] = self::insight(
                    'return-rate',
                    $delta['direction'] === 'down' ? 'positive' : 'warning',
                    sprintf(
                        'Returns are %s %s%% against the previous period (%d, from %d).',
                        $delta['direction'] === 'down' ? 'down' : 'up',
                        $delta['percent'],
                        $totals['returns'],
                        $before['returns'],
                    ),
                    [],
                );
            }
        }

        if ($items === []) {
            $items[] = self::insight(
                'all-clear',
                'positive',
                $totals['returns'] === 0
                    ? 'No returns were raised in this period.'
                    : 'Nothing is waiting: every return here has its stock movement and its debit note, and no supplier credit is overdue.',
                [],
            );
        }

        return $items;
    }

    /**
     * One supplier accounting for an unusual share of the returns.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private function supplierConcentration(ReturnClaimService $service, array $filters): ?array
    {
        [$clause, $params] = $service->registerClause($filters);

        $rows = Db::all(
            "SELECT r.supplier_account_id,
                    COALESCE(r.supplier_name_snapshot, p.supplier_name_snapshot) AS supplier_name,
                    COUNT(*)::int AS count,
                    COALESCE(SUM(agg.return_value), 0)::text AS value
               FROM purchase_returns r
               LEFT JOIN purchase_orders p ON p.po_id = r.po_id
               LEFT JOIN LATERAL (
                   SELECT COALESCE(SUM(l.line_amount), 0) AS return_value
                   FROM purchase_return_lines l WHERE l.return_id = r.return_id
               ) agg ON TRUE
              WHERE {$clause}
              GROUP BY 1, 2
              ORDER BY 3 DESC
              LIMIT 2",
            $params,
        );

        if ($rows === []) {
            return null;
        }

        $total = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_returns r
               LEFT JOIN purchase_orders p ON p.po_id = r.po_id
              WHERE {$clause}",
            $params,
        );

        $top = $rows[0];
        $count = (int) $top['count'];
        // Three is the floor for calling anything a pattern, and a supplier is
        // only remarkable if they are most of the picture.
        if ($total < 5 || $count < 3 || $count * 100 / $total < 40) {
            return null;
        }

        $name = $top['supplier_name'] ?? ('Account ' . $top['supplier_account_id']);

        return self::insight(
            'supplier-concentration',
            'warning',
            sprintf(
                '%s accounts for %d of %d returns (%s%%) in this period.',
                $name,
                $count,
                $total,
                number_format($count * 100 / $total, 0),
            ),
            ['supplier_id' => (string) $top['supplier_account_id']],
        );
    }

    /**
     * The register clause with extra conditions bolted on.
     *
     * @param array<string, mixed>  $filters
     * @param list<string>          $extra
     * @param array<string, mixed>|null $params filled with the bindings
     */
    private function clauseWith(ReturnClaimService $service, array $filters, array $extra, ?array &$params): string
    {
        [$clause, $bindings] = $service->registerClause($filters);
        $params = $bindings;

        return $clause . ($extra === [] ? '' : ' AND ' . implode(' AND ', $extra));
    }

    /**
     * Change against the comparison window, as a direction and a percentage.
     *
     * A rise from zero has no percentage — it is a rise from nothing — so it is
     * reported as "new" rather than as the infinity a division would produce.
     *
     * @return array{direction:string, percent:string, from:string, to:string}
     */
    private static function delta(string $current, string $before): array
    {
        $now = Decimal::of($current);
        $then = Decimal::of($before);

        $percent = Decimal::percentChange($then, $now, 1);
        if ($percent === null) {
            return [
                'direction' => Decimal::isZero($now) ? 'flat' : 'new',
                'percent'   => '',
                'from'      => $then,
                'to'        => $now,
            ];
        }

        $direction = Decimal::isNegative($percent) ? 'down' : (Decimal::isZero($percent) ? 'flat' : 'up');

        return [
            'direction' => $direction,
            'percent'   => ltrim($percent, '-'),
            'from'      => $then,
            'to'        => $now,
        ];
    }

    /**
     * @param array<string, string> $filters      what the workspace should filter to when this is acted on
     * @param array{label:string, action_type:string}|null $action
     * @return array<string, mixed>
     */
    private static function insight(string $id, string $severity, string $message, array $filters, ?array $action = null): array
    {
        return [
            'id'       => $id,
            'severity' => $severity,
            'message'  => $message,
            'filters'  => $filters,
            'action'   => $action,
            'basis'    => 'Purchases records, by a fixed rule.',
        ];
    }
}
