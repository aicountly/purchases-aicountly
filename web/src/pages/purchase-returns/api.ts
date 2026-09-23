/**
 * The returns endpoints, in one place.
 *
 * Nothing here talks to a database and nothing here talks to Inventory or
 * Books: it calls this product's own API, which is what holds the service keys
 * and does the cross-app work. The browser never sees a credential.
 *
 * Every call goes through the shared `api` client, so the company scope, the
 * portal session and the one silent retry on 401 are already handled and are
 * not re-implemented per screen.
 */

import { api, type ListResponse, type QueryParams } from '../../services/api'
import type {
  ImportPreview,
  ImportResult,
  PurchaseReturnDetail,
  PurchaseReturnRow,
  ReturnIntegration,
  ReturnOptions,
  ReturnSummary,
} from './types'
import { PAGE_SIZE, toQuery, type ReturnsFilters, type SortKey } from './filters'

export interface RegisterQuery {
  filters: ReturnsFilters
  page: number
  sort: SortKey
  order: 'asc' | 'desc'
  limit?: number
}

function registerParams({ filters, page, sort, order, limit = PAGE_SIZE }: RegisterQuery): QueryParams {
  return {
    ...toQuery(filters),
    limit,
    offset: (page - 1) * limit,
    sort,
    order,
  }
}

export const purchaseReturnsApi = {
  /** The register, paged on the SERVER. The browser never holds the whole table. */
  list: (query: RegisterQuery, signal?: AbortSignal): Promise<ListResponse<PurchaseReturnRow>> =>
    api.list<PurchaseReturnRow>('v1/returns', registerParams(query), signal),

  /**
   * The cards, the trend, the reason split and the insights.
   *
   * Takes the same filters as the register and no paging, because it describes
   * the whole filtered set rather than the page being looked at.
   */
  summary: (filters: ReturnsFilters, signal?: AbortSignal) =>
    api.one<ReturnSummary>('v1/returns/summary', toQuery(filters), signal),

  /** Statuses, credit statuses and reasons, as this company actually uses them. */
  options: (signal?: AbortSignal) => api.one<ReturnOptions>('v1/returns/options', undefined, signal),

  getById: (id: number | string, signal?: AbortSignal) =>
    api.one<PurchaseReturnDetail>(`v1/returns/${id}`, undefined, signal),

  /** Asked of Inventory and Books at the moment somebody looks. Never stored. */
  integration: (id: number | string, signal?: AbortSignal) =>
    api.one<ReturnIntegration>(`v1/returns/${id}/integration`, undefined, signal),

  create: (payload: Record<string, unknown>) => api.post<PurchaseReturnDetail>('v1/returns', payload),

  approve: (id: number | string, note?: string) =>
    api.post<PurchaseReturnDetail>(`v1/returns/${id}/approve`, note ? { note } : {}),

  /** Asks Inventory to move the goods out. Inventory owns what happens next. */
  dispatch: (id: number | string) => api.post<PurchaseReturnDetail>(`v1/returns/${id}/dispatch`, {}),

  /** Asks Smart Books for the debit note. Books owns the credit against the payable. */
  debitNote: (id: number | string) => api.post<PurchaseReturnDetail>(`v1/returns/${id}/debit-note`, {}),

  cancel: (id: number | string, reason: string) =>
    api.post<PurchaseReturnDetail>(`v1/returns/${id}/cancel`, { reason }),

  /** The supplier's own credit note reference — ours to track, Books' to account for. */
  supplierCredit: (
    id: number | string,
    payload: {
      supplier_credit_status: string
      supplier_credit_ref?: string
      supplier_credit_date?: string
      supplier_credit_amount?: number
    },
  ) => api.post<PurchaseReturnDetail>(`v1/returns/${id}/supplier-credit`, payload),

  /** Reads the file and reports. Writes nothing — that is the next call. */
  importPreview: (file: File, signal?: AbortSignal) => {
    const form = new FormData()
    form.append('file', file)

    return api.upload<ImportPreview>('v1/returns/import/preview', form, undefined, signal)
  },

  importCommit: (returns: unknown[]) => api.post<ImportResult>('v1/returns/import', { returns }),

  /**
   * The register as a CSV, under the same filters.
   *
   * Through `api.download` rather than an <a href>, because this API
   * authenticates with a bearer token and a plain link cannot carry one.
   */
  exportCsv: (query: RegisterQuery) =>
    api.download(
      'v1/returns/export',
      `purchase-returns-${new Date().toISOString().slice(0, 10)}.csv`,
      { ...registerParams({ ...query, page: 1, limit: 5000 }) },
    ),
}
