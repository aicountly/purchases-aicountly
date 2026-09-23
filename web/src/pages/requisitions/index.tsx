/**
 * Procurement → Requisitions.
 *
 * The list screen, rebuilt as the place a buyer starts their day rather than a
 * table with a dropdown over it. Four things are new and each answers a real
 * question somebody had to leave the page to answer: how much is waiting, what
 * is stuck, which of these is mine, and where has this one got to in approval.
 *
 * THREE RULES THIS SCREEN KEEPS.
 *
 *  1. Every figure is counted by the server over everything the filters match.
 *     Nothing above the table is derived from the page beneath it, because a
 *     total that changes when you turn the page is not a total.
 *  2. Nothing is invented. A trend with no previous month, a name this product
 *     was never given, an approval nobody granted — each of those draws a dash
 *     and says why, and none of them draws a zero.
 *  3. The URL is the state. A filtered list is a link somebody can send, a
 *     dashboard drill-down arrives filtered, and Back undoes one choice.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ArrowRight,
  CircleCheck,
  CircleX,
  ClipboardList,
  Copy,
  Download,
  Eye,
  FileSearch,
  Plus,
  Send,
  SearchX,
  Upload,
} from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { requisitions as service } from '../../services/requisitions'
import type { RequisitionRow, RequisitionSummary } from '../../services/types'
import { useApi } from '../../hooks/useApi'
import { useFinancialYear } from '../../hooks/useFinancialYear'
import { usePurchases } from '../../context/PurchasesContext'
import { AiInsight } from './AiInsight'
import { FilterBar } from './FilterBar'
import { PAGE_SIZES, toQuery, useRequisitionFilters, type RequisitionFilters } from './filters'
import { allClearInsight, buildInsights, type Insight } from './insights'
import { StatusTabs } from './StatusTabs'
import { SummaryCards } from './SummaryCards'
import { RequisitionTable } from './RequisitionTable'
import type { Bucket } from './model'
import { ConfirmDialog, EmptyState, ErrorState, TableSkeleton, Toasts, useToasts, type MenuAction } from './ui'
import './requisitions.css'

/** What a row action is about to do, once it has been confirmed. */
interface PendingAction {
  kind: 'submit' | 'approve' | 'reject'
  rows: RequisitionRow[]
}

