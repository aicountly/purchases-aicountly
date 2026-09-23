/**
 * The sourcing screen's state: what is being asked for, and what came back.
 *
 * THE FILTERS LIVE IN THE URL, which is the same decision `useUrlFilter` makes
 * for every other list in this product and for the same two reasons — a
 * drill-down arriving from a dashboard is honoured, and a buyer who has
 * narrowed the list can send somebody the link.
 *
 * Only filters the API can actually honour are here. A control the backend
 * cannot answer is worse than no control at all, because the screen then
 * reports a filtered list that was never filtered.
 */

import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, type ListResponse } from '../../services/api'
import type { RfqListRow, SourcingSummary } from '../../services/types'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'

export type QuoteFilter = '' | 'none' | 'any' | 'comparable'
export type DeadlineFilter = '' | 'overdue' | 'due_soon'
export type SortField = 'rfq_date' | 'rfq_no' | 'status' | 'response_deadline' | 'created_at'

export interface RfqFilters {
  q: string
  status: string
  from: string
  to: string
  supplierId: number | null
  /** Only enquiries this user raised. Sent as `created_by=me`; the API resolves it. */
  mine: boolean
  quotes: QuoteFilter
  deadline: DeadlineFilter
  sort: SortField
  order: 'asc' | 'desc'
  page: number
  size: number
}

export const PAGE_SIZES = [10, 25, 50, 100]

const DEFAULTS: RfqFilters = {
  q: '',
  status: '',
  from: '',
  to: '',
  supplierId: null,
  mine: false,
  quotes: '',
  deadline: '',
  sort: 'rfq_date',
  order: 'desc',
  page: 1,
  size: 25,
}

/** Everything except paging and sorting — what "Clear filters" clears. */
export function activeFilterCount(filters: RfqFilters): number {
  return [
    filters.q !== '',
    filters.status !== '',
    filters.from !== '' || filters.to !== '',
    filters.supplierId !== null,
    filters.mine,
    filters.quotes !== '',
    filters.deadline !== '',
  ].filter(Boolean).length
}

export function useRfqFilters() {
  const [params, setParams] = useSearchParams()

  const filters = useMemo<RfqFilters>(() => {
    const int = (name: string, fallback: number): number => {
      const parsed = Number.parseInt(params.get(name) ?? '', 10)
      return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback
    }
    const supplier = Number.parseInt(params.get('supplier_id') ?? '', 10)

    return {
      q: params.get('q') ?? '',
      status: params.get('status') ?? '',
      from: params.get('from') ?? '',
      to: params.get('to') ?? '',
      supplierId: Number.isFinite(supplier) && supplier > 0 ? supplier : null,
      mine: params.get('mine') === '1',
      quotes: (params.get('quotes') ?? '') as QuoteFilter,
      deadline: (params.get('deadline') ?? '') as DeadlineFilter,
      sort: (params.get('sort') ?? DEFAULTS.sort) as SortField,
      order: params.get('order') === 'asc' ? 'asc' : 'desc',
      page: int('page', 1),
      size: PAGE_SIZES.includes(int('size', DEFAULTS.size)) ? int('size', DEFAULTS.size) : DEFAULTS.size,
    }
  }, [params])

  /**
   * Change some of the filters.
   *
   * Changing WHAT is being asked for always returns to page one: page four of
   * a narrower list is usually page nothing, and an empty table after typing
   * one letter reads as a broken search.
   *
   * `replace` is for the search box, which would otherwise put one history
   * entry behind every keystroke and make Back useless.
   */
  const update = useCallback(
    (patch: Partial<RfqFilters>, options: { replace?: boolean } = {}) => {
      const next = new URLSearchParams(params)

      const write = (name: string, value: string) => {
        if (value === '') next.delete(name)
        else next.set(name, value)
      }

      if ('q' in patch) write('q', patch.q ?? '')
      if ('status' in patch) write('status', patch.status ?? '')
      if ('from' in patch) write('from', patch.from ?? '')
      if ('to' in patch) write('to', patch.to ?? '')
      if ('supplierId' in patch) write('supplier_id', patch.supplierId ? String(patch.supplierId) : '')
      if ('mine' in patch) write('mine', patch.mine ? '1' : '')
      if ('quotes' in patch) write('quotes', patch.quotes ?? '')
      if ('deadline' in patch) write('deadline', patch.deadline ?? '')
      if ('sort' in patch) write('sort', patch.sort === DEFAULTS.sort ? '' : (patch.sort ?? ''))
      if ('order' in patch) write('order', patch.order === DEFAULTS.order ? '' : (patch.order ?? ''))
      if ('size' in patch) write('size', patch.size === DEFAULTS.size ? '' : String(patch.size))

      if ('page' in patch) write('page', (patch.page ?? 1) <= 1 ? '' : String(patch.page))
      else if (Object.keys(patch).some((key) => key !== 'sort' && key !== 'order')) next.delete('page')

      setParams(next, { replace: options.replace ?? false })
    },
    [params, setParams],
  )

  const clear = useCallback(() => {
    const next = new URLSearchParams(params)
    for (const name of ['q', 'status', 'from', 'to', 'supplier_id', 'mine', 'quotes', 'deadline', 'page']) {
      next.delete(name)
    }
    setParams(next, { replace: false })
  }, [params, setParams])

  return { filters, update, clear }
}

/**
 * One page of enquiries, plus how many sit under each status.
 *
 * The status counts come back with the page rather than from a second call:
 * they change with every search, and a tab strip that lagged the table behind
 * it by one request is a tab strip nobody trusts.
 */
export function useRfqList(filters: RfqFilters) {
  const { scope } = usePurchases()

  return useApi<ListResponse<RfqListRow>>(
    (signal) =>
      api.list<RfqListRow>(
        'v1/rfqs',
        {
          q: filters.q || undefined,
          status: filters.status || undefined,
          from: filters.from || undefined,
          to: filters.to || undefined,
          supplier_account_id: filters.supplierId ?? undefined,
          created_by: filters.mine ? 'me' : undefined,
          quotes: filters.quotes || undefined,
          deadline: filters.deadline || undefined,
          sort: filters.sort,
          order: filters.order,
          limit: filters.size,
          offset: (filters.page - 1) * filters.size,
        },
        signal,
      ),
    [
      scope?.cmp_id,
      scope?.fy_id,
      scope?.bo_id,
      filters.q,
      filters.status,
      filters.from,
      filters.to,
      filters.supplierId,
      filters.mine,
      filters.quotes,
      filters.deadline,
      filters.sort,
      filters.order,
      filters.page,
      filters.size,
    ],
    Boolean(scope),
  )
}

/**
 * The figures above the list.
 *
 * Deliberately NOT refetched when a filter changes: they answer for the whole
 * financial year, and a "Total RFQs" that dropped to 3 because somebody typed
 * in the search box would be a different question with the same label on it.
 */
export function useSourcingSummary() {
  const { scope } = usePurchases()

  return useApi<{ data: SourcingSummary }>(
    (signal) => api.one<SourcingSummary>('v1/rfqs/summary', undefined, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id],
    Boolean(scope),
  )
}
