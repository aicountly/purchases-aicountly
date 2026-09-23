/**
 * The dashboard container.
 *
 * One route, `/dashboard/:view`, that mounts exactly one of the five view
 * components. They are not all rendered with four hidden by CSS: four hidden
 * dashboards are four sets of requests nobody asked for, and a switcher that
 * does not change the URL is a switcher the Back button cannot undo.
 */

import { useEffect, useId, useRef, useState } from 'react'
import { Navigate, useNavigate, useParams } from 'react-router-dom'
import { Check, Download, FileText, Link2, MoreHorizontal, Search, X } from 'lucide-react'
import { usePurchases } from '../context/PurchasesContext'
import { api } from '../services/api'
import { PurchaseDashboardShell, type ShellFeature } from './shell'
import { DATE_PRESETS, isPurchaseView, useDashboardFilters, type DashboardFilters, type PurchaseViewId } from './filters'
import { useDashboard } from './useDashboard'
import { OverviewDashboard } from './views/Overview'
import { ProcurementDashboard } from './views/Procurement'
import { SuppliersDashboard } from './views/Suppliers'
import { AiInsightsDashboard } from './views/AiInsights'
import { PayablesPage } from './payables/PayablesPage'
import './purchase.css'

const TITLES: Record<PurchaseViewId, { title: string; subtitle: string; breadcrumb: string }> = {
  overview: {
    title: 'Purchase overview',
    subtitle: 'Your purchasing priorities, in one place.',
    breadcrumb: 'Purchase overview',
  },
  procurement: {
    title: 'Procurement workspace',
    subtitle: 'Move every request from requirement to receipt.',
    breadcrumb: 'Procurement workspace',
  },
  suppliers: {
    title: 'Supplier performance',
    subtitle: 'Understand reliability, cost and concentration.',
    breadcrumb: 'Supplier performance',
  },
  'bills-payables': {
    title: 'Bills & payables',
    subtitle: 'Match invoices and plan supplier payments.',
    breadcrumb: 'Bills & payables',
  },
  'ai-insights': {
    title: 'Purchase intelligence',
    subtitle: 'Understand spend, pricing, anomalies, savings and purchase risk.',
    breadcrumb: 'Purchase intelligence',
  },
}

/** Filters that mean the same thing on every dashboard follow the reader across. */
const KEPT_ACROSS_VIEWS = ['preset', 'from', 'to', 'compare', 'supplier_id', 'buyer', 'warehouse_id']

