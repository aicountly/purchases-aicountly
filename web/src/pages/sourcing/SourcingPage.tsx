/**
 * Procurement → Supplier quotations → Sourcing.
 *
 * WHAT THIS SCREEN IS FOR. A buyer opens it to answer four questions in one
 * glance: what is out with suppliers, who has not answered, what is ready to
 * compare, and what is still on the table. Everything on it is counted from the
 * records themselves — there is no analytics table behind this page, and a
 * figure this product cannot honestly produce keeps its card and says what it
 * is waiting for rather than showing a zero.
 *
 * WHAT IT REUSES. The company scope, the session and `can()` from
 * PurchasesContext; the abortable `useApi`; the URL as the home of the filters,
 * the way every other list in this product does it; the existing RFQ editor and
 * detail screens, which are still the only places an enquiry is written.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  ChevronLeft,
  ChevronRight,
  Home,
  Plus,
  Send,
  Upload,
  X,
  LayoutTemplate,
} from 'lucide-react'
import { api, ApiError } from '../../services/api'
import type { CatalogSupplier } from '../../services/types'
import { usePurchases } from '../../context/PurchasesContext'
import { Notice } from '../../ui'
import { activeFilterCount, useRfqFilters, useRfqList, useSourcingSummary, type RfqFilters } from './useSourcing'
import { statusOf, toRfqView, type RfqView } from './model'
import { SourcingKpis } from './components/SourcingKpis'
import { AiSourcingBanner } from './components/AiSourcingBanner'
import { RfqStatusTabs, RFQ_PANEL_ID } from './components/RfqStatusTabs'
import { RfqFilterBar } from './components/RfqFilterBar'
import { AdvancedFilters } from './components/AdvancedFilters'
import { RfqTable, type RowAction } from './components/RfqTable'
import { ErrorState, FirstRunState, NoResultsState } from './components/RfqStates'
import { SourcingInsights } from './components/SourcingInsights'
import { SupplierDiscovery } from './components/SupplierDiscovery'
import { RfqBriefDrawer, briefToDraft, type RfqBrief } from './components/RfqBriefDrawer'
import './sourcing.css'

/**
 * Capabilities the backend does not have yet.
 *
 * Both are drawn because the workflow they belong to is real and people ask
 * for them — and both are inert, labelled, and impossible to mistake for
 * working, because there is no endpoint behind either. When one arrives, this
 * constant is the only thing that changes.
 */
const UNBUILT = {
  importRfqs: 'Importing enquiries from a spreadsheet is not built yet — there is no endpoint behind it.',
  templates: 'Saved RFQ templates are not built yet — there is no endpoint behind it.',
}

