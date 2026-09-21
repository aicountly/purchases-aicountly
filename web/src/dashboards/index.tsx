/**
 * The dashboard container.
 *
 * One route, `/dashboard/:view`, that mounts exactly one of the five view
 * components. They are not all rendered with four hidden by CSS: four hidden
 * dashboards are four sets of requests nobody asked for, and a switcher that
 * does not change the URL is a switcher the Back button cannot undo.
 */

import { useEffect, useRef, useState } from 'react'
import { Navigate, useNavigate, useParams } from 'react-router-dom'
import { Download, Search } from 'lucide-react'
import { usePurchases } from '../context/PurchasesContext'
import { getApiBaseUrl } from '../config'
import { PurchaseDashboardShell } from './shell'
import { DATE_PRESETS, isPurchaseView, useDashboardFilters, type PurchaseViewId } from './filters'
import { useDashboard } from './useDashboard'
import { OverviewDashboard } from './views/Overview'
import { ProcurementDashboard } from './views/Procurement'
import { ProcurementSkeleton } from './views/procurement/Skeleton'
import { SuppliersDashboard } from './views/Suppliers'
import { BillsPayablesDashboard } from './views/BillsPayables'
import { AiInsightsDashboard } from './views/AiInsights'
import type { FilterOptions } from './types'
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
    subtitle: 'Understand reliability, cost and concentration.',
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

/**
 * The search box.
 *
 * Typing is not a decision, so it does not go in the URL on every keystroke —
 * that would be one history entry and one request per letter. It settles for
 * half a second first, and Enter commits immediately for anyone who does not
 * want to wait.
 */
