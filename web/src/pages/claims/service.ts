/**
 * Everything the claim screen asks another system for.
 *
 * ONE RULE, AND IT IS THE PRODUCT'S RULE: nothing here keeps a copy. The
 * supplier list is Books', the item is Inventory's, and the purchase order,
 * bill, delivery and return are read from this product's own endpoints at the
 * moment they are searched for. What a claim stores is an id.
 *
 * Every search goes through `api`, which already carries the session key and
 * the company, financial-year and branch context — this module adds none of its
 * own, and cannot: a screen that could choose its own company would be a screen
 * that could read another one's orders.
 */

import { api, type ListResponse } from '../../services/api'
import type { PurchaseOrder } from '../../services/types'
import type {
  AssistIntent,
  AssistResult,
  Capability,
  ClaimMeta,
  CreateClaimPayload,
  CreatedClaim,
  ReferenceOption,
  SimilarClaim,
  SupplierOption,
} from './types'

/** Shared by every reference search: the supplier narrows it, the term finds it. */
export interface ReferenceQuery {
  supplierId: number | null
  term: string
  signal: AbortSignal
}

const PAGE = 20

function text(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null
}

function amount(value: unknown): string | null {
  const parsed = typeof value === 'string' ? Number.parseFloat(value) : typeof value === 'number' ? value : Number.NaN
  return Number.isFinite(parsed) ? String(parsed) : null
}

// ---------------------------------------------------------------------------
// Claim meta, creation and the duplicate check
// ---------------------------------------------------------------------------

export function fetchClaimMeta(signal: AbortSignal): Promise<ClaimMeta> {
  return api.get<{ data: ClaimMeta }>('v1/claims/meta', undefined, signal).then((response) => response.data)
}

export function createClaim(payload: CreateClaimPayload): Promise<CreatedClaim> {
  return api.post<CreatedClaim>('v1/claims', payload).then((response) => response.data)
}

/**
 * Open claims that look like this one.
 *
 * Advisory and non-blocking by design — see the service that answers it. A
 * failure here is silent: a warning that could not be fetched must never stop
 * somebody raising a legitimate claim.
 */
export async function fetchSimilarClaims(
  params: { supplierId: number; claimKind: string; poId: number | null; billId: number | null },
  signal: AbortSignal,
): Promise<SimilarClaim[]> {
  const response = await api.list<SimilarClaim>(
    'v1/claims/similar',
    {
      supplier_account_id: params.supplierId,
      claim_kind: params.claimKind,
      po_id: params.poId ?? undefined,
      bill_request_id: params.billId ?? undefined,
    },
    signal,
  )

  return response.data
}

// ---------------------------------------------------------------------------
// AI
// ---------------------------------------------------------------------------

/**
 * Ask the server's assistant for one of its named jobs.
 *
 * There is no model key in this bundle and there must never be one: the browser
 * sends an intent and the draft, and the server decides what — if anything — to
 * ask a model. With no model configured this answers `available: false` with a
 * reason the screen shows as it is written.
 */
export function requestClaimAssist(
  intent: AssistIntent,
  draft: Record<string, unknown>,
): Promise<AssistResult> {
  return api.post<AssistResult>('v1/claims/assist', { intent, draft }).then((response) => response.data)
}

// ---------------------------------------------------------------------------
// Suppliers — Books' party ledgers, read live
// ---------------------------------------------------------------------------

export async function searchSuppliers(term: string, signal: AbortSignal): Promise<SupplierOption[]> {
  const response = await api.list<SupplierOption>('v1/catalog/suppliers', { q: term, limit: PAGE }, signal)
  return response.data
}

// ---------------------------------------------------------------------------
// Reference documents
//
// Four searches, four row shapes, one option shape. The mapping is here so the
// combobox that renders them has no idea which table it is looking at.
// ---------------------------------------------------------------------------

export async function searchPurchaseOrders({ supplierId, term, signal }: ReferenceQuery): Promise<ReferenceOption[]> {
  const response = await api.list<Record<string, unknown>>(
    'v1/purchase-orders',
    { q: term, supplier_account_id: supplierId ?? undefined, limit: PAGE, sort: 'po_date', order: 'desc' },
    signal,
  )

  return response.data.map((row) => ({
    id: Number(row.po_id),
    primary: String(row.po_no ?? `Order ${row.po_id}`),
    secondary: text(row.supplier_name_snapshot),
    meta: [
      { label: 'Order date', value: String(row.po_date ?? '—') },
      { label: 'Order value', value: amount(row.total_amount) ?? '—' },
      { label: 'Status', value: String(row.status ?? '—') },
    ],
  }))
}

