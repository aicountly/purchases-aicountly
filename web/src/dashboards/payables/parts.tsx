/**
 * The small pieces this screen repeats.
 *
 * They are here rather than inline so that the table, the KPI strip and the
 * supplier ranking cannot drift apart on the two things that matter most on a
 * financial screen: how a status is worded, and how an unavailable figure is
 * distinguished from a zero.
 */

import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { AlertTriangle, ChevronDown, Info } from 'lucide-react'
import type { DashboardMetric, Drilldown } from '../types'
import { initials } from './types'

// ---------------------------------------------------------------------------

export function Avatar({ name, id }: { name: string | null; id?: number }) {
  return (
    <span className="aic-avatar" aria-hidden>
      {initials(name, id === undefined ? '#' : String(id).slice(0, 2))}
    </span>
  )
}

export function Badge({
  tone,
  children,
}: {
  tone: 'warning' | 'danger' | 'success' | 'primary' | 'neutral' | 'purple'
  children: ReactNode
}) {
  return <span className={`aic-badge aic-badge--${tone}`}>{children}</span>
}

// ---------------------------------------------------------------------------
// Dropdown menu
// ---------------------------------------------------------------------------

/**
 * A menu that closes the two ways a user expects it to.
 *
 * Escape and a click outside both close it, and Escape returns focus to the
 * trigger — without that, dismissing a row menu from the keyboard drops the
 * caret at the top of the document and the reader loses their place in a
 * table of twenty-five rows.
 */
export function Menu({
  label,
  trigger,
  align = 'right',
  children,
}: {
  label: string
  trigger: (props: { onClick: () => void; 'aria-expanded': boolean; 'aria-haspopup': 'menu' }) => ReactNode
  align?: 'left' | 'right'
  children: (close: () => void) => ReactNode
}) {
  const [open, setOpen] = useState(false)
  const wrap = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return

    const onPointer = (event: MouseEvent) => {
      if (wrap.current && !wrap.current.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpen(false)
      const button = wrap.current?.querySelector<HTMLElement>('[aria-haspopup="menu"]')
      button?.focus()
    }

    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <div className="aic-menu-wrap" ref={wrap}>
      {trigger({ onClick: () => setOpen((o) => !o), 'aria-expanded': open, 'aria-haspopup': 'menu' })}
      {open && (
        <div className={align === 'left' ? 'aic-menu aic-menu--left' : 'aic-menu'} role="menu" aria-label={label}>
          {children(() => setOpen(false))}
        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// KPI card
// ---------------------------------------------------------------------------

/**
 * One figure, with its basis and the reason it might be missing.
 *
 * `unavailable` is rendered as the word, never as a zero or a dash that could
 * be read as one. A payables card showing ₹0 because Books timed out is a lie
 * that looks like good news — the server is careful about this and the card
 * has to be too.
 */
export function KpiCard({
  metric,
  tone,
  icon,
  onOpen,
}: {
  metric: DashboardMetric
  tone: 'primary' | 'warning' | 'danger' | 'success'
  icon: ReactNode
  onOpen?: (target: Drilldown) => void
}) {
  const ready = metric.status === 'ready'
  const clickable = ready && metric.drilldown !== undefined && onOpen !== undefined

  const body = (
    <>
      <span className="aic-kpi__top">
        <span className="aic-kpi__icon" aria-hidden>{icon}</span>
        {metric.label}
        <Info size={12} aria-hidden style={{ opacity: 0.45, flexShrink: 0 }} />
      </span>

      <strong className={ready ? 'aic-kpi__value' : 'aic-kpi__value is-unavailable'}>
        {ready ? metric.formatted_value : 'Unavailable'}
      </strong>

      {/* The comparison text arrives with its own ▲ / ▼ already in it, so the
          direction is in the words as well as the colour and a second icon
          beside it would only say the same thing twice. */}
      <span className={`aic-kpi__delta ${ready ? metric.change_tone : ''}`}>
        {ready ? metric.comparison_text : (metric.unavailable_reason ?? 'Comparison unavailable')}
      </span>

      {metric.footnote && <span className="aic-kpi__foot">{metric.footnote}</span>}
    </>
  )

  return (
    <article
      className={`aic-kpi aic-kpi--${tone}${clickable ? ' is-clickable' : ''}`}
      title={metric.explanation}
    >
      {clickable ? (
        <button
          type="button"
          className="aic-kpi__inner"
          onClick={() => onOpen(metric.drilldown as Drilldown)}
          aria-label={`View the records behind ${metric.label}: ${metric.formatted_value}`}
        >
          {body}
        </button>
      ) : (
        <div className="aic-kpi__inner">{body}</div>
      )}
    </article>
  )
}

export function KpiSkeleton() {
  return (
    <div className="aic-kpis" aria-busy="true" aria-live="polite">
      <span className="aic-sr-only">Loading the payables figures</span>
      {Array.from({ length: 5 }, (_, index) => (
        <div key={index} className="aic-kpi aic-skeleton aic-skeleton--kpi" />
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Cards and states
// ---------------------------------------------------------------------------

export function Card({
  title,
  action,
  note,
  children,
}: {
  title: string
  action?: ReactNode
  note?: string
  children: ReactNode
}) {
  const titleId = useId()

  return (
    <section className="aic-card" aria-labelledby={titleId}>
      <header className="aic-card__header">
        <h2 id={titleId}>{title}</h2>
        {action}
      </header>
      {children}
      {note && <p className="aic-card__note">{note}</p>}
    </section>
  )
}

/**
 * A panel the server declined to fill in.
 *
 * The two reasons are told apart because they need different things from the
 * reader: a permission to ask for, or a product that is down.
 */
export function Unavailable({ reason, kind }: { reason: string; kind?: 'permission' | 'source' }) {
  return (
    <div className={kind === 'permission' ? 'aic-notice aic-notice--info' : 'aic-notice aic-notice--warning'}>
      <AlertTriangle size={17} aria-hidden />
      <div>
        <strong>{kind === 'permission' ? 'Not available to you' : 'Not available right now'}</strong>
        <p>{reason}</p>
      </div>
    </div>
  )
}

export function WidgetError({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="aic-notice aic-notice--danger" role="alert">
      <AlertTriangle size={17} aria-hidden />
      <div>
        <strong>We couldn’t load this.</strong>
        <p>{message}</p>
        <button type="button" className="aic-btn aic-btn--secondary" style={{ marginTop: 10 }} onClick={onRetry}>
          Try again
        </button>
      </div>
    </div>
  )
}

export function EmptyState({
  icon,
  title,
  children,
  actions,
}: {
  icon: ReactNode
  title: string
  children?: ReactNode
  actions?: ReactNode
}) {
  return (
    <div className="aic-state">
      {icon}
      <strong>{title}</strong>
      {children && <p>{children}</p>}
      {actions && <div className="aic-state__actions">{actions}</div>}
    </div>
  )
}

export function CardSkeleton() {
  return <div className="aic-card aic-skeleton aic-skeleton--card" aria-hidden />
}

// ---------------------------------------------------------------------------

export function Chevron() {
  return <ChevronDown size={14} aria-hidden />
}
