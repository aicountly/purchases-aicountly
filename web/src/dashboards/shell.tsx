/**
 * The furniture every purchase dashboard shares.
 *
 * Five screens that each invented their own idea of a metric card would drift
 * apart within a month, and the first thing to go would be the honesty: one
 * screen would start showing a dash where another showed "Unavailable", and a
 * reader would learn to treat both as zero.
 */

import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowDownRight,
  ArrowRight,
  ArrowUpRight,
  Banknote,
  BadgeIndianRupee,
  Boxes,
  CalendarClock,
  ChartNoAxesCombined,
  CheckCircle2,
  ChevronRight,
  ClipboardList,
  CreditCard,
  FileText,
  FileWarning,
  Info,
  LayoutGrid,
  Mail,
  PackageCheck,
  PauseCircle,
  PiggyBank,
  RefreshCw,
  ShoppingCart,
  Sparkles,
  Timer,
  TrendingUp,
  Truck,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { MetricSpark } from './charts'
import { PURCHASE_VIEWS, type PurchaseViewId } from './filters'
import type { DashboardMetric, Drilldown, SourceStatus } from './types'

export { PURCHASE_VIEWS }

// ---------------------------------------------------------------------------
// Switcher
// ---------------------------------------------------------------------------

/** The face of each dashboard in the tab strip. */
const VIEW_ICON: Record<PurchaseViewId, LucideIcon> = {
  overview: LayoutGrid,
  procurement: ShoppingCart,
  suppliers: Users,
  'bills-payables': FileText,
  'ai-insights': Sparkles,
}

export function DashboardSwitcher({
  activeView,
  onChange,
}: {
  activeView: string
  onChange: (view: PurchaseViewId) => void
}) {
  return (
    <nav className="purchase-switcher" aria-label="Purchase dashboards">
      {PURCHASE_VIEWS.map((view) => {
        const Icon = VIEW_ICON[view.id]
        return (
          <button
            key={view.id}
            type="button"
            className={activeView === view.id ? 'purchase-switcher__button is-active' : 'purchase-switcher__button'}
            aria-current={activeView === view.id ? 'page' : undefined}
            onClick={() => onChange(view.id)}
          >
            <Icon size={16} aria-hidden />
            {view.label}
          </button>
        )
      })}
    </nav>
  )
}

// ---------------------------------------------------------------------------
// Metric card
// ---------------------------------------------------------------------------

/**
 * The tile beside each figure.
 *
 * Keyed on the metric id rather than chosen from its value, so a card does not
 * change its face when the number moves — the icon says what the figure IS, the
 * colour says what kind of thing it is, and only the delta reacts to the data.
 * Anything unmapped falls back to a neutral tile rather than to no tile, so the
 * row never loses its rhythm.
 */
const METRIC_FACE: Record<string, { icon: LucideIcon; tone: 'good' | 'warn' | 'bad' | 'info' | '' }> = {
  net_purchases: { icon: ShoppingCart, tone: 'good' },
  open_commitment: { icon: ClipboardList, tone: 'info' },
  supplier_dues: { icon: CreditCard, tone: 'info' },
  payables_total: { icon: CreditCard, tone: 'info' },
  overdue_dues: { icon: AlertTriangle, tone: 'bad' },
  payables_overdue: { icon: AlertTriangle, tone: 'bad' },
  due_in_horizon: { icon: CalendarClock, tone: 'warn' },
  overdue_orders: { icon: Timer, tone: 'bad' },
  delayed_orders: { icon: Timer, tone: 'bad' },
  my_approvals: { icon: CheckCircle2, tone: 'warn' },
  orders_pending_approval: { icon: CheckCircle2, tone: 'warn' },
  requisitions_awaiting: { icon: ClipboardList, tone: 'warn' },
  approved_not_ordered: { icon: ClipboardList, tone: 'info' },
  bills_awaiting_review: { icon: FileWarning, tone: 'warn' },
  bills_with_exceptions: { icon: FileWarning, tone: 'bad' },
  payment_terms: { icon: Banknote, tone: 'info' },
  due_windows: { icon: CalendarClock, tone: 'warn' },
  active_suppliers: { icon: Users, tone: 'good' },
  on_time_delivery: { icon: Truck, tone: 'good' },
  acceptance_rate: { icon: CheckCircle2, tone: 'good' },
  concentration: { icon: Boxes, tone: 'info' },
  supplier_issues: { icon: AlertTriangle, tone: 'warn' },
  opportunity_value: { icon: PiggyBank, tone: 'good' },
  forecast_spend: { icon: TrendingUp, tone: 'info' },
  anomalies_open: { icon: AlertTriangle, tone: 'warn' },
  duplicate_candidates: { icon: PauseCircle, tone: 'warn' },
  stock_out_risk: { icon: Boxes, tone: 'bad' },
  purchase_value: { icon: BadgeIndianRupee, tone: 'good' },
  purchase_orders: { icon: ShoppingCart, tone: 'good' },
  avg_po_value: { icon: ChartNoAxesCombined, tone: 'info' },
  price_anomalies: { icon: AlertTriangle, tone: 'bad' },
  purchase_risks_open: { icon: Boxes, tone: 'warn' },

  // The Procurement workspace's own six.
  open_requisitions: { icon: ClipboardList, tone: 'warn' },
  rfqs_awaiting_quotes: { icon: Mail, tone: 'warn' },
  purchase_orders_released: { icon: ShoppingCart, tone: 'good' },
  in_transit_deliveries: { icon: Truck, tone: 'info' },
  grn_pending: { icon: PackageCheck, tone: 'warn' },
  spend_under_approval: { icon: BadgeIndianRupee, tone: 'warn' },
}

