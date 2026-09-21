/**
 * The furniture every purchase dashboard shares.
 *
 * Five screens that each invented their own idea of a metric card would drift
 * apart within a month, and the first thing to go would be the honesty: one
 * screen would start showing a dash where another showed "Unavailable", and a
 * reader would learn to treat both as zero.
 */

import { useId, type ComponentType, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowRight,
  BarChart3,
  CalendarClock,
  CheckCircle2,
  ChevronRight,
  ClipboardList,
  Clock,
  Copy,
  FileWarning,
  Gauge,
  Hourglass,
  IndianRupee,
  Info,
  Mail,
  PackageCheck,
  PiggyBank,
  Receipt,
  RefreshCw,
  ShieldAlert,
  ShoppingCart,
  Sparkles,
  Timer,
  TrendingUp,
  Truck,
  Users,
} from 'lucide-react'
import { SparkBars } from './charts'
import { PURCHASE_VIEWS, type PurchaseViewId } from './filters'
import type { DashboardMetric, Drilldown, SourceStatus } from './types'

/**
 * One icon and one tint per metric, chosen by the metric's own id.
 *
 * Keyed by id rather than guessed from the label, so a reworded card keeps its
 * icon and a new card nobody has mapped gets an honest generic one rather than
 * something that looks deliberate and means nothing.
 *
 * THE TINT IS AN IDENTITY, NEVER A JUDGEMENT. "GRN pending" is rose whether the
 * queue is empty or on fire — it is there so a reader finds the same card in
 * the same place at a glance. What is good and bad news is said by the delta's
 * colour, the status pill and the words, all three of which change with the
 * figure. A tint that also changed would be a fourth signal saying the same
 * thing, and the first one a reader would learn to misread.
 */
type MetricTint = 'green' | 'blue' | 'violet' | 'amber' | 'rose' | 'mint'
type MetricIcon = ComponentType<{ size?: number; 'aria-hidden'?: boolean }>

const METRIC_ICONS: Record<string, { icon: MetricIcon; tint: MetricTint }> = {
  open_requisitions: { icon: ClipboardList, tint: 'green' },
  rfqs_awaiting_quotes: { icon: Mail, tint: 'blue' },
  purchase_orders_released: { icon: ShoppingCart, tint: 'violet' },
  in_transit_deliveries: { icon: Truck, tint: 'amber' },
  grn_pending: { icon: PackageCheck, tint: 'rose' },
  spend_under_approval: { icon: IndianRupee, tint: 'mint' },

  open_commitment: { icon: IndianRupee, tint: 'mint' },
  delayed_orders: { icon: Timer, tint: 'rose' },
  my_approvals: { icon: Hourglass, tint: 'amber' },
  net_purchases: { icon: TrendingUp, tint: 'green' },
  active_suppliers: { icon: Users, tint: 'blue' },
  on_time_delivery: { icon: Gauge, tint: 'green' },
  acceptance_rate: { icon: CheckCircle2, tint: 'mint' },
  concentration: { icon: BarChart3, tint: 'violet' },
  payment_terms: { icon: CalendarClock, tint: 'blue' },
  supplier_issues: { icon: ShieldAlert, tint: 'rose' },
  supplier_dues: { icon: IndianRupee, tint: 'amber' },
  payables_total: { icon: Receipt, tint: 'green' },
  payables_overdue: { icon: Clock, tint: 'rose' },
  overdue_dues: { icon: Clock, tint: 'rose' },
  due_windows: { icon: CalendarClock, tint: 'amber' },
  bills_awaiting_review: { icon: Receipt, tint: 'blue' },
  bills_with_exceptions: { icon: FileWarning, tint: 'rose' },
  duplicate_candidates: { icon: Copy, tint: 'violet' },
  opportunity_value: { icon: PiggyBank, tint: 'mint' },
  anomalies_open: { icon: ShieldAlert, tint: 'amber' },
  stock_out_risk: { icon: PackageCheck, tint: 'rose' },
  forecast_spend: { icon: Sparkles, tint: 'violet' },
}

