/**
 * Keep the keyboard inside an open dialog, and give it back on close.
 *
 * None of this is optional. A dialog that traps nothing is a dialog a keyboard
 * user falls out of the back of, into a page they cannot see and cannot tell
 * they are on. Escape closes, Tab wraps at both ends, focus moves in when it
 * opens and returns to whatever opened it when it closes, and the page behind
 * stops scrolling while it is up.
 *
 * Focus lands on the PANEL rather than the first control, so a screen reader
 * announces the dialog's title before it announces "Close".
 */

import { useCallback, useEffect, useRef } from 'react'

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  'details',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

export function useFocusTrap<T extends HTMLElement>(open: boolean, onClose: () => void) {
  const panel = useRef<T>(null)
  const returnFocusTo = useRef<HTMLElement | null>(null)

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
    panel.current?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        // Stopped here so one Escape closes one dialog, rather than every
        // dismissible thing between this and the document.
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
      returnFocusTo.current?.focus?.()
    }
  }, [open, onClose, focusables])

  return panel
}
