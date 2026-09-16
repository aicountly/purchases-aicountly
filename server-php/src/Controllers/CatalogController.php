<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Read-through to the products that own this data.
 *
 * EVERY handler here is a pass-through. Nothing it returns is written to this
 * product's database, in any table, under any name, for any length of time.
 * They exist so the browser makes one same-origin call instead of several
 * cross-origin ones, and so this product's own permissions can be applied on
 * top — a requisitioner without `cost.view` sees the item without its price
 * history.
 */
final class CatalogController extends Controller
{
    public static function items(): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new InventoryClient())->withSession($auth->sesKey())->items($ctx, [
            'q'           => Http::param('q'),
            'limit'       => Http::intParam('limit', 50),
            'offset'      => Http::intParam('offset', 0),
            'item_grp_id' => Http::intParam('item_grp_id'),
        ]), $ctx, $auth);
    }

    public static function searchItems(): void
    {
        [$auth, $ctx] = self::enter();
        $term = (string) (Http::param('q') ?? '');
        if (mb_strlen($term) < 2) {
            Http::list([], 0, 20, 0);
        }

        self::relay(
            (new InventoryClient())->withSession($auth->sesKey())->searchItems($ctx, $term, Http::intParam('limit', 20) ?? 20),
            $ctx,
            $auth,
        );
    }

    public static function availability(): void
    {
        [$auth, $ctx] = self::enter();
        $itemId = Http::intParam('item_id');
        if ($itemId === null) {
            Http::validationFailed('item_id is required.', ['field' => 'item_id']);
        }

        self::relay(
            (new InventoryClient())->withSession($auth->sesKey())->availability($ctx, $itemId, Http::intParam('warehouse_id')),
            $ctx,
            $auth,
        );
    }

    public static function warehouses(): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new InventoryClient())->withSession($auth->sesKey())->warehouses($ctx), $ctx, $auth);
    }

    public static function uoms(): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new InventoryClient())->withSession($auth->sesKey())->uoms($ctx), $ctx, $auth);
    }

    /** Suppliers are Books' party ledgers; the procurement profile beside them is ours. */
    public static function suppliers(): void
    {
        [$auth, $ctx] = self::enter();
        $result = (new BooksClient())->withSession($auth->sesKey())->accounts($ctx, [
            'q'          => Http::param('q'),
            'limit'      => Http::intParam('limit', 50),
            'offset'     => Http::intParam('offset', 0),
            'party_type' => 'creditor',
            'nature'     => 'sundry_creditors',
        ]);

        if (!$result['ok']) {
            self::relay($result, $ctx, $auth);
        }

        $body = $result['body'] ?? ['data' => []];
        $rows = (array) ($body['data'] ?? []);

        // Decorate with OUR procurement profile, which is the only part of a
        // supplier this product owns. The ledger stays in Books.
        $profiles = [];
        foreach (Db::all(
            'SELECT supplier_account_id, qualification_status, is_preferred, operational_lead_days, risk_flag
             FROM purchase_supplier_profiles WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        ) as $profile) {
            $profiles[(int) $profile['supplier_account_id']] = $profile;
        }

        foreach ($rows as $index => $row) {
            $accountId = (int) ($row['acc_id'] ?? $row['account_id'] ?? 0);
            $rows[$index]['procurement_profile'] = $profiles[$accountId] ?? null;
        }
        $body['data'] = $rows;

        Http::json(200, $body);
    }

    public static function taxCategories(): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new BooksClient())->withSession($auth->sesKey())->taxCategories($ctx), $ctx, $auth);
    }

    /**
     * What we have paid this supplier for this item before.
     *
     * Composed from OUR purchase orders — a procurement fact. It is not a
     * valuation (Inventory's) and not a spend figure from the accounts (Books').
     */
    public static function priceHistory(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'cost.view');

        $itemId = Http::intParam('item_id');
        if ($itemId === null) {
            Http::validationFailed('item_id is required.', ['field' => 'item_id']);
        }

        Http::data(Db::all(
            'SELECT p.po_no, p.po_date, p.supplier_account_id, p.supplier_name_snapshot,
                    l.agreed_rate, l.ordered_qty, l.received_qty, p.currency_code
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE l.cmp_id = :cmp AND l.item_id = :item
               AND p.status NOT IN (\'DRAFT\', \'CANCELLED\')
             ORDER BY p.po_date DESC
             LIMIT 20',
            ['cmp' => $ctx->cmpId, 'item' => $itemId],
        ));
    }

    /**
     * Hand the owning product's answer back, unchanged, with its own status.
     *
     * An upstream failure is reported as an upstream failure. Turning it into an
     * empty list would make "Inventory is down" look like "you have no items",
     * and somebody would then create an item that already exists.
     *
     * @param array{ok:bool, status:int, body:?array, error:?string} $result
     */
    private static function relay(array $result, $ctx, $auth): never
    {
        if (!$result['ok']) {
            $status = $result['status'] === 0 ? 503 : $result['status'];
            Http::error(
                $status,
                $status === 503 ? 'upstream_unavailable' : 'upstream_error',
                $status === 503
                    ? 'Could not reach the app that owns this information. Please retry.'
                    : (string) ($result['error'] ?? 'The request was refused.'),
            );
        }

        $body = $result['body'] ?? ['data' => []];
        if (!Permissions::allows($ctx, $auth, 'cost.view')) {
            $body = self::stripCostFields($body);
        }

        Http::json(200, $body);
    }

    /** @param array<string, mixed> $payload */
    private static function stripCostFields(array $payload): array
    {
        $sensitive = ['unit_cost', 'cost', 'cost_rate', 'valuation_rate', 'valuation_amount', 'purchase_rate', 'last_purchase_rate'];

        $walk = static function (array $node) use (&$walk, $sensitive): array {
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, $sensitive, true)) {
                    unset($node[$key]);
                    continue;
                }
                if (is_array($value)) {
                    $node[$key] = $walk($value);
                }
            }

            return $node;
        };

        return $walk($payload);
    }
}
