import { useEffect, useState, type ReactNode } from 'react'
import { Button, Notice } from '../ui'
import { ApiError } from '../services/api'
import { fetchCompanyInfo } from '../services/manage'
import { usePurchases } from '../context/PurchasesContext'
import { judgeLinkScope, readLinkScope, withoutScopeParams, type ScopeVerdict } from './scopeLink'

type GateState =
  | { phase: 'open' }
  | { phase: 'checking' }
  | { phase: 'refused'; message: string }
  | { phase: 'unavailable'; message: string }

/** Drop cmp_id / fy_id / bo_id from the address bar, keeping the page the link pointed at. */
function clearScopeFromUrl(): void {
  const { pathname, search, hash } = window.location
  window.history.replaceState(window.history.state, '', withoutScopeParams(pathname, search, hash))
}

/**
 * A link that names a company, year and branch (`?cmp_id=&fy_id=&bo_id=`, MNY-15) is checked with
 * Manage — live, as the signed-in person, the read the company picker makes — before anything is
 * shown. If it checks out the scope is applied and the destination opens; if not, it is refused
 * and the app stays where it was: a link from company A never silently opens company B.
 * Routes and parameters: docs/contracts/insights/routes.md.
 */
export function DeepLinkScopeGate({ children }: { children: ReactNode }) {
  const { scope, setCompanyScope } = usePurchases()
  const [state, setState] = useState<GateState>(() =>
    readLinkScope(window.location.search) === null ? { phase: 'open' } : { phase: 'checking' },
  )
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    const link = readLinkScope(window.location.search)
    if (link === null) {
      setState({ phase: 'open' })
      return
    }
    const controller = new AbortController()
    const settle = (verdict: ScopeVerdict) => {
      if (controller.signal.aborted) return
      if (verdict.kind === 'apply') {
        setCompanyScope({ cmp_id: verdict.cmp_id, fy_id: verdict.fy_id ?? 0, bo_id: verdict.bo_id })
        clearScopeFromUrl()
        setState({ phase: 'open' })
      } else {
        setState({ phase: 'refused', message: verdict.message })
      }
    }
    if (link === 'malformed') {
      settle(judgeLinkScope(link, null))
      return
    }
    setState({ phase: 'checking' })
    fetchCompanyInfo(link.cmp_id, controller.signal)
      .then((info) =>
        settle(judgeLinkScope(link, { fyIds: info.fyList.map((f) => f.fyId), branchIds: info.branches.map((b) => b.boId) })),
      )
      .catch((err: unknown) => {
        if (controller.signal.aborted) return
        // Manage said no for this person: not theirs to open. Anything else is "could not check".
        if (err instanceof ApiError && (err.status === 403 || err.status === 404)) {
          settle(judgeLinkScope(link, null))
          return
        }
        setState({
          phase: 'unavailable',
          message: 'The company in this link could not be checked right now, so it was not opened. Nothing was changed.',
        })
      })

    return () => controller.abort()
  }, [attempt, setCompanyScope])

  if (state.phase === 'open') return <>{children}</>

  if (state.phase === 'checking') {
    return (
      <main className="screen">
        <div className="panel">
          <p className="message">Checking the company in this link…</p>
        </div>
      </main>
    )
  }

  return (
    <main className="screen">
      <div className="panel">
        <Notice
          tone={state.phase === 'refused' ? 'danger' : 'warning'}
          title={state.phase === 'refused' ? 'This link was not opened' : 'Could not check this link'}
          action={state.phase === 'unavailable' ? <Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button> : undefined}
        >
          {state.message}
          {scope ? ` You are still working in company ${scope.cmp_id}.` : ''}
        </Notice>
        <div style={{ marginTop: 12 }}>
          <Button
            tone="primary"
            onClick={() => {
              clearScopeFromUrl()
              setState({ phase: 'open' })
            }}
          >
            {scope ? 'Continue in my current company' : 'Choose a company'}
          </Button>
        </div>
      </div>
    </main>
  )
}