function DebouncedSearch({ value, onCommit }: { value: string; onCommit: (next: string) => void }) {
  const [text, setText] = useState(value)
  const committed = useRef(value)

  // A change from outside — Clear filters, or a link someone opened — wins over
  // whatever is half-typed, because the user asked for it more recently.
  useEffect(() => {
    if (value !== committed.current) {
      committed.current = value
      setText(value)
    }
  }, [value])

  useEffect(() => {
    if (text === committed.current) return
    const timer = setTimeout(() => {
      committed.current = text
      onCommit(text)
    }, 500)

    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [text])

  return (
    <label className="purchase-context__field purchase-context__field--search">
      Search
      <span className="purchase-context__input">
        <Search size={14} aria-hidden />
        <input
          type="search"
          value={text}
          placeholder="Order, supplier, material or invoice…"
          onChange={(event) => setText(event.target.value)}
          onKeyDown={(event) => {
            if (event.key !== 'Enter') return
            committed.current = text
            onCommit(text)
          }}
        />
      </span>
    </label>
  )
}

/**
 * A supplier or material-centre chooser.
 *
 * The options are the ones the server found in this company's own orders, so
 * the list cannot offer a value that returns nothing. Until they arrive — or
 * where the caller is looking at a record that has since stopped appearing —
 * the current id is still offered, so a shared link does not silently reset
 * itself to "all".
 */
function OptionSelect({
  label,
  allLabel,
  value,
  options,
  loading,
  onChange,
}: {
  label: string
  allLabel: string
  value: string
  options: { id: number; label: string }[]
  loading: boolean
  onChange: (next: string) => void
}) {
  const known = options.some((option) => String(option.id) === value)

  return (
    <label className="purchase-context__field">
      {label}
      <select value={value} onChange={(event) => onChange(event.target.value)} disabled={loading && options.length === 0}>
        <option value="">{allLabel}</option>
        {value !== '' && !known && <option value={value}>Account {value}</option>}
        {options.map((option) => (
          <option key={option.id} value={String(option.id)}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  )
}

export default function PurchaseDashboards() {
  const { view } = useParams<{ view: string }>()
  const navigate = useNavigate()
  const filters = useDashboardFilters()
  const { session, can } = usePurchases()

  const resolved: PurchaseViewId = isPurchaseView(view) ? view : 'overview'
  const { data, loading, refreshing, error, retryable, fetchedAt, refresh } = useDashboard(resolved, filters.apiParams)

  // The document title follows the dashboard, so a browser history entry reads
  // as the screen it was.
  useEffect(() => {
    document.title = `${TITLES[resolved].title} · Aicountly Purchases`
  }, [resolved])

  if (view !== undefined && !isPurchaseView(view)) {
    return <Navigate to="/dashboard/overview" replace />
  }

  const preset = filters.get('preset') ?? 'this_month'
  const canExport = can('reports.view')
  const options: FilterOptions = data?.filter_options ?? { suppliers: [], centres: [], reason: null }

  const exportUrl = () => {
    const params = new URLSearchParams(
      Object.entries(filters.apiParams).map(([key, value]) => [key, String(value)]),
    )
    if (session) {
      params.set('cmp_id', String(session.context.cmp_id))
      params.set('fy_id', String(session.context.fy_id))
      params.set('bo_id', String(session.context.bo_id))
    }
    return `${getApiBaseUrl()}/v1/dashboards/${resolved}/export?${params.toString()}`
  }

  return (
    <PurchaseDashboardShell
      activeView={resolved}
      title={TITLES[resolved].title}
      subtitle={TITLES[resolved].subtitle}
      onViewChange={(next) => {
        // Filters that only make sense on one dashboard do not follow it to the
        // next one; the period and the supplier do.
        const kept = new URLSearchParams()
        for (const name of ['preset', 'from', 'to', 'compare', 'supplier_id', 'buyer', 'warehouse_id']) {
          const value = filters.get(name)
          if (value !== null) kept.set(name, value)
        }
        const query = kept.toString()
        navigate(query === '' ? `/dashboard/${next}` : `/dashboard/${next}?${query}`)
      }}
      contextControls={
        <>
          <label className="purchase-context__field">
            Period
            <select value={preset} onChange={(event) => filters.set({ preset: event.target.value })}>
              {DATE_PRESETS.map((option) => (
                <option key={option.id} value={option.id}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          {preset === 'custom' && (
            <>
              <label className="purchase-context__field">
                From
                <input
                  type="date"
                  value={filters.get('from') ?? ''}
                  onChange={(event) => filters.set({ from: event.target.value })}
                />
              </label>
              <label className="purchase-context__field">
                To
                <input
                  type="date"
                  value={filters.get('to') ?? ''}
                  onChange={(event) => filters.set({ to: event.target.value })}
                />
              </label>
            </>
          )}

          <label className="purchase-context__field">
            Compare with
            <select value={filters.get('compare') ?? 'previous_period'} onChange={(event) => filters.set({ compare: event.target.value })}>
              <option value="previous_period">Previous period</option>
              <option value="none">No comparison</option>
            </select>
          </label>
        </>
      }
      filterControls={
        <>
          <DebouncedSearch value={filters.get('q') ?? ''} onCommit={(next) => filters.set({ q: next })} />

          <OptionSelect
            label="Supplier account"
            allLabel="All suppliers"
            value={filters.get('supplier_id') ?? ''}
            options={options.suppliers}
            loading={loading}
            onChange={(next) => filters.set({ supplier_id: next })}
          />

          <OptionSelect
            label="Material centre"
            allLabel="All centres"
            value={filters.get('warehouse_id') ?? ''}
            options={options.centres}
            loading={loading}
            onChange={(next) => filters.set({ warehouse_id: next })}
          />

          <div className="purchase-context__meta">
            {data && (
              <span className="purchase-muted">
                {data.period.label} · {data.scope.branch_label}
                {data.scope.reporting_currency === null && ' · mixed currencies'}
              </span>
            )}

            {filters.isNarrowed && (
              <button type="button" className="purchase-button purchase-button--quiet" onClick={filters.reset}>
                Clear filters
              </button>
            )}

            {canExport && data && (
              <a
                className="purchase-button purchase-button--secondary"
                href={exportUrl()}
                style={{ marginLeft: 'auto' }}
                // The export runs the same code as the screen, with the same
                // filters, so its totals cannot drift from what is displayed.
                download
              >
                <Download size={15} aria-hidden /> Export
              </a>
            )}
          </div>
        </>
      }
      sources={data?.sources ?? []}
      metrics={data?.metrics ?? []}
      loading={loading}
      refreshing={refreshing}
      fetchedAt={fetchedAt}
      onRefresh={refresh}
    >
      {error && (
        <div className="purchase-notice purchase-notice--danger">
          <div>
            <strong>Could not load this dashboard</strong>
            <p>{error}</p>
            {retryable && (
              <button type="button" className="purchase-button purchase-button--secondary" style={{ marginTop: 10 }} onClick={refresh}>
                Try again
              </button>
            )}
          </div>
        </div>
      )}

      {loading && !data && resolved === 'procurement' && <ProcurementSkeleton />}

      {loading && !data && resolved !== 'procurement' && (
        <div className="purchase-panel" style={{ padding: 20 }}>
          <div className="purchase-skeleton purchase-skeleton--row" style={{ width: '40%' }} />
          <div className="purchase-skeleton purchase-skeleton--row" />
          <div className="purchase-skeleton purchase-skeleton--row" style={{ width: '80%' }} />
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
