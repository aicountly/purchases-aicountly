/**
 * The payables table's own data.
 *
 * Fetched separately from the dashboard payload on purpose. Turning a page or
 * changing a tab here must not re-run the Smart Books reads the panels above
 * need — those cost a request per supplier — and a table that fails must not
 * take the KPI strip and the charts down with it.
 *
 * The two failure modes handled are the same two the dashboard hook handles,
 * for the same reasons: a response for a filter set nobody is looking at any
 * more is dropped rather than rendered, and a refetch over data already on
 * screen keeps it there instead of blanking the table.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError, type QueryParams } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import type { PayableRow, PayableTabCounts } from './types'

export interface PayablesQuery {
  tab: string
  q: string
  supplierId: string | null
  poId: string | null
  from: string | null
  to: string | null
  status: string | null
  sort: string
  order: 'asc' | 'desc'
  page: number
  perPage: number
}

export interface PayablesState {
  rows: PayableRow[]
  total: number
  counts: PayableTabCounts | null
  loading: boolean
  refreshing: boolean
  error: string | null
  retryable: boolean
  refresh: () => void
}

const EMPTY_COUNTS: PayableTabCounts = {
  all: 0, awaiting_review: 0, exceptions: 0, ready_to_post: 0, posted: 0, failed: 0, duplicates: 0,
}

export function usePayables(query: PayablesQuery, enabled: boolean): PayablesState {
  const { scope } = usePurchases()

  const [loaded, setLoaded] = useState<{ key: string; rows: PayableRow[]; total: number; counts: PayableTabCounts } | null>(null)
  const [loading, setLoading] = useState(enabled)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [retryable, setRetryable] = useState(false)
  const [nonce, setNonce] = useState(0)

  const key = JSON.stringify({ scope, query })
  const wanted = useRef(key)
  wanted.current = key

  // Narrowed to this key, so nothing from a previous tab or page can be shown
  // under the current one for even a single render.
  const current = loaded?.key === key ? loaded : null

  const refresh = useCallback(() => setNonce((n) => n + 1), [])

  useEffect(() => {
    if (!enabled || !scope) {
      setLoading(false)
      return
    }

    const controller = new AbortController()
    const requested = key
    const hadData = current !== null

    setRefreshing(hadData)
    setLoading(!hadData)
    setError(null)

    const params: QueryParams = {
      tab: query.tab,
      q: query.q || undefined,
      supplier_account_id: query.supplierId ?? undefined,
      po_id: query.poId ?? undefined,
      from: query.from ?? undefined,
      to: query.to ?? undefined,
      status: query.status ?? undefined,
      sort: query.sort,
      order: query.order,
      limit: query.perPage,
      offset: (query.page - 1) * query.perPage,
    }

    api
      .list<PayableRow>('v1/bills/payables', params, controller.signal)
      .then((response) => {
        if (wanted.current !== requested) return
        setLoaded({
          key: requested,
          rows: response.data,
          total: response.meta.total,
          counts: (response.meta.counts as PayableTabCounts | undefined) ?? EMPTY_COUNTS,
        })
        setError(null)
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted || wanted.current !== requested) return
        if (err instanceof ApiError) {
          setError(err.message)
          setRetryable(err.retryable)
        } else {
          setError(err instanceof Error ? err.message : 'Could not load these bills.')
          setRetryable(true)
        }
      })
      .finally(() => {
        if (wanted.current !== requested) return
        setLoading(false)
        setRefreshing(false)
      })

    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, nonce, enabled])

  return {
    rows: current?.rows ?? [],
    total: current?.total ?? 0,
    counts: current?.counts ?? null,
    loading,
    refreshing,
    error,
    retryable,
    refresh,
  }
}

/**
 * A value that follows its input after it stops changing.
 *
 * Typing in the search box must not be one request per keystroke; 350ms is
 * long enough that a normal typing speed produces one call and short enough
 * that the wait does not read as a broken box.
 */
export function useDebounced<T>(value: T, delay = 350): T {
  const [settled, setSettled] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(value), delay)
    return () => window.clearTimeout(timer)
  }, [value, delay])

  return settled
}
