/**
 * Company scope, session and permissions for the whole app.
 *
 * The scope is three ids. The names behind them — company, branch, financial
 * year — belong to Manage and are read from Manage through the API; this
 * provider holds only what it needs to make calls and to remember the user's
 * last choice between visits.
 */

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, setScope, type CompanyScope } from '../services/api'

export interface PurchasesSession {
  uuid: string
  display_name: string
  kind: 'user' | 'service'
  is_owner: boolean
  /**
   * Whether Manage named a role for this company at all.
   *
   * `is_owner: false` on its own is ambiguous — it covers both "you are a
   * delegate" and "we never got an answer", which need different things said
   * to the user and different things done about them.
   */
  access_resolved: boolean
  context: CompanyScope
  permissions: string[]
}

interface PurchasesContextValue {
  scope: CompanyScope | null
  setCompanyScope: (scope: CompanyScope) => void
  session: PurchasesSession | null
  /** Has this user got that permission? Owners hold everything. */
  can: (permission: string) => boolean
  loading: boolean
  error: string | null
  reload: () => void
}

const PurchasesContextObject = createContext<PurchasesContextValue | null>(null)

const SCOPE_KEY = 'purchases:scope'

function readStoredScope(): CompanyScope | null {
  try {
    const raw = window.localStorage.getItem(SCOPE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<CompanyScope>
    if (!parsed.cmp_id || !parsed.fy_id) return null
    return { cmp_id: Number(parsed.cmp_id), fy_id: Number(parsed.fy_id), bo_id: Number(parsed.bo_id ?? 0) }
  } catch {
    // A private window, or storage the browser refuses. The app still works;
    // the user just picks their company again.
    return null
  }
}

export function PurchasesProvider({ children }: { children: ReactNode }) {
  const [scope, setScopeState] = useState<CompanyScope | null>(() => readStoredScope())
  const [session, setSession] = useState<PurchasesSession | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)

  // The API module reads the scope on every call, so it is registered as soon
  // as it changes rather than being threaded through each request.
  useEffect(() => {
    setScope(scope)
  }, [scope])

  const setCompanyScope = useCallback((next: CompanyScope) => {
    setScopeState(next)
    try {
      window.localStorage.setItem(SCOPE_KEY, JSON.stringify(next))
    } catch {
      /* storage refused; the choice still applies for this visit */
    }
  }, [])

  useEffect(() => {
    if (!scope) {
      setSession(null)
      return
    }

    let cancelled = false
    const controller = new AbortController()

    setLoading(true)
    setError(null)

    api
      .one<PurchasesSession>('v1/session', undefined, controller.signal)
      .then((response) => {
        if (!cancelled) setSession(response.data)
      })
      .catch((err: Error) => {
        if (cancelled || controller.signal.aborted) return
        setError(err.message)
        setSession(null)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [scope, reloadToken])

  const can = useCallback(
    (permission: string) => {
      if (!session) return false
      if (session.is_owner) return true
      return session.permissions.includes(permission)
    },
    [session],
  )

  const value = useMemo<PurchasesContextValue>(
    () => ({
      scope,
      setCompanyScope,
      session,
      can,
      loading,
      error,
      reload: () => setReloadToken((n) => n + 1),
    }),
    [scope, setCompanyScope, session, can, loading, error],
  )

  return <PurchasesContextObject.Provider value={value}>{children}</PurchasesContextObject.Provider>
}

export function usePurchases(): PurchasesContextValue {
  const value = useContext(PurchasesContextObject)
  if (!value) throw new Error('usePurchases must be used inside PurchasesProvider')
  return value
}