export function RfqList() {
  const navigate = useNavigate()
  const { can } = usePurchases()
  const { filters, update, clear } = useRfqFilters()

  const list = useRfqList(filters)
  const summary = useSourcingSummary()

  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [advancedOpen, setAdvancedOpen] = useState(false)
  const [discoveryOpen, setDiscoveryOpen] = useState(false)
  const [briefOpen, setBriefOpen] = useState(false)
  const [supplierName, setSupplierName] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; title: string; body?: string } | null>(null)

  /**
   * The page that is already on screen, kept while the next one is fetched.
   *
   * Without this, every keystroke, sort and page turn replaces the table with
   * skeletons — the screen flickers, the scroll position jumps, and the buyer
   * loses their place. The rows dim instead, and `aria-busy` says so.
   */
  const lastPage = useRef<{ rows: RfqView[]; total: number; counts: Record<string, number> } | null>(null)

  const page = useMemo(() => {
    if (list.data) {
      const counts = (list.data.meta.status_counts ?? {}) as Record<string, number>
      const next = {
        rows: list.data.data.map(toRfqView),
        total: list.data.meta.total,
        counts,
      }
      lastPage.current = next
      return next
    }
    return lastPage.current
  }, [list.data])

  // A selection is about the rows in front of you. Keeping it across a filter
  // change would mean acting on records that are no longer on screen.
  const filterKey = JSON.stringify(filters)
  useEffect(() => {
    setSelected(new Set())
  }, [filterKey])

  const rows = page?.rows ?? []
  const total = page?.total ?? 0
  const counts = page?.counts ?? {}
  const firstLoad = list.loading && lastPage.current === null
  const refreshing = list.loading && lastPage.current !== null

  const filtersOn = activeFilterCount(filters)
  const companyHasNone = (summary.data?.data.counts.total ?? null) === 0
  const canCreate = can('rfq.create')

  // `useApi` memoises `reload`, so depending on the two callbacks keeps this
  // stable — depending on `list` and `summary` would rebuild every row action
  // on every render.
  const reloadList = list.reload
  const reloadSummary = summary.reload
  const reloadEverything = useCallback(() => {
    reloadList()
    reloadSummary()
  }, [reloadList, reloadSummary])

  const setFilters = useCallback(
    (patch: Partial<RfqFilters>, options?: { replace?: boolean }) => update(patch, options),
    [update],
  )

  // -------------------------------------------------------------------------
  // Actions that write
  // -------------------------------------------------------------------------

  /**
   * Issue drafts to the suppliers already invited to them.
   *
   * The same endpoint the RFQ screen uses, called once per enquiry, with every
   * outcome reported. A bulk action that said "done" while three of eight
   * failed would be worse than no bulk action.
   */
  const issue = useCallback(
    async (targets: RfqView[]) => {
      if (targets.length === 0) return
      const plural = targets.length === 1 ? 'this RFQ' : `these ${targets.length} RFQs`
      if (!window.confirm(`Issue ${plural} to the suppliers already invited? Suppliers can quote from then on.`)) {
        return
      }

      setBusy(true)
      setMessage(null)

      const failures: string[] = []
      for (const target of targets) {
        try {
          await api.post(`v1/rfqs/${target.id}/issue`)
        } catch (error) {
          failures.push(`${target.number}: ${error instanceof ApiError ? error.message : String(error)}`)
        }
      }

      setBusy(false)
      setSelected(new Set())
      reloadEverything()

      setMessage(
        failures.length === 0
          ? {
              tone: 'success',
              title: targets.length === 1 ? `${targets[0].number} is with its suppliers.` : `${targets.length} RFQs issued.`,
            }
          : {
              tone: 'danger',
              title:
                failures.length === targets.length
                  ? 'Nothing was issued.'
                  : `${targets.length - failures.length} issued, ${failures.length} refused.`,
              body: failures.join(' · '),
            },
      )
    },
    [reloadEverything],
  )

  const selectedRows = useMemo(() => rows.filter((row) => selected.has(row.id)), [rows, selected])
  const issuable = useMemo(
    () => (canCreate ? selectedRows.filter((row) => row.status.value === 'DRAFT' && row.invited > 0) : []),
    [selectedRows, canCreate],
  )

  const actions: RowAction[] = useMemo(
    () => [
      {
        key: 'open',
        label: 'Open the RFQ',
        visible: () => true,
        onSelect: (row) => navigate(`/rfqs/${row.id}`),
      },
      {
        key: 'compare',
        label: 'Compare quotations',
        visible: () => true,
        disabled: (row) =>
          row.quotes === 0
            ? 'Nobody has quoted yet'
            : row.quotes === 1
              ? 'One quotation — nothing to compare it against'
              : null,
        onSelect: (row) => navigate(`/rfqs/${row.id}`),
      },
      {
        key: 'issue',
        label: 'Issue to suppliers',
        visible: (row) => canCreate && row.status.value === 'DRAFT',
        disabled: (row) => (row.invited === 0 ? 'Invite a supplier first' : null),
        onSelect: (row) => void issue([row]),
      },
      {
        key: 'invite',
        label: 'Invite another supplier',
        visible: (row) => canCreate && !['AWARDED', 'CLOSED', 'CANCELLED'].includes(row.status.value),
        onSelect: (row) => navigate(`/rfqs/${row.id}`),
      },
      {
        key: 'po',
        label: 'Raise the purchase order',
        visible: (row) => can('po.create') && row.awarded,
        onSelect: (row) => navigate(`/purchase-orders/new?rfq_id=${row.id}`),
      },
    ],
    [canCreate, can, issue, navigate],
  )

  // -------------------------------------------------------------------------
  // The chips that say what is being filtered
  // -------------------------------------------------------------------------

  const chips: { key: string; label: string; clear: () => void }[] = []
  if (filters.q) chips.push({ key: 'q', label: `“${filters.q}”`, clear: () => setFilters({ q: '' }) })
  if (filters.status) {
    chips.push({ key: 'status', label: statusOf(filters.status).label, clear: () => setFilters({ status: '' }) })
  }
  if (filters.from || filters.to) {
    chips.push({
      key: 'dates',
      label:
        filters.from && filters.to
          ? `Raised ${filters.from} to ${filters.to}`
          : filters.from
            ? `Raised from ${filters.from}`
            : `Raised until ${filters.to}`,
      clear: () => setFilters({ from: '', to: '' }),
    })
  }
  if (filters.supplierId !== null) {
    chips.push({
      key: 'supplier',
      label: supplierName ?? `Supplier account ${filters.supplierId}`,
      clear: () => {
        setSupplierName(null)
        setFilters({ supplierId: null })
      },
    })
  }
  if (filters.mine) chips.push({ key: 'mine', label: 'Raised by me', clear: () => setFilters({ mine: false }) })
  if (filters.quotes) {
    const said = {
      none: 'Nobody has quoted',
      any: 'At least one quotation',
      comparable: 'Two or more quotations',
    }[filters.quotes]
    chips.push({ key: 'quotes', label: said ?? filters.quotes, clear: () => setFilters({ quotes: '' }) })
  }
  if (filters.deadline) {
    const said = { overdue: 'Past its deadline', due_soon: 'Closing within seven days' }[filters.deadline]
    chips.push({ key: 'deadline', label: said ?? filters.deadline, clear: () => setFilters({ deadline: '' }) })
  }

  // -------------------------------------------------------------------------

  const pages = Math.max(1, Math.ceil(total / filters.size))
  const from = total === 0 ? 0 : (filters.page - 1) * filters.size + 1
  const to = Math.min(filters.page * filters.size, total)

  const pickSupplier = (supplier: CatalogSupplier | null) => {
    setSupplierName(supplier?.acc_name ?? null)
    setFilters({ supplierId: supplier?.acc_id ?? null })
  }

  return (
    <div className="sourcing-workspace">
      <nav className="sq-breadcrumb" aria-label="Breadcrumb">
        <Link to="/dashboard/overview" aria-label="Purchases home">
          <Home size={14} aria-hidden />
        </Link>
        <span aria-hidden>›</span>
        <Link to="/rfqs">Supplier quotations</Link>
        <span aria-hidden>›</span>
        <span aria-current="page">Sourcing</span>
      </nav>

      <header className="sq-header">
        <div className="sq-header__titles">
          <h1>Sourcing</h1>
          <p>
            Create, manage and compare supplier quotations to get the best price, quality and delivery terms.
          </p>
        </div>

        <div className="sq-header__actions">
          {/* Drawn because the workflow is real; inert because the endpoint is
              not. A button that appeared to work and quietly did nothing would
              be the worse of the two. */}
          <button type="button" className="sq-button sq-button--outline" disabled title={UNBUILT.importRfqs}>
            <Upload size={15} aria-hidden />
            Import RFQs
            <span className="sq-visually-hidden"> — {UNBUILT.importRfqs}</span>
          </button>
          <button type="button" className="sq-button sq-button--outline" disabled title={UNBUILT.templates}>
            <LayoutTemplate size={15} aria-hidden />
            Templates
            <span className="sq-visually-hidden"> — {UNBUILT.templates}</span>
          </button>
          {canCreate && (
            <button type="button" className="sq-button sq-button--primary" onClick={() => navigate('/rfqs/new')}>
              <Plus size={16} aria-hidden />
              New RFQ
            </button>
          )}
        </div>
      </header>

      {summary.error && (
        <Notice tone="warning" title="The figures above the list could not be loaded" onDismiss={summary.reload}>
          {summary.error} The enquiries themselves are unaffected.
        </Notice>
      )}

      {message && (
        <Notice tone={message.tone} title={message.title} onDismiss={() => setMessage(null)}>
          {message.body}
        </Notice>
      )}

      <SourcingKpis summary={summary.data?.data ?? null} loading={summary.loading} />

      <AiSourcingBanner
        summary={summary.data?.data ?? null}
        canCreate={canCreate}
        onDraft={() => setBriefOpen(true)}
        onFindSuppliers={() => setDiscoveryOpen(true)}
        onCompare={() => setFilters({ quotes: 'comparable', status: '' })}
        onAnalysePricing={() => navigate('/dashboard/ai-insights')}
        onPastPerformance={() => navigate('/dashboard/suppliers')}
      />

      <RfqStatusTabs
        status={filters.status}
        counts={counts}
        known={page !== null}
        onChange={(status) => setFilters({ status })}
      />

      <RfqFilterBar
        filters={filters}
        activeCount={filtersOn}
        onChange={setFilters}
        onOpenAdvanced={() => setAdvancedOpen(true)}
      />

      {chips.length > 0 && (
        <div className="sq-chips" aria-label="Filters in use">
          {chips.map((chip) => (
            <button key={chip.key} type="button" className="sq-chip sq-chip--clear" onClick={chip.clear}>
              {chip.label}
              <X size={12} aria-hidden />
              <span className="sq-visually-hidden">Remove this filter</span>
            </button>
          ))}
          <button type="button" className="sq-link-button" onClick={clear}>
            Clear all
          </button>
        </div>
      )}

      <section
        className={refreshing ? 'sq-panel is-refreshing' : 'sq-panel'}
        id={RFQ_PANEL_ID}
        role="region"
        aria-label="RFQs"
      >
        {selectedRows.length > 0 && (
          <div className="sq-selection" role="status">
            <strong>
              {selectedRows.length} selected
            </strong>
            {issuable.length > 0 && (
              <button
                type="button"
                className="sq-button sq-button--tiny sq-button--primary"
                disabled={busy}
                onClick={() => void issue(issuable)}
              >
                <Send size={13} aria-hidden />
                Issue {issuable.length} draft{issuable.length === 1 ? '' : 's'}
              </button>
            )}
            {issuable.length === 0 && (
              <span className="sq-selection__note">
                Nothing selected can be issued — an enquiry must be a draft with a supplier invited.
              </span>
            )}
            <button type="button" className="sq-link-button" onClick={() => setSelected(new Set())}>
              Clear selection
            </button>
          </div>
        )}

        {list.error ? (
          <ErrorState message={list.error} onRetry={list.reload} />
        ) : !firstLoad && rows.length === 0 && (companyHasNone || (filtersOn === 0 && total === 0)) ? (
          <FirstRunState
            canCreate={canCreate}
            onCreate={() => navigate('/rfqs/new')}
            onDraft={() => setBriefOpen(true)}
          />
        ) : !firstLoad && rows.length === 0 ? (
          <NoResultsState onClear={clear} />
        ) : (
          <>
            <RfqTable
              rows={rows}
              loading={firstLoad}
              refreshing={refreshing}
              selected={selected}
              pageSize={filters.size}
              actions={actions}
              onOpen={(row) => navigate(`/rfqs/${row.id}`)}
              onToggle={(id) =>
                setSelected((current) => {
                  const next = new Set(current)
                  if (next.has(id)) next.delete(id)
                  else next.add(id)
                  return next
                })
              }
              onToggleAll={(checked) => setSelected(checked ? new Set(rows.map((row) => row.id)) : new Set())}
            />

            <footer className="sq-pagination">
              <span className="sq-pagination__count">
                {total === 0 ? 'No RFQs' : `Showing ${from} to ${to} of ${total} RFQ${total === 1 ? '' : 's'}`}
              </span>

              <div className="sq-pagination__controls">
                <label className="sq-pagination__size">
                  <span>Show</span>
                  <select
                    value={filters.size}
                    onChange={(event) => setFilters({ size: Number.parseInt(event.target.value, 10) })}
                    aria-label="Rows per page"
                  >
                    {[10, 25, 50, 100].map((size) => (
                      <option key={size} value={size}>
                        {size}
                      </option>
                    ))}
                  </select>
                </label>

                <div className="sq-pager">
                  <button
                    type="button"
                    className="sq-pager__step"
                    disabled={filters.page <= 1}
                    onClick={() => setFilters({ page: filters.page - 1 })}
                    aria-label="Previous page"
                  >
                    <ChevronLeft size={15} aria-hidden />
                  </button>
                  <span className="sq-pager__where">
                    Page {filters.page} of {pages}
                  </span>
                  <button
                    type="button"
                    className="sq-pager__step"
                    disabled={filters.page >= pages}
                    onClick={() => setFilters({ page: filters.page + 1 })}
                    aria-label="Next page"
                  >
                    <ChevronRight size={15} aria-hidden />
                  </button>
                </div>
              </div>
            </footer>
          </>
        )}
      </section>

      <SourcingInsights
        summary={summary.data?.data ?? null}
        onShowComparable={() => setFilters({ quotes: 'comparable', status: '' })}
        onFindSuppliers={() => setDiscoveryOpen(true)}
      />

      <AdvancedFilters
        open={advancedOpen}
        filters={filters}
        supplierName={supplierName}
        onClose={() => setAdvancedOpen(false)}
        onChange={setFilters}
        onClear={() => {
          setSupplierName(null)
          clear()
        }}
        onSupplierPicked={pickSupplier}
      />

      <SupplierDiscovery
        open={discoveryOpen}
        onClose={() => setDiscoveryOpen(false)}
        onFilterBySupplier={(supplier) => {
          pickSupplier(supplier)
          setDiscoveryOpen(false)
        }}
      />

      <RfqBriefDrawer
        open={briefOpen}
        onClose={() => setBriefOpen(false)}
        onContinue={(brief: RfqBrief) => {
          setBriefOpen(false)
          // The editor is the only place an enquiry is written. The brief
          // arrives as navigation state, not as a saved record.
          navigate('/rfqs/new', { state: { draft: briefToDraft(brief) } })
        }}
      />
    </div>
  )
}
