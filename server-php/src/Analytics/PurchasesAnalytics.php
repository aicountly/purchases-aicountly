<?php

declare(strict_types=1);

namespace Aicountly\Api\Analytics;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Purchases' operational summaries for AICOUNTLY Insights: what is still committed on open
 * purchase orders, and how orders raised in a period have been received and billed.
 *
 * NOT ACCOUNTING FIGURES. Books owns the purchase voucher and the supplier's balance; a bill
 * Purchases has not posted is not a liability in Books. What Purchases knows is its own order
 * (agreed quantities and rates), what Inventory confirmed received, and how much of each line it
 * has had Books bill. Values are quantity × the order line's agreed rate — before line discounts
 * and before tax — the definition Purchases' own "Open commitment" card uses, so the two agree.
 *
 * Currency is kept per row and never summed across. Every SUM is PostgreSQL NUMERIC and leaves as
 * a 4-place decimal string.
 */
final class PurchasesAnalytics
{
    /** Orders still being delivered (the "Open commitment" card's statuses). */
    public const OPEN = ['ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED'];

    /** Orders sent to the supplier, whatever has happened since (drafts, unapproved, cancelled excluded). */
    public const COMMITTED = ['ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED'];

    public const COMMITMENT_BASIS = 'Purchases\' own order record, as at the moment of the request (not a past date). Open orders = '
        . 'issued, acknowledged or partly received. commitment_value = Σ (ordered − received) × agreed rate, before line '
        . 'discounts and tax — the definition of Purchases\' "Open commitment" card. received_not_billed_value = Σ (received − '
        . 'billed) × agreed rate on open AND closed orders: goods in that Books has no purchase voucher for yet. overdue = open '
        . 'orders with a line past its promised date. Scope: company and financial year (orders are stamped with the year they '
        . 'were raised in); a branch also sees company-level (bo 0) orders. Not an accounting figure; never add to Books\'.';

    public const PO_TO_BILL_BASIS = 'Purchases\' own order record. Cohort: purchase orders dated in [from, to], stamped with this '
        . 'financial year, issued onwards (drafts, unapproved and cancelled excluded). Values are quantity × agreed rate (before '
        . 'line discounts and tax): ordered, received (Inventory-confirmed), billed (quantity on bills Smart Books posted). '
        . 'bill_requests counts the bills raised against these orders by state; only POSTED bills are in Books. Not an '
        . 'accounting figure; never add to Books\'.';

    public function __construct(private readonly Context $ctx)
    {
    }