export default function PurchaseDashboards() {
  const { view } = useParams<{ view: string }>()
  const navigate = useNavigate()
  const filters = useDashboardFilters()
  const { can } = usePurchases()

  const resolved: PurchaseViewId = isPurchaseView(view) ? view : 'overview'
  const { data, loading, refreshing, error, retryable, fetchedAt, refresh } = useDashboard(resolved, filters.apiParams)

  // The document title follows the dashboard, so a browser history entry reads
  // as the screen it was.
  useEffect(() => {
    document.title = `${TITLES[resolved].title} · Aicountly Purchases`
  }, [resolved])

  // Hooks first, unconditionally: a render that returns early is still a
  // render, and one fewer hook call than the render before it is exactly what
  // React's rule against a hook behind a branch exists to prevent.
  const [exporting, setExporting] = useState<'csv' | 'pdf' | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)

  // Filters that only make sense on one dashboard do not follow it to the next
  // one; the period and the supplier do. Shared with the header's own switcher
  // below, so the two can never carry a different set across.
  const changeView = (next: PurchaseViewId) => {
    const kept = new URLSearchParams()
    for (const name of KEPT_ACROSS_VIEWS) {
      const value = filters.get(name)
      if (value !== null) kept.set(name, value)
    }
    const query = kept.toString()
    navigate(query === '' ? `/dashboard/${next}` : `/dashboard/${next}?${query}`)
  }

  if (view !== undefined && !isPurchaseView(view)) {
    return <Navigate to="/dashboard/overview" replace />
  }

  const preset = filters.get('preset') ?? 'this_month'
  const canExport = can('reports.view')

  // Bills & Payables draws its own furniture: a hero, its own filter bar and a
  // KPI strip laid out for payables rather than the five-dashboard shell. It
  // is rendered instead of that shell, not inside it, so the other four
  // dashboards keep exactly the chrome they had.
  if (resolved === 'bills-payables') {
    return (
      <PayablesPage
        data={data}
        filters={filters}
        loading={loading}
        refreshing={refreshing}
        error={error}
        retryable={retryable}
        fetchedAt={fetchedAt}
        onRefresh={refresh}
        onViewChange={changeView}
      />
    )
  }

  /**
   * Take the figures, in the format asked for.
   *
   * Fetched with the session key rather than linked to: this API authenticates
   * with a bearer token and a plain <a href> cannot carry one, so the link this
   * replaced was answering 401 and the click did nothing visible.
   */
  const take = async (format: 'csv' | 'pdf') => {
    setExporting(format)
    setExportError(null)
    try {
      await api.download(
        `v1/dashboards/${resolved}/export`,
        `purchases-${resolved}-${filters.get('preset') ?? 'period'}.${format}`,
        { ...filters.apiParams, format },
      )
    } catch (error) {
      setExportError(error instanceof Error ? error.message : 'That export could not be produced.')
    } finally {
      setExporting(null)
    }
  }

  // The green card is only offered where its arrow has somewhere real to go.
  // A decorative button that does nothing is worse than no button.
  const feature: ShellFeature | undefined =
    resolved === 'ai-insights'
      ? {
          title: 'Smarter purchase decisions',
          description: 'Evidence-backed insights for better buying.',
          actionLabel: 'Ask Aicountly AI about your purchases',
          onAction: () => filters.set({ ask: '1' }),
        }
      : resolved === 'suppliers'
        ? {
            title: 'Better supplier relationships',
            description: 'A stronger, more resilient supply chain.',
            actionLabel: 'Open the supplier list',
            onAction: () => navigate('/suppliers'),
          }
        : undefined

  return (
    <PurchaseDashboardShell
      activeView={resolved}
      title={TITLES[resolved].title}
      subtitle={TITLES[resolved].subtitle}
      breadcrumb={TITLES[resolved].breadcrumb}
      monitorNoun={resolved === 'ai-insights' ? 'AI monitoring' : 'Data'}
      feature={feature}
      onViewChange={changeView}
      actions={
        <>
          {canExport && data && (
            <button
              type="button"
              className="purchase-button purchase-button--primary"
              onClick={() => void take('csv')}
              disabled={exporting !== null}
            >
              <Download size={15} aria-hidden /> {exporting === 'csv' ? 'Exporting…' : 'Export'}
            </button>
          )}
          <OverflowMenu
            view={resolved}
            filters={filters}
            canExport={canExport && data !== null}
            exporting={exporting}
            onPdf={() => void take('pdf')}
          />
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

          {preset === 'custom' && (
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
                <input type="date" value={filters.get('to') ?? ''} onChange={(event) => filters.set({ to: event.target.value })} />
              </label>
            </>
          )}

          <label className="purchase-field">
            <span>Compare with</span>
            <select
              value={filters.get('compare') ?? 'previous_period'}
              onChange={(event) => filters.set({ compare: event.target.value })}
            >
              <option value="previous_period">Previous period</option>
              <option value="none">No comparison</option>
            </select>
          </label>

          <label className="purchase-field">
            <span>Supplier account</span>
            <input
              type="text"
              inputMode="numeric"
              defaultValue={filters.get('supplier_id') ?? ''}
              placeholder="All suppliers"
              onBlur={(event) => filters.set({ supplier_id: event.target.value })}
            />
          </label>

          <label className="purchase-field">
            <span>Material centre</span>
            <input
              type="text"
              inputMode="numeric"
              defaultValue={filters.get('warehouse_id') ?? ''}
              placeholder="All centres"
              onBlur={(event) => filters.set({ warehouse_id: event.target.value })}
            />
          </label>

          <label className="purchase-field purchase-field--search">
            <span>Search</span>
            <span className="purchase-searchbox">
              <Search size={15} aria-hidden />
              <input
                type="search"
                defaultValue={filters.get('q') ?? ''}
                placeholder="Order, supplier or invoice…"
                onBlur={(event) => filters.set({ q: event.target.value })}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') filters.set({ q: (event.target as HTMLInputElement).value })
                }}
              />
            </span>
          </label>

          {filters.isNarrowed && (
            <button type="button" className="purchase-button purchase-button--quiet purchase-field__clear" onClick={filters.reset}>
              <X size={14} aria-hidden /> Clear filters
            </button>
          )}
        </>
      }
      sources={data?.sources ?? []}
      metrics={data?.metrics ?? []}
      loading={loading}
      refreshing={refreshing}
      fetchedAt={fetchedAt}
      onRefresh={refresh}
    >
      {exportError !== null && (
        <div className="purchase-notice purchase-notice--danger" style={{ marginBottom: '1rem' }}>
          {exportError}
        </div>
      )}

      {data && (
        <p className="purchase-scope-line">
          {data.period.label} · {data.scope.branch_label}
          {data.scope.reporting_currency === null && ' · mixed currencies'}
          {data.period.comparison_mode === 'previous_period' && ` · ${data.period.comparison_label}`}
        </p>
      )}

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
      {/* bills-payables never reaches here: it returns via PayablesPage above,
          before this shell renders at all. */}
      {data && resolved === 'ai-insights' && <AiInsightsDashboard data={data} filters={filters} onRefresh={refresh} />}
    </PurchaseDashboardShell>
  )
}

