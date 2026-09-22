/**
 * The dashboard container.
 *
 * One route, `/dashboard/:view`, that mounts exactly one of the five view
 * components. They are not all rendered with four hidden by CSS: four hidden
 * dashboards are four sets of requests nobody asked for, and a switcher that
 * does not change the URL is a switcher the Back button cannot undo.
 */

import { useEffect } from 'react'
import { Navigate, useNavigate, useParams } from 'react-router-dom'
import { Download } from 'lucide-react'
import { usePurchases } from '../context/PurchasesContext'
import { getApiBaseUrl } from '../config'
import { PurchaseDashboardShell } from './shell'
import { DATE_PRESETS, isPurchaseView, useDashboardFilters, type PurchaseViewId } from './filters'
import { useDashboard } from './useDashboard'
import { OverviewDashboard } from './views/Overview'
import { ProcurementDashboard } from './views/Procurement'
import { SuppliersDashboard } from './views/Suppliers'
import { BillsPayablesDashboard } from './views/BillsPayables'
import { AiInsightsDashboard } from './views/AiInsights'
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
      headerControls={
        <>
          <label className="purchase-header-period">
            <span className="purchase-sr-only">Period</span>
            <select value={preset} onChange={(event) => filters.set({ preset: event.target.value })}>
              {DATE_PRESETS.map((option) => (
                <option key={option.id} value={option.id}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          {canExport && data && (
            <a
              className="purchase-button purchase-button--secondary"
              href={exportUrl()}
              // The export runs the same code as the screen, with the same
              // filters, so its totals cannot drift from what is displayed.
              download
            >
              <Download size={15} aria-hidden /> Export
            </a>
          )}
        </>
      }
      contextControls={
        <>
          {preset === 'custom' && (
            <>
              <label>
                From
                <input
                  type="date"
                  value={filters.get('from') ?? ''}
                  onChange={(event) => filters.set({ from: event.target.value })}
                />
              </label>
              <label>
                To
                <input
                  type="date"
                  value={filters.get('to') ?? ''}
                  onChange={(event) => filters.set({ to: event.target.value })}
                />
              </label>
            </>
          )}

          <label>
            Compare with
            <select value={filters.get('compare') ?? 'previous_period'} onChange={(event) => filters.set({ compare: event.target.value })}>
              <option value="previous_period">Previous period</option>
              <option value="none">No comparison</option>
            </select>
          </label>

          {data && (
            <span className="purchase-muted" style={{ fontSize: 12 }}>
              {data.period.label} · {data.scope.branch_label}
              {data.scope.reporting_currency === null && ' · mixed currencies'}
            </span>
          )}
        </>
      }
      filterControls={
        <>
          <label>
            Search
            <input
              type="search"
              defaultValue={filters.get('q') ?? ''}
              placeholder="Order, supplier or invoice"
              onBlur={(event) => filters.set({ q: event.target.value })}
              onKeyDown={(event) => {
                if (event.key === 'Enter') filters.set({ q: (event.target as HTMLInputElement).value })
              }}
            />
          </label>

          <label>
            Supplier account
            <input
              type="text"
              inputMode="numeric"
              defaultValue={filters.get('supplier_id') ?? ''}
              placeholder="All suppliers"
              onBlur={(event) => filters.set({ supplier_id: event.target.value })}
            />
          </label>

          <label>
            Material centre
            <input
              type="text"
              inputMode="numeric"
              defaultValue={filters.get('warehouse_id') ?? ''}
              placeholder="All centres"
              onBlur={(event) => filters.set({ warehouse_id: event.target.value })}
            />
          </label>

          {filters.isNarrowed && (
            <button type="button" className="purchase-button purchase-button--quiet" onClick={filters.reset}>
              Clear filters
            </button>
          )}

        </>
      }
      filtersApplied={
        filters.get('q') !== null ||
        filters.get('supplier_id') !== null ||
        filters.get('warehouse_id') !== null ||
        filters.get('buyer') !== null
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

      {loading && !data && (
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
