<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Http;

/**
 * The masters a document names, confirmed with the products that own them BEFORE it is stored
 * (launch readiness E8-PUR: F176, F420, F430, F449).
 *
 * A purchase order used to store whatever supplier account, item and warehouse ids it was sent —
 * a ledger that does not exist, a customer's ledger, another company's supplier — with the name
 * the client typed as its "snapshot", and only Inventory or Books refused, at the receipt or the
 * bill, long after the order had been approved, issued and printed with a false name.
 *
 * Each read is made as the person (their own session), so Books and Inventory answer only for
 * the company and the masters this person may see there; nothing is copied. An answer that cannot
 * be read is 503 — a document is not stored on a guess — and a definite "no such thing here" is
 * 422 naming the field.
 */
final class MasterCheck
{
    /** Books' anchor for supplier ledgers. */
    public const CREDITORS = 'SUNDRY_CREDITORS';

    /** @var array<int, array<string, mixed>> */
    private array $suppliers = [];

    /** @var array<int, true>|null */
    private ?array $warehouses = null;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * The supplier as Books holds it: an account of this company under Sundry Creditors. Returns
     * Books' row; its acc_name is the only name a document may print.
     *
     * @return array<string, mixed>
     */
    public function supplier(int $accId, string $field = 'supplier_account_id'): array
    {
        if ($accId <= 0) {
            Http::validationFailed('Choose a supplier.', ['field' => $field]);
        }
        if (isset($this->suppliers[$accId])) {
            return $this->suppliers[$accId];
        }
        $response = (new BooksClient())->withSession($this->auth->sesKey())->account($this->ctx, $accId);
        $body = is_array($response['body'] ?? null) ? $response['body'] : null;
        if ((int) $response['status'] === 404 && $body !== null) {
            Http::validationFailed(sprintf('Smart Books has no ledger %d in this company. Choose the supplier from the list.', $accId), ['field' => $field, 'supplier_account_id' => $accId, 'reason' => 'supplier_not_found']);
        }
        $row = $response['ok'] && $body !== null ? ($body['data'] ?? null) : null;
        if (!is_array($row) || (int) ($row['acc_id'] ?? $row['account_id'] ?? 0) !== $accId) {
            Http::error(503, 'books_unavailable', 'Smart Books could not confirm this supplier just now, so nothing was saved. Try again.', ['retryable' => true, 'field' => $field]);
        }
        $anchor = (string) ($row['resolved_anchor_code'] ?? $row['grp_anchor_code'] ?? $row['anchor_code'] ?? '');
        if ($anchor !== '' && strtoupper($anchor) !== self::CREDITORS) {
            Http::validationFailed(sprintf('"%s" is not a supplier ledger in Smart Books (it is under %s, not Sundry Creditors). Choose a supplier.', (string) ($row['acc_name'] ?? $accId), $anchor), ['field' => $field, 'supplier_account_id' => $accId, 'reason' => 'not_a_supplier']);
        }

        return $this->suppliers[$accId] = $row;
    }

    /** Books' name for the supplier — never the one a client sent. */
    public function supplierName(int $accId): ?string
    {
        $name = trim((string) ($this->supplier($accId)['acc_name'] ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * Every item, as Inventory holds it for this company (its bulk lookup answers only the items it
     * has here; one it leaves out is not this company's).
     *
     * @param list<int> $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function items(array $itemIds, string $field = 'lines'): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id) => $id > 0)));
        if ($itemIds === []) {
            return [];
        }
        $response = (new InventoryClient())->withSession($this->auth->sesKey())->bulkLookupItems($this->ctx, $itemIds);
        $rows = $response['ok'] && is_array($response['body'] ?? null) ? ($response['body']['data'] ?? null) : null;
        if (!is_array($rows)) {
            Http::error(503, 'inventory_unavailable', 'Inventory could not confirm the items just now, so nothing was saved. Try again.', ['retryable' => true, 'field' => $field]);
        }
        $found = [];
        foreach ($rows as $row) {
            if (is_array($row) && (int) ($row['item_id'] ?? 0) > 0) {
                $found[(int) $row['item_id']] = $row;
            }
        }
        $missing = array_values(array_diff($itemIds, array_keys($found)));
        if ($missing !== []) {
            Http::validationFailed(
                sprintf('Inventory has no item %s in this company. Choose the item from the list.', implode(', ', $missing)),
                ['field' => $field, 'item_ids' => $missing, 'reason' => 'item_not_found'],
            );
        }

        return $found;
    }

    /** Every warehouse named is one of this company's in Inventory. @param list<int|null> $warehouseIds */
    public function warehouses(array $warehouseIds, string $field = 'warehouse_id'): void
    {
        $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds), static fn (int $id) => $id > 0)));
        if ($warehouseIds === []) {
            return;
        }
        if ($this->warehouses === null) {
            $response = (new InventoryClient())->withSession($this->auth->sesKey())->warehouses($this->ctx);
            $rows = $response['ok'] && is_array($response['body'] ?? null) ? ($response['body']['data'] ?? null) : null;
            if (!is_array($rows)) {
                Http::error(503, 'inventory_unavailable', 'Inventory could not confirm the warehouses just now, so nothing was saved. Try again.', ['retryable' => true, 'field' => $field]);
            }
            $this->warehouses = [];
            foreach ($rows as $row) {
                $id = is_array($row) ? (int) ($row['warehouse_id'] ?? $row['id'] ?? 0) : 0;
                if ($id > 0) {
                    $this->warehouses[$id] = true;
                }
            }
        }
        $missing = array_values(array_filter($warehouseIds, fn (int $id) => !isset($this->warehouses[$id])));
        if ($missing !== []) {
            Http::validationFailed(
                sprintf('Inventory has no warehouse %s in this company.', implode(', ', $missing)),
                ['field' => $field, 'warehouse_ids' => $missing, 'reason' => 'warehouse_not_found'],
            );
        }
    }
}