export function RequisitionsList() {
  const navigate = useNavigate()
  const { scope, session, can } = usePurchases()
  const { filters, update, reset, activeCount } = useRequisitionFilters()
  const financialYear = useFinancialYear(scope)
  const { toasts, push, dismiss } = useToasts()

  const searchBox = useRef<HTMLInputElement>(null)
  const [search, setSearch] = useState(filters.q)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [pending, setPending] = useState<PendingAction | null>(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [exporting, setExporting] = useState(false)

  /*
   * The box leads the filter by a third of a second.
   *
   * Every keystroke is a request without this, and the answers arrive out of
   * order — `useApi` aborts the one it replaces, so the list would flicker
   * through four wrong answers on the way to the right one. Replacing the
   * history entry rather than pushing one means Back leaves the search rather
   * than walking backwards through it one letter at a time.
   */
  useEffect(() => {
    if (search === filters.q) return
    const timer = window.setTimeout(() => update({ q: search }, { replace: true }), 300)
    return () => window.clearTimeout(timer)
  }, [search, filters.q, update])

  // A filter cleared from a chip or from a link has to reach the box too.
  useEffect(() => setSearch(filters.q), [filters.q])

  // Ctrl/Cmd + K, the shortcut every list in every product this user has open
  // answers to. Not bound when a dialog is up: the dialog owns the keyboard.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'k' || !(event.metaKey || event.ctrlKey)) return
      event.preventDefault()
      searchBox.current?.focus()
      searchBox.current?.select()
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [])

  const query = useMemo(() => toQuery(filters, session?.uuid), [filters, session?.uuid])

  const list = useApi(
    (signal) => service.list(query, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, JSON.stringify(query)],
    Boolean(scope),
  )

  /*
   * The figures, fetched separately and deliberately.
   *
   * They do not change when the tab changes — the tab counts come from this
   * call — so the status is left out of its key and switching tabs re-fetches
   * one page of rows rather than everything on the screen.
   */
  const { status: _tab, page: _page, pageSize: _size, ...summaryKey } = query
  const summary = useApi(
    (signal) => service.summary(query, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, JSON.stringify(summaryKey)],
    Boolean(scope),
  )

  const rows = list.data?.data ?? []
  const total = list.data?.meta.total ?? 0
  const figures: RequisitionSummary | null = summary.data?.data ?? null

  // Selecting rows and then filtering them away would leave a bulk bar acting
  // on records nobody can see.
  useEffect(() => setSelected(new Set()), [JSON.stringify(query)])

  const counts = useMemo<Record<Bucket, number> | null>(() => {
    if (!figures) return null
    return {
      all: figures.totals.total,
      draft: figures.totals.draft,
      pending: figures.totals.pending,
      approved: figures.totals.approved,
      rejected: figures.totals.rejected,
    }
  }, [figures])

  const insights = useMemo<Insight[]>(() => {
    if (!figures) return []
    const built = buildInsights(figures.signals, figures.today.date)
    // Nothing to report is worth reporting, but only to a company that has
    // requisitions. An empty product gets the empty state, not a headline.
    if (built.length === 0 && figures.totals.total > 0) return [allClearInsight(figures.totals.pending)]
    return built
  }, [figures])

  const applyInsight = useCallback(
    (insight: Insight) => {
      update({
        bucket: (insight.filter.status as Bucket) ?? 'all',
        department: insight.filter.department ?? '',
        minValue: insight.filter.minValue ?? '',
        requiredByBefore: insight.filter.requiredByBefore ?? '',
      })
    },
    [update],
  )

  const onFilterChange = useCallback(
    (patch: Partial<RequisitionFilters>) => {
      if (patch.q !== undefined) setSearch(patch.q)
      update(patch)
    },
    [update],
  )

  // ---------------------------------------------------------------------
  // Actions
  // ---------------------------------------------------------------------

  /**
   * What this user may do to this requisition, per this product's own rules.
   *
   * Hiding an action nobody may take is a courtesy — the API enforces every one
   * of these again and would refuse the call. What it buys is a menu with three
   * things in it that all work, instead of eight where five answer 403.
   *
   * Segregation of duties is the one rule repeated here rather than left to the
   * server: the person who raised a requisition may not approve it, and showing
   * them the button teaches them to press it and be told off.
   */
  const actionsFor = useCallback(
    (row: RequisitionRow): MenuAction[] => {
      const actions: MenuAction[] = [
        {
          label: 'Open',
          icon: <Eye size={14} aria-hidden />,
          onSelect: () => navigate(`/requisitions/${row.requisition_id}`),
        },
      ]

      if (row.status === 'DRAFT' && can('requisition.create')) {
        actions.push({
          label: 'Submit for approval',
          icon: <Send size={14} aria-hidden />,
          onSelect: () => setPending({ kind: 'submit', rows: [row] }),
        })
      }

      if (row.status === 'APPROVAL_PENDING' && can('requisition.approve')) {
        const mineAndNotOwner = row.is_mine && !session?.is_owner
        actions.push({
          label: 'Approve',
          icon: <CircleCheck size={14} aria-hidden />,
          disabled: mineAndNotOwner,
          title: mineAndNotOwner ? 'You raised this one, so somebody else has to approve it.' : undefined,
          onSelect: () => setPending({ kind: 'approve', rows: [row] }),
        })
        actions.push({
          label: 'Reject',
          icon: <CircleX size={14} aria-hidden />,
          danger: true,
          disabled: mineAndNotOwner,
          title: mineAndNotOwner ? 'You raised this one, so somebody else has to decide it.' : undefined,
          onSelect: () => setPending({ kind: 'reject', rows: [row] }),
        })
      }

      if (row.status === 'APPROVED' && can('rfq.create')) {
        actions.push({
          label: 'Raise an RFQ',
          icon: <FileSearch size={14} aria-hidden />,
          separatorBefore: true,
          onSelect: () => navigate(`/rfqs/new?requisition_id=${row.requisition_id}`),
        })
      }

      actions.push({
        label: 'Copy number',
        icon: <Copy size={14} aria-hidden />,
        separatorBefore: true,
        onSelect: () => {
          navigator.clipboard
            ?.writeText(row.requisition_no)
            .then(() => push('success', `${row.requisition_no} copied.`))
            .catch(() => push('danger', 'Could not copy that.', 'Your browser refused clipboard access.'))
        },
      })

      return actions
    },
    [can, navigate, push, session?.is_owner],
  )

  /**
   * Run the confirmed action, one record at a time, and report honestly.
   *
   * Sequential rather than parallel: each of these writes an approval row and
   * an audit entry, and ten at once is ten transactions racing for the same
   * rows. Each is reported separately too — "4 of 5 approved" with the reason
   * the fifth was refused is the truth, and a single "Done" would not be.
   */
  async function run() {
    if (!pending) return
    setBusy(true)

    const path = pending.kind === 'submit' ? 'submit' : pending.kind
    const body = pending.kind === 'reject' ? { note: note.trim() } : { note: note.trim() || undefined }
    const failures: string[] = []
    let done = 0

    for (const row of pending.rows) {
      try {
        await api.post(`v1/requisitions/${row.requisition_id}/${path}`, body)
        done++
      } catch (error) {
        failures.push(`${row.requisition_no}: ${error instanceof ApiError ? error.message : String(error)}`)
      }
    }

    setBusy(false)
    setPending(null)
    setNote('')
    setSelected(new Set())

    const verb = pending.kind === 'submit' ? 'submitted' : pending.kind === 'approve' ? 'approved' : 'rejected'
    if (done > 0) push('success', `${done} requisition${done === 1 ? '' : 's'} ${verb}.`)
    if (failures.length > 0) {
      push('danger', `${failures.length} could not be ${verb}.`, failures.slice(0, 3).join(' · '))
    }

    list.reload()
    summary.reload()
  }

  async function exportCsv() {
    setExporting(true)
    try {
      await service.export(query)
      push('success', 'Export downloaded.', 'The same rows and the same order as the table.')
    } catch (error) {
      push('danger', 'That export could not be produced.', error instanceof ApiError ? error.message : undefined)
    } finally {
      setExporting(false)
    }
  }

  // ---------------------------------------------------------------------
  // Selection
  // ---------------------------------------------------------------------

  const toggle = useCallback((id: number, checked: boolean) => {
    setSelected((was) => {
      const next = new Set(was)
      if (checked) next.add(id)
      else next.delete(id)
      return next
    })
  }, [])

  const toggleAll = useCallback(
    (checked: boolean) => setSelected(checked ? new Set(rows.map((row) => row.requisition_id)) : new Set()),
    [rows],
  )

  const chosen = useMemo(() => rows.filter((row) => selected.has(row.requisition_id)), [rows, selected])
  const submittable = chosen.filter((row) => row.status === 'DRAFT')
  const decidable = chosen.filter((row) => row.status === 'APPROVAL_PENDING' && (!row.is_mine || session?.is_owner))

  // ---------------------------------------------------------------------
  // Paging
  // ---------------------------------------------------------------------

  const pageCount = Math.max(1, Math.ceil(total / filters.pageSize))
  const firstOnPage = total === 0 ? 0 : (filters.page - 1) * filters.pageSize + 1
  const lastOnPage = Math.min(total, filters.page * filters.pageSize)

  // A window of five around the current page. A company with 4,000
  // requisitions has 400 pages, and 400 buttons is not a paginator.
  const pages = useMemo(() => {
    const start = Math.max(1, Math.min(filters.page - 2, pageCount - 4))
    return Array.from({ length: Math.min(5, pageCount) }, (_, index) => start + index)
  }, [filters.page, pageCount])

  const filtered = activeCount > 0 || filters.q !== '' || filters.bucket !== 'all' || filters.status !== ''
  const showingEmpty = !list.loading && !list.error && rows.length === 0

  return (
    <div className="requisitions-workspace">
      <header className="rq-header">
        <div className="rq-header__title">
          <span className="rq-header__icon" aria-hidden="true">
            <ClipboardList size={22} />
          </span>
          <div>
            <h1>Requisitions</h1>
            <p>Create, track and get approvals for your purchase requisitions.</p>
          </div>
        </div>

        <div className="rq-header__actions">
          {/* Honest about what it cannot do yet. This product reads uploaded
              documents (`v1/import/preview`) but has no endpoint that turns one
              into requisitions, and a button that opened a file picker and then
              did nothing would be worse than one that says so. */}
          <button
            type="button"
            className="rq-btn rq-btn--secondary"
            disabled
            title="Bulk import of requisitions is not available in Purchases yet."
          >
            <Upload size={15} aria-hidden /> Import
          </button>

          <button type="button" className="rq-btn rq-btn--secondary" onClick={exportCsv} disabled={exporting || total === 0}>
            <Download size={15} aria-hidden /> {exporting ? 'Exporting…' : 'Export'}
          </button>

          {can('requisition.create') && (
            <button type="button" className="rq-btn rq-btn--primary" onClick={() => navigate('/requisitions/new')}>
              <Plus size={16} aria-hidden /> New requisition
            </button>
          )}
        </div>
      </header>

      {/* The figures fail on their own. A summary that could not be counted
          must not take the list of requisitions down with it. */}
      <SummaryCards
        summary={figures}
        loading={summary.loading && figures === null}
        bucket={filters.bucket}
        onPick={(bucket) => update({ bucket: bucket as Bucket })}
      />

      <AiInsight insights={insights} onApply={applyInsight} />

      <FilterBar
        filters={filters}
        search={search}
        onSearch={setSearch}
        onChange={onFilterChange}
        onReset={reset}
        departments={figures?.departments ?? []}
        financialYear={financialYear}
        activeCount={activeCount}
        searchRef={searchBox}
      />

      <StatusTabs active={filters.bucket} counts={counts} onChange={(bucket) => update({ bucket })} />

      {chosen.length > 0 && (
        <div className="rq-bulk" role="status">
          <span>
            <strong>{chosen.length}</strong> selected on this page
          </span>
          <div className="rq-bulk__actions">
            {can('requisition.create') && submittable.length > 0 && (
              <button
                type="button"
                className="rq-btn rq-btn--secondary rq-btn--sm"
                onClick={() => setPending({ kind: 'submit', rows: submittable })}
              >
                <Send size={14} aria-hidden /> Submit {submittable.length}
              </button>
            )}
            {can('requisition.approve') && decidable.length > 0 && (
              <button
                type="button"
                className="rq-btn rq-btn--primary rq-btn--sm"
                onClick={() => setPending({ kind: 'approve', rows: decidable })}
              >
                <CircleCheck size={14} aria-hidden /> Approve {decidable.length}
              </button>
            )}
            <button type="button" className="rq-btn rq-btn--quiet" onClick={() => setSelected(new Set())}>
              Clear selection
            </button>
          </div>
        </div>
      )}

      <section className="rq-table-card" id="rq-table-panel" role="tabpanel" aria-label="Requisitions">
        {list.loading && <TableSkeleton rows={filters.pageSize > 10 ? 10 : filters.pageSize} />}

        {!list.loading && list.error && (
          <ErrorState what="requisitions" message={list.error} onRetry={list.reload} />
        )}

        {showingEmpty && filtered && (
          <EmptyState
            icon={<SearchX size={26} />}
            title="No requisitions match these filters"
            actions={
              <button type="button" className="rq-btn rq-btn--secondary" onClick={reset}>
                Clear filters
              </button>
            }
          >
            Try changing your filters or search terms.
          </EmptyState>
        )}

        {showingEmpty && !filtered && (
          <EmptyState
            icon={<ClipboardList size={26} />}
            title="No requisitions yet"
            actions={
              can('requisition.create') ? (
                <>
                  <button type="button" className="rq-btn rq-btn--primary" onClick={() => navigate('/requisitions/new')}>
                    <Plus size={15} aria-hidden /> New requisition
                  </button>
                  {/* Real, and already built: the editor can fill itself from
                      Inventory's own replenishment report. It is the nearest
                      thing this product has to an import, and it works. */}
                  <button
                    type="button"
                    className="rq-btn rq-btn--secondary"
                    onClick={() => navigate('/requisitions/new?suggest=1')}
                  >
                    Start from Inventory&rsquo;s reorder list <ArrowRight size={14} aria-hidden />
                  </button>
                </>
              ) : undefined
            }
            note="A requisition is how somebody asks for something. Approve it and it becomes an RFQ or a purchase order."
          >
            Create your first purchase requisition to begin the procurement and approval workflow.
          </EmptyState>
        )}

        {!list.loading && !list.error && rows.length > 0 && (
          <>
            <RequisitionTable
              rows={rows}
              meLabel={session?.display_name ?? 'You'}
              selected={selected}
              onSelect={toggle}
              onSelectAll={toggleAll}
              actionsFor={actionsFor}
            />

            <footer className="rq-table-foot">
              <span>
                Showing {firstOnPage}–{lastOnPage} of {total} requisition{total === 1 ? '' : 's'}
              </span>

              <div className="rq-pager">
                <button
                  type="button"
                  onClick={() => update({ page: filters.page - 1 })}
                  disabled={filters.page <= 1}
                  aria-label="Previous page"
                >
                  ‹
                </button>
                {pages.map((page) => (
                  <button
                    key={page}
                    type="button"
                    className={page === filters.page ? 'is-active' : undefined}
                    aria-current={page === filters.page ? 'page' : undefined}
                    onClick={() => update({ page })}
                  >
                    {page}
                  </button>
                ))}
                <button
                  type="button"
                  onClick={() => update({ page: filters.page + 1 })}
                  disabled={filters.page >= pageCount}
                  aria-label="Next page"
                >
                  ›
                </button>

                <select
                  aria-label="Requisitions per page"
                  value={filters.pageSize}
                  onChange={(event) => update({ pageSize: Number(event.target.value), page: 1 })}
                >
                  {PAGE_SIZES.map((size) => (
                    <option key={size} value={size}>
                      {size} / page
                    </option>
                  ))}
                </select>
              </div>
            </footer>
          </>
        )}
      </section>

      {pending && (
        <ConfirmDialog
          title={
            pending.kind === 'submit'
              ? `Submit ${pending.rows.length} requisition${pending.rows.length === 1 ? '' : 's'} for approval?`
              : pending.kind === 'approve'
                ? `Approve ${pending.rows.length} requisition${pending.rows.length === 1 ? '' : 's'}?`
                : `Reject ${pending.rows.length} requisition${pending.rows.length === 1 ? '' : 's'}?`
          }
          confirmLabel={pending.kind === 'submit' ? 'Submit' : pending.kind === 'approve' ? 'Approve' : 'Reject'}
          tone={pending.kind === 'reject' ? 'danger' : 'primary'}
          busy={busy}
          // The API refuses a rejection with no reason, so the dialog asks for
          // one rather than letting the click fail.
          disabled={pending.kind === 'reject' && note.trim() === ''}
          onCancel={() => {
            setPending(null)
            setNote('')
          }}
          onConfirm={run}
        >
          <p className="rq-dialog__list">{pending.rows.map((row) => row.requisition_no).join(', ')}</p>
          {pending.kind === 'submit' ? (
            <p>
              Anything above this company&rsquo;s approval threshold, or carrying a routing exception, goes to an
              approver. Everything else is approved on submission.
            </p>
          ) : (
            <label className="rq-field">
              <span>{pending.kind === 'reject' ? 'Why is this being rejected?' : 'Note (optional)'}</span>
              <textarea
                value={note}
                onChange={(event) => setNote(event.target.value)}
                rows={3}
                placeholder={pending.kind === 'reject' ? 'The requester reads this.' : 'Anything the requester should know.'}
              />
            </label>
          )}
        </ConfirmDialog>
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  )
}

export { RequisitionDetail } from './detail'
export { RequisitionEditor } from './editor'
