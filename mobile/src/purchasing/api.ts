import { api, type ListEnvelope } from '../core/http';
import type {
  ApprovalRow,
  CatalogItem,
  EntityKind,
  PoListRow,
  PurchaseOrder,
  Requisition,
  SupplierHit,
  SupplierPosition,
} from './types';

/**
 * Every Purchases route the phone calls. All but `supplierPosition` are the routes the web app
 * already uses, with the same parameters, so the server's permission checks apply to the phone
 * exactly as they do to the browser.
 */

export interface Page<T> {
  rows: T[];
  total: number;
}

const page = <T>(body: ListEnvelope<T> | null | undefined): Page<T> => ({
  rows: Array.isArray(body?.data) ? body!.data : [],
  total: Number(body?.meta?.total ?? body?.data?.length ?? 0) || 0,
});

/** Statuses goods can be received against (ReceiptService::RECEIVABLE_PO, less RECEIVED). */
export const RECEIVABLE = ['APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED'];

export const purchasing = {
  /** GET v1/approvals — only what this person decides; 403 when they decide nothing here. */
  async approvals(): Promise<Page<ApprovalRow>> {
    return page(await api.get<ListEnvelope<ApprovalRow>>('v1/approvals', { status: 'PENDING', limit: 100 }));
  },

  /** Orders still to be received, soonest promised first. */
  async toReceive(): Promise<Page<PoListRow>> {
    const res = page(await api.get<ListEnvelope<PoListRow>>('v1/purchase-orders', { open_only: 1, limit: 100, sort: 'promised_date', order: 'asc' }));
    const rows = res.rows.filter((r) => RECEIVABLE.includes(r.status) && r.receipt_status !== 'COMPLETE');
    return { rows, total: rows.length };
  },

  async orders(filter: { q?: string; view: 'open' | 'overdue' | 'pending' | 'all' }, offset = 0): Promise<Page<PoListRow>> {
    const query: Record<string, string | number> = { limit: 30, offset, sort: 'po_date', order: 'desc' };
    if (filter.q && filter.q.trim()) query.q = filter.q.trim();
    if (filter.view === 'open') query.open_only = 1;
    if (filter.view === 'overdue') query.overdue = 1;
    if (filter.view === 'pending') query.status = 'APPROVAL_PENDING';
    return page(await api.get<ListEnvelope<PoListRow>>('v1/purchase-orders', query));
  },

  async order(id: number): Promise<PurchaseOrder> {
    return (await api.get<{ data: PurchaseOrder }>(`v1/purchase-orders/${id}`)).data;
  },

  async requisition(id: number): Promise<Requisition> {
    return (await api.get<{ data: Requisition }>(`v1/requisitions/${id}`)).data;
  },

  /**
   * Approve or reject. `note` is the reason (required to reject); `vendorOverride` is the reason
   * given to order from a supplier on hold (supplier.approve only). The server applies
   * segregation of duties and answers 403 sod_reason_required when the owner must say why.
   */
  async decide(kind: EntityKind, id: number, action: 'approve' | 'reject', note?: string, vendorOverride?: string): Promise<void> {
    const path = kind === 'purchase_order' ? `v1/purchase-orders/${id}/${action}` : `v1/requisitions/${id}/${action}`;
    await api.post(path, {
      ...(note && note.trim() ? { note: note.trim() } : {}),
      ...(vendorOverride && vendorOverride.trim() ? { vendor_override_reason: vendorOverride.trim() } : {}),
    });
  },

  /** POST v1/purchase-orders/{id}/receive — under the delivery's own submission token. */
  async receive(poId: number, body: Record<string, unknown>, token: string): Promise<PurchaseOrder> {
    return (await api.post<{ data: PurchaseOrder }>(`v1/purchase-orders/${poId}/receive`, { ...body, client_token: token }, { 'Idempotency-Key': token })).data;
  },

  /** Ask Inventory whether an unconfirmed receipt was recorded, and settle it from the answer. */
  async reconcileReceipt(requestId: number): Promise<PurchaseOrder> {
    return (await api.post<{ data: PurchaseOrder }>(`v1/receipt-requests/${requestId}/reconcile`)).data;
  },

  async retryReceipt(requestId: number): Promise<PurchaseOrder> {
    return (await api.post<{ data: PurchaseOrder }>(`v1/receipt-requests/${requestId}/retry`)).data;
  },

  /** Inventory's typeahead over name / SKU / barcode, relayed (needs at least two characters). */
  async searchItems(q: string): Promise<CatalogItem[]> {
    if (q.trim().length < 2) return [];
    const body = await api.get<ListEnvelope<CatalogItem>>('v1/catalog/items/search', { q: q.trim(), limit: 20 });
    return Array.isArray(body?.data) ? body.data : [];
  },

  /** Books' creditor ledgers, with Purchases' procurement profile beside each. */
  async searchSuppliers(q: string): Promise<SupplierHit[]> {
    const body = await api.get<ListEnvelope<SupplierHit>>('v1/catalog/suppliers', { q: q.trim(), limit: 40 });
    return Array.isArray(body?.data) ? body.data : [];
  },

  /** GET v1/suppliers/{id}/position — added for this app (server-php SupplierPositionController). */
  async supplierPosition(id: number): Promise<SupplierPosition> {
    return (await api.get<{ data: SupplierPosition }>(`v1/suppliers/${id}/position`)).data;
  },
};
