/**
 * Small pieces the Requisitions screen uses more than once.
 *
 * Local on purpose, exactly as `pages/access/ui.tsx` is local: the shared kit in
 * `src/ui` is the neutral one the whole fleet renders with, and widening it to
 * carry this screen's indigo surfaces would change every other product's tables
 * the next time it is imported. The tokens here are the same family as the
 * Access screen's, so the two upgraded screens read as one product.
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
import { AlertTriangle, CheckCircle2, MoreVertical, RefreshCw, X } from 'lucide-react'

// ---------------------------------------------------------------------------
// Sparkline
// ---------------------------------------------------------------------------

/**
 * Twelve months of a number, in 105 pixels.
 *
 * Hand-drawn SVG rather than a chart library: this product already ships one
 * for the dashboards, and loading it on a list screen to draw a 24px line would
 * cost more than the line is worth. There is no axis, no tooltip and no legend
 * because there is no decision to make from it — it is context for the figure
 * beside it, which is why it is `aria-hidden` and the figure is not.
 *
 * Nothing is drawn when there is not enough history to draw. A single month
 * rendered as a flat line looks exactly like a year of no change.
 */
export function Sparkline({ points, className }: { points: number[]; className?: string }) {
  if (points.length < 2) return null

  const width = 100
  const height = 24
  const max = Math.max(...points)
  const min = Math.min(...points)
  const span = max - min || 1
  const step = width / (points.length - 1)

  const path = points
    .map((value, index) => {
      const x = index * step
      // 2px of padding top and bottom so the stroke is not clipped at the ends.
      const y = height - 2 - ((value - min) / span) * (height - 4)
      return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`
    })
    .join(' ')

  return (
    <svg
      className={className ? `rq-sparkline ${className}` : 'rq-sparkline'}
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      aria-hidden="true"
      focusable="false"
    >
      <path d={path} />
    </svg>
  )
}

// ---------------------------------------------------------------------------
// Portal anchoring
// ---------------------------------------------------------------------------

/**
 * Anchor a portaled panel to its trigger, in viewport coordinates.
 *
 * The table card clips its own overflow for the rounded corners and scrolls
 * sideways, so a panel positioned inside a row is a panel cut in half on the
 * last row. Fixed positioning escapes both; the trade is that it has to be
 * re-anchored when anything underneath it moves, which is what `track` does.
 */
function useAnchor(estimatedHeight: number) {
  const trigger = useRef<HTMLButtonElement>(null)
  const [style, setStyle] = useState<CSSProperties>({})

  const place = useCallback((): boolean => {
    const rect = trigger.current?.getBoundingClientRect()
    if (!rect) return false
    // The row has scrolled out of sight. A panel still hanging where the row
    // used to be belongs to nothing, so the caller closes it instead.
    if (rect.bottom < 0 || rect.top > window.innerHeight) return false

    const openUp = rect.bottom + estimatedHeight > window.innerHeight && rect.top > estimatedHeight
    setStyle({
      right: Math.max(8, window.innerWidth - rect.right),
      ...(openUp ? { bottom: window.innerHeight - rect.top + 6 } : { top: rect.bottom + 6 }),
    })
    return true
  }, [estimatedHeight])

  return { trigger, style, place }
}

/** Close on outside click, on Escape, and when the row underneath moves away. */
function useDismiss(
  open: boolean,
  refs: { trigger: React.RefObject<HTMLElement | null>; panel: React.RefObject<HTMLElement | null> },
  place: () => boolean,
  close: (returnFocus: boolean) => void,
) {
  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node
      if (refs.trigger.current?.contains(target) || refs.panel.current?.contains(target)) return
      close(false)
    }
    const onMove = () => {
      if (!place()) close(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        close(true)
      }
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
  }, [open, refs.trigger, refs.panel, place, close])
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
 * keys move through it, and a click anywhere else dismisses it. Eight buttons
 * in a table cell is a table nobody can read, which is the only reason this is
 * a menu at all.
 */
export function RowMenu({ label, actions }: { label: string; actions: MenuAction[] }) {
  const [open, setOpen] = useState(false)
  const { trigger, style, place } = useAnchor(48 + actions.length * 38)
  const panel = useRef<HTMLDivElement>(null)

  const close = useCallback((returnFocus: boolean) => {
    setOpen(false)
    if (returnFocus) trigger.current?.focus()
  }, [trigger])

  useDismiss(open, { trigger, panel }, place, close)

  useEffect(() => {
    if (!open) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return
      const items = Array.from(panel.current?.querySelectorAll<HTMLButtonElement>('.rq-menu__item:not(:disabled)') ?? [])
      if (items.length === 0) return
      event.preventDefault()
      const index = items.indexOf(document.activeElement as HTMLButtonElement)
      const next = event.key === 'ArrowDown' ? index + 1 : index - 1
      items[(next + items.length) % items.length].focus()
    }
    document.addEventListener('keydown', onKeyDown, true)
    return () => document.removeEventListener('keydown', onKeyDown, true)
  }, [open])

  if (actions.length === 0) return null

  return (
    <div className="rq-menu">
      <button
        ref={trigger}
        type="button"
        className="rq-menu__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={label}
        onClick={() => {
          if (!open) place()
          setOpen((was) => !was)
        }}
      >
        <MoreVertical size={16} aria-hidden />
      </button>

      {open &&
        createPortal(
          <div className="rq-portal rq-menu__list" role="menu" aria-label={label} style={style} ref={panel}>
            {actions.map((action) => (
              <div key={action.label}>
                {action.separatorBefore && <div className="rq-menu__sep" role="separator" />}
                <button
                  type="button"
                  role="menuitem"
                  className={action.danger ? 'rq-menu__item rq-menu__item--danger' : 'rq-menu__item'}
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

/**
 * A popover hung off a button, for detail a table cell has no room for.
 *
 * Opened by click rather than by hover: a hover-only popover is a popover a
 * keyboard cannot reach and a touchscreen cannot open, and the approval chain
 * is the one thing on this row somebody may genuinely need to read.
 */
export function Popover({
  label,
  children,
  triggerClassName = 'rq-popover__trigger',
  trigger: renderTrigger,
}: {
  label: string
  children: ReactNode
  triggerClassName?: string
  trigger: (props: { open: boolean }) => ReactNode
}) {
  const [open, setOpen] = useState(false)
  const { trigger, style, place } = useAnchor(220)
  const panel = useRef<HTMLDivElement>(null)
  const panelId = useId()

  const close = useCallback((returnFocus: boolean) => {
    setOpen(false)
    if (returnFocus) trigger.current?.focus()
  }, [trigger])

  useDismiss(open, { trigger, panel }, place, close)

  return (
    <>
      <button
        ref={trigger}
        type="button"
        className={triggerClassName}
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        aria-label={label}
        onClick={() => {
          if (!open) place()
          setOpen((was) => !was)
        }}
      >
        {renderTrigger({ open })}
      </button>

      {open &&
        createPortal(
          <div className="rq-portal rq-popover" id={panelId} role="dialog" aria-label={label} style={style} ref={panel}>
            {children}
          </div>,
          document.body,
        )}
    </>
  )
}

// ---------------------------------------------------------------------------
// Waiting, empty and broken
// ---------------------------------------------------------------------------

/**
 * A skeleton shaped like the thing it stands in for.
 *
 * Not a spinner: a spinner says "wait", a skeleton says "a table of about this
 * size is coming", and the second one stops the page jumping when it lands.
 */
export function TableSkeleton({ rows = 8 }: { rows?: number }) {
  return (
    <div className="rq-skeleton-rows" aria-hidden="true">
      {Array.from({ length: rows }, (_, row) => (
        <div className="rq-skeleton-row" key={row}>
          <span className="rq-skeleton" style={{ width: 16, height: 16, borderRadius: 4, flex: '0 0 16px' }} />
          <span className="rq-skeleton" style={{ height: 11, flex: '1 1 0' }} />
          <span className="rq-skeleton" style={{ height: 11, flex: '2 1 0' }} />
          <span className="rq-skeleton" style={{ height: 11, flex: '1 1 0' }} />
          <span className="rq-skeleton" style={{ height: 11, flex: '1 1 0' }} />
          <span className="rq-skeleton" style={{ height: 11, flex: '1 1 0' }} />
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
}: {
  icon: ReactNode
  title: string
  children: ReactNode
  actions?: ReactNode
  note?: ReactNode
}) {
  return (
    <div className="rq-state">
      <div className="rq-state__visual" aria-hidden="true">
        {icon}
      </div>
      <h3>{title}</h3>
      <p>{children}</p>
      {actions && <div className="rq-state__actions">{actions}</div>}
      {note && <div className="rq-state__note">{note}</div>}
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
    <div className="rq-state" role="alert">
      <div className="rq-state__visual rq-state__visual--danger" aria-hidden="true">
        <AlertTriangle size={26} />
      </div>
      <h3>We couldn&rsquo;t load {what}.</h3>
      <p>{message}</p>
      <div className="rq-state__actions">
        <button type="button" className="rq-btn rq-btn--secondary" onClick={onRetry}>
          <RefreshCw size={14} aria-hidden /> Retry
        </button>
      </div>
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
 * Approving from a row changes one cell halfway down a table; without this, a
 * successful approval looks exactly like a button that did nothing.
 */
export function useToasts() {
  const [toasts, setToasts] = useState<Toast[]>([])
  const timers = useRef<number[]>([])

  const dismiss = useCallback((id: number) => setToasts((all) => all.filter((toast) => toast.id !== id)), [])

  const push = useCallback(
    (tone: Toast['tone'], title: string, body?: string) => {
      const id = ++toastId
      setToasts((all) => [...all, { id, tone, title, body }])
      // Failures stay until dismissed; a message explaining why something was
      // refused is the one message that must not vanish while it is being read.
      if (tone === 'success') timers.current.push(window.setTimeout(() => dismiss(id), 5000))
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
    <div className="rq-toasts" role="status" aria-live="polite">
      {toasts.map((toast) => (
        <div className={`rq-toast rq-toast--${toast.tone}`} key={toast.id}>
          {toast.tone === 'success' ? <CheckCircle2 size={16} aria-hidden /> : <AlertTriangle size={16} aria-hidden />}
          <div style={{ minWidth: 0 }}>
            <strong>{toast.title}</strong>
            {toast.body && <p>{toast.body}</p>}
          </div>
          <button type="button" className="rq-toast__dismiss" onClick={() => onDismiss(toast.id)} aria-label={`Dismiss: ${toast.title}`}>
            <X size={14} aria-hidden />
          </button>
        </div>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Confirmation
// ---------------------------------------------------------------------------

/**
 * A confirm step for anything that cannot be taken back.
 *
 * Rejecting a requisition sends somebody back to the start of their week, so
 * it asks first and says what will happen — and, where the API demands one, it
 * collects the reason here rather than refusing the click later.
 */
export function ConfirmDialog({
  title,
  children,
  confirmLabel,
  tone = 'primary',
  busy = false,
  disabled = false,
  onConfirm,
  onCancel,
}: {
  title: string
  children: ReactNode
  confirmLabel: string
  tone?: 'primary' | 'danger'
  busy?: boolean
  disabled?: boolean
  onConfirm: () => void
  onCancel: () => void
}) {
  const panel = useRef<HTMLDivElement>(null)
  const headingId = useId()

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    panel.current?.querySelector<HTMLElement>('input, textarea, button')?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onCancel()
        return
      }
      if (event.key !== 'Tab') return
      // A dialog you can Tab out of is a dialog the page behind it can steal
      // focus from, and a screen reader then reads a form nobody can see.
      const focusable = Array.from(
        panel.current?.querySelectorAll<HTMLElement>('button:not(:disabled), input, textarea, select, [href]') ?? [],
      )
      if (focusable.length === 0) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      previous?.focus()
    }
  }, [onCancel])

  return createPortal(
    <div className="rq-portal rq-scrim" onMouseDown={(event) => event.target === event.currentTarget && onCancel()}>
      <div className="rq-dialog" role="dialog" aria-modal="true" aria-labelledby={headingId} ref={panel}>
        <h2 id={headingId}>{title}</h2>
        <div className="rq-dialog__body">{children}</div>
        <div className="rq-dialog__actions">
          <button type="button" className="rq-btn rq-btn--secondary" onClick={onCancel} disabled={busy}>
            Cancel
          </button>
          <button
            type="button"
            className={tone === 'danger' ? 'rq-btn rq-btn--danger' : 'rq-btn rq-btn--primary'}
            onClick={onConfirm}
            disabled={busy || disabled}
          >
            {busy ? 'Working…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
