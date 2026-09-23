/**
 * Everything the workspace loads, and when it reloads.
 *
 * THREE INDEPENDENT REQUESTS, on purpose. The register, the analytics and the
 * filter vocabularies fail separately and are shown separately: analytics that
 * cannot be computed must not take the register down with them, because the
 * register is the part somebody came here to work in.
 *
 * Paging reloads the register and NOT the analytics — the cards describe the
 * whole filtered set, and recomputing them to turn a page would be three
 * queries to redraw numbers that have not changed.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'
import { purchaseReturnsApi } from './api'
import { PAGE_SIZE, type ReturnsFilters, type SortKey } from './filters'
import type { PurchaseReturnRow, ReturnOptions, ReturnSummary } from './types'
import type { ListResponse } from '../../services/api'

/**
 * The search box, held back from the network.
 *
 * 300ms: long enough that typing "Metro Electronics" is one request rather than
 * seventeen, short enough that nobody notices waiting. The URL is updated on
 * every keystroke regardless, so the address bar always reflects the box.
 */
function useDebounced<T>(value: T, ms = 300): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), ms)

    return () => window.clearTimeout(timer)
  }, [value, ms])

  return debounced
}

export interface WorkspaceData {
  register: {
    data: ListResponse<PurchaseReturnRow> | null
    loading: boolean
    error: string | null
    reload: () => void
  }
  summary: {
    data: ReturnSummary | null
    loading: boolean
    error: string | null
    reload: () => void
  }
  options: ReturnOptions | null
  /** True while the very first load of this screen is still in flight. */
  firstLoad: boolean
  reloadAll: () => void
}

export function useReturnsWorkspace(
  filters: ReturnsFilters,
  page: number,
  sort: SortKey,
  order: 'asc' | 'desc',
  /**
   * How many rows to fetch.
   *
   * The list pages at twenty-five. The calendar and the board lay the whole
   * filtered period out at once, so they ask for more — bounded, because a
   * month drawn from four thousand rows is a month nobody can read and a
   * request nobody should make.
   */
  limit = PAGE_SIZE,
): WorkspaceData {
  const { scope } = usePurchases()
  const enabled = Boolean(scope)

  const debouncedQuery = useDebounced(filters.q)
  const effective = useMemo<ReturnsFilters>(() => ({ ...filters, q: debouncedQuery }), [filters, debouncedQuery])

  // The filters are a stable string for the dependency array. Passing the
  // object itself would refetch on every render, because a new object is a new
  // dependency however identical its contents.
  const filterKey = useMemo(() => JSON.stringify(effective), [effective])

  const register = useApi(
    (signal) => purchaseReturnsApi.list({ filters: effective, page, sort, order, limit }, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, filterKey, page, sort, order, limit],
    enabled,
  )

  const summary = useApi(
    (signal) => purchaseReturnsApi.summary(effective, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, filterKey],
    enabled,
  )

  // The vocabularies change when somebody adds a reason, which is roughly
  // never, so they are fetched once per company rather than per filter change.
  const options = useApi(
    (signal) => purchaseReturnsApi.options(signal),
    [scope?.cmp_id, scope?.fy_id],
    enabled,
  )

  const reloadAll = useCallback(() => {
    register.reload()
    summary.reload()
    options.reload()
  }, [register, summary, options])

  return {
    register: {
      data: register.data,
      loading: register.loading,
      error: register.error,
      reload: register.reload,
    },
    summary: {
      data: summary.data?.data ?? null,
      loading: summary.loading,
      error: summary.error,
      reload: summary.reload,
    },
    options: options.data?.data ?? null,
    firstLoad: register.loading && register.data === null,
    reloadAll,
  }
}
