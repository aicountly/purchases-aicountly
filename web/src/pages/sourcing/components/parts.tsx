/**
 * The small pieces this screen repeats.
 *
 * They live here rather than in `ui/index.tsx` because they are styled from
 * this workspace's own tokens. The shared kit is deliberately plain and is read
 * by a dozen other screens; adding a violet pill to it to suit one page is how
 * a design system stops being one.
 */

import { useCallback, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { useFocusTrap } from '../../../hooks/useFocusTrap'
import type { RfqTone } from '../model'

export function StatusPill({ tone, children, title }: { tone: RfqTone; children: ReactNode; title?: string }) {
  return (
    <span className={`sq-pill sq-pill--${tone}`} title={title}>
      {/* The dot carries the same meaning as the colour, for anyone who cannot
          tell two tints apart. Colour is never the only signal here. */}
      <span className="sq-pill__dot" aria-hidden />
      {children}
    </span>
  )
}

export function Skeleton({ width, height = 12 }: { width: string; height?: number }) {
  return <span className="sq-skeleton" style={{ width, height }} aria-hidden />
}

/**
 * A side panel that behaves like a dialog, because it is one.
 *
 * Escape closes, Tab is trapped, focus moves in and comes back out to whatever
 * opened it. `useFocusTrap` is the product's shared implementation of all of
 * that — the dashboards' own Drawer is styled from a stylesheet this screen
 * does not load, so the behaviour is shared and the skin is not.
 */
export function SourcingDrawer({
  open,
  title,
  subtitle,
  onClose,
  children,
  footer,
}: {
  open: boolean
  title: string
  subtitle?: ReactNode
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}) {
  const close = useCallback(() => onClose(), [onClose])
  const panel = useFocusTrap<HTMLDivElement>(open, close)

  if (!open) return null

  return (
    <div
      className="sq-drawer-backdrop sourcing-workspace"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) close()
      }}
    >
      <div className="sq-drawer" role="dialog" aria-modal="true" aria-label={title} tabIndex={-1} ref={panel}>
        <header className="sq-drawer__head">
          <div>
            <h2>{title}</h2>
            {subtitle && <p>{subtitle}</p>}
          </div>
          <button type="button" className="sq-icon-button" onClick={close} aria-label={`Close ${title}`}>
            <X size={17} aria-hidden />
          </button>
        </header>
        <div className="sq-drawer__body">{children}</div>
        {footer && <footer className="sq-drawer__foot">{footer}</footer>}
      </div>
    </div>
  )
}
