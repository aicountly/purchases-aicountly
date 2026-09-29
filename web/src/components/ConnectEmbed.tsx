import { useEffect, useRef, useSyncExternalStore } from 'react'
import { useNavigate } from 'react-router-dom'
import { MessagesSquare } from 'lucide-react'
import { ensureSesKey } from '../auth/portal'
import { usePurchases } from '../context/PurchasesContext'
import { buildAppLaunchUrl, getAppById, launchApp } from '../services/appLauncher'
import {
  CONNECT_OPEN_PATHS,
  connectApi,
  discussInConnect,
  isConnectReady,
  loadConnect,
  setConnectReady,
  subscribeConnectReady,
  type ConnectEntityType,
} from '../services/connect'
import { Button } from '../ui'

/**
 * Mounts Aicountly Connect for the signed-in person while the shell is on screen, keeps its
 * company scope in step with the header, and takes it down again on sign-out (the shell
 * unmounts). Renders nothing itself — the widget draws its own launcher.
 */
export function ConnectEmbed() {
  const { scope, setCompanyScope } = usePurchases()
  const navigate = useNavigate()
  const ready = useConnectReady()
  const latest = useRef({ scope, navigate, setCompanyScope })
  latest.current = { scope, navigate, setCompanyScope }

  useEffect(() => {
    let cancelled = false
    const advisor = getAppById('advisor')
    void loadConnect().then(async (api) => {
      if (!api || cancelled) return
      const current = latest.current.scope
      const mounted = await Promise.resolve().then(() => api.init({
        // The person's own session, and only theirs. A signed-out person gets null, and Connect
        // shows its own "Sign in to use Connect" rather than sending anyone anywhere.
        getSesKey: async (forceRefresh) => {
          try {
            return await ensureSesKey(forceRefresh)
          } catch {
            return null
          }
        },
        product: 'purchases',
        companyId: current?.cmp_id ?? null,
        fyId: current?.fy_id ?? null,
        boId: current?.bo_id ?? 0,
        // Advisor through the portal's SSO jump, with no token in the link itself.
        advisorUrl: advisor ? buildAppLaunchUrl(advisor, { authToken: '' }) : undefined,
        labels: { button: 'Connect', openAria: 'Open Connect', closeAria: 'Close Connect', advisor: 'Advisor' },
        calling: true,
        onEvent: (name, detail) => {
          if (name === 'aicountly:connect:context-open') openShared(detail)
        },
      })).then((ok) => ok === true, () => false)
      if (!cancelled) setConnectReady(mounted)
    })

    function openShared(detail: Record<string, unknown>) {
      const product = String(detail.product ?? '')
      if (product !== 'purchases') {
        // Somebody shared a record from another product: open that product, where it lives.
        const app = getAppById(product)
        if (app) launchApp(app, { newTab: true })
        return
      }
      const route = CONNECT_OPEN_PATHS[String(detail.entityType ?? '') as ConnectEntityType]
      const id = Number(detail.entityId ?? 0)
      if (!route || !Number.isFinite(id) || id <= 0) return
      const { scope: current, navigate: go, setCompanyScope: choose } = latest.current
      const companyId = Number(detail.companyId ?? 0)
      if (companyId > 0 && current && companyId !== current.cmp_id) {
        // A record of another company: switch to it (Manage still decides whether this person
        // may open it) rather than reading an id against the wrong company.
        choose({ cmp_id: companyId, fy_id: Number(detail.fyId ?? current.fy_id), bo_id: Number(detail.boId ?? 0) })
      }
      go(route(id))
    }

    return () => {
      cancelled = true
      setConnectReady(false)
      try {
        connectApi()?.destroy()
      } catch {
        /* a widget that failed to mount has nothing to take down */
      }
    }
  }, [])

  // Every company, year or branch change, and once more when the widget finishes mounting in
  // case the header changed while it loaded.
  useEffect(() => {
    if (!ready) return
    connectApi()?.update({ companyId: scope?.cmp_id ?? null, fyId: scope?.fy_id ?? null, boId: scope?.bo_id ?? 0 })
  }, [ready, scope?.cmp_id, scope?.fy_id, scope?.bo_id])

  return null
}

/** Whether Connect mounted in this session. */
export function useConnectReady(): boolean {
  return useSyncExternalStore(subscribeConnectReady, isConnectReady, () => false)
}

/**
 * "Discuss in Connect" on a Purchases document. Connect asks this product whether the person may
 * share it and who else may open it before anything is attached. Hidden when Connect is not here.
 */
export function DiscussInConnect({ entityType, entityId }: { entityType: ConnectEntityType; entityId: number }) {
  const ready = useConnectReady()
  if (!ready) return null
  return (
    <Button onClick={() => discussInConnect(entityType, entityId)} title="Discuss this document with colleagues in Aicountly Connect">
      <MessagesSquare size={15} aria-hidden style={{ marginRight: '0.35rem', verticalAlign: '-2px' }} />
      Discuss in Connect
    </Button>
  )
}
