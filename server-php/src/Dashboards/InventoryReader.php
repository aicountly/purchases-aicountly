<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;

/**
 * What Inventory says, adapted once.
 *
 * Item names, units, warehouses, stock and the replenishment signal. None of it
 * is stored: every call here happens on the request that renders the screen,
 * and the ids our own rows carry are what tie the two together.
 *
 * The item lookup is deliberately a BULK call. One request per document line is
 * the pattern the cross-service rules exist to prevent, and a procurement
 * workbench with sixty lines would make sixty of them.
 */
final class InventoryReader
{
    public const LABEL = 'Inventory';

    public function __construct(
        private readonly Context $ctx,
        private readonly string $sesKey,
    ) {
    }

    private function client(): InventoryClient
    {
        return (new InventoryClient())->withSession($this->sesKey);
    }

    /**
     * item_id => ['name' => ..., 'uom' => ..., 'group' => ...] for the ids given.
     *
     * @param list<int> $itemIds
     * @return array{ok: bool, error: ?string, items: array<int, array<string, mixed>>}
     */
    public function items(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter($itemIds, static fn ($id) => (int) $id > 0)));
        if ($ids === []) {
            return ['ok' => true, 'error' => null, 'items' => []];
        }

        // One call, capped. A screen that needs more item names than this is a
        // screen that should be paginating instead.
        $result = $this->client()->bulkLookupItems($this->ctx, array_slice($ids, 0, 200));

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => self::reason($result, 'item names'), 'items' => []];
        }

        $rows = (array) ($result['body']['data'] ?? []);
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['item_id'] ?? $row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $items[$id] = [
                'item_id' => $id,
                'name'    => self::text($row['item_name'] ?? $row['name'] ?? null),
                'code'    => self::text($row['item_code'] ?? $row['sku'] ?? $row['code'] ?? null),
                'uom'     => self::text($row['uom'] ?? $row['unit_name'] ?? $row['base_unit'] ?? null),
                'group'   => self::text($row['group_name'] ?? $row['item_group'] ?? null),
            ];
        }

        return ['ok' => true, 'error' => null, 'items' => $items];
    }

    /**
     * The reorder signal: what Inventory believes is short.
     *
     * Purchases does not decide this. It reads Inventory's own replenishment
     * report and turns each row into a reviewable draft — never an order.
     *
     * @return array{ok: bool, error: ?string, rows: list<array<string, mixed>>, as_of: ?string}
     */
    public function replenishment(?int $warehouseId, int $limit = 25): array
    {
        $result = $this->client()->replenishment($this->ctx, array_filter([
            'warehouse_id' => $warehouseId,
            'limit'        => $limit,
        ], static fn ($v) => $v !== null));

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => self::reason($result, 'the reorder signal'), 'rows' => [], 'as_of' => null];
        }

        $body = (array) ($result['body'] ?? []);
        $data = $body['data'] ?? [];
        $rows = is_array($data) && isset($data['rows']) ? $data['rows'] : $data;

        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $out[] = [
                'item_id'         => $itemId,
                'item_name'       => self::text($row['item_name'] ?? $row['name'] ?? null),
                'uom'             => self::text($row['uom'] ?? $row['unit_name'] ?? null),
                'warehouse_id'    => isset($row['warehouse_id']) ? (int) $row['warehouse_id'] : null,
                'available_qty'   => self::decimal($row['available_qty'] ?? $row['on_hand'] ?? $row['balance_qty'] ?? null),
                'reorder_level'   => self::decimal($row['reorder_level'] ?? $row['min_level'] ?? null),
                'safety_stock'    => self::decimal($row['safety_stock'] ?? null),
                'suggested_qty'   => self::decimal($row['suggested_qty'] ?? $row['reorder_qty'] ?? null),
                'lead_days'       => isset($row['lead_days']) ? (int) $row['lead_days'] : null,
                'as_of'           => self::text($row['as_of'] ?? null),
            ];
        }

        $asOf = is_array($data) ? self::text($data['as_of'] ?? $data['generated_at'] ?? null) : null;

        return ['ok' => true, 'error' => null, 'rows' => $out, 'as_of' => $asOf];
    }

    /**
     * Warehouses, for the material-centre filter.
     *
     * @return array{ok: bool, error: ?string, rows: list<array{id:int, name:?string}>}
     */
    public function warehouses(): array
    {
        $result = $this->client()->warehouses($this->ctx);
        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => self::reason($result, 'the warehouse list'), 'rows' => []];
        }

        $rows = (array) ($result['body']['data'] ?? []);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['warehouse_id'] ?? $row['id'] ?? 0);
            if ($id > 0) {
                $out[] = ['id' => $id, 'name' => self::text($row['warehouse_name'] ?? $row['name'] ?? null)];
            }
        }

        return ['ok' => true, 'error' => null, 'rows' => $out];
    }

    /** @param array{status?: int} $result */
    private static function reason(array $result, string $what): string
    {
        $status = (int) ($result['status'] ?? 0);

        return match (true) {
            $status === 403 => 'Inventory did not allow this session to read ' . $what . '.',
            $status === 401 => 'Inventory did not accept this session.',
            $status === 0   => 'Inventory did not answer in time.',
            $status >= 500  => 'Inventory returned an error for ' . $what . '.',
            default         => 'Inventory could not provide ' . $what . ' (HTTP ' . $status . ').',
        };
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function decimal(mixed $value): ?string
    {
        if (is_float($value)) {
            return Decimal::parse(sprintf('%.4F', $value));
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return Decimal::parse($value);
    }
}
