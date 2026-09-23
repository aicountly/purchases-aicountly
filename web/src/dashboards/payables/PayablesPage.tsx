/**
 * Bills & Payables — the accounts payable command centre.
 *
 * The screen answers, in this order: how much do we owe, how much of it is
 * late, what falls due next, what is held up and why, who we owe it to, and
 * then the list itself.
 *
 * THE DIVISION OF LABOUR IS ON THE SCREEN. What is stuck, and why, is this
 * application's. What is owed, to whom and when, is Smart Books' and is read
 * live on every request. Neither is ever derived from the other, and no card
 * here blends the two into one figure — which is why this screen cannot
 * disagree with the accounts.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  CalendarClock,
  CalendarRange,
  Copy,
  CreditCard,
  FileWarning,
  Receipt,
  RefreshCw,
  Search,
  SlidersHorizontal,
  Sparkles,
  Upload,
  X,
} from 'lucide-react'
import { api } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import { fetchCompanyInfo } from '../../services/manage'
import { DashboardSwitcher } from '../shell'
import { DATE_PRESETS, type DashboardFilters, type PurchaseViewId } from '../filters'
import type { DashboardResponse, Drilldown, Panel } from '../types'
import { BillsPayablesWorkbench } from '../views/BillsPayables'
import { PayablesAgeingCard, PayablesTrendCard, TopSupplierPayablesCard } from './analytics'
import { AskAicountlyPanel } from './AskAicountly'
import { Card, CardSkeleton, Chevron, KpiCard, KpiSkeleton, Menu, WidgetError } from './parts'
import { PayablesWorkspace } from './PayablesWorkspace'
import type { BooksOpenItem, PayableTabId, SupplierExposurePanel, TrendPanel } from './types'
import { PAYABLE_TABS } from './types'
import './payables.css'

/**
 * The face each figure wears.
 *
 * Keyed on the metric id, never chosen from its value, so a card does not
 * change colour when the number moves: the icon says what the figure IS and
 * the tone says what kind of thing it is. Only the delta reacts to the data.
 */
const KPI_FACE: Record<string, { tone: 'primary' | 'warning' | 'danger' | 'success'; icon: typeof Receipt }> = {
  bills_awaiting_review: { tone: 'warning', icon: FileWarning },
  bills_with_exceptions: { tone: 'danger', icon: AlertTriangle },
  payables_total: { tone: 'primary', icon: CreditCard },
  payables_overdue: { tone: 'danger', icon: AlertTriangle },
  due_windows: { tone: 'success', icon: CalendarClock },
  duplicate_candidates: { tone: 'warning', icon: Copy },
}

type PlanningPanel = Panel<{
  rows: {
    supplier_account_id: number
    bill_ref: string | null
    due_date: string | null
    due_label: string
    pending: string
    pending_formatted: string
    part_paid: boolean
    days_overdue: number | null
    held: boolean
    held_reason: string | null
  }[]
  windows: Record<string, { amount: string; formatted: string; label: string }>
}>

