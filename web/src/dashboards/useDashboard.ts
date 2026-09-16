/**
 * Fetching a dashboard, with the two failure modes that matter handled.
 *
 * STALE RESPONSES. A user who switches company while a request is in flight
 * must not have the previous company's figures painted over the new one. Every
 * request carries the scope and filters it was made for, and a response whose
 * key no longer matches the current one is dropped rather than rendered — the
 * abort alone is not enough, because a response can already be in the
 * microtask queue when the abort fires.
 *
 * BACKGROUND REFRESH. A refresh keeps the previous data on screen and marks it
 * refreshing. Blanking a dashboard the user is reading, to fetch numbers that
 * are mostly the same, is worse than a half-second of slightly old data.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError, type QueryParams } from '../services/api'
import { usePurchases } from '../context/PurchasesContext'
import type { DashboardResponse } from './types'

export interface DashboardState {
  data: DashboardResponse | null
  loading: boolean
  refreshing: boolean
  error: string | null
  retryable: boolean
  /** When the data on screen was fetched, not when the server generated it. */
  fetchedAt: Date | null
  refresh: () => void
}

export function useDashboard(view: string, params: QueryParams): DashboardState {
  const { scope } = usePurchases()

  // The payload is stored WITH the key it was fetched for. Keeping them apart —
  // data in one state, the key in another — means that for one render after the
  // view changes, the new view's component is handed the previous view's panels
  // and reads a panel that does not exist there. Storing them together makes
  // that unrepresentable.
  const [loaded, setLoaded] = useState<{ key: string; data: DashboardResponse; at: Date } | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [retryable, setRetryable] = useState(false)
  const [nonce, setNonce] = useState(0)

  // The identity of the request currently wanted. Compared on arrival.
  const key = JSON.stringify({ view, scope, params })
  const wanted = useRef(key)
  wanted.current = key

  // Anything fetched for a different scope, view or filter set is not this
  // screen's data, whatever is still in state.
  const data = loaded?.key === key ? loaded.data : null
  const fetchedAt = loaded?.key === key ? loaded.at : null

  const refresh = useCallback(() => setNonce((n) => n + 1), [])

  useEffect(() => {
    if (!scope) return

    const controller = new AbortController()
    const requested = key

    // `data` is already narrowed to this key, so a non-null value means we are
    // re-fetching something already on screen — a background refresh, which
    // leaves it in place. A new view or a new filter set has no data yet and
    // gets the skeleton, not the previous screen's numbers.
    setRefreshing(data !== null)
    setLoading(data === null)
    setError(null)

    api
      .one<DashboardResponse>(`v1/dashboards/${view}`, params, controller.signal)
      .then((response) => {
        if (wanted.current !== requested) return
        setLoaded({ key: requested, data: response.data, at: new Date() })
        setError(null)
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted || wanted.current !== requested) return
        if (err instanceof ApiError) {
          setError(err.message)
          setRetryable(err.retryable)
        } else {
          setError(err instanceof Error ? err.message : 'Could not load this dashboard.')
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
  }, [key, nonce])

  return { data, loading, refreshing, error, retryable, fetchedAt, refresh }
}
