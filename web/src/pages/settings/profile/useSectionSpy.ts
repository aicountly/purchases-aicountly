/**
 * Which section the reader is looking at, and how to go to one.
 *
 * The spy is best-effort by design: it moves the highlight, it does not move
 * the page. If the browser has no IntersectionObserver the navigator still
 * works — it just stops following the scroll, which is the part nobody clicked
 * for.
 */

import { useCallback, useEffect, useRef, useState } from 'react'

export function useSectionSpy(ids: string[], enabled: boolean) {
  const [active, setActive] = useState(ids[0] ?? '')
  /** Set while a click-driven scroll is in flight, so the spy does not fight it. */
  const navigating = useRef(false)

  const goTo = useCallback((id: string) => {
    const target = document.getElementById(id)
    if (!target) return

    navigating.current = true
    setActive(id)
    target.scrollIntoView({ behavior: 'smooth', block: 'start' })

    // Focus lands on the card, not inside it: a keyboard user arrives at the
    // section's heading and tabs forward from there, rather than being dropped
    // into the middle of a form.
    target.focus({ preventScroll: true })

    window.setTimeout(() => {
      navigating.current = false
    }, 700)
  }, [])

  useEffect(() => {
    if (!enabled || typeof IntersectionObserver === 'undefined') return

    const elements = ids
      .map((id) => document.getElementById(id))
      .filter((element): element is HTMLElement => element !== null)
    if (elements.length === 0) return

    const visible = new Map<string, number>()

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) visible.set(entry.target.id, entry.boundingClientRect.top)
          else visible.delete(entry.target.id)
        }
        if (navigating.current || visible.size === 0) return

        // The topmost section still on screen is the one being read.
        const topmost = ids.find((id) => visible.has(id))
        if (topmost) setActive(topmost)
      },
      // The band is the upper part of the viewport: a card is "current" once
      // its heading is near the top, not when its last line leaves the bottom.
      { rootMargin: '-8% 0px -70% 0px', threshold: 0 },
    )

    elements.forEach((element) => observer.observe(element))
    return () => observer.disconnect()
  }, [ids, enabled])

  return { active, goTo, setActive }
}