export async function searchPurchaseBills({ supplierId, term, signal }: ReferenceQuery): Promise<ReferenceOption[]> {
  const response = await api.list<Record<string, unknown>>(
    'v1/bills',
    { q: term, supplier_account_id: supplierId ?? undefined, limit: PAGE },
    signal,
  )

  return response.data.map((row) => ({
    id: Number(row.request_id),
    primary: text(row.supplier_invoice_no) ?? `Bill ${row.request_id}`,
    secondary: text(row.books_voucher_no) ? `Books voucher ${row.books_voucher_no}` : null,
    meta: [
      { label: 'Invoice date', value: String(row.supplier_invoice_date ?? '—') },
      { label: 'Status', value: String(row.status ?? '—') },
    ],
  }))
}

export async function searchDeliveries({ supplierId, term, signal }: ReferenceQuery): Promise<ReferenceOption[]> {
  const response = await api.list<Record<string, unknown>>(
    'v1/receipts',
    { q: term, supplier_account_id: supplierId ?? undefined, limit: PAGE },
    signal,
  )

  return response.data.map((row) => ({
    id: Number(row.request_id),
    // Inventory owns the GRN and gives us its number. Where it has not answered
    // yet there is the supplier's own delivery note to go on, and failing that
    // the order it belongs to — never a number invented here.
    primary:
      text(row.inventory_document_no) ??
      (text(row.supplier_dc_no) ? `DC ${row.supplier_dc_no}` : `Delivery against ${row.po_no ?? '—'}`),
    secondary: text(row.po_no) ? `Against ${row.po_no}` : null,
    meta: [
      { label: 'Received', value: String(row.received_at ?? '—') },
      { label: 'Vehicle', value: text(row.vehicle_no) ?? '—' },
      { label: 'Status', value: String(row.status ?? '—') },
    ],
  }))
}

export async function searchReturns({ supplierId, term, signal }: ReferenceQuery): Promise<ReferenceOption[]> {
  const response = await api.list<Record<string, unknown>>(
    'v1/returns',
    { q: term, supplier_account_id: supplierId ?? undefined, limit: PAGE },
    signal,
  )

  return response.data.map((row) => ({
    id: Number(row.return_id),
    primary: String(row.return_no ?? `Return ${row.return_id}`),
    secondary: text(row.reason_code),
    meta: [
      { label: 'Return date', value: String(row.return_date ?? '—') },
      { label: 'Status', value: String(row.status ?? '—') },
    ],
  }))
}

/**
 * One purchase order in full, for importing its lines onto a claim.
 *
 * The lines come back with what was ordered and what has been received against
 * each, which is exactly the arithmetic a shortage is made of.
 */
export function fetchPurchaseOrder(poId: number, signal?: AbortSignal): Promise<PurchaseOrder> {
  return api.one<PurchaseOrder>(`v1/purchase-orders/${poId}`, undefined, signal).then((response) => response.data)
}

// ---------------------------------------------------------------------------
// Attachments
// ---------------------------------------------------------------------------

/**
 * WHERE THE UPLOAD WOULD GO, AND WHY IT DOES NOT GO ANYWHERE YET.
 *
 * Purchases stores no files. The one upload this product accepts — a supplier
 * statement — is parsed in the request that carried it and deleted before the
 * response is written, deliberately, and there is no document store behind this
 * product to put a claim's evidence in.
 *
 * So this is an adapter and not a pretence. The screen asks the server what it
 * can do (`/v1/claims/meta` → `capabilities.attachments`) and says so plainly
 * where the dropzone would be; nothing is uploaded to an endpoint that would
 * answer 404, and nothing the user chose is silently dropped.
 *
 * TODO(purchases-api): when a document store exists, this becomes
 * `api.upload('v1/claims/attachments', form)` and the capability flips to true
 * on the server. Nothing in the components changes.
 */
export async function uploadClaimAttachment(
  _file: File,
  capability: Capability,
): Promise<{ ok: false; reason: string }> {
  return {
    ok: false,
    reason: capability.reason ?? 'Attachments cannot be stored by this deployment yet.',
  }
}

export type { ListResponse }