export function MetricCard({ metric, onOpen }: { metric: DashboardMetric; onOpen: (target: Drilldown) => void }) {
  const available = metric.status === 'ready'
  const clickable = available && metric.drilldown !== undefined
  const face = METRIC_FACE[metric.id] ?? { icon: Sparkles, tone: '' as const }
  const Face = face.icon
  const trend = metric.trend ?? []

  // The line takes its colour from the comparison, not from the direction of
  // the line: a falling count of anomalies is good news and a green line is
  // the honest way to draw it.
  const sparkTone =
    metric.change_tone === 'is-positive' ? 'positive' : metric.change_tone === 'is-negative' ? 'negative' : 'neutral'

  const body = (
    <>
      <span className="purchase-metric__head">
        <span className={`purchase-metric__icon ${face.tone === '' ? '' : `is-${face.tone}`}`} aria-hidden>
          <Face size={18} />
        </span>
        <span className="purchase-metric__label">{metric.label}</span>
        {/* The basis is in the tooltip on the card and spelled out in the
            figures table behind every panel — never only in a hover. */}
        <Info size={13} aria-hidden className="purchase-metric__info" />
      </span>

      <strong
        className={available ? 'purchase-metric__value' : 'purchase-metric__value is-unavailable'}
        // The short form is what fits; the exact figure is what reconciles.
        title={available ? (metric.exact_value ?? undefined) : undefined}
      >
        {available ? metric.formatted_value : 'Unavailable'}
      </strong>

      <span className={`purchase-metric__change ${available ? metric.change_tone : 'is-neutral'}`}>
        {/* The arrow says which way the figure moved; the colour says whether
            that is good news. They are not the same thing and are drawn from
            different fields — `change_pc` for the direction, `change_tone` for
            the reading. */}
        {available && metric.comparison.available && (
          metric.comparison_text.startsWith('▼')
            ? <ArrowDownRight size={14} aria-hidden />
            : metric.comparison_text.startsWith('▲')
              ? <ArrowUpRight size={14} aria-hidden />
              : null
        )}
        {available ? metric.comparison_text.replace(/^[▲▼]\s*/, '') : (metric.unavailable_reason ?? 'Comparison unavailable')}
      </span>

      {/* The slot keeps its height whether or not there is a series to draw,
          so six cards stay one row of six rather than a ragged edge. */}
      <span className="purchase-metric__sparkslot">
        {trend.length > 0 ? (
          <MetricSpark points={trend} tone={sparkTone} label={metric.label} />
        ) : (
          <span className="purchase-metric__nospark">{available ? 'Position as at now' : ''}</span>
        )}
      </span>

      <span className="purchase-metric__footer">{metric.footer ?? metric.footnote ?? metric.basis}</span>
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

/**
 * Which products answered, and when.
 *
 * Kept as a list rather than a badge, because "Inventory: Unavailable" and the
 * sentence saying why are the whole point — a green dot alone teaches a reader
 * to stop looking.
 */
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

/**
 * The monitoring pill, and the source list behind it.
 *
 * The three states are read from what the products actually answered, never
 * hard-coded: everything live is green, anything degraded is amber and says
 * "Limited", and nothing live at all is red. Clicking opens the same list the
 * screen used to print in full, so nothing is hidden — only folded.
 */
export function MonitorPill({
  noun,
  sources,
  fetchedAt,
}: {
  noun: string
  sources: SourceStatus[]
  fetchedAt: Date | null
}) {
  const [open, setOpen] = useState(false)
  const wrapper = useRef<HTMLDivElement>(null)
  const panelId = useId()

  useEffect(() => {
    if (!open) return
    const onDown = (event: MouseEvent) => {
      if (!wrapper.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const live = sources.filter((source) => source.status === 'ready').length
  const state = live === 0 ? 'unavailable' : live === sources.length ? 'ready' : 'limited'
  const label =
    state === 'ready' ? `${noun} live` : state === 'limited' ? `Limited ${noun.toLowerCase()}` : `${noun} unavailable`

  return (
    <div className="purchase-monitor" ref={wrapper}>
      <button
        type="button"
        className={`purchase-monitor__pill is-${state}`}
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((was) => !was)}
      >
        <span className="purchase-monitor__dot" aria-hidden />
        {label}
      </button>

      <div className="purchase-monitor__popover" id={panelId} hidden={!open}>
        <strong>Where these figures come from</strong>
        <SourceList sources={sources} fetchedAt={fetchedAt} />
        <p className="purchase-muted">
          This screen monitors the selected scope for the rules it states. Nothing is fetched that your permissions do
          not already allow.
        </p>
      </div>
    </div>
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

/** The pale green card at the top right of every dashboard. */
export interface ShellFeature {
  title: string
  description: string
  actionLabel: string
  onAction: () => void
}

export function PurchaseDashboardShell({
  activeView,
  title,
  subtitle,
  breadcrumb,
  onViewChange,
  actions,
  filters,
  monitorNoun = 'Data',
  feature,
  sources,
  metrics,
  loading,
  refreshing,
  fetchedAt,
  onRefresh,
  children,
}: {
  activeView: string
  title: string
  subtitle: string
  /** The last crumb. The product name before it is the same on every screen. */
  breadcrumb: string
  onViewChange: (view: PurchaseViewId) => void
  /** Export and the overflow menu. Refresh is the shell's own. */
  actions?: ReactNode
  /** The labelled controls in the filter row. */
  filters: ReactNode
  /** What the monitoring pill is monitoring: "AI monitoring", "Data". */
  monitorNoun?: string
  feature?: ShellFeature
  sources: SourceStatus[]
  metrics: DashboardMetric[]
  loading: boolean
  refreshing: boolean
  fetchedAt: Date | null
  onRefresh: () => void
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
        <div className="purchase-page-heading">
          <nav className="purchase-breadcrumb" aria-label="Breadcrumb">
            <span>Aicountly Purchases</span>
            <ChevronRight size={13} aria-hidden />
            <span aria-current="page">{breadcrumb}</span>
          </nav>
          <h1>{title}</h1>
          <p className="purchase-page-subtitle">{subtitle}</p>
        </div>

        <div className="purchase-header-actions">
          <button
            type="button"
            className="purchase-button purchase-button--secondary"
            onClick={onRefresh}
            disabled={refreshing}
            aria-label="Refresh the figures"
          >
            <RefreshCw size={15} aria-hidden className={refreshing ? 'purchase-spin' : undefined} />{' '}
            {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>
          {actions}
        </div>

        {feature && (
          <aside className="purchase-feature">
            <div>
              <strong>{feature.title}</strong>
              <p>{feature.description}</p>
            </div>
            <button type="button" onClick={feature.onAction} aria-label={feature.actionLabel} title={feature.actionLabel}>
              <ArrowUpRight size={19} aria-hidden />
            </button>
            <span className="purchase-feature__wave" aria-hidden />
          </aside>
        )}
      </header>

      {/* The filters sit above the tabs and stay put across them: the period a
          reader chose on one dashboard is the period they meant on the next. */}
      <div className="purchase-filterbar">
        {filters}
        <MonitorPill noun={monitorNoun} sources={sources} fetchedAt={fetchedAt} />
      </div>

      <DashboardSwitcher activeView={activeView} onChange={onViewChange} />

      <MetricsRow metrics={metrics} loading={loading} onOpen={openDrilldown} />

      <div className="purchase-dashboard-content" aria-busy={refreshing}>
        {children}
      </div>
    </div>
  )
}
