<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Document numbers for the documents this product owns.
 *
 * Requisitions, RFQs, purchase orders, returns and claims are ours. A VENDOR
 * BILL number is not — that is the supplier's, and the voucher number Books
 * assigns to it is Books', from its own statutory series.
 *
 * The row is locked while the next number is taken, so two buyers pressing Save
 * at the same instant get consecutive numbers rather than the same one. Reading
 * MAX+1 would give both of them the same answer.
 */
final class NumberSeries
{
    public static function next(Context $ctx, string $kind): string
    {
        [$table, $column, $prefixColumn, $default] = match ($kind) {
            'requisition' => ['purchase_requisitions', 'requisition_no', 'requisition_prefix', 'PR'],
            'rfq'         => ['purchase_rfqs', 'rfq_no', 'rfq_prefix', 'RFQ'],
            'po'          => ['purchase_orders', 'po_no', 'po_prefix', 'PO'],
            'return'      => ['purchase_returns', 'return_no', 'return_prefix', 'PRET'],
            'claim'       => ['purchase_claims', 'claim_no', 'claim_prefix', 'CLM'],
            default       => throw new \InvalidArgumentException('Unknown document kind ' . $kind),
        };

        $prefix = (string) (Db::scalar(
            'SELECT ' . Db::quoteIdentifier($prefixColumn) . ' FROM purchase_settings WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        ) ?? $default);

        $stem = sprintf('%s/%d/', $prefix, $ctx->fyId);

        $highest = Db::scalar(
            'SELECT ' . Db::quoteIdentifier($column) . '
             FROM ' . Db::quoteIdentifier($table) . '
             WHERE cmp_id = :cmp AND fy_id = :fy AND ' . Db::quoteIdentifier($column) . ' LIKE :stem
             ORDER BY length(' . Db::quoteIdentifier($column) . ') DESC, ' . Db::quoteIdentifier($column) . ' DESC
             LIMIT 1
             FOR UPDATE',
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'stem' => $stem . '%'],
        );

        $sequence = 1;
        if (is_string($highest) && preg_match('/(\d+)$/', $highest, $m) === 1) {
            $sequence = (int) $m[1] + 1;
        }

        return $stem . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
