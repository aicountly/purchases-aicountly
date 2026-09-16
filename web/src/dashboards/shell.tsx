/**
 * The furniture every purchase dashboard shares.
 *
 * Five screens that each invented their own idea of a metric card would drift
 * apart within a month, and the first thing to go would be the honesty: one
 * screen would start showing a dash where another showed "Unavailable", and a
 * reader would learn to treat both as zero.
 */

import { useId, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, ArrowRight, CheckCircle2, ChevronRight, Info, RefreshCw } from 'lucide-react'
import { PURCHASE_VIEWS, type PurchaseViewId } from './filters'
import type { DashboardMetric, Drilldown, SourceStatus } from './types'

export { PURCHASE_VIEWS }

// ---------------------------------------------------------------------------
// Switcher
// ---------------------------------------------------------------------------

export function DashboardSwitcher({
  activeView,
  onChange,
}: {
  activeView: string
  onChange: (view: PurchaseViewId) => void
}) {
  return (
    <nav className="purchase-switcher" aria-label="Purchase dashboards">
      {PURCHASE_VIEWS.map((view) => (
        <button
          key={view.id}
          type="button"
          className={activeView === view.id ? 'purchase-switcher__button is-active' : 'purchase-switcher__button'}
          aria-current={activeView === view.id ? 'page' : undefined}
          onClick={() => onChange(view.id)}
        >
          {view.label}
        </button>
      ))}
    </nav>
  )
}

// ---------------------------------------------------------------------------
// Metric card
// ---------------------------------------------------------------------------

export function MetricCard({ metric, onOpen }: { metric: DashboardMetric; onOpen: (target: Drilldown) => void }) {
  const available = metric.status === 'ready'
  const clickable = available && metric.drilldown !== undefined

  const body = (
    <>
      <span className="purchase-metric__label">
        {metric.label}
        {/* The basis is on the card and in the tooltip: a reader should not have
            to hover to find out what a number counts. */}
        <Info size={13} aria-hidden style={{ opacity: 0.5, flexShrink: 0 }} />
      </span>

      <strong className={available ? 'purchase-metric__value' : 'purchase-metric__value is-unavailable'}>
        {available ? metric.formatted_value : 'Unavailable'}
      </strong>

      <span className={`purchase-metric__change ${available ? metric.change_tone : 'is-neutral'}`}>
        {available ? metric.comparison_text : (metric.unavailable_reason ?? 'Comparison unavailable')}
      </span>

      <span className="purchase-metric__basis">{metric.basis}</span>

      {metric.footnote && <span className="purchase-metric__footnote">{metric.footnote}</span>}
    </>
  )

  return (
    <article className="purchase-metric" title={metric.explanation}>
      {clickable ? (
        <button
          type="button"
          className="purchase-metric__link"
          onClick={() => onOpen(metric.drilldown as Drilldown)}
          aria-label={`View the records behind ${metric.label}: ${metric.formatted_value}`}
        >
          {body}
        </button>
      ) : (
        <div className="purchase-metric__content">{body}</div>
      )}
    </article>
  )
}

// ---------------------------------------------------------------------------
// Panels
// ---------------------------------------------------------------------------

export function DashboardPanel({
  title,
  description,
  action,
  className = '',
  flush = false,
  children,
}: {
  title: string
  description?: ReactNode
  action?: ReactNode
  className?: string
  flush?: boolean
  children: ReactNode
}) {
  const titleId = useId()

  return (
    <section className={`purchase-panel ${className}`} aria-labelledby={titleId}>
      <header className="purchase-panel__header">
        <div style={{ minWidth: 0 }}>
          <h2 id={titleId}>{title}</h2>
          {description && <p>{description}</p>}
        </div>
        {action}
      </header>
      <div className={flush ? 'purchase-panel__body purchase-panel__body--flush' : 'purchase-panel__body'}>
        {children}
      </div>
    </section>
  )
}

/**
 * A panel the server declined to fill in.
 *
 * The two reasons are told apart, because they need different actions from the
 * reader: a permission they must ask for, or a product that is down.
 */
export function PanelUnavailable({ reason, kind }: { reason: string; kind?: 'permission' | 'source' }) {
  return (
    <div className={kind === 'permission' ? 'purchase-notice purchase-notice--info' : 'purchase-notice purchase-notice--warning'}>
      <AlertTriangle size={18} aria-hidden style={{ flexShrink: 0, marginTop: 2 }} />
      <div>
        <strong>{kind === 'permission' ? 'Not available to you' : 'Not available right now'}</strong>
        <p>{reason}</p>
      </div>
    </div>
  )
}

export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="purchase-empty">
      <CheckCircle2 size={22} aria-hidden style={{ color: 'var(--purchase-action)' }} />
      <strong>{title}</strong>
      {children && <span>{children}</span>}
    </div>
  )
}

export function Badge({
  tone,
  children,
}: {
  tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral'
  children: ReactNode
}) {
  return <span className={`purchase-badge purchase-badge--${tone}`}>{children}</span>
}

// ---------------------------------------------------------------------------
// Priority card
// ---------------------------------------------------------------------------

export function PriorityCard({
  item,
  onReview,
}: {
  item: {
    severity: string
    severity_label: string
    source_label?: string
    source?: string
    title: string
    explanation?: string
    detail?: string
    impact_label?: string
    action_label: string
  }
  onReview: () => void
}) {
  const tone = (['success', 'warning', 'danger', 'info'].includes(item.severity) ? item.severity : 'neutral') as
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'

  return (
    <article className="purchase-priority">
      <div className="purchase-priority__top">
        <Badge tone={tone}>{item.severity_label}</Badge>
        <span className="purchase-muted" style={{ fontSize: 11 }}>
          {item.source_label ?? item.source}
        </span>
      </div>
      <h3>{item.title}</h3>
      <p>{item.explanation ?? item.detail}</p>
      <div className="purchase-priority__footer">
        <strong style={{ fontVariantNumeric: 'tabular-nums' }}>{item.impact_label}</strong>
        <button type="button" className="purchase-button purchase-button--secondary" onClick={onReview}>
          {item.action_label} <ArrowRight size={14} aria-hidden />
        </button>
      </div>
    </article>
  )
}

