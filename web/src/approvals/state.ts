/**
 * Approvals state lives in the URL, exactly as the dashboards' does.
 *
 * A link to "purchase orders over a week old, waiting on me" is a link to that,
 * and Back undoes one filter rather than leaving the screen. The one piece of
 * state that is NOT in the URL is the half-typed search term: a history entry
 * per keystroke is a Back button nobody can use.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import type { QueryParams } from '../services/api'
import { isApprovalTab, QUEUE_TABS, type ApprovalTabId } from './types'

export const APPROVAL_PERIODS = [
  { id: 'this_month', label: 'This month' },
  { id: 'last_month', label: 'Last month' },
  { id: 'last_7_days', label: 'Last 7 days' },
  { id: 'last_30_days', label: 'Last 30 days' },
  { id: 'last_90_days', label: 'Last 90 days' },
  { id: 'this_quarter', label: 'This quarter' },
  { id: 'this_year', label: 'This year' },
] as const

export const PAGE_SIZES = [10, 25, 50] as const

export interface ApprovalFilters {
  tab: ApprovalTabId
  /** The queue scope the API understands. Only meaningful on a queue tab. */
  scope: string
  preset: string
  type: string
  requester: string
  status: string
  q: string
  page: number
  pageSize: number
  /** Everything the queue endpoint reads, ready to hand to a request. */
  queueParams: QueryParams
  /** Everything the summary endpoint reads. The period, and nothing else. */
  summaryParams: QueryParams
  set: (changes: Record<string, string | number | null>) => void
  clear: () => void
  isNarrowed: boolean
}

export function useApprovalFilters(): ApprovalFilters {
  const [params, setParams] = useSearchParams()

  const set = useCallback(
    (changes: Record<string, string | number | null>) => {
      const next = new URLSearchParams(params)
      for (const [name, value] of Object.entries(changes)) {
        if (value === null || value === '') next.delete(name)
        else next.set(name, String(value))
      }
      // Paging is a property of a filter set. Changing a filter starts again at
      // the first page rather than leaving a reader on page four of a list that
      // no longer has four pages.
      if (!('page' in changes)) next.delete('page')
      setParams(next, { replace: false })
    },
    [params, setParams],
  )

  const clear = useCallback(() => {
    const next = new URLSearchParams()
    const tab = params.get('tab')
    if (tab !== null) next.set('tab', tab)
    setParams(next, { replace: false })
  }, [params, setParams])

  const rawTab = params.get('tab')
  const tab: ApprovalTabId = isApprovalTab(rawTab) ? rawTab : 'mine'
  // A drill-down from a KPI arrives with `scope` rather than `tab`, because that
  // is the name the API uses. Both are honoured, and the tab follows the scope.
  const scopeParam = params.get('scope')
  const resolvedTab: ApprovalTabId =
    rawTab === null && isApprovalTab(scopeParam) ? (scopeParam as ApprovalTabId) : tab

  const preset = params.get('preset') ?? 'this_month'
  const type = params.get('type') ?? ''
  const requester = params.get('requester') ?? ''
  // A drill-down from a KPI card arrives with the status lower-cased. The API
  // is case-insensitive about it; the <select> is not, so it is normalised here.
  const status = (params.get('status') ?? '').toUpperCase()
  const q = params.get('q') ?? ''
  const pageSize = clampPageSize(Number.parseInt(params.get('size') ?? '', 10))
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1)

  const onQueue = QUEUE_TABS.includes(resolvedTab)

  const queueParams = useMemo<QueryParams>(() => {
    const out: QueryParams = {
      scope: resolvedTab,
      limit: pageSize,
      offset: (page - 1) * pageSize,
      sort: params.get('sort') ?? undefined,
      order: params.get('order') ?? undefined,
    }
    if (type !== '') out.type = type
    if (requester !== '') out.requester = requester
    if (status !== '') out.status = status
    if (q !== '') out.q = q
    // The period narrows decided documents only, so it is not even sent on a
    // tab that lists what is still pending.
    if (resolvedTab === 'actioned') out.preset = preset
    return out
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resolvedTab, pageSize, page, type, requester, status, q, preset, params])

  const summaryParams = useMemo<QueryParams>(() => ({ preset }), [preset])

  return {
    tab: resolvedTab,
    scope: onQueue ? resolvedTab : 'all_pending',
    preset,
    type,
    requester,
    status,
    q,
    page,
    pageSize,
    queueParams,
    summaryParams,
    set,
    clear,
    isNarrowed: type !== '' || requester !== '' || status !== '' || q !== '',
  }
}

function clampPageSize(value: number): number {
  return PAGE_SIZES.includes(value as (typeof PAGE_SIZES)[number]) ? value : 10
}

/**
 * A value that settles before it is used.
 *
 * The search box is server-side, so every keystroke would otherwise be a
 * request and the answers would arrive out of order. 300ms is long enough that
 * typing a document number is one request and short enough that it still feels
 * like the list is following you.
 */
export function useDebounced<T>(value: T, delay = 300): T {
  const [settled, setSettled] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(value), delay)
    return () => window.clearTimeout(timer)
  }, [value, delay])

  return settled
}
