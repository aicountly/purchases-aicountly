/**
 * The dashboard container.
 *
 * One route, `/dashboard/:view`, that mounts exactly one of the five view
 * components. They are not all rendered with four hidden by CSS: four hidden
 * dashboards are four sets of requests nobody asked for, and a switcher that
 * does not change the URL is a switcher the Back button cannot undo.
 *
 * This file owns the chrome the five views share — the heading, the command
 * bar, the actions — and the shell owns how it is laid out. The split matters:
 * the filters have to know what the API reads, and the shell must not.
 */

import { useEffect, useRef, useState } from 'react'
import { Navigate, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowUpRight,
  Download,
  FileSpreadsheet,
  FileText,
  Link2,
  MoreHorizontal,
  RefreshCw,
  Search,
  X,
} from 'lucide-react'
import { usePurchases } from '../context/PurchasesContext'
import { api } from '../services/api'
import { LiveChip, PurchaseDashboardShell } from './shell'
import { DATE_PRESETS, isPurchaseView, useDashboardFilters, type PurchaseViewId } from './filters'
import { FilterCombo, useCentreOptions, useSupplierOptions } from './FilterCombo'
import { useDashboard } from './useDashboard'
import { OverviewDashboard } from './views/Overview'
import { ProcurementDashboard } from './views/Procurement'
import { SuppliersDashboard } from './views/Suppliers'
import { BillsPayablesDashboard } from './views/BillsPayables'
import { AiInsightsDashboard } from './views/AiInsights'
import { supplierSparklines } from './suppliers/sparklines'
import './purchase.css'

const TITLES: Record<PurchaseViewId, { title: string; subtitle: string }> = {
  overview: {
    title: 'Purchase overview',
    subtitle: 'Your purchasing priorities, in one place.',
  },
  procurement: {
    title: 'Procurement workspace',
    subtitle: 'Move every request from requirement to receipt.',
  },
  suppliers: {
    title: 'Supplier performance',
    subtitle: 'Understand reliability, cost, concentration and supplier risk.',
  },
  'bills-payables': {
    title: 'Bills & payables',
    subtitle: 'Match invoices and plan supplier payments.',
  },
  'ai-insights': {
    title: 'Purchase intelligence',
    subtitle: 'Evidence-backed suggestions. You stay in control.',
  },
}

/** What the crumb above the heading says you are looking at. */
const CRUMB: Record<PurchaseViewId, string> = {
  overview: 'Overview',
  procurement: 'Procurement',
  suppliers: 'Supplier performance',
  'bills-payables': 'Bills & payables',
  'ai-insights': 'AI insights',
}

const COMPARISONS = [
  { id: 'previous_period', label: 'Previous period' },
  { id: 'none', label: 'No comparison' },
]

/** Filters that describe the question rather than the screen follow the tabs. */
const PORTABLE = ['preset', 'from', 'to', 'compare', 'supplier_id', 'supplier_name', 'buyer', 'warehouse_id', 'warehouse_name']

