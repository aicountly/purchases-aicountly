/**
 * A centred modal that behaves like a dialog, because it is one.
 *
 * The same contract as the dashboards' Drawer, which is where this behaviour
 * was worked out: Escape closes, Tab is trapped, focus moves in on open and
 * returns to whatever opened it on close, the close button has a name rather
 * than being a bare glyph, and the backdrop closes on a click that both starts
 * and ends on the backdrop. A dialog that traps nothing is a dialog a keyboard
 * user falls out of the back of, into a page they cannot see.
 *
 * This screen grants and revokes authority, so its confirmations are the last
 * thing standing between a click and somebody losing access. They are dialogs,
 * not window.confirm.
 */

import { useCallback, useEffect, useId, useRef, type ReactNode } from 'react'
import { X } from 'lucide-react'

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

export function AccessDialog({
  open,
  title,
  description,
  onClose,
  children,
  footer,
  wide = false,
  /** Set while a request is in flight: closing mid-write hides the outcome. */
  busy = false,
}: {
  open: boolean
  title: string
  description?: ReactNode
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  wide?: boolean
  busy?: boolean
}) {
  const panel = useRef<HTMLDivElement>(null)
  const returnFocusTo = useRef<HTMLElement | null>(null)
  const titleId = useId()
  const descriptionId = useId()

  const focusables = useCallback(
    () =>
      Array.from(panel.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []).filter(
        (element) => element.offsetParent !== null,
      ),
    [],
  )

  useEffect(() => {
    if (!open) return

    returnFocusTo.current = document.activeElement as HTMLElement | null

    // The panel itself, not the first control: a dialog that opens with Close
    // focused reads as "Close" before it reads its own title.
    panel.current?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        if (!busy) onClose()
        return
      }
      if (event.key !== 'Tab') return

      const items = focusables()
      if (items.length === 0) {
        event.preventDefault()
        panel.current?.focus()
        return
      }

      const first = items[0]
      const last = items[items.length - 1]
      const active = document.activeElement

      if (event.shiftKey && (active === first || active === panel.current)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && active === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      document.body.style.overflow = previousOverflow
      returnFocusTo.current?.focus?.()
    }
  }, [open, onClose, busy, focusables])

  if (!open) return null

  return (
    <div
      className="access-dialog-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !busy) onClose()
      }}
    >
      <div
        ref={panel}
        className={wide ? 'access-dialog access-dialog--wide' : 'access-dialog'}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
      >
        <header className="access-dialog__header">
          <div style={{ minWidth: 0 }}>
            <h2 id={titleId}>{title}</h2>
            {description && <p id={descriptionId}>{description}</p>}
          </div>
          <button
            type="button"
            className="access-dialog__close"
            onClick={onClose}
            disabled={busy}
            aria-label={`Close ${title}`}
          >
            <X size={17} aria-hidden />
          </button>
        </header>

        <div className="access-dialog__body">{children}</div>

        {footer && <footer className="access-dialog__footer">{footer}</footer>}
      </div>
    </div>
  )
}