    /** @return list<array<string, mixed>> */
    public function openCommitment(): array
    {
        [$scope, $params] = $this->ctx->scopeClause('p');
        $open = self::list(self::OPEN);
        $committed = self::list(self::COMMITTED);

        $rows = Db::all(
            "SELECT p.currency_code AS currency,
                    COUNT(DISTINCT p.po_id) FILTER (WHERE p.status IN ({$open}))                                   AS open_orders,
                    COUNT(*) FILTER (WHERE p.status IN ({$open}) AND l.ordered_qty > l.received_qty)            AS open_lines,
                    ROUND(COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate)
                          FILTER (WHERE p.status IN ({$open})), 0), 4)::text                                     AS commitment_value,
                    ROUND(COALESCE(SUM(GREATEST(l.received_qty - l.billed_qty, 0) * l.agreed_rate), 0), 4)::text AS received_not_billed_value,
                    COUNT(DISTINCT p.po_id) FILTER (WHERE p.status IN ({$open}) AND l.ordered_qty > l.received_qty
                          AND COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE)                         AS overdue_orders,
                    MIN(p.po_date) FILTER (WHERE p.status IN ({$open}))                                          AS oldest_open_po_date
               FROM purchase_orders p
               JOIN purchase_order_lines l ON l.po_id = p.po_id
              WHERE {$scope} AND p.status IN ({$committed})
              GROUP BY p.currency_code
             HAVING COUNT(DISTINCT p.po_id) FILTER (WHERE p.status IN ({$open})) > 0
                 OR SUM(GREATEST(l.received_qty - l.billed_qty, 0)) > 0
              ORDER BY p.currency_code",
            $params,
        );

        return array_map(static fn (array $r): array => [
            'currency'                  => (string) $r['currency'],
            'open_orders'               => (int) $r['open_orders'],
            'open_lines'                => (int) $r['open_lines'],
            'commitment_value'          => (string) $r['commitment_value'],
            'received_not_billed_value' => (string) $r['received_not_billed_value'],
            'overdue_orders'            => (int) $r['overdue_orders'],
            'oldest_open_po_date'       => $r['oldest_open_po_date'] === null ? null : (string) $r['oldest_open_po_date'],
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function poToBill(string $from, string $to): array
    {
        [$scope, $params] = $this->ctx->scopeClause('p');
        $params += ['from' => $from, 'to' => $to];
        $committed = self::list(self::COMMITTED);

        $rows = Db::all(
            "WITH per_po AS (
                SELECT p.po_id, p.currency_code,
                       COALESCE(SUM(l.ordered_qty * l.agreed_rate), 0)  AS ordered_value,
                       COALESCE(SUM(l.received_qty * l.agreed_rate), 0) AS received_value,
                       COALESCE(SUM(l.billed_qty * l.agreed_rate), 0)   AS billed_value,
                       COALESCE(SUM(GREATEST(l.received_qty - l.billed_qty, 0) * l.agreed_rate), 0) AS received_not_billed,
                       COUNT(l.line_id)                                 AS lines,
                       COALESCE(BOOL_AND(l.billed_qty >= l.ordered_qty), FALSE) AS fully_billed,
                       COALESCE(BOOL_OR(l.billed_qty > 0), FALSE)       AS any_billed
                  FROM purchase_orders p
                  LEFT JOIN purchase_order_lines l ON l.po_id = p.po_id
                 WHERE {$scope} AND p.po_date BETWEEN :from AND :to AND p.status IN ({$committed})
                 GROUP BY p.po_id, p.currency_code
             ),
             bills AS (
                SELECT b.po_id,
                       COUNT(*) FILTER (WHERE b.status = 'POSTED')                               AS posted,
                       COUNT(*) FILTER (WHERE b.status IN ('DRAFT', 'MATCHING', 'MATCHED'))      AS in_progress,
                       COUNT(*) FILTER (WHERE b.status = 'EXCEPTION')                            AS exception,
                       COUNT(*) FILTER (WHERE b.status = 'FAILED')                               AS failed
                  FROM purchase_bill_requests b
                 WHERE b.cmp_id = :ctx_cmp_id AND b.po_id IN (SELECT po_id FROM per_po)
                 GROUP BY b.po_id
             )
             SELECT x.currency_code AS currency,
                    COUNT(*)                                                         AS orders,
                    COUNT(*) FILTER (WHERE x.lines > 0 AND x.fully_billed)           AS orders_fully_billed,
                    COUNT(*) FILTER (WHERE x.any_billed AND NOT (x.lines > 0 AND x.fully_billed)) AS orders_partly_billed,
                    COUNT(*) FILTER (WHERE NOT x.any_billed)                         AS orders_not_billed,
                    ROUND(SUM(x.ordered_value), 4)::text                             AS ordered_value,
                    ROUND(SUM(x.received_value), 4)::text                            AS received_value,
                    ROUND(SUM(x.billed_value), 4)::text                              AS billed_value,
                    ROUND(SUM(x.received_not_billed), 4)::text                       AS received_not_billed_value,
                    CASE WHEN SUM(x.ordered_value) > 0 THEN ROUND(SUM(x.billed_value) * 100 / SUM(x.ordered_value), 2)::text END AS billed_pc,
                    COALESCE(SUM(b.posted), 0)      AS bills_posted,
                    COALESCE(SUM(b.in_progress), 0) AS bills_in_progress,
                    COALESCE(SUM(b.exception), 0)   AS bills_exception,
                    COALESCE(SUM(b.failed), 0)      AS bills_failed
               FROM per_po x
               LEFT JOIN bills b ON b.po_id = x.po_id
              GROUP BY x.currency_code
              ORDER BY x.currency_code",
            $params,
        );

        return array_map(static fn (array $r): array => [
            'currency'                  => (string) $r['currency'],
            'orders'                    => (int) $r['orders'],
            'orders_fully_billed'       => (int) $r['orders_fully_billed'],
            'orders_partly_billed'      => (int) $r['orders_partly_billed'],
            'orders_not_billed'         => (int) $r['orders_not_billed'],
            'ordered_value'             => (string) $r['ordered_value'],
            'received_value'            => (string) $r['received_value'],
            'billed_value'              => (string) $r['billed_value'],
            'received_not_billed_value' => (string) $r['received_not_billed_value'],
            'billed_pc'                 => $r['billed_pc'] === null ? null : (string) $r['billed_pc'],
            'bill_requests'             => [
                'posted'      => (int) $r['bills_posted'],
                'in_progress' => (int) $r['bills_in_progress'],
                'exception'   => (int) $r['bills_exception'],
                'failed'      => (int) $r['bills_failed'],
            ],
        ], $rows);
    }

    /** @param list<string> $values constants, never input */
    private static function list(array $values): string
    {
        return "'" . implode("','", $values) . "'";
    }
}
