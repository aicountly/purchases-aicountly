/**
 * Small pieces this screen uses more than once.
 *
 * Local on purpose. The shared kit in `src/ui` is the neutral one the whole
 * fleet renders with, and widening it to carry this page's indigo surfaces
 * would change every other product's tables the next time it is imported.
 * Anything here that earns its place elsewhere can be promoted later.
 */

import { useCallback, useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import {
  AlertTriangle,
  CheckCircle2,
  Info,
  MoreHorizontal,
  RefreshCw,
  ShieldAlert,
  X,
} from 'lucide-react'

// ---------------------------------------------------------------------------
// Identity presentation
// ---------------------------------------------------------------------------

/**
 * Initials for the avatar.
 *
 * From the administrator's own label when there is one. A uuid has no initials
 * worth showing, so it gets a neutral glyph rather than two characters of hex
 * dressed up as a person's name.
 */
export function initials(label: string | null): string {
  if (!label) return '••'
  const words = label.trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '••'
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase()

  return (words[0][0] + words[words.length - 1][0]).toUpperCase()
}

/** A uuid is 36 characters of no interest until you need all of them. */
export function shortUuid(uuid: string, keep = 8): string {
  return uuid.length > keep + 4 ? `${uuid.slice(0, keep)}…${uuid.slice(-4)}` : uuid
}

/** "3 hours ago", falling back to the date once that stops being useful. */
export function relativeTime(value: string | null | undefined): string {
  if (!value) return '—'
  const then = new Date(value)
  if (Number.isNaN(then.getTime())) return value

  const seconds = Math.round((Date.now() - then.getTime()) / 1000)
  if (seconds < 60) return 'just now'

  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['minute', 60],
    ['hour', 3600],
    ['day', 86400],
  ]
  const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })

  for (const [unit, size] of units) {
    const next = size * (unit === 'minute' ? 60 : unit === 'hour' ? 24 : 7)
    if (seconds < next) return formatter.format(-Math.round(seconds / size), unit)
  }

  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }).format(then)
}

/** The full timestamp, for the title attribute behind a relative one. */
export function fullTimestamp(value: string | null | undefined): string {
  if (!value) return ''
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed)
}

// ---------------------------------------------------------------------------
// Panel states
// ---------------------------------------------------------------------------

/**
 * A skeleton shaped like the rows it stands in for.
 *
 * Not a spinner: a spinner says "wait", a skeleton says "a table of about this
 * size is coming", and the second one stops the page jumping when it lands.
 */
export function LoadingRows({ rows = 4, columns = 4 }: { rows?: number; columns?: number }) {
  return (
    <div className="access-skeleton-rows" aria-hidden="true">
      {Array.from({ length: rows }, (_, row) => (
        <div className="access-skeleton-row" key={row}>
          <div className="access-skeleton" style={{ width: 34, height: 34, borderRadius: '50%', flexShrink: 0 }} />
          {Array.from({ length: columns }, (_, column) => (
            <div
              className="access-skeleton"
              key={column}
              style={{ height: 11, flex: column === 0 ? '2 1 0' : '1 1 0' }}
            />
          ))}
        </div>
      ))}
    </div>
  )
}

export function EmptyState({
  icon,
  title,
  children,
  actions,
  note,
  tone = 'default',
}: {
  icon: ReactNode
  title: string
  children: ReactNode
  actions?: ReactNode
  note?: ReactNode
  tone?: 'default' | 'warn' | 'danger'
}) {
  const visual =
    tone === 'warn'
      ? 'access-state__visual access-state__visual--warn'
      : tone === 'danger'
        ? 'access-state__visual access-state__visual--danger'
        : 'access-state__visual'

  return (
    <div className="access-state">
      <div className={visual} aria-hidden="true">
        {icon}
      </div>
      <h3>{title}</h3>
      <p>{children}</p>
      {actions && <div className="access-state__actions">{actions}</div>}
      {note && (
        <div className="access-state__note">
          <Info size={14} aria-hidden />
          <span>{note}</span>
        </div>
      )}
    </div>
  )
}

/**
 * What a failed load says.
 *
 * The server's own message, which is written for a person — never a status
 * code, never a stack. Retry is the only thing there is to offer, so it is the
 * only thing offered.
 */
export function ErrorState({ what, message, onRetry }: { what: string; message: string; onRetry: () => void }) {
  return (
    <div className="access-state" role="alert">
      <div className="access-state__visual access-state__visual--danger" aria-hidden="true">
        <AlertTriangle size={26} />
      </div>
      <h3>Unable to load {what}.</h3>
      <p>{message}</p>
      <div className="access-state__actions">
        <button type="button" className="access-btn access-btn--secondary" onClick={onRetry}>
          <RefreshCw size={14} aria-hidden /> Retry
        </button>
      </div>
    </div>
  )
}

