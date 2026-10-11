<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Supplier names for screens that list documents by supplier (launch readiness E8-PUR: F275).
 *
 * Bills, returns, claims, supplier profiles and RFQs carried only the Books ledger id, and the
 * screens printed "Account 601" — on the very screens where somebody approves, awards or claims
 * money. Books owns the name; what this product already holds of it is the snapshot taken when an
 * order or an RFQ invitation was confirmed against Books. Ids with no snapshot are asked of Books,
 * as the person (their own session), one ledger at a time and at most MAX_ASKED per list — a list
 * of a thousand unseen suppliers is not a thousand calls. A name that could not be found stays
 * null; the screen then says "Ledger #601", never a guess.
 */
final class SupplierNames
{
    public const MAX_ASKED = 25;

    /**
     * @param list<int> $supplierIds
     * @return array<int, ?string>
     */
    public static function for(Context $ctx, Auth $auth, array $supplierIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $supplierIds), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $names = array_fill_keys($ids, null);
        $list = '{' . implode(',', $ids) . '}';
        foreach (Db::all(
            "SELECT supplier_account_id, name FROM (
                 SELECT supplier_account_id, supplier_name_snapshot AS name, updated_at AS at
                   FROM purchase_orders WHERE cmp_id = :cmp AND supplier_account_id = ANY(CAST(:ids AS BIGINT[])) AND supplier_name_snapshot IS NOT NULL
                 UNION ALL
                 SELECT supplier_account_id, supplier_name_snapshot, invited_at
                   FROM purchase_rfq_invitations WHERE cmp_id = :cmp2 AND supplier_account_id = ANY(CAST(:ids2 AS BIGINT[])) AND supplier_name_snapshot IS NOT NULL
             ) s ORDER BY at ASC",
            ['cmp' => $ctx->cmpId, 'ids' => $list, 'cmp2' => $ctx->cmpId, 'ids2' => $list],
        ) as $row) {
            $names[(int) $row['supplier_account_id']] = (string) $row['name']; // the latest snapshot wins
        }

        $missing = array_keys(array_filter($names, static fn ($n) => $n === null));
        if ($missing !== [] && !$auth->isService() && $auth->sesKey() !== '') {
            $books = (new BooksClient())->withSession($auth->sesKey());
            foreach (array_slice($missing, 0, self::MAX_ASKED) as $id) {
                $answer = $books->account($ctx, $id);
                $row = $answer['ok'] && is_array($answer['body'] ?? null) ? ($answer['body']['data'] ?? null) : null;
                if (is_array($row) && (int) ($row['acc_id'] ?? $row['account_id'] ?? 0) === $id && trim((string) ($row['acc_name'] ?? '')) !== '') {
                    $names[$id] = trim((string) $row['acc_name']);
                }
            }
        }

        return $names;
    }

    /**
     * Each row with supplier_name set from its supplier_account_id (an existing non-empty
     * supplier_name is kept).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function attach(Context $ctx, Auth $auth, array $rows, string $idKey = 'supplier_account_id'): array
    {
        $names = self::for($ctx, $auth, array_map(static fn ($r) => (int) ($r[$idKey] ?? 0), $rows));
        foreach ($rows as $i => $row) {
            if (trim((string) ($row['supplier_name'] ?? '')) === '') {
                $rows[$i]['supplier_name'] = $names[(int) ($row[$idKey] ?? 0)] ?? null;
            }
        }

        return $rows;
    }
}
