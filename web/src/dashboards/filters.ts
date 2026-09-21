/**
 * Dashboard state lives in the URL.
 *
 * Every filter a user sets is a query parameter, so a link to what they are
 * looking at is a link to what they are looking at, and Back goes back one
 * change rather than out of the dashboard entirely.
 */

import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import type { QueryParams } from '../services/api'

export const PURCHASE_VIEWS = [
  { id: 'overview', label: 'Overview' },
  { id: 'procurement', label: 'Procurement' },
  { id: 'suppliers', label: 'Suppliers' },
  { id: 'bills-payables', label: 'Bills & Payables' },
  { id: 'ai-insights', label: 'AI Insights' },
] as const

export type PurchaseViewId = (typeof PURCHASE_VIEWS)[number]['id']

export function isPurchaseView(value: string | undefined): value is PurchaseViewId {
  return PURCHASE_VIEWS.some((view) => view.id === value)
}

export const DATE_PRESETS = [
  { id: 'today', label: 'Today' },
  { id: 'this_week', label: 'This week' },
  { id: 'this_month', label: 'This month' },
  { id: 'last_month', label: 'Last month' },
  { id: 'last_7_days', label: 'Last 7 days' },
  { id: 'last_30_days', label: 'Last 30 days' },
  { id: 'last_90_days', label: 'Last 90 days' },
  { id: 'this_quarter', label: 'This quarter' },
  { id: 'this_year', label: 'This year' },
  { id: 'custom', label: 'Custom range' },
] as const

/** Parameters the API understands. Anything else in the URL is the UI's own. */
const API_PARAMS = [
  'preset', 'from', 'to', 'compare', 'granularity',
  'supplier_id', 'buyer', 'warehouse_id', 'q',
  'view', 'horizon', 'category', 'rfq_id', 'limit', 'offset',
] as const

export interface DashboardFilters {
  params: URLSearchParams
  /** Only the parameters the API reads, ready to pass to a request. */
  apiParams: QueryParams
  get: (name: string) => string | null
  set: (changes: Record<string, string | null>) => void
  reset: () => void
  /** True when anything beyond the default period is applied. */
  isNarrowed: boolean
}

export function useDashboardFilters(): DashboardFilters {
  const [params, setParams] = useSearchParams()

  const set = useCallback(
    (changes: Record<string, string | null>) => {
      const next = new URLSearchParams(params)
      for (const [name, value] of Object.entries(changes)) {
        if (value === null || value === '') {
          next.delete(name)
        } else {
          next.set(name, value)
        }
      }
      // Paging is a property of a filter set, so changing a filter starts again
      // at the first page rather than leaving the reader on page four of a
      // list that no longer has four pages.
      if (!('offset' in changes)) next.delete('offset')

      // replace: false so each filter change is its own history entry and Back
      // undoes exactly one of them.
      setParams(next, { replace: false })
    },
    [params, setParams],
  )

  const reset = useCallback(() => setParams(new URLSearchParams(), { replace: false }), [setParams])

  const apiParams = useMemo(() => {
    const out: QueryParams = {}
    for (const name of API_PARAMS) {
      const value = params.get(name)
      if (value !== null && value !== '') out[name] = value
    }
    return out
  }, [params])

  const isNarrowed = useMemo(
    () => ['supplier_id', 'buyer', 'warehouse_id', 'q'].some((name) => (params.get(name) ?? '') !== ''),
    [params],
  )

  return {
    params,
    apiParams,
    get: (name: string) => params.get(name),
    set,
    reset,
    isNarrowed,
  }
}