// ---------------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------------

export interface Column<T> {
  key: string
  header: string
  numeric?: boolean
  width?: string
  render: (row: T) => ReactNode
}

/**
 * A semantic table with a caption.
 *
 * The caption is what a screen-reader user hears before the first cell, and it
 * is where the basis of the figures goes — the same sentence sighted users read
 * in the panel description.
 */
export function DataTable<T>({
  caption,
  columns,
  rows,
  rowKey,
  empty,
  onRowOpen,
}: {
  caption: string
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  empty: ReactNode
  onRowOpen?: (row: T) => void
}) {
  if (rows.length === 0) return <>{empty}</>

  return (
    <div className="purchase-table-scroll">
      <table className="purchase-table">
        <caption className="purchase-sr-only">{caption}</caption>
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key} scope="col" className={column.numeric ? 'is-numeric' : undefined} style={{ width: column.width }}>
                {column.header}
              </th>
            ))}
            {onRowOpen && (
              <th scope="col">
                <span className="purchase-sr-only">Open</span>
              </th>
            )}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={rowKey(row)}>
              {columns.map((column) => (
                <td key={column.key} className={column.numeric ? 'is-numeric' : undefined}>
                  {column.render(row)}
                </td>
              ))}
              {onRowOpen && (
                <td>
                  {/* An explicit row action rather than a click handler on the
                      row: a whole row that is secretly a link cannot be reached
                      from a keyboard and cannot be opened in a new tab. */}
                  <button type="button" className="purchase-button purchase-button--quiet" onClick={() => onRowOpen(row)}>
                    Open <ChevronRight size={13} aria-hidden />
                  </button>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ---------------------------------------------------------------------------
// The shell
// ---------------------------------------------------------------------------

export function SourceList({ sources, fetchedAt }: { sources: SourceStatus[]; fetchedAt: Date | null }) {
  return (
    <ul className="purchase-source-list" aria-label="Data source status">
      {sources.map((source) => (
        <li key={source.id} title={source.message ?? undefined}>
          <span className={`purchase-status-dot purchase-status-dot--${source.status}`} aria-hidden />
          <span>
            {source.label}: {source.status_label}
          </span>
          {source.message && <span className="purchase-muted">— {source.message}</span>}
        </li>
      ))}
      {fetchedAt && (
        <li>
          <span className="purchase-status-dot purchase-status-dot--ready" aria-hidden />
          <span>
            Loaded{' '}
            <time dateTime={fetchedAt.toISOString()}>
              {fetchedAt.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}
            </time>
          </span>
        </li>
      )}
    </ul>
  )
}

export function MetricsRow({
  metrics,
  loading,
  onOpen,
}: {
  metrics: DashboardMetric[]
  loading: boolean
  onOpen: (target: Drilldown) => void
}) {
  if (loading) {
    return (
      <div className="purchase-metrics" aria-busy="true">
        {Array.from({ length: 6 }, (_, index) => (
          <div key={index} className="purchase-metric purchase-skeleton purchase-skeleton--metric" />
        ))}
      </div>
    )
  }

  return (
    <div className="purchase-metrics">
      {metrics.map((metric) => (
        <MetricCard key={metric.id} metric={metric} onOpen={onOpen} />
      ))}
    </div>
  )
}

export function PurchaseDashboardShell({
  activeView,
  title,
  subtitle,
  onViewChange,
  contextControls,
  filterControls,
  sources,
  metrics,
  loading,
  refreshing,
  fetchedAt,
  onRefresh,
  primaryAction,
  children,
}: {
  activeView: string
  title: string
  subtitle: string
  onViewChange: (view: PurchaseViewId) => void
  contextControls: ReactNode
  filterControls: ReactNode
  sources: SourceStatus[]
  metrics: DashboardMetric[]
  loading: boolean
  refreshing: boolean
  fetchedAt: Date | null
  onRefresh: () => void
  primaryAction?: ReactNode
  children: ReactNode
}) {
  const navigate = useNavigate()

  const openDrilldown = (target: Drilldown) => {
    const query = new URLSearchParams(target.filters).toString()
    navigate(query === '' ? target.route : `${target.route}?${query}`)
  }

  return (
    // A div, not a <main>: the application frame already provides the main
    // landmark, and two of them is one too many for a screen reader.
    <div className="purchase-workspace">
      <header className="purchase-page-header">
        <div style={{ minWidth: 0 }}>
          <p className="purchase-eyebrow">Aicountly Purchases</p>
          <h1>{title}</h1>
          <p className="purchase-page-subtitle">{subtitle}</p>
        </div>
        <div className="purchase-header-actions">
          <button
            type="button"
            className="purchase-button purchase-button--secondary"
            onClick={onRefresh}
            disabled={refreshing}
          >
            <RefreshCw size={15} aria-hidden /> {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>
          {primaryAction}
        </div>
      </header>

      <div className="purchase-context">{contextControls}</div>

      <DashboardSwitcher activeView={activeView} onChange={onViewChange} />

      <div className="purchase-filterbar">{filterControls}</div>

      <SourceList sources={sources} fetchedAt={fetchedAt} />

      <MetricsRow metrics={metrics} loading={loading} onOpen={openDrilldown} />

      <div className="purchase-dashboard-content" aria-busy={refreshing}>
        {children}
      </div>
    </div>
  )
}
