<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Http;

/**
 * The supplier / buyer / material-centre narrowing every dashboard shares.
 *
 * Read once from the request and turned into SQL fragments, so the same filter
 * means the same thing on a KPI card, the table under it and the export — which
 * is the only way an export total can be expected to equal what is on screen.
 */
final class Filters
{
    private function __construct(
        public readonly ?int $supplierAccountId,
        public readonly ?string $buyerUuid,
        public readonly ?int $warehouseId,
        public readonly string $search,
    ) {
    }

    public static function fromRequest(): self
    {
        $supplier = Http::intParam('supplier_id');
        $warehouse = Http::intParam('warehouse_id');
        $buyer = trim((string) (Http::param('buyer') ?? ''));
        $search = trim((string) (Http::param('q') ?? ''));

        return new self(
            ($supplier !== null && $supplier > 0) ? $supplier : null,
            $buyer === '' ? null : $buyer,
            ($warehouse !== null && $warehouse > 0) ? $warehouse : null,
            // A search term long enough to be a paste of something private is
            // not a search term.
            mb_substr($search, 0, 80),
        );
    }

    public function isEmpty(): bool
    {
        return $this->supplierAccountId === null
            && $this->buyerUuid === null
            && $this->warehouseId === null
            && $this->search === '';
    }

    /**
     * SQL and bindings for the purchase_orders table (or an alias of it).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function orderClause(string $alias = 'p'): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $sql = '';
        $params = [];

        if ($this->supplierAccountId !== null) {
            $sql .= ' AND ' . $prefix . 'supplier_account_id = :f_supplier';
            $params['f_supplier'] = $this->supplierAccountId;
        }
        if ($this->buyerUuid !== null) {
            $sql .= ' AND ' . $prefix . 'created_by = :f_buyer';
            $params['f_buyer'] = $this->buyerUuid;
        }
        if ($this->warehouseId !== null) {
            $sql .= ' AND ' . $prefix . 'delivery_warehouse_id = :f_warehouse';
            $params['f_warehouse'] = $this->warehouseId;
        }
        if ($this->search !== '') {
            $sql .= ' AND (' . $prefix . 'po_no ILIKE :f_search OR ' . $prefix . 'supplier_name_snapshot ILIKE :f_search)';
            $params['f_search'] = '%' . $this->search . '%';
        }

        return [$sql, $params];
    }

    /**
     * The same narrowing for requisitions, where the buyer is the requester and
     * there is no supplier yet — a requisition names a need, not a source.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function requisitionClause(string $alias = 'r'): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $sql = '';
        $params = [];

        if ($this->buyerUuid !== null) {
            $sql .= ' AND ' . $prefix . 'requester_uuid = :f_buyer';
            $params['f_buyer'] = $this->buyerUuid;
        }
        if ($this->search !== '') {
            $sql .= ' AND ' . $prefix . 'requisition_no ILIKE :f_search';
            $params['f_search'] = '%' . $this->search . '%';
        }
        // A supplier filter cannot narrow requisitions, and silently ignoring
        // it would be wrong; the caller states this in the panel description.

        return [$sql, $params];
    }

    /** @return array{0: string, 1: array<string, mixed>} for purchase_bill_requests */
    public function billClause(string $alias = 'b'): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $sql = '';
        $params = [];

        if ($this->supplierAccountId !== null) {
            $sql .= ' AND ' . $prefix . 'supplier_account_id = :f_supplier';
            $params['f_supplier'] = $this->supplierAccountId;
        }
        if ($this->search !== '') {
            $sql .= ' AND ' . $prefix . 'supplier_invoice_no ILIKE :f_search';
            $params['f_search'] = '%' . $this->search . '%';
        }

        return [$sql, $params];
    }

    /** Echoed back so the client can show exactly what narrowed the figures. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'supplier_id'  => $this->supplierAccountId,
            'buyer'        => $this->buyerUuid,
            'warehouse_id' => $this->warehouseId,
            'q'            => $this->search === '' ? null : $this->search,
            'applied'      => !$this->isEmpty(),
        ];
    }

    /** The filters a drill-down link must carry to show the same rows. @return array<string, string> */
    public function drilldown(): array
    {
        $out = [];
        if ($this->supplierAccountId !== null) {
            $out['supplier_id'] = (string) $this->supplierAccountId;
        }
        if ($this->buyerUuid !== null) {
            $out['buyer'] = $this->buyerUuid;
        }
        if ($this->warehouseId !== null) {
            $out['warehouse_id'] = (string) $this->warehouseId;
        }
        if ($this->search !== '') {
            $out['q'] = $this->search;
        }

        return $out;
    }
}
