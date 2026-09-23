/**
 * The pieces this workspace uses more than once.
 *
 * Local, the same way Access keeps its own: the shared kit in `src/ui` is the
 * neutral one the whole fleet renders with, and widening it to carry this
 * screen's blue surfaces and its status vocabulary would change every other
 * product's tables the next time it is imported. Anything here that earns its
 * place elsewhere can be promoted later.
 */

import {
  useCallback,
  useEffect,
  useId,
  useRef,
  useState,
  type CSSProperties,
  type ReactNode,
} from 'react'
import { createPortal } from 'react-dom'
import {
  AlertTriangle,
  CheckCircle2,
  Info,
  MoreHorizontal,
  RefreshCw,
  X,
} from 'lucide-react'
import { useFocusTrap } from '../../hooks/useFocusTrap'
import type { CreditStatus, LinkStatus, ReturnStatus } from './types'

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/**
 * ₹2,48,320 — Indian grouping, no paise.
 *
 * For the CARDS and the CHARTS only, where the exact figure is stated in full
 * beside it or in the table behind it. Anything somebody would reconcile
 * against uses the server's formatted string, which carries the paise.
 */
export function inrShort(value: string | number | null | undefined): string {
  const amount = typeof value === 'string' ? Number.parseFloat(value) : (value ?? 0)
  if (!Number.isFinite(amount)) return '—'

  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(amount)
}

export function shortDate(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }).format(parsed)
}

export function dayMonth(value: string): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short' }).format(parsed)
}

/** "18 days ago", for an age that is the point rather than the date. */
export function ageInDays(value: string | null | undefined): number | null {
  if (!value) return null
  const then = new Date(value)
  if (Number.isNaN(then.getTime())) return null

  return Math.max(0, Math.round((Date.now() - then.getTime()) / 86_400_000))
}

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'purple'

/**
 * Colour is assigned by what a status MEANS, and never carries it alone.
 *
 * Every pill states its label in words and carries a small square in the same
 * colour, so the difference between "approved" and "cancelled" survives a
 * greyscale print and a reader who cannot separate blue from grey.
 */
const STATUS_TONES: Record<ReturnStatus, Tone> = {
  DRAFT: 'neutral',
  APPROVED: 'info',
  DISPATCHED: 'purple',
  DEBITED: 'info',
  CLOSED: 'success',
  CANCELLED: 'neutral',
}

const CREDIT_TONES: Record<CreditStatus, Tone> = {
  PENDING: 'warning',
  RECEIVED: 'success',
  NOT_REQUIRED: 'neutral',
}

export function StatusPill({ status, label }: { status: ReturnStatus; label: string }) {
  return (
    <span className={`pr-pill is-${STATUS_TONES[status] ?? 'neutral'}`}>
      <span className="pr-pill__mark" aria-hidden />
      {label}
    </span>
  )
}

export function CreditPill({ status, label }: { status: CreditStatus; label: string }) {
  return (
    <span className={`pr-pill is-${CREDIT_TONES[status] ?? 'neutral'}`}>
      <span className="pr-pill__mark" aria-hidden />
      {label}
    </span>
  )
}

/**
 * Whether Inventory and Books hold a document for this return.
 *
 * Deliberately small and grey until there is something to say. It reports what
 * THIS product knows — that it holds a reference — and says so in the title;
 * what those documents actually say is read live on the return itself.
 */
export function LinkDot({
  label,
  status,
  reference,
}: {
  label: string
  status: LinkStatus
  reference: string | null
}) {
  const state = status === 'POSTED' ? 'is-posted' : ''
  const title =
    status === 'POSTED'
      ? `${label}: ${reference ?? 'document held'}`
      : status === 'NOT_REQUIRED'
        ? `${label}: not required`
        : `${label}: nothing yet`

  return (
    <span className={`pr-link-dot ${state}`} title={title}>
      <i aria-hidden />
      {label}
      <span className="pr-sr-only">
        {status === 'POSTED' ? ' posted' : status === 'NOT_REQUIRED' ? ' not required' : ' pending'}
      </span>
    </span>
  )
}

// ---------------------------------------------------------------------------
// States
// ---------------------------------------------------------------------------

