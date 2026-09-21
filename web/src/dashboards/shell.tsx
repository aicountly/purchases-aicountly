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
import {
  AlertTriangle,
  ArrowRight,
  BarChart3,
  CalendarDays,
  CheckCircle2,
  ChevronRight,
  Clock,
  Coins,
  FileText,
  Info,
  RefreshCw,
  ShoppingCart,
  UserCheck,
} from 'lucide-react'
import { Sparkline } from './charts'
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
  const basisId = useId()

  const body = (
    <>
      <span className="purchase-metric__label">
        <span className="purchase-metric__icon" aria-hidden>
          <MetricIcon id={metric.id} />
        </span>
        <span className="purchase-metric__name">{metric.label}</span>
        {/* The basis is on the card AND in the tooltip AND announced with the
            card: a reader should not have to hover to find out what a number
            counts, and a reader who cannot hover should not lose it. */}
        <Info size={13} aria-hidden className="purchase-metric__info" />
      </span>

      <strong className={available ? 'purchase-metric__value' : 'purchase-metric__value is-unavailable'}>
        {available ? metric.formatted_value : 'Unavailable'}
      </strong>

      <span className={`purchase-metric__change ${available ? metric.change_tone : 'is-neutral'}`}>
        {available ? metric.comparison_text : (metric.unavailable_reason ?? 'Comparison unavailable')}
      </span>

      {/* The slot is always here, filled or not, so six cards in a row keep one
          baseline. An absent history is absent — never a flat line at zero,
          which reads as "nothing happened" rather than "nothing to draw". */}
      {metric.series && metric.series.length > 1 ? (
        <Sparkline
          points={metric.series}
          label={`${metric.label}, ${metric.series[0].label} to ${metric.series[metric.series.length - 1].label}`}
        />
      ) : (
        <div className="purchase-spark" aria-hidden="true" />
      )}

      <span className="purchase-metric__basis" id={basisId}>
        {metric.basis}
      </span>

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
          aria-describedby={basisId}
        >
          {body}
        </button>
      ) : (
        <div className="purchase-metric__content">{body}</div>
      )}
    </article>
  )
}

/**
 * The icon for a KPI, chosen by what the figure is about.
 *
 * Keyed on the metric id the server sends rather than on its position in the
 * row, so re-ordering the cards — or a dashboard that sends four of them —
 * cannot leave a payables card wearing a delivery van.
 */
function MetricIcon({ id }: { id: string }) {
  const size = 15
  switch (id) {
    case 'net_purchases':
      return <ShoppingCart size={size} />
    case 'open_commitment':
      return <FileText size={size} />
    case 'supplier_dues':
      return <Coins size={size} />
    case 'overdue_dues':
      return <AlertTriangle size={size} />
    case 'delayed_orders':
      return <Clock size={size} />
    case 'my_approvals':
      return <UserCheck size={size} />
    default:
      return <BarChart3 size={size} />
  }
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

/**
 * Which upstream products answered, and when.
 *
 * Never a decorative "Live" badge: each dot is the status the server reported
 * for that source on THIS request, and a source that did not answer says so in
 * words beside the dot rather than only in a colour.
 */
export function SourceList({ sources, fetchedAt }: { sources: SourceStatus[]; fetchedAt: Date | null }) {
  return (
    <ul className="purchase-source-list" aria-label="Data source status">
      {sources.map((source) => (
        <li key={source.id} className={`purchase-source purchase-source--${source.status}`} title={source.message ?? undefined}>
          <span className={`purchase-status-dot purchase-status-dot--${source.status}`} aria-hidden />
          <span>
            {source.label}: {source.status_label}
          </span>
          {source.message && <span className="purchase-muted purchase-source__message">— {source.message}</span>}
        </li>
      ))}
      {fetchedAt && (
        <li className="purchase-source purchase-source--clock">
          <Clock size={12} aria-hidden />
          <span>
            Last sync{' '}
            <time dateTime={fetchedAt.toISOString()}>
              {fetchedAt.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' })}
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
  executive,
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
  /** The Overview's four summary cards. Absent on the other four dashboards. */
  executive?: ReactNode
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
        {/* Decoration, and nothing else: it carries no information, so it is
            hidden from assistive technology and disappears below 1100px where
            the header stacks. */}
        <span className="purchase-header-wave" aria-hidden="true" />

        <div className="purchase-page-header__titles">
          <p className="purchase-eyebrow">Aicountly Purchases</p>
          <h1>{title}</h1>
          <p className="purchase-page-subtitle">{subtitle}</p>
        </div>

        <div className="purchase-header-side">
          <p className="purchase-header-motto" aria-hidden="true">
            Smarter purchases.
            <br />
            Stronger tomorrow.
          </p>
          <div className="purchase-header-actions">
            {/* The period control is a real pair of selects inside the card
                rather than a button that opens a menu: a select is reachable,
                announced and operable by keyboard on every platform we ship
                to, and the resolved range sits under it so the figures can
                never disagree with the dates they were computed for. */}
            <div className="purchase-context">
              <span className="purchase-context__icon" aria-hidden>
                <CalendarDays size={17} />
              </span>
              {contextControls}
            </div>

            <button
              type="button"
              className="purchase-button purchase-button--secondary purchase-refresh"
              onClick={onRefresh}
              disabled={refreshing}
            >
              <RefreshCw size={15} aria-hidden className={refreshing ? 'purchase-refresh__icon is-spinning' : 'purchase-refresh__icon'} />
              {refreshing ? 'Refreshing…' : 'Refresh'}
            </button>
            {primaryAction}
          </div>
        </div>
      </header>

      {executive}

      <DashboardSwitcher activeView={activeView} onChange={onViewChange} />

      <div className="purchase-commandbar">
        <div className="purchase-filterbar">{filterControls}</div>
        <SourceList sources={sources} fetchedAt={fetchedAt} />
      </div>

      <MetricsRow metrics={metrics} loading={loading} onOpen={openDrilldown} />

      <div className="purchase-dashboard-content" aria-busy={refreshing}>
        {children}
      </div>
    </div>
  )
}