export function PayablesPage({
  data,
  filters,
  loading,
  refreshing,
  error,
  retryable,
  fetchedAt,
  onRefresh,
  onViewChange,
}: {
  data: DashboardResponse | null
  filters: DashboardFilters
  loading: boolean
  refreshing: boolean
  error: string | null
  retryable: boolean
  fetchedAt: Date | null
  onRefresh: () => void
  onViewChange: (view: PurchaseViewId) => void
}) {
  const navigate = useNavigate()
  const { scope, setCompanyScope, can } = usePurchases()

  const [askOpen, setAskOpen] = useState(false)
  const [moreFiltersOpen, setMoreFiltersOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [exporting, setExporting] = useState<string | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)
  const [branches, setBranches] = useState<{ boId: number; name: string }[]>([])

  const tab = (PAYABLE_TABS.some((entry) => entry.id === filters.get('tab')) ? filters.get('tab') : 'all') as PayableTabId
  const preset = filters.get('preset') ?? 'this_month'
  const bucket = filters.get('bucket')
  const supplierId = filters.get('supplier_id')

  // Branches belong to Manage and are read from Manage. A branch name copied
  // into this product is a name that stays wrong after somebody corrects it
  // over there.
  useEffect(() => {
    if (!scope) return
    let cancelled = false

    fetchCompanyInfo(scope.cmp_id)
      .then((info) => {
        if (!cancelled) setBranches(info.branches.map((branch) => ({ boId: branch.boId, name: branch.name })))
      })
      .catch(() => {
        // Quiet on purpose: the ids still work and every scoped panel reports
        // its own state. A banner for a cosmetic lookup is the loudest way to
        // say the least useful thing.
      })

    return () => {
      cancelled = true
    }
  }, [scope])

  const planning = (data?.panels.payment_planning ?? { available: false, reason: '', kind: 'source' }) as PlanningPanel

  /**
   * What Books knows, keyed the way the table looks it up.
   *
   * Built from open items ALREADY read for the planning panel, so the table
   * costs Smart Books nothing. A bill whose supplier was not among those read
   * simply has no entry, and the cell says so.
   */
  const booksItems = useMemo(() => {
    const map = new Map<string, BooksOpenItem>()
    if (!planning.available) return map

    for (const row of planning.rows) {
      map.set(`${row.supplier_account_id}|${row.bill_ref ?? ''}`, {
        due_date: row.due_date,
        due_label: row.due_label,
        pending: row.pending,
        pending_formatted: row.pending_formatted,
        part_paid: row.part_paid,
        days_overdue: row.days_overdue,
        held: row.held,
        held_reason: row.held_reason,
      })
    }

    return map
  }, [planning])

  const openDrilldown = useCallback(
    (target: Drilldown) => {
      const query = new URLSearchParams(target.filters).toString()
      navigate(query === '' ? target.route : `${target.route}?${query}`)
    },
    [navigate],
  )

  const take = async (format: 'csv' | 'pdf') => {
    setExporting(format)
    setExportError(null)
    try {
      await api.download(
        'v1/dashboards/bills-payables/export',
        `purchases-bills-payables-${preset}.${format}`,
        { ...filters.apiParams, format },
      )
    } catch (err) {
      setExportError(err instanceof Error ? err.message : 'That export could not be produced.')
    } finally {
      setExporting(null)
    }
  }

  const activeChips = [
    supplierId !== null && { id: 'supplier_id', label: `Supplier account ${supplierId}` },
    filters.get('warehouse_id') !== null && { id: 'warehouse_id', label: `Material centre ${filters.get('warehouse_id')}` },
    filters.get('q') !== null && { id: 'q', label: `Figures narrowed to “${filters.get('q')}”` },
    bucket !== null && { id: 'bucket', label: `Ageing: ${bucket.replace(/_/g, ' ')}` },
  ].filter(Boolean) as { id: string; label: string }[]

  return (
    <div className="aic-payables">
      <DashboardSwitcher activeView="bills-payables" onChange={onViewChange} />

      <header className="aic-hero">
        <div style={{ minWidth: 0 }}>
          <nav className="aic-breadcrumb" aria-label="Breadcrumb">
            <a href="/dashboard/overview" onClick={(event) => { event.preventDefault(); onViewChange('overview') }}>
              Purchases
            </a>
            <span aria-hidden>/</span>
            <span aria-current="page">Bills &amp; Payables</span>
          </nav>

          <div className="aic-title-row">
            <span className="aic-title-icon" aria-hidden>
              <Receipt size={20} />
            </span>
            <div>
              <h1>Bills &amp; Payables</h1>
              <p>Track supplier invoices, manage payables and plan payments with AI insights.</p>
            </div>
          </div>
        </div>

        <div className="aic-hero-actions">
          <button type="button" className="aic-ask-card" onClick={() => setAskOpen(true)}>
            <span className="aic-ask-icon" aria-hidden>
              <Sparkles size={17} />
            </span>
            <span className="aic-ask-copy">
              <strong>Ask Aicountly</strong>
              <small>Which bills need review before payment?</small>
            </span>
            <span className="aic-ask-arrow" aria-hidden>
              <Chevron />
            </span>
          </button>

          {can('bill.enter') && (
            <button type="button" className="aic-btn aic-btn--secondary" onClick={() => navigate('/statements')}>
              <Upload size={14} aria-hidden /> Import
            </button>
          )}

          {can('bill.enter') && (
            <Menu
              label="New bill"
              trigger={(props) => (
                <button type="button" className="aic-btn aic-btn--primary" {...props}>
                  + New bill <Chevron />
                </button>
              )}
            >
              {(close) => (
                <>
                  {/* Only what this application actually does. There is no
                      "upload an invoice and we will make a bill of it": an
                      uploaded document can be READ here, but nothing turns one
                      into a bill, and an entry that led nowhere would be worse
                      than no entry. */}
                  <button type="button" onClick={() => { close(); navigate('/bills') }}>
                    <Receipt size={14} aria-hidden /> Enter a supplier bill
                  </button>
                  <button type="button" onClick={() => { close(); navigate('/purchase-orders') }}>
                    <Search size={14} aria-hidden /> Start from a purchase order
                  </button>
                  <button type="button" onClick={() => { close(); navigate('/statements') }}>
                    <Upload size={14} aria-hidden /> Import a supplier statement
                  </button>
                </>
              )}
            </Menu>
          )}
        </div>
      </header>

      {/* ------------------------------------------------------ filter bar */}

      <div className="aic-filterbar">
        <label className="aic-field">
          <span>Period</span>
          <select value={preset} onChange={(event) => filters.set({ preset: event.target.value })}>
            {DATE_PRESETS.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <label className="aic-field">
          <span>Compare with</span>
          <select
            value={filters.get('compare') ?? 'previous_period'}
            onChange={(event) => filters.set({ compare: event.target.value })}
          >
            <option value="previous_period">Previous period</option>
            <option value="none">No comparison</option>
          </select>
        </label>

        {preset === 'custom' ? (
          <>
            <label className="aic-field">
              <span>From</span>
              <input
                type="date"
                value={filters.get('from') ?? ''}
                onChange={(event) => filters.set({ from: event.target.value })}
              />
            </label>
            <label className="aic-field">
              <span>To</span>
              <input
                type="date"
                value={filters.get('to') ?? ''}
                onChange={(event) => filters.set({ to: event.target.value })}
              />
            </label>
          </>
        ) : (
          <div className="aic-field aic-field--range">
            <span>Date range</span>
            <button type="button" onClick={() => filters.set({ preset: 'custom' })}>
              <CalendarRange size={14} aria-hidden />
              {data ? data.period.label : '—'}
            </button>
          </div>
        )}

        <label className="aic-field">
          <span>Branch</span>
          <select
            value={scope?.bo_id ?? 0}
            onChange={(event) => scope && setCompanyScope({ ...scope, bo_id: Number(event.target.value) })}
          >
            <option value={0}>All branches</option>
            {branches.map((branch) => (
              <option key={branch.boId} value={branch.boId}>
                {branch.name}
              </option>
            ))}
          </select>
        </label>

        <div className="aic-filterbar__end">
          <button
            type="button"
            className={activeChips.length > 0 ? 'aic-btn aic-btn--secondary is-on' : 'aic-btn aic-btn--secondary'}
            onClick={() => setMoreFiltersOpen(true)}
            aria-haspopup="dialog"
          >
            <SlidersHorizontal size={14} aria-hidden /> More filters
            {activeChips.length > 0 && ` (${activeChips.length})`}
          </button>
          <button
            type="button"
            className="aic-btn aic-btn--secondary"
            onClick={onRefresh}
            disabled={refreshing}
            aria-label="Refresh the figures"
          >
            <RefreshCw size={14} aria-hidden /> {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </div>

      {activeChips.length > 0 && (
        <div className="aic-chips">
          {activeChips.map((chip) => (
            <span key={chip.id} className="aic-chip">
              {chip.label}
              <button type="button" onClick={() => filters.set({ [chip.id]: null })} aria-label={`Remove ${chip.label}`}>
                <X size={12} aria-hidden />
              </button>
            </span>
          ))}
          <button type="button" className="aic-btn aic-btn--quiet" onClick={filters.reset}>
            Clear all
          </button>
        </div>
      )}

      {/* ---------------------------------------------------- source status */}

      {data && (
        <ul className="aic-sources">
          {data.sources.map((source) => (
            <li key={source.id} title={source.message ?? undefined}>
              <span className={`aic-dot aic-dot--${source.status}`} aria-hidden />
              {source.label}: {source.status_label}
            </li>
          ))}
          {fetchedAt && (
            <li>
              <span className="aic-dot aic-dot--ready" aria-hidden />
              Last updated{' '}
              <time dateTime={fetchedAt.toISOString()}>
                {fetchedAt.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}
              </time>
            </li>
          )}
          <li>
            <span className="aic-dot" aria-hidden />
            {data.scope.branch_label}
          </li>
        </ul>
      )}

      {exportError !== null && (
        <div className="aic-notice aic-notice--danger" style={{ marginBottom: 14 }} role="alert">
          <AlertTriangle size={16} aria-hidden />
          <div>{exportError}</div>
        </div>
      )}

      {/* A failure of the dashboard payload does not take the table with it:
          the workspace below fetches its own rows from its own endpoint. */}
      {error !== null && (
        <div style={{ marginBottom: 14 }}>
          <WidgetError message={error} onRetry={retryable ? onRefresh : onRefresh} />
        </div>
      )}

      {/* ----------------------------------------------------------- KPIs */}

      {loading && !data ? (
        <KpiSkeleton />
      ) : data ? (
        <div className="aic-kpis">
          {data.metrics.map((metric) => {
            const face = KPI_FACE[metric.id] ?? { tone: 'primary' as const, icon: Receipt }
            const Icon = face.icon
            return (
              <KpiCard
                key={metric.id}
                metric={metric}
                tone={face.tone}
                icon={<Icon size={16} />}
                onOpen={openDrilldown}
              />
            )
          })}
        </div>
      ) : null}

      {/* ------------------------------------------------------- analytics */}

      <div className="aic-analytics">
        {loading && !data ? (
          <>
            <CardSkeleton />
            <CardSkeleton />
            <CardSkeleton />
          </>
        ) : data ? (
          <>
            <PayablesTrendCard panel={data.panels.trend as TrendPanel} />
            <PayablesAgeingCard
              panel={data.panels.ageing as Parameters<typeof PayablesAgeingCard>[0]['panel']}
              activeBucket={bucket}
              onPick={(next) => filters.set({ bucket: next })}
            />
            <TopSupplierPayablesCard
              panel={data.panels.supplier_exposure as SupplierExposurePanel}
              activeSupplier={supplierId}
              onPick={(next) => filters.set({ supplier_id: next })}
            />
          </>
        ) : null}
      </div>

      {/* ------------------------------------------------------- workspace */}

      <PayablesWorkspace
        tab={tab}
        onTabChange={(next) => filters.set({ tab: next === 'all' ? null : next })}
        search={search}
        onSearchChange={setSearch}
        supplierId={supplierId}
        from={data?.period.from ?? null}
        to={data?.period.to ?? null}
        onClearFilters={() => {
          setSearch('')
          filters.reset()
        }}
        filtersApplied={activeChips.length > 0 || search !== ''}
        booksItems={booksItems}
        canExport={can('reports.view')}
        onExport={(format) => void take(format)}
        exporting={exporting}
        onChanged={onRefresh}
      />

      {/* The matching workbench, the payment planner and the intake pipeline
          are unchanged business surfaces and keep working exactly as they did.
          They sit below the list because they answer "why is this held up",
          which is the question a reader asks second. */}
      {data && (
        <div className="purchase-workspace" style={{ padding: 0, background: 'transparent' }}>
          <div className="purchase-dashboard-content">
            <BillsPayablesWorkbench data={data} filters={filters} onChanged={onRefresh} />
          </div>
        </div>
      )}

      <AskAicountlyPanel open={askOpen} onClose={() => setAskOpen(false)} />

      <MoreFilters
        open={moreFiltersOpen}
        filters={filters}
        onClose={() => setMoreFiltersOpen(false)}
      />
    </div>
  )
}

// ---------------------------------------------------------------------------

/**
 * The narrowing a reader does occasionally.
 *
 * Everything in here narrows the FIGURES as well as the list, which is why it
 * is not the same control as the search box on the table toolbar: one finds a
 * bill, the other changes what every card on the screen counts.
 */
function MoreFilters({
  open,
  filters,
  onClose,
}: {
  open: boolean
  filters: DashboardFilters
  onClose: () => void
}) {
  const [supplier, setSupplier] = useState(filters.get('supplier_id') ?? '')
  const [centre, setCentre] = useState(filters.get('warehouse_id') ?? '')
  const [query, setQuery] = useState(filters.get('q') ?? '')

  useEffect(() => {
    if (!open) return
    setSupplier(filters.get('supplier_id') ?? '')
    setCentre(filters.get('warehouse_id') ?? '')
    setQuery(filters.get('q') ?? '')

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, filters, onClose])

  if (!open) return null

  return (
    <>
      <div className="aic-drawer-backdrop" onClick={onClose} aria-hidden />
      <aside className="aic-drawer" role="dialog" aria-modal="true" aria-labelledby="aic-more-filters-title">
        <header className="aic-drawer__head">
          <div>
            <h2 id="aic-more-filters-title">More filters</h2>
            <p>These narrow every figure on the screen, not just the list.</p>
          </div>
          <button type="button" className="aic-icon-btn" onClick={onClose} aria-label="Close">
            <X size={16} aria-hidden />
          </button>
        </header>

        <div className="aic-drawer__body">
          <label className="aic-field">
            <span>Supplier account</span>
            <input
              type="text"
              inputMode="numeric"
              value={supplier}
              placeholder="All suppliers"
              onChange={(event) => setSupplier(event.target.value)}
            />
          </label>

          <label className="aic-field">
            <span>Material centre</span>
            <input
              type="text"
              inputMode="numeric"
              value={centre}
              placeholder="All centres"
              onChange={(event) => setCentre(event.target.value)}
            />
          </label>

          <label className="aic-field">
            <span>Invoice reference</span>
            <input
              type="search"
              value={query}
              placeholder="Order, supplier or invoice"
              onChange={(event) => setQuery(event.target.value)}
            />
          </label>

          <Card title="What is not here, and why">
            <p className="aic-card__note" style={{ margin: 0 }}>
              Approval state, payment state and due date are Smart Books&rsquo; own, and Books answers open items one
              supplier at a time. Filtering the whole screen by them would mean reading the entire creditors ledger on
              every keystroke, so they are shown per bill in the list instead of offered as filters here.
            </p>
          </Card>
        </div>

        <footer className="aic-drawer__foot">
          <button
            type="button"
            className="aic-btn aic-btn--secondary"
            onClick={() => {
              setSupplier('')
              setCentre('')
              setQuery('')
              filters.set({ supplier_id: null, warehouse_id: null, q: null })
            }}
          >
            Clear all
          </button>
          <button
            type="button"
            className="aic-btn aic-btn--primary"
            onClick={() => {
              filters.set({
                supplier_id: supplier.trim() || null,
                warehouse_id: centre.trim() || null,
                q: query.trim() || null,
              })
              onClose()
            }}
          >
            Apply filters
          </button>
        </footer>
      </aside>
    </>
  )
}