function metricIcon(metric: DashboardMetric): { icon: MetricIcon; tint: MetricTint } {
  const mapped = METRIC_ICONS[metric.id]
  if (mapped) return mapped

  return {
    icon:
      metric.format === 'currency'
        ? IndianRupee
        : metric.format === 'percent'
          ? Gauge
          : metric.format === 'days'
            ? Clock
            : BarChart3,
    tint: 'green',
  }
}

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
  const { icon: Icon, tint } = metricIcon(metric)

  // The two bars are the comparison the card already states in words. Where the
  // server sent no previous figure there is nothing to draw, and nothing is
  // drawn — the shape would otherwise be decoration standing where data goes.
  const spark =
    available && metric.comparison.available && metric.comparison.previous_raw !== undefined && metric.raw_value !== null
      ? [
          { label: 'previous', value: metric.comparison.previous_raw },
          { label: 'current', value: metric.raw_value },
        ]
      : []

  /*
   * One secondary line, not three.
   *
   * Where there is a comparison it is the comparison, because that is the
   * question a reader asks first. Where there is not — most of these are a
   * queue as at now, and how long that queue was a month ago was never
   * recorded — the line says "as at now" and hands the space to the ageing
   * footnote, which is the thing that can actually be acted on. It never shows
   * a delta that does not exist, and the reason it does not exist is in the
   * tooltip and in the basis printed underneath.
   */
  const secondary = available
    ? metric.comparison.available
      ? metric.comparison_text
      : (metric.footnote ?? 'As at now')
    : (metric.unavailable_reason ?? 'Comparison unavailable')

  const tone = available && metric.comparison.available ? metric.change_tone : 'is-quiet'

  const body = (
    <>
      <span className={`purchase-metric__icon purchase-metric__icon--${tint}`} aria-hidden>
        <Icon size={17} aria-hidden />
      </span>

      <span className="purchase-metric__body">
        <span className="purchase-metric__label">
          {metric.label}
          {/* The basis is on the card and in the tooltip: a reader should not
              have to hover to find out what a number counts. */}
          <Info size={12} aria-hidden style={{ opacity: 0.45, flexShrink: 0 }} />
        </span>

        <strong className={available ? 'purchase-metric__value' : 'purchase-metric__value is-unavailable'}>
          {available ? (metric.compact_value ?? metric.formatted_value) : 'Unavailable'}
        </strong>

        <span className={`purchase-metric__change ${tone}`}>{secondary}</span>

        <span className="purchase-metric__basis">{metric.basis}</span>
      </span>

      <SparkBars points={spark} tone={metric.change_tone} />
    </>
  )

  // The tooltip carries what the card had to shorten: the exact figure, why
  // there is no comparison, and the footnote the secondary line gave way to.
  const tooltip = [
    metric.explanation,
    available && metric.compact_value !== metric.formatted_value ? `Exactly: ${metric.formatted_value}` : null,
    metric.comparison.available ? null : (metric.comparison.reason ?? null),
    metric.comparison.available ? metric.footnote : null,
  ]
    .filter((line): line is string => typeof line === 'string' && line !== '')
    .join('\n\n')

  return (
    <article className="purchase-metric" title={tooltip}>
      {clickable ? (
        <button
          type="button"
          className="purchase-metric__link"
          onClick={() => onOpen(metric.drilldown as Drilldown)}
          aria-label={`View the records behind ${metric.label}: ${metric.formatted_value}`}
          title={tooltip}
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
        {/* Shaped like the card it stands in for, rather than a grey block:
            the layout does not jump when the figures land, and the reader can
            already see what is about to be there. */}
        {Array.from({ length: 6 }, (_, index) => (
          <div key={index} className="purchase-metric purchase-metric--skeleton" aria-hidden>
            <span className="purchase-skeleton purchase-skeleton--icon" />
            <span className="purchase-metric__body">
              <span className="purchase-skeleton purchase-skeleton--line" style={{ width: '76%' }} />
              <span className="purchase-skeleton purchase-skeleton--value" />
              <span className="purchase-skeleton purchase-skeleton--line" style={{ width: '52%' }} />
              <span className="purchase-skeleton purchase-skeleton--line" style={{ width: '88%', height: 8 }} />
            </span>
          </div>
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
            className="purchase-button purchase-button--primary"
            onClick={onRefresh}
            disabled={refreshing}
            title={
              fetchedAt
                ? `Last loaded at ${fetchedAt.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}`
                : undefined
            }
          >
            <RefreshCw size={15} aria-hidden className={refreshing ? 'purchase-spin' : undefined} />{' '}
            {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>
          {primaryAction}
        </div>
      </header>

      {/* One control row, not two. The period, the comparison and the three
          narrowing controls are one decision about what is being looked at, and
          splitting them either side of the switcher made the switcher read as a
          divider between two unrelated things. */}
      <div className="purchase-context">
        {contextControls}
        {filterControls}
      </div>

      <DashboardSwitcher activeView={activeView} onChange={onViewChange} />

      <MetricsRow metrics={metrics} loading={loading} onOpen={openDrilldown} />

      <div className="purchase-dashboard-content" aria-busy={refreshing}>
        {children}
      </div>

      {/* Which products answered sits at the foot of the screen rather than
          above the figures. It is the small print of every number above it, and
          a panel that could not be filled in says so where it stands. */}
      <SourceList sources={sources} fetchedAt={fetchedAt} />
    </div>
  )
}
