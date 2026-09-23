/**
 * The furniture every purchase dashboard shares.
 *
 * Five screens that each invented their own idea of a metric card would drift
 * apart within a month, and the first thing to go would be the honesty: one
 * screen would start showing a dash where another showed "Unavailable", and a
 * reader would learn to treat both as zero.
 */

import { useId, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowRight,
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
  Inbox,
  Info,
  LayoutGrid,
  Mail,
  Minus,
  PackageCheck,
  PauseCircle,
  PiggyBank,
  ShoppingCart,
  Sparkles,
  Timer,
  TrendingDown,
  TrendingUp,
  Truck,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { MetricSparkline, type SparkTone } from './charts'
import { PURCHASE_VIEWS, type PurchaseViewId } from './filters'
import type { DashboardMetric, Drilldown, SourceStatus } from './types'

export { PURCHASE_VIEWS }

// ---------------------------------------------------------------------------
// Report category tabs
// ---------------------------------------------------------------------------

/** One face per dashboard, so the strip reads at a glance and not as five words. */
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
    <nav className="purchase-tabs" aria-label="Purchase intelligence report categories">
      {PURCHASE_VIEWS.map((view) => {
        const Icon = VIEW_ICON[view.id]
        const active = activeView === view.id

        return (
          <button
            key={view.id}
            type="button"
            className={active ? 'purchase-tab is-active' : 'purchase-tab'}
            aria-current={active ? 'page' : undefined}
            onClick={() => onChange(view.id)}
          >
            <Icon size={15} className="purchase-tab__icon" aria-hidden />
            {view.label}
          </button>
        )
      })}
    </nav>
  )
}

/**
 * Whether the insight engine is reading live data right now.
 *
 * It is wired to the source statuses rather than being a decoration that is
 * always lit: a green dot that cannot go amber teaches people to ignore it, and
 * then it cannot warn them about anything.
 */