/**
 * The overflow menu beside Export.
 *
 * Only the things that would otherwise crowd the header, and every one of them
 * does something: the PDF is the same export in another format, the link is
 * this screen's own URL with its filters, and clearing the filters is the
 * action the filter row offers when one is applied.
 */
function OverflowMenu({
  view,
  filters,
  canExport,
  exporting,
  onPdf,
}: {
  view: PurchaseViewId
  filters: DashboardFilters
  canExport: boolean
  exporting: 'csv' | 'pdf' | null
  onPdf: () => void
}) {
  const [open, setOpen] = useState(false)
  const [copied, setCopied] = useState(false)
  const wrapper = useRef<HTMLDivElement>(null)
  const menuId = useId()

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

  const copyLink = async () => {
    const query = filters.params.toString()
    const url = `${window.location.origin}/dashboard/${view}${query === '' ? '' : `?${query}`}`
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard access can be refused; the URL bar already holds the link.
      setCopied(false)
    }
  }

  return (
    <div className="purchase-overflow" ref={wrapper}>
      <button
        type="button"
        className="purchase-button purchase-button--secondary purchase-button--icon"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={menuId}
        aria-label="More actions"
        onClick={() => setOpen((was) => !was)}
      >
        <MoreHorizontal size={16} aria-hidden />
      </button>

      <div className="purchase-overflow__menu" id={menuId} role="menu" hidden={!open}>
        {canExport && (
          <button type="button" role="menuitem" onClick={onPdf} disabled={exporting !== null}>
            <FileText size={15} aria-hidden /> {exporting === 'pdf' ? 'Printing…' : 'Download as PDF'}
          </button>
        )}
        <button type="button" role="menuitem" onClick={() => void copyLink()}>
          {copied ? <Check size={15} aria-hidden /> : <Link2 size={15} aria-hidden />}
          {copied ? 'Link copied' : 'Copy link to this view'}
        </button>
        {filters.isNarrowed && (
          <button
            type="button"
            role="menuitem"
            onClick={() => {
              filters.reset()
              setOpen(false)
            }}
          >
            <X size={15} aria-hidden /> Clear filters
          </button>
        )}
      </div>
    </div>
  )
}