export default function PurchaseDashboards() {
  const { view } = useParams<{ view: string }>()
  const navigate = useNavigate()
  const filters = useDashboardFilters()
  const { can } = usePurchases()

  const resolved: PurchaseViewId = isPurchaseView(view) ? view : 'overview'
  const { data, loading, refreshing, error, retryable, fetchedAt, refresh } = useDashboard(resolved, filters.apiParams)

  const [busy, setBusy] = useState<'csv' | 'pdf' | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [menu, setMenu] = useState<'export' | 'more' | null>(null)
  const menuRef = useRef<HTMLDivElement>(null)

  // Search is typed here and committed to the URL on a timer, so the query
  // string still holds the state and the API is not asked on every keystroke.
  const committed = filters.get('q') ?? ''
  const [term, setTerm] = useState(committed)
  const termRef = useRef(committed)

  const commit = useRef(filters.set)
  commit.current = filters.set

  const supplierOptions = useSupplierOptions()
  const centreOptions = useCentreOptions()

  // The document title follows the dashboard, so a browser history entry reads
  // as the screen it was.
  useEffect(() => {
    document.title = `${TITLES[resolved].title} · Aicountly Purchases`
  }, [resolved])

  // A filter changed somewhere else — a cleared set, the Back button — so the
  // box follows the URL rather than the two quietly disagreeing.
  useEffect(() => {
    if (committed !== termRef.current) {
      termRef.current = committed
      setTerm(committed)
    }
  }, [committed])

  useEffect(() => {
    if (term === termRef.current) return

    const timer = setTimeout(() => {
      termRef.current = term
      commit.current({ q: term })
    }, 300)

    return () => clearTimeout(timer)
  }, [term])

  useEffect(() => {
    if (menu === null) return

    function onClickAway(event: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) setMenu(null)
    }
    function onEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') setMenu(null)
    }

    document.addEventListener('mousedown', onClickAway)
    document.addEventListener('keydown', onEscape)
    return () => {
      document.removeEventListener('mousedown', onClickAway)
      document.removeEventListener('keydown', onEscape)
    }
  }, [menu])

  if (view !== undefined && !isPurchaseView(view)) {
    return <Navigate to="/dashboard/overview" replace />
  }

  const preset = filters.get('preset') ?? 'this_month'
  const canExport = can('reports.view')

  /**
   * Take the figures, in the format asked for.
   *
   * Fetched with the session key rather than linked to: this API authenticates
   * with a bearer token and a plain <a href> cannot carry one, so the link this
   * replaced was answering 401 and the click did nothing visible.
   *
   * It carries `filters.apiParams`, which is exactly what drew the screen, so
   * an export and the dashboard it came from cannot disagree.
   */
  const take = async (format: 'csv' | 'pdf') => {
    setBusy(format)
    setActionError(null)
    setMenu(null)
    try {
      await api.download(
        `v1/dashboards/${resolved}/export`,
        `purchases-${resolved}-${preset}.${format}`,
        { ...filters.apiParams, format },
      )
    } catch (failure) {
      setActionError(failure instanceof Error ? failure.message : 'That export could not be produced.')
    } finally {
      setBusy(null)
    }
  }

  const copyLink = async () => {
    setMenu(null)
    try {
      await navigator.clipboard.writeText(window.location.href)
      setActionError(null)
    } catch {
      setActionError('This browser would not let the page copy to the clipboard.')
    }
  }

  const sparklines = resolved === 'suppliers' && data !== null ? supplierSparklines(data) : undefined

  return (
    <PurchaseDashboardShell
      activeView={resolved}
      breadcrumb={['Aicountly Purchases', CRUMB[resolved]]}
      title={TITLES[resolved].title}
      subtitle={TITLES[resolved].subtitle}
      onViewChange={(next) => {
        // Filters that only make sense on one dashboard do not follow it to the
        // next one; the period and the supplier do.
        const kept = new URLSearchParams()
        for (const name of PORTABLE) {
          const value = filters.get(name)
          if (value !== null) kept.set(name, value)
        }
        const query = kept.toString()
        navigate(query === '' ? `/dashboard/${next}` : `/dashboard/${next}?${query}`)
      }}
      periodLabel={
        data && (
          <>
            {data.period.label} · {data.scope.branch_label}
            {data.scope.reporting_currency === null && ' · mixed currencies'}
          </>
        )
      }
      hero={
        resolved === 'suppliers' ? (
          <aside className="purchase-hero" aria-hidden>
            <span className="purchase-hero__mark">
              <ArrowUpRight size={15} aria-hidden />
            </span>
            <strong>Better supplier relationships</strong>
            <p>A stronger, more resilient supply chain.</p>
          </aside>
        ) : undefined
      }
      actions={
        <>
          <button
            type="button"
            className="purchase-button purchase-button--secondary"
            onClick={refresh}
            disabled={refreshing}
          >
            <RefreshCw size={15} aria-hidden className={refreshing ? 'purchase-spin' : undefined} />
            {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>

          <div className="purchase-menu-group" ref={menuRef}>
          <div className="purchase-menu">
            {canExport && (
              <button
                type="button"
                className="purchase-button purchase-button--primary"
                onClick={() => setMenu((open) => (open === 'export' ? null : 'export'))}
                aria-expanded={menu === 'export'}
                aria-haspopup="menu"
                disabled={data === null || busy !== null}
              >
                <Download size={15} aria-hidden />
                {busy === null ? 'Export' : 'Exporting…'}
              </button>
            )}

            {menu === 'export' && (
              <div className="purchase-menu__list" role="menu">
                <button type="button" role="menuitem" className="purchase-menu__item" onClick={() => void take('csv')}>
                  <FileSpreadsheet size={15} aria-hidden /> Spreadsheet (CSV)
                </button>
                <button type="button" role="menuitem" className="purchase-menu__item" onClick={() => void take('pdf')}>
                  <FileText size={15} aria-hidden /> Print-ready PDF
                </button>
                <div className="purchase-menu__rule" />
                <p className="purchase-menu__item" style={{ cursor: 'default', fontSize: '0.74rem', color: 'var(--purchase-muted)' }}>
                  Exports carry the filters on screen.
                </p>
              </div>
            )}
          </div>

          <div className="purchase-menu">
            <button
              type="button"
              className="purchase-button purchase-button--secondary purchase-button--icon"
              aria-label="More actions"
              aria-haspopup="menu"
              aria-expanded={menu === 'more'}
              onClick={() => setMenu((open) => (open === 'more' ? null : 'more'))}
            >
              <MoreHorizontal size={16} aria-hidden />
            </button>

            {menu === 'more' && (
              <div className="purchase-menu__list" role="menu">
                <button type="button" role="menuitem" className="purchase-menu__item" onClick={() => void copyLink()}>
                  <Link2 size={15} aria-hidden /> Copy a link to this report
                </button>
                <button
                  type="button"
                  role="menuitem"
                  className="purchase-menu__item"
                  onClick={() => {
                    setMenu(null)
                    filters.reset()
                  }}
                  disabled={!filters.isNarrowed}
                >
                  <X size={15} aria-hidden /> Clear every filter
                </button>
              </div>
            )}
          </div>
          </div>
        </>
      }
      filters={
        <>
          <label className="purchase-field">
            <span>Period</span>
            <select value={preset} onChange={(event) => filters.set({ preset: event.target.value })}>
              {DATE_PRESETS.map((option) => (
                <option key={option.id} value={option.id}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          {preset === 'custom' ? (
            <>
              <label className="purchase-field">
                <span>From</span>
                <input
                  type="date"
                  value={filters.get('from') ?? ''}
                  onChange={(event) => filters.set({ from: event.target.value })}
                />
              </label>
              <label className="purchase-field">
                <span>To</span>
                <input
                  type="date"
                  value={filters.get('to') ?? ''}
                  onChange={(event) => filters.set({ to: event.target.value })}
                />
              </label>
            </>
          ) : (
            <label className="purchase-field">
              <span>Compare with</span>
              <select
                value={filters.get('compare') ?? 'previous_period'}
                onChange={(event) => filters.set({ compare: event.target.value })}
              >
                {COMPARISONS.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
          )}

          <FilterCombo
            label="Supplier account"
            allLabel="All suppliers"
            value={filters.get('supplier_id')}
            valueLabel={filters.get('supplier_name')}
            load={supplierOptions}
            minimumTerm={2}
            onChange={(option) =>
              filters.set({
                supplier_id: option?.id ?? null,
                supplier_name: option?.label ?? null,
              })
            }
          />

          <FilterCombo
            label="Material centre"
            allLabel="All centres"
            value={filters.get('warehouse_id')}
            valueLabel={filters.get('warehouse_name')}
            load={centreOptions}
            onChange={(option) =>
              filters.set({
                warehouse_id: option?.id ?? null,
                warehouse_name: option?.label ?? null,
              })
            }
          />

          <label className="purchase-field purchase-search">
            <span>Search</span>
            <Search size={14} className="purchase-search__icon" aria-hidden />
            <input
              type="search"
              value={term}
              placeholder="Order, supplier or invoice…"
              onChange={(event) => setTerm(event.target.value)}
            />
            {term !== '' && (
              <button
                type="button"
                className="purchase-search__clear"
                onClick={() => setTerm('')}
                aria-label="Clear the search"
              >
                <X size={14} aria-hidden />
              </button>
            )}
          </label>

          <LiveChip sources={data?.sources ?? []} />
        </>
      }
      sources={data?.sources ?? []}
      metrics={data?.metrics ?? []}
      sparklines={sparklines}
      loading={loading}
      refreshing={refreshing}
      fetchedAt={fetchedAt}
    >
      {actionError !== null && (
        <div className="purchase-notice purchase-notice--danger" style={{ marginBottom: 14 }}>
          {actionError}
        </div>
      )}

      {error && (
        <div className="purchase-notice purchase-notice--danger">
          <div>
            <strong>Supplier performance could not be loaded</strong>
            <p>{error}</p>
            {retryable && (
              <button
                type="button"
                className="purchase-button purchase-button--secondary"
                style={{ marginTop: 10 }}
                onClick={refresh}
              >
                Try again
              </button>
            )}
          </div>
        </div>
      )}

      {/* The skeleton keeps the shape of what is coming, so nothing jumps when
          it arrives. The KPI row draws its own. */}
      {loading && !data && (
        <div className="purchase-analytics">
          <div className="purchase-panel purchase-skeleton" style={{ height: 420 }} />
          <div className="purchase-stack">
            <div className="purchase-panel purchase-skeleton" style={{ height: 300 }} />
            <div className="purchase-panel purchase-skeleton" style={{ height: 300 }} />
          </div>
          <div className="purchase-stack purchase-stack--wide">
            <div className="purchase-panel purchase-skeleton" style={{ height: 250 }} />
            <div className="purchase-panel purchase-skeleton" style={{ height: 250 }} />
          </div>
        </div>
      )}

      {data && resolved === 'overview' && <OverviewDashboard data={data} />}
      {data && resolved === 'procurement' && <ProcurementDashboard data={data} filters={filters} onChanged={refresh} />}
      {data && resolved === 'suppliers' && <SuppliersDashboard data={data} filters={filters} />}
      {data && resolved === 'bills-payables' && <BillsPayablesDashboard data={data} filters={filters} onChanged={refresh} />}
      {data && resolved === 'ai-insights' && <AiInsightsDashboard data={data} />}
    </PurchaseDashboardShell>
  )
}