export function LiveChip({ sources }: { sources: SourceStatus[] }) {
  const degraded = sources.filter((source) => source.status !== 'ready')
  const limited = degraded.length > 0

  return (
    <span
      className={limited ? 'purchase-live purchase-live--stale' : 'purchase-live'}
      title={
        limited
          ? `Running on what answered: ${degraded.map((source) => `${source.label} ${source.status_label.toLowerCase()}`).join(', ')}.`
          : 'Insights on this screen are recomputed from live Purchase data every time it loads. Nothing here is a stored snapshot.'
      }
    >
      <span className="purchase-live__dot" aria-hidden />
      AI monitoring
      <strong style={{ fontWeight: 700 }}>{limited ? 'limited' : 'live'}</strong>
    </span>
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

/**
 * One KPI.
 *
 * WHAT MOVED OUT OF THE CARD. The methodology used to be printed inside every
 * card — a basis sentence and a footnote, six times over — and six cards of
 * prose is not a KPI row, it is a page of small print with numbers in it. The
 * definition now lives behind the info button, where somebody who is asking
 * "what does this count?" can find it and nobody else has to read past it.
 *
 * WHAT AN UNAVAILABLE CARD LOOKS LIKE. It keeps the card, the tile and the
 * footprint, shows a dash where the figure would be, and says in small type
 * that there is not enough data and why. The old screen wrote "Unavailable" in
 * the size a number would have been, and six of those read as six failures when
 * what they actually mean is that nothing has been bought yet.
 */
export function MetricCard({
  metric,
  spark,
  onOpen,
}: {
  metric: DashboardMetric
  /** The months behind the figure, when the server can produce them honestly. */
  spark?: { period: string; value: string | null }[]
  onOpen: (target: Drilldown) => void
}) {
  const [tipOpen, setTipOpen] = useState(false)
  const tipId = useId()

  const available = metric.status === 'ready'
  const clickable = available && metric.drilldown !== undefined
  const face = METRIC_FACE[metric.id] ?? { icon: Sparkles, tone: '' as const }
  const Face = face.icon

  const comparison = metric.comparison?.available === true
  const Trend = metric.change_tone === 'is-positive' ? TrendingUp : metric.change_tone === 'is-negative' ? TrendingDown : Minus

  // The line under the value, in order of what a reader most needs: the reason
  // a figure is missing, the backend's own "X in the previous period" footer
  // (which folds in a footnote where there is no numeric comparison to state),
  // then the reason there is no comparison at all.
  const caption = !available
    ? (metric.unavailable_reason ?? null)
    : (metric.footer ?? metric.comparison?.reason ?? null)

  // Explicit per-card data (Suppliers, matched against its own delivery-trend
  // panel) wins when supplied; otherwise the backend's own `metric.trend` is
  // used, which is how the AI Insights cards get a shape now that Metric::ready
  // accepts a `trend` option directly.
  const points = spark ?? (metric.trend && metric.trend.length > 0 ? metric.trend.map((point) => ({ period: point.period, value: point.value })) : undefined)

  const body = (
    <>
      <span className="purchase-metric__head">
        <span className={`purchase-metric__icon ${face.tone === '' ? '' : `is-${face.tone}`}`} aria-hidden>
          <Face size={17} />
        </span>
        <span className="purchase-metric__label">
          <span>{metric.label}</span>
        </span>
      </span>

      <strong
        className={available ? 'purchase-metric__value' : 'purchase-metric__value is-unavailable'}
        // The short form is what fits; the exact figure is what reconciles.
        title={available ? (metric.exact_value ?? undefined) : undefined}
      >
        {available ? metric.formatted_value : '—'}
      </strong>

      <span className={`purchase-metric__change ${available && comparison ? metric.change_tone : 'is-neutral'}`}>
        {available ? (
          comparison && (
            <>
              <Trend size={13} aria-hidden />
              {metric.comparison_text.replace(/^[▲▼]\s*/, '')}
            </>
          )
        ) : (
          'Insufficient data'
        )}
      </span>

      {caption !== null && caption !== '' && (
        <span className="purchase-metric__compare" title={caption}>
          {caption}
        </span>
      )}

      <span className="purchase-metric__spark" aria-hidden={points === undefined}>
        {points !== undefined && (
          <MetricSparkline
            points={points}
            tone={sparkTone(metric, points)}
            label={`${metric.label} by month`}
          />
        )}
      </span>
    </>
  )

  return (
    <article className="purchase-metric">
      {/* The info control sits outside the drill-down button: a button inside a
          button is not valid HTML and the browser will not give you both. */}
      <button
        type="button"
        className="purchase-metric__info"
        aria-expanded={tipOpen}
        aria-controls={tipId}
        aria-label={`What "${metric.label}" counts`}
        onClick={(event) => {
          event.stopPropagation()
          setTipOpen((open) => !open)
        }}
        onMouseEnter={() => setTipOpen(true)}
        onMouseLeave={() => setTipOpen(false)}
        onFocus={() => setTipOpen(true)}
        onBlur={() => setTipOpen(false)}
      >
        <Info size={14} aria-hidden />
      </button>

      {tipOpen && (
        <span className="purchase-tip" id={tipId} role="tooltip">
          <strong>{metric.label}</strong>
          {metric.explanation}
          {metric.basis !== metric.explanation && <p>{metric.basis}</p>}
        </span>
      )}

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

/**
 * The colour of a sparkline, from the BUSINESS meaning of its movement.
 *
 * Never from the arithmetic alone. Weighted payment terms going from 34 days to
 * 38 is a line sloping up and a fact sloping down, and a green line there would
 * be telling the reader the opposite of what happened.
 */
function sparkTone(metric: DashboardMetric, spark: { value: string | null }[]): SparkTone {
  if (metric.direction === 'neutral') return 'neutral'

  const rated = spark.filter((point) => point.value !== null)
  if (rated.length < 2) return 'neutral'

  const first = Number.parseFloat(rated[0].value as string)
  const last = Number.parseFloat(rated[rated.length - 1].value as string)
  if (!Number.isFinite(first) || !Number.isFinite(last) || first === last) return 'neutral'

  const up = last > first
  return (metric.direction === 'higher_is_better') === up ? 'positive' : 'negative'
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
  title: ReactNode
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

export function EmptyState({
  title,
  /** True when the panel is empty because nothing is WRONG, not because nothing is there. */
  reassuring = false,
  children,
}: {
  title: string
  reassuring?: boolean
  children?: ReactNode
}) {
  const Face = reassuring ? CheckCircle2 : Inbox

  return (
    <div className="purchase-empty">
      <Face
        size={22}
        aria-hidden
        style={{ color: reassuring ? 'var(--purchase-good)' : 'var(--purchase-faint)' }}
      />
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
  sparklines,
  loading,
  onOpen,
}: {
  metrics: DashboardMetric[]
  /** Monthly series by metric id, for the cards the server can draw a shape for. */
  sparklines?: Record<string, { period: string; value: string | null }[]>
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
        <MetricCard key={metric.id} metric={metric} spark={sparklines?.[metric.id]} onOpen={onOpen} />
      ))}
    </div>
  )
}

/**
 * The page frame every purchase dashboard sits in.
 *
 * The order on the page is deliberate and it is not the order it used to be.
 * It was: tabs, heading, a period in the header, a comparison strip, a filter
 * row behind a toggle, then the figures. Five places to look before the first
 * number, and two of them hidden.
 *
 * It is now: heading, every filter in one bar, the report you are on, then the
 * figures. A reader can see what is being measured, over what, and narrowed by
 * what, without opening anything.
 */
export function PurchaseDashboardShell({
  activeView,
  breadcrumb,
  title,
  subtitle,
  onViewChange,
  actions,
  hero,
  filters,
  periodLabel,
  sources,
  metrics,
  sparklines,
  loading,
  refreshing,
  fetchedAt,
  children,
}: {
  activeView: string
  /** The trail above the heading. The last entry is where you are. */
  breadcrumb: string[]
  title: string
  subtitle: string
  onViewChange: (view: PurchaseViewId) => void
  /** Refresh, Export and the overflow menu. */
  actions: ReactNode
  /** The one decorative card, on wide screens only. */
  hero?: ReactNode
  /** The whole command bar: period, comparison, supplier, centre, search. */
  filters: ReactNode
  /** "01 Sep 2026 – 21 Sep 2026 · All branches", from the response. */
  periodLabel?: ReactNode
  sources: SourceStatus[]
  metrics: DashboardMetric[]
  sparklines?: Record<string, { period: string; value: string | null }[]>
  loading: boolean
  refreshing: boolean
  fetchedAt: Date | null
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
      <div className="purchase-page">
        <header className="purchase-page-header">
          <div className="purchase-page-header__text">
            <p className="purchase-eyebrow">
              {breadcrumb.map((crumb, index) => (
                <span key={crumb} className={index === breadcrumb.length - 1 ? 'purchase-eyebrow__here' : undefined}>
                  {index > 0 && (
                    <span className="purchase-eyebrow__sep" aria-hidden>
                      {'  ›  '}
                    </span>
                  )}
                  {crumb}
                </span>
              ))}
            </p>

            <h1>{title}</h1>
            <p className="purchase-page-subtitle">{subtitle}</p>
          </div>

          <div className="purchase-page-header__side">
            <div className="purchase-header-actions">{actions}</div>
            {hero}
          </div>
        </header>

        <section className="purchase-commandbar" aria-label="Report filters">
          {filters}
        </section>

        <DashboardSwitcher activeView={activeView} onChange={onViewChange} />

        <div className="purchase-meta">
          {periodLabel !== undefined && <span className="purchase-meta__period">{periodLabel}</span>}
          <SourceList sources={sources} fetchedAt={fetchedAt} />
        </div>

        <MetricsRow metrics={metrics} sparklines={sparklines} loading={loading} onOpen={openDrilldown} />

        <div className="purchase-dashboard-content" aria-busy={refreshing}>
          {children}
        </div>
      </div>
    </div>
  )
}