export function InlineNotice({
  tone = 'info',
  title,
  children,
  onDismiss,
}: {
  tone?: 'info' | 'warning' | 'danger' | 'success'
  title?: string
  children: ReactNode
  onDismiss?: () => void
}) {
  const Icon = tone === 'danger' || tone === 'warning' ? ShieldAlert : tone === 'success' ? CheckCircle2 : Info

  return (
    <div className={`access-notice access-notice--${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon size={15} aria-hidden />
      <div style={{ minWidth: 0 }}>
        {title && <strong>{title}</strong>}
        {children}
      </div>
      {onDismiss && (
        <button type="button" className="access-notice__dismiss" onClick={onDismiss} aria-label="Dismiss this message">
          <X size={14} aria-hidden />
        </button>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Row actions
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
 * keys move through it, and a click anywhere else dismisses it. Six buttons in
 * a table cell is a table nobody can read, which is the only reason this is a
 * menu at all.
 */
export function RowMenu({ label, actions }: { label: string; actions: MenuAction[] }) {
  const [open, setOpen] = useState(false)
  const [anchor, setAnchor] = useState<CSSProperties>({})
  const trigger = useRef<HTMLButtonElement>(null)
  const menu = useRef<HTMLDivElement>(null)

  const close = useCallback(
    (returnFocus: boolean) => {
      setOpen(false)
      if (returnFocus) trigger.current?.focus()
    },
    [],
  )

  /**
   * Anchor the menu to the trigger in viewport coordinates.
   *
   * The panel clips its own overflow for the rounded corners and the table
   * scrolls sideways, so a menu positioned inside the row is a menu cut in
   * half on the last row. Fixed positioning escapes both; the trade is that it
   * has to close when anything moves underneath it, which it does below.
   */
  const place = useCallback(() => {
    const rect = trigger.current?.getBoundingClientRect()
    if (!rect) return false

    // The row has scrolled out of sight. A menu still hanging where the row
    // used to be belongs to nothing, so the caller closes it instead.
    if (rect.bottom < 0 || rect.top > window.innerHeight) return false

    // Roughly the tallest this menu gets. Below the trigger when there is room,
    // above it when there is not.
    const estimatedHeight = 48 + actions.length * 38
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
      // The menu is portaled out of the row, so "outside" has to be tested
      // against both the trigger and the menu itself.
      if (trigger.current?.contains(target) || menu.current?.contains(target)) return
      close(false)
    }
    // A fixed popover does not travel with the row it belongs to, so it is
    // re-anchored whenever anything underneath it moves — the table scrolling
    // sideways, the page scrolling down, the window resizing. Closing on every
    // scroll event instead would mean a one-pixel nudge dismisses the menu.
    const onMove = () => {
      if (!place()) close(false)
    }
    window.addEventListener('scroll', onMove, true)
    window.addEventListener('resize', onMove)
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        close(true)
        return
      }
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return

      const items = Array.from(menu.current?.querySelectorAll<HTMLButtonElement>('.access-menu__item:not(:disabled)') ?? [])
      if (items.length === 0) return
      event.preventDefault()

      const index = items.indexOf(document.activeElement as HTMLButtonElement)
      const next = event.key === 'ArrowDown' ? index + 1 : index - 1
      items[(next + items.length) % items.length].focus()
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown, true)

    return () => {
      window.removeEventListener('scroll', onMove, true)
      window.removeEventListener('resize', onMove)
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown, true)
    }
  }, [open, close, place])

  return (
    <div className="access-menu">
      <button
        ref={trigger}
        type="button"
        className="access-menu__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={label}
        onClick={() => {
          if (!open) place()
          setOpen((was) => !was)
        }}
      >
        <MoreHorizontal size={16} aria-hidden />
      </button>

      {open &&
        createPortal(
          <div className="access-portal access-menu__list" role="menu" aria-label={label} style={anchor} ref={menu}>
            {actions.map((action) => (
              <div key={action.label}>
                {action.separatorBefore && <div className="access-menu__sep" role="separator" />}
                <button
                  type="button"
                  role="menuitem"
                  className={action.danger ? 'access-menu__item access-menu__item--danger' : 'access-menu__item'}
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
          </div>,
          document.body,
        )}
    </div>
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
 * Granting access changes a table halfway up the page; without this, a
 * successful grant looks exactly like a button that did nothing.
 */
export function useToasts() {
  const [toasts, setToasts] = useState<Toast[]>([])
  const timers = useRef<number[]>([])

  const dismiss = useCallback((id: number) => setToasts((all) => all.filter((toast) => toast.id !== id)), [])

  const push = useCallback(
    (tone: Toast['tone'], title: string, body?: string) => {
      const id = ++toastId
      setToasts((all) => [...all, { id, tone, title, body }])
      // Failures stay until dismissed; a message explaining why a save was
      // refused is the one message that must not vanish while it is being read.
      if (tone === 'success') {
        timers.current.push(window.setTimeout(() => dismiss(id), 5000))
      }
      return id
    },
    [dismiss],
  )

  useEffect(() => () => timers.current.forEach(window.clearTimeout), [])

  return { toasts, push, dismiss }
}

export function Toasts({ toasts, onDismiss }: { toasts: Toast[]; onDismiss: (id: number) => void }) {
  if (toasts.length === 0) return null

  return (
    <div className="access-toasts" role="status" aria-live="polite">
      {toasts.map((toast) => (
        <div className={`access-toast access-toast--${toast.tone}`} key={toast.id}>
          {toast.tone === 'success' ? <CheckCircle2 size={16} aria-hidden /> : <AlertTriangle size={16} aria-hidden />}
          <div style={{ minWidth: 0 }}>
            <strong>{toast.title}</strong>
            {toast.body && <p>{toast.body}</p>}
          </div>
          <button
            type="button"
            className="access-notice__dismiss"
            onClick={() => onDismiss(toast.id)}
            aria-label={`Dismiss: ${toast.title}`}
          >
            <X size={14} aria-hidden />
          </button>
        </div>
      ))}
    </div>
  )
}
