/**
 * A side drawer that behaves like a dialog, because it is one.
 *
 * Escape closes it, Tab is trapped inside it, focus moves in when it opens and
 * returns to whatever opened it when it closes, and the close button has a
 * name rather than being a bare glyph. None of that is optional: a drawer that
 * traps nothing is a drawer a keyboard user falls out of the back of, into a
 * page they cannot see.
 */

import { useCallback, useEffect, useId, useRef, type ReactNode } from 'react'
import { X } from 'lucide-react'

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  'details',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

export function Drawer({
  open,
  title,
  subtitle,
  onClose,
  children,
}: {
  open: boolean
  title: string
  subtitle?: ReactNode
  onClose: () => void
  children: ReactNode
}) {
  const panel = useRef<HTMLDivElement>(null)
  const returnFocusTo = useRef<HTMLElement | null>(null)
  const titleId = useId()

  const focusables = useCallback(
    () => Array.from(panel.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []).filter((el) => el.offsetParent !== null),
    [],
  )

  useEffect(() => {
    if (!open) return

    returnFocusTo.current = document.activeElement as HTMLElement | null

    // Focus the panel itself rather than the first control: a drawer that opens
    // with the close button focused reads as "Close" before it reads its title.
    panel.current?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
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
      // Returning focus is what stops a keyboard user landing back at the top
      // of the document with no idea where they were.
      returnFocusTo.current?.focus?.()
    }
  }, [open, onClose, focusables])

  if (!open) return null

  return (
    <div
      className="purchase-drawer-backdrop purchase-workspace"
      style={{ padding: 0 }}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={panel}
        className="purchase-drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
      >
        <header className="purchase-drawer__header">
          <div style={{ minWidth: 0 }}>
            <h2 id={titleId}>{title}</h2>
            {subtitle && <p className="purchase-muted" style={{ margin: '4px 0 0', fontSize: 13 }}>{subtitle}</p>}
          </div>
          <button type="button" className="purchase-drawer__close" onClick={onClose} aria-label={`Close ${title}`}>
            <X size={17} aria-hidden />
          </button>
        </header>
        <div className="purchase-drawer__body">{children}</div>
      </div>
    </div>
  )
}
