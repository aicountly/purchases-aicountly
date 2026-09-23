<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;

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

        // The prefix and the profile's status, in one read: this is the point
        // every new requisition, RFQ, purchase order, return and claim passes
        // through, so it is also the one place that has to ask whether the
        // company's purchase profile is open for new documents at all.
        $profile = Db::first(
            'SELECT ' . Db::quoteIdentifier($prefixColumn) . ' AS prefix, is_active
             FROM purchase_settings WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        );

        // Absent row means nobody has opened Settings yet, which is not the
        // same as switched off. Defaults apply, and the profile is open.
        if ($profile !== null && !self::isTrue($profile['is_active'] ?? true)) {
            Http::conflict(
                'The purchase profile for this company is inactive, so no new purchase documents can be raised. '
                . 'Turn it back on in Settings → New Profile.',
                ['field' => 'is_active', 'retryable' => false],
            );
        }

        $prefix = (string) ($profile['prefix'] ?? $default);
        if ($prefix === '') {
            $prefix = $default;
        }

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

    /**
     * Read a PostgreSQL boolean whatever the driver hands back.
     *
     * pdo_pgsql has returned BOOLEAN as a PHP bool and as 't'/'f' depending on
     * the build, and getting this wrong here would stop a company raising
     * purchase orders. It is worth four lines to be sure.
     */
    private static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower($value), ['f', 'false', '0', 'no', ''], true);
        }

        return (bool) $value;
    }
}
