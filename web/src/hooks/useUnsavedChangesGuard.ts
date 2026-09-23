/**
 * Stop a half-finished form being walked away from by accident.
 *
 * TWO WAYS OUT, TWO GUARDS:
 *
 *  - Reloading, closing the tab or typing a different address is the browser's
 *    to ask about, and `beforeunload` is how you ask. The browser writes the
 *    wording; a custom message has been ignored by every browser for a decade.
 *
 *  - Following a link inside the app never touches the browser, so it is caught
 *    here: one capture-phase listener, active only while there is something to
 *    lose, which holds the destination and lets the screen ask properly.
 *
 * WHAT IT DOES NOT CATCH, and deliberately rather than badly: the Back button.
 * Cancelling a history move needs a data router (`useBlocker`), and this app is
 * built on `<BrowserRouter>`. The alternative — pushing a decoy entry and
 * undoing it on popstate — breaks Back for everybody in exchange for catching
 * one case, so Back leaves, and the form is small enough to redo.
 */

import { useCallback, useEffect, useState } from 'react'

function isPlainLeftClick(event: MouseEvent): boolean {
  return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey
}

export function useUnsavedChangesGuard(dirty: boolean) {
  /** Where the person was trying to go, while they are being asked about it. */
  const [pendingHref, setPendingHref] = useState<string | null>(null)

  const cancelLeave = useCallback(() => setPendingHref(null), [])

  useEffect(() => {
    if (!dirty) return

    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      // Still set for the handful of engines that check it. The string itself
      // has not been shown to a user in years.
      event.returnValue = ''
    }

    const onClick = (event: MouseEvent) => {
      if (event.defaultPrevented || !isPlainLeftClick(event)) return

      const target = event.target as Element | null
      const anchor = target?.closest?.('a[href]') as HTMLAnchorElement | null
      if (!anchor) return

      // Opening elsewhere, or downloading, leaves this page where it is.
      if (anchor.hasAttribute('download')) return
      if (anchor.target !== '' && anchor.target !== '_self') return

      let url: URL
      try {
        url = new URL(anchor.href, window.location.href)
      } catch {
        return
      }

      if (url.origin !== window.location.origin) return
      // Same page — an in-page jump to a section, not a way out of the form.
      if (url.pathname === window.location.pathname) return

      event.preventDefault()
      event.stopPropagation()
      setPendingHref(`${url.pathname}${url.search}${url.hash}`)
    }

    window.addEventListener('beforeunload', onBeforeUnload)
    document.addEventListener('click', onClick, true)

    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload)
      document.removeEventListener('click', onClick, true)
    }
  }, [dirty])

  // Nothing to warn about any more — and a dialog still asking about a form
  // that has just been saved is a dialog nobody can answer correctly.
  useEffect(() => {
    if (!dirty) setPendingHref(null)
  }, [dirty])

  return { pendingHref, cancelLeave }
}