export function EmptyState({
  icon,
  title,
  children,
  actions,
}: {
  icon: ReactNode
  title: string
  children: ReactNode
  actions?: ReactNode
}) {
  return (
    <div className="pr-state">
      <div className="pr-state__visual" aria-hidden="true">
        {icon}
      </div>
      <h3>{title}</h3>
      <p>{children}</p>
      {actions && <div className="pr-state__actions">{actions}</div>}
    </div>
  )
}

/**
 * What a failed load says.
 *
 * The server's own message, which is written for a person, and a Retry — which
 * is the only thing there is to offer. A failure in one widget must not take
 * the page with it, so this renders INSIDE the card that failed.
 */
export function ErrorState({
  what,
  message,
  onRetry,
  compact = false,
}: {
  what: string
  message: string
  onRetry: () => void
  compact?: boolean
}) {
  if (compact) {
    return (
      <div className="pr-inline-error" role="alert">
        <AlertTriangle size={15} aria-hidden style={{ flexShrink: 0, marginTop: 1 }} />
        <div style={{ minWidth: 0 }}>
          <strong>{what} could not be loaded.</strong>
          {message}
          <div style={{ marginTop: 8 }}>
            <button type="button" className="pr-btn pr-btn--small" onClick={onRetry}>
              <RefreshCw size={13} aria-hidden /> Retry
            </button>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="pr-state" role="alert">
      <div className="pr-state__visual is-danger" aria-hidden="true">
        <AlertTriangle size={26} />
      </div>
      <h3>{what} could not be loaded.</h3>
      <p>{message}</p>
      <div className="pr-state__actions">
        <button type="button" className="pr-btn" onClick={onRetry}>
          <RefreshCw size={14} aria-hidden /> Retry
        </button>
      </div>
    </div>
  )
}

export function Notice({
  tone = 'info',
  children,
}: {
  tone?: 'info' | 'warning' | 'danger' | 'success' | 'plain'
  children: ReactNode
}) {
  const Icon = tone === 'danger' || tone === 'warning' ? AlertTriangle : tone === 'success' ? CheckCircle2 : Info

  return (
    <div className={tone === 'plain' ? 'pr-notice' : `pr-notice pr-notice--${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon size={15} aria-hidden style={{ flexShrink: 0, marginTop: 1 }} />
      <div style={{ minWidth: 0 }}>{children}</div>
    </div>
  )
}

export function Skeleton({ width, height = 12, style }: { width?: number | string; height?: number; style?: CSSProperties }) {
  return <div className="pr-skeleton" style={{ width, height, ...style }} aria-hidden="true" />
}

export function SkeletonRows({ rows = 6, columns = 6 }: { rows?: number; columns?: number }) {
  return (
    <div className="pr-skeleton-rows" aria-hidden="true">
      {Array.from({ length: rows }, (_, row) => (
        <div className="pr-skeleton-row" key={row}>
          {Array.from({ length: columns }, (_, column) => (
            <Skeleton key={column} height={11} style={{ flex: column === 0 ? '1.4 1 0' : '1 1 0' }} />
          ))}
        </div>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Popover — the anchor for the date, status and filter menus
// ---------------------------------------------------------------------------

/**
 * A panel anchored to its trigger, in viewport coordinates.
 *
 * Fixed rather than absolute because the register scrolls sideways and the card
 * clips its own overflow for the rounded corners; a popover positioned inside
 * the toolbar is a popover cut in half. The trade is that it has to follow the
 * trigger when anything moves underneath it, which it does below.
 */
export function Popover({
  label,
  trigger,
  children,
  align = 'start',
  onClose,
}: {
  label: string
  trigger: (props: { open: boolean; toggle: () => void; ref: React.Ref<HTMLButtonElement> }) => ReactNode
  children: (close: () => void) => ReactNode
  align?: 'start' | 'end'
  onClose?: () => void
}) {
  const [open, setOpen] = useState(false)
  const [anchor, setAnchor] = useState<CSSProperties>({})
  const triggerRef = useRef<HTMLButtonElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)

  const close = useCallback(
    (returnFocus = true) => {
      setOpen(false)
      if (returnFocus) triggerRef.current?.focus()
      onClose?.()
    },
    [onClose],
  )

  const place = useCallback(() => {
    const rect = triggerRef.current?.getBoundingClientRect()
    if (!rect) return false
    if (rect.bottom < 0 || rect.top > window.innerHeight) return false

    setAnchor(
      align === 'end'
        ? { right: Math.max(8, window.innerWidth - rect.right), top: rect.bottom + 6 }
        : { left: Math.max(8, rect.left), top: rect.bottom + 6 },
    )

    return true
  }, [align])

  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node
      if (triggerRef.current?.contains(target) || panelRef.current?.contains(target)) return
      close(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        close(true)
      }
    }
    const onMove = () => {
      if (!place()) close(false)
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown, true)
    window.addEventListener('scroll', onMove, true)
    window.addEventListener('resize', onMove)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown, true)
      window.removeEventListener('scroll', onMove, true)
      window.removeEventListener('resize', onMove)
    }
  }, [open, close, place])

  return (
    <>
      {trigger({
        open,
        ref: triggerRef,
        toggle: () => {
          if (!open) place()
          setOpen((was) => !was)
        },
      })}
      {open &&
        createPortal(
          <div className="pr-portal" style={anchor} ref={panelRef}>
            <div className="pr-pop" role="dialog" aria-label={label}>
              {children(() => close(true))}
            </div>
          </div>,
          document.body,
        )}
    </>
  )
}

// ---------------------------------------------------------------------------
// Row menu
// ---------------------------------------------------------------------------

export interface MenuAction {
  label: string
  icon: ReactNode
  onSelect: () => void
  disabled?: boolean
  /** Shown on hover and read out when the item is disabled, so "why not" has an answer. */
  title?: string
  danger?: boolean
  separatorBefore?: boolean
}

/**
 * The per-row menu.
 *
 * A real menu: Escape closes it and gives focus back to its trigger, the arrow
 * keys move through it, and a click anywhere else dismisses it. Eleven buttons
 * in a table cell is a table nobody can read, which is the only reason this is
 * a menu at all.
 */
export function RowMenu({ label, actions }: { label: string; actions: MenuAction[] }) {
  const [open, setOpen] = useState(false)
  const [anchor, setAnchor] = useState<CSSProperties>({})
  const trigger = useRef<HTMLButtonElement>(null)
  const menu = useRef<HTMLDivElement>(null)

  const close = useCallback((returnFocus: boolean) => {
    setOpen(false)
    if (returnFocus) trigger.current?.focus()
  }, [])

  const place = useCallback(() => {
    const rect = trigger.current?.getBoundingClientRect()
    if (!rect) return false
    if (rect.bottom < 0 || rect.top > window.innerHeight) return false

    const estimatedHeight = 24 + actions.length * 36
    const openUp = rect.bottom + estimatedHeight > window.innerHeight && rect.top > estimatedHeight

    setAnchor({
      right: Math.max(8, window.innerWidth - rect.right),
      ...(openUp ? { bottom: window.innerHeight - rect.top + 6 } : { top: rect.bottom + 6 }),
    })

    return true
  }, [actions.length])

  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node
      if (trigger.current?.contains(target) || menu.current?.contains(target)) return
      close(false)
    }
    const onMove = () => {
      if (!place()) close(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        close(true)
        return
      }
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return

      const items = Array.from(menu.current?.querySelectorAll<HTMLButtonElement>('.pr-menu__item:not(:disabled)') ?? [])
      if (items.length === 0) return
      event.preventDefault()

      const index = items.indexOf(document.activeElement as HTMLButtonElement)
      const next = event.key === 'ArrowDown' ? index + 1 : index - 1
      items[(next + items.length) % items.length].focus()
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown, true)
    window.addEventListener('scroll', onMove, true)
    window.addEventListener('resize', onMove)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown, true)
      window.removeEventListener('scroll', onMove, true)
      window.removeEventListener('resize', onMove)
    }
  }, [open, close, place])

  return (
    <div className="pr-menu">
      <button
        ref={trigger}
        type="button"
        className="pr-menu__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={label}
        onClick={(event) => {
          event.stopPropagation()
          if (!open) place()
          setOpen((was) => !was)
        }}
      >
        <MoreHorizontal size={16} aria-hidden />
      </button>

      {open &&
        createPortal(
          <div className="pr-portal" style={anchor}>
            <div className="pr-menu__list" role="menu" aria-label={label} ref={menu}>
              {actions.map((action) => (
                <div key={action.label}>
                  {action.separatorBefore && <div className="pr-menu__sep" role="separator" />}
                  <button
                    type="button"
                    role="menuitem"
                    className={action.danger ? 'pr-menu__item pr-menu__item--danger' : 'pr-menu__item'}
                    disabled={action.disabled}
                    title={action.title}
                    onClick={() => {
                      close(false)
                      action.onSelect()
                    }}
                  >
                    {action.icon}
                    {action.label}
                  </button>
                </div>
              ))}
            </div>
          </div>,
          document.body,
        )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Drawer
// ---------------------------------------------------------------------------

/**
 * A side panel that behaves like a dialog, because it is one.
 *
 * Escape closes it, Tab is trapped inside it, focus moves in when it opens and
 * returns to whatever opened it when it closes. The trap itself is the shared
 * hook every other dialog in this product uses; what is local is the skin.
 */
export function Drawer({
  open,
  title,
  badge,
  subtitle,
  wide = false,
  footer,
  onClose,
  children,
}: {
  open: boolean
  title: string
  badge?: ReactNode
  subtitle?: ReactNode
  wide?: boolean
  footer?: ReactNode
  onClose: () => void
  children: ReactNode
}) {
  const panel = useFocusTrap<HTMLDivElement>(open, onClose)
  const titleId = useId()

  if (!open) return null

  return createPortal(
    <div
      className="pr-drawer-backdrop purchase-returns-workspace"
      style={{ padding: 0, minHeight: 0, background: 'rgba(15, 23, 42, 0.38)' }}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={panel}
        className={wide ? 'pr-drawer is-wide' : 'pr-drawer'}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
      >
        <header className="pr-drawer__head">
          <div style={{ minWidth: 0 }}>
            <h2 id={titleId}>
              {title}
              {badge}
            </h2>
            {subtitle && <p className="pr-drawer__sub">{subtitle}</p>}
          </div>
          <button type="button" className="pr-drawer__close" onClick={onClose} aria-label={`Close ${title}`}>
            <X size={17} aria-hidden />
          </button>
        </header>

        <div className="pr-drawer__body">{children}</div>

        {footer && <footer className="pr-drawer__foot">{footer}</footer>}
      </div>
    </div>,
    document.body,
  )
}

// ---------------------------------------------------------------------------
// Toasts
// ---------------------------------------------------------------------------

export interface Toast {
  id: number
  tone: 'success' | 'danger'
  title: string
  body?: string
}

let toastId = 0

/**
 * Confirmation that something happened, where the eye already is.
 *
 * Approving a return changes a pill halfway up a table; without this, a
 * successful approval looks exactly like a button that did nothing.
 */
export function useToasts() {
  const [toasts, setToasts] = useState<Toast[]>([])

  const dismiss = useCallback((id: number) => setToasts((all) => all.filter((toast) => toast.id !== id)), [])

  const push = useCallback(
    (tone: Toast['tone'], title: string, body?: string) => {
      const id = ++toastId
      setToasts((all) => [...all, { id, tone, title, body }])
      // Errors stay until they are read; a confirmation has done its job in a
      // few seconds and should not sit on top of the work.
      if (tone === 'success') window.setTimeout(() => dismiss(id), 4500)
    },
    [dismiss],
  )

  return { toasts, push, dismiss }
}

export function Toasts({ toasts, onDismiss }: { toasts: Toast[]; onDismiss: (id: number) => void }) {
  if (toasts.length === 0) return null

  return createPortal(
    <div className="pr-toasts purchase-returns-workspace" style={{ padding: 0, minHeight: 0, background: 'none' }} role="status" aria-live="polite">
      {toasts.map((toast) => (
        <div key={toast.id} className={`pr-toast pr-toast--${toast.tone}`}>
          <span className="pr-toast__icon" aria-hidden>
            {toast.tone === 'success' ? <CheckCircle2 size={16} /> : <AlertTriangle size={16} />}
          </span>
          <div className="pr-toast__body">
            <strong>{toast.title}</strong>
            {toast.body}
          </div>
          <button type="button" className="pr-toast__close" onClick={() => onDismiss(toast.id)} aria-label="Dismiss">
            <X size={14} aria-hidden />
          </button>
        </div>
      ))}
    </div>,
    document.body,
  )
}
