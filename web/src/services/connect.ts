/**
 * Aicountly Connect, embedded — the shared widget Connect serves, not a copy of it.
 *
 * Connect ships one script (`/embed/v1/loader.js`) that mounts its widget in a shadow root:
 * conversations, company contacts from Aicountly Contacts, audio/video calling, AI Pulse pinned
 * first, and an Advisor link. This product only loads it, hands it the signed-in person's own
 * session and company scope, and asks it to open with a Purchases document to discuss.
 *
 * Nothing here may break Purchases: a Connect that is down, blocked or not deployed simply means
 * no widget and no "Discuss in Connect" button.
 *
 * Contract: connect-aicountly docs/EMBED_SDK.md (Embed SDK v1).
 */
import { isSandboxHost } from '../auth/hostnames'
import { getAppById, resolveAppOrigin } from './appLauncher'

/** Purchases documents Connect can verify with this product before attaching them. */
export type ConnectEntityType = 'purchase_order' | 'purchase_bill' | 'purchase_return' | 'claim' | 'requisition' | 'rfq'

export interface ConnectShareContext {
  product: 'purchases'
  entityType: ConnectEntityType
  entityId: number
  companyId?: number
  fyId?: number
  boId?: number
}

export interface ConnectOptions {
  getSesKey: (forceRefresh: boolean) => Promise<string | null>
  product?: string
  companyId?: number | null
  fyId?: number | null
  boId?: number | null
  advisorUrl?: string
  labels?: { button?: string; openAria?: string; closeAria?: string; advisor?: string }
  calling?: boolean
  onEvent?: (name: string, detail: Record<string, unknown>) => void
}

interface ConnectApi {
  init(options: ConnectOptions): Promise<boolean>
  update(patch: Partial<ConnectOptions>): void
  open(options: { conversationId?: string; shareContext?: ConnectShareContext }): void
  close(): void
  destroy(): void
  isReady(): boolean
  version: string
}

declare global {
  interface Window {
    AICountlyChat?: ConnectApi
    AICountlyConnect?: ConnectApi
  }
}

/** Where a record shared in Connect opens in this app. */
export const CONNECT_OPEN_PATHS: Record<ConnectEntityType, (id: number) => string> = {
  purchase_order: (id) => `/purchase-orders/${id}`,
  purchase_bill: (id) => `/bills/${id}`,
  purchase_return: (id) => `/returns/${id}`,
  claim: () => '/claims',
  requisition: (id) => `/requisitions/${id}`,
  rfq: (id) => `/rfqs/${id}`,
}

/**
 * Connect's origin for this deployment: connect.gh for a sandbox host, connect for production,
 * `VITE_CONNECT_ORIGIN` when set (`off` disables it). A local dev server has no Connect unless
 * one is configured — a widget talking to production from localhost is never what anyone wants.
 */
export function connectOrigin(hostname: string = window.location.hostname): string | null {
  const configured = (import.meta.env.VITE_CONNECT_ORIGIN as string | undefined)?.trim()
  if (configured) {
    return configured.toLowerCase() === 'off' ? null : configured.replace(/\/+$/, '')
  }
  const host = hostname.toLowerCase()
  if (host === 'localhost' || host.endsWith('.localhost') || host.startsWith('127.') || host === '') {
    return null
  }
  const app = getAppById('chat')
  return app ? resolveAppOrigin(app, isSandboxHost(hostname)) : null
}

/**
 * The widget, only when it is Embed SDK v1. Connect's earlier loader also defines
 * `window.AICountlyChat`, but it mounts Connect's own sign-in and styles straight into the host
 * page and returns nothing from init() — a Connect deployment still serving it is treated as
 * "no Connect here", never initialised.
 */
export function connectApi(): ConnectApi | null {
  const api = window.AICountlyChat ?? window.AICountlyConnect ?? null
  return api && api.version === '1' && typeof api.init === 'function' ? api : null
}

let loading: Promise<ConnectApi | null> | null = null

/** Load Connect's loader once, asynchronously. Resolves null — never throws — when it cannot. */
export function loadConnect(): Promise<ConnectApi | null> {
  const existing = connectApi()
  if (existing) return Promise.resolve(existing)
  const origin = connectOrigin()
  if (!origin) return Promise.resolve(null)
  if (!loading) {
    loading = new Promise((resolve) => {
      const script = document.createElement('script')
      script.src = `${origin}/embed/v1/loader.js`
      script.async = true
      script.dataset.aicountly = 'connect'
      script.onload = () => resolve(connectApi())
      script.onerror = () => {
        script.remove()
        loading = null
        resolve(null)
      }
      document.head.appendChild(script)
    })
  }
  return loading
}

// Whether the widget mounted — what the "Discuss in Connect" buttons wait for.
let ready = false
const listeners = new Set<(value: boolean) => void>()

export function setConnectReady(value: boolean): void {
  if (ready === value) return
  ready = value
  listeners.forEach((listener) => listener(value))
}

export function isConnectReady(): boolean {
  return ready
}

export function subscribeConnectReady(listener: (value: boolean) => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** Open Connect with a Purchases document attached, for the person to pick who to discuss it with. */
export function discussInConnect(entityType: ConnectEntityType, entityId: number): boolean {
  const api = connectApi()
  if (!api || !ready) return false
  api.open({ shareContext: { product: 'purchases', entityType, entityId } })
  return true
}
