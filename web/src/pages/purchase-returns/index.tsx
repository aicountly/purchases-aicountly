/**
 * Purchase processing → Returns.
 *
 * WHAT THIS SCREEN IS. Purchases decides whether goods go back and on what
 * terms. Inventory moves the stock out; Smart Books raises the debit note; the
 * supplier issues their own credit note. This screen is where those four facts
 * about one event are seen together — and it holds a copy of none of them. Two
 * references are stored, and what those documents say is read from the products
 * that own them at the moment somebody looks.
 *
 * WHAT IS ON IT. Four figures over the filtered period, a trend, a reason
 * split, rules-based insights, then the register in whichever of three shapes
 * suits the question. Every one of them is drawn from ONE set of filters, which
 * lives in the URL, so nothing on the page can quietly be describing a
 * different period from anything else on it — and a narrowed register is a link
 * somebody can send.
 *
 * WHAT FAILS SEPARATELY. The analytics, the register and the live cross-app
 * status are three requests and three failures. A summary that cannot be
 * computed must not take the register with it, because the register is the part
 * somebody came here to work in.
 */

import { useCallback, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ChevronLeft,
  ChevronRight,
  Download,
  MoreVertical,
  Plus,
  Printer,
  RefreshCw,
  RotateCcw,
  Stamp,
  Undo2,
} from 'lucide-react'
import { ApiError } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import { useApi } from '../../hooks/useApi'
import { AnalyticsBand } from './AnalyticsBand'
import { CalendarView } from './CalendarView'
import { FilterBar, FilterChips } from './FilterBar'
import { FollowUpDrawer } from './FollowUpDrawer'
import { ImportDrawer } from './ImportDrawer'
import { KanbanView } from './KanbanView'
import { KpiRow } from './KpiRow'
import { MoreFilters } from './MoreFilters'
import { BulkBar, RegisterTable } from './RegisterTable'
import { ReturnDetailBody, ReturnDetailHeading } from './ReturnDetail'
import { NewReturnDrawer } from './NewReturnDrawer'
import { purchaseReturnsApi } from './api'
import { fromInsight, PAGE_SIZE, useWorkspaceState } from './filters'
import type { PurchaseReturnDetail, PurchaseReturnRow, ReturnInsight } from './types'
import { useReturnsWorkspace } from './useReturnsWorkspace'
import {
  Drawer,
  EmptyState,
  ErrorState,
  Notice,
  Popover,
  SkeletonRows,
  StatusPill,
  Toasts,
  useToasts,
} from './ui'
import './returns.css'

type Dialog =
  | { kind: 'cancel'; row: PurchaseReturnRow }
  | { kind: 'credit'; row: PurchaseReturnRow }
  | null

export default function PurchaseReturnsWorkspace() {
  const { can, session } = usePurchases()
  const { state, isFiltered, advancedCount, setFilters, setView, setPage, setSort, open, reset } = useWorkspaceState()
  const { toasts, push, dismiss } = useToasts()

  // The calendar and the board lay the whole period out; the list pages.
  const limit = state.view === 'list' ? PAGE_SIZE : 200
  const data = useReturnsWorkspace(state.filters, state.page, state.sort, state.order, limit)

  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [dialog, setDialog] = useState<Dialog>(null)
  const [moreFilters, setMoreFilters] = useState(false)
  const [importing, setImporting] = useState(false)
  const [creating, setCreating] = useState<{ seed: PurchaseReturnRow | null } | null>(null)
  const [followUp, setFollowUp] = useState(false)
  const [busy, setBusy] = useState(false)
  const [printOnOpen, setPrintOnOpen] = useState(false)
  // The supplier filter is an id in the URL; its name comes from the picker or
  // from a row, so the chip reads as a supplier rather than as a number.
  const [supplierLabel, setSupplierLabel] = useState<string | null>(null)

  const rows = data.register.data?.data ?? []
  const total = data.register.data?.meta.total ?? 0
  const pages = Math.max(1, Math.ceil(total / PAGE_SIZE))

  const permissions = useMemo(
    () => ({
      create: data.options?.can.create ?? can('return.create'),
      approve: data.options?.can.approve ?? can('return.approve'),
      export: data.options?.can.export ?? can('reports.view'),
    }),
    [data.options, can],
  )

  // The supplier name for the chip, picked up from the rows when the filter
  // arrived as a bare id in a link.
  const resolvedSupplier = useMemo(() => {
    if (state.filters.supplier_id === '') return null
    if (supplierLabel) return supplierLabel
    const match = rows.find((row) => String(row.supplier_account_id) === state.filters.supplier_id)

    return match?.supplier_name ?? null
  }, [state.filters.supplier_id, supplierLabel, rows])

  /**
   * One write, then reload what it changed.
   *
   * The server's own refusal is what the user sees: "approve the return before
   * sending the goods back" is a better sentence than anything this file could
   * write about it.
   */
  const act = useCallback(
    async (what: string, run: () => Promise<unknown>) => {
      setBusy(true)
      try {
        await run()
        data.reloadAll()
        push('success', what)

        return true
      } catch (failure) {
        push('danger', `${what} did not work`, failure instanceof ApiError ? failure.message : String(failure))

        return false
      } finally {
        setBusy(false)
      }
    },
    [data, push],
  )

  const handlers = useMemo(
    () => ({
      onOpen: (row: PurchaseReturnRow) => open(row.return_id),
      onApprove: (row: PurchaseReturnRow) =>
        void act(`${row.return_no} approved`, () => purchaseReturnsApi.approve(row.return_id)),
      onDispatch: (row: PurchaseReturnRow) =>
        void act(`${row.return_no} sent back through Inventory`, () => purchaseReturnsApi.dispatch(row.return_id)),
      onDebitNote: (row: PurchaseReturnRow) =>
        void act(`Debit note raised for ${row.return_no}`, () => purchaseReturnsApi.debitNote(row.return_id)),
      onCancel: (row: PurchaseReturnRow) => setDialog({ kind: 'cancel', row }),
      onSupplierCredit: (row: PurchaseReturnRow) => setDialog({ kind: 'credit', row }),
      onDuplicate: (row: PurchaseReturnRow) => setCreating({ seed: row }),
      onPrint: (row: PurchaseReturnRow) => {
        setPrintOnOpen(true)
        open(row.return_id)
      },
    }),
    [act, open],
  )

  const approvable = rows.filter((row) => selected.has(row.return_id) && row.status === 'DRAFT')

  async function approveSelected() {
    setBusy(true)
    let done = 0
    const failures: string[] = []

    // Sequential rather than parallel: each approval is its own audited
    // decision, and twenty at once against one connection buys nothing.
    for (const row of approvable) {
      try {
        await purchaseReturnsApi.approve(row.return_id)
        done++
      } catch (failure) {
        failures.push(`${row.return_no}: ${failure instanceof ApiError ? failure.message : String(failure)}`)
      }
    }

    setBusy(false)
    setSelected(new Set())
    data.reloadAll()

    if (done > 0) push('success', `${done} return${done === 1 ? '' : 's'} approved`)
    if (failures.length > 0) push('danger', `${failures.length} could not be approved`, failures.join(' · '))
  }

  function applyInsight(insight: ReturnInsight) {
    setFilters(fromInsight(insight.filters))
  }

  const anchorMonth = (state.filters.to || state.filters.from || new Date().toISOString().slice(0, 10)).slice(0, 7)

  return (
    <main className="purchase-returns-workspace">
      <header className="pr-header">
        <div className="pr-title-wrap">
          <span className="pr-title-icon" aria-hidden="true">
            <Undo2 size={22} />
          </span>
          <div style={{ minWidth: 0 }}>
            <h1 className="pr-heading">Purchase returns</h1>
            <p className="pr-subtitle">
              Manage returned goods to suppliers, track financial adjustments and keep inventory and accounts in sync.
            </p>
          </div>
        </div>

        <div className="pr-header-actions">
          {permissions.create && (
            <button type="button" className="pr-btn" onClick={() => setImporting(true)}>
              <Download size={15} aria-hidden /> Import
            </button>
          )}

          <Popover
            label="More actions"
            align="end"
            trigger={({ open: isOpen, toggle, ref }) => (
              <button
                ref={ref}
                type="button"
                className="pr-btn"
                aria-haspopup="menu"
                aria-expanded={isOpen}
                onClick={toggle}
              >
                More <MoreVertical size={15} aria-hidden />
              </button>
            )}
          >
            {(close) => (
              <>
                <p className="pr-pop__title">This register</p>
                <button
                  type="button"
                  className="pr-pop__option"
                  disabled={!permissions.export}
                  title={permissions.export ? undefined : 'Exporting needs the reports permission.'}
                  onClick={() => {
                    close()
                    void act('Register exported', () =>
                      purchaseReturnsApi.exportCsv({
                        filters: state.filters,
                        page: 1,
                        sort: state.sort,
                        order: state.order,
                      }),
                    )
                  }}
                >
                  Export to CSV
                </button>
                <button
                  type="button"
                  className="pr-pop__option"
                  onClick={() => {
                    close()
                    data.reloadAll()
                  }}
                >
                  Refresh
                </button>
              </>
            )}
          </Popover>

          {permissions.create && (
            <button type="button" className="pr-btn pr-btn--primary" onClick={() => setCreating({ seed: null })}>
              <Plus size={16} aria-hidden /> New purchase return
            </button>
          )}
        </div>
      </header>

      {!permissions.create && (
        <Notice tone="warning">
          You can read this register but not add to it. Raising a return needs the “Raise a purchase return”
          permission, which an administrator grants under Administration → Access.
        </Notice>
      )}

      <KpiRow summary={data.summary.data} loading={data.summary.loading} />

      <AnalyticsBand
        summary={data.summary.data}
        loading={data.summary.loading}
        error={data.summary.error}
        onRetry={data.summary.reload}
        onApplyInsight={applyInsight}
        onPickReason={(reason) => setFilters({ reason })}
        onAct={(insight) => {
          applyInsight(insight)
          setFollowUp(true)
        }}
      />

      <section className="pr-card pr-register">
        <FilterBar
          filters={state.filters}
          view={state.view}
          options={data.options}
          advancedCount={advancedCount}
          supplierLabel={resolvedSupplier}
          onFilters={setFilters}
          onView={setView}
          onOpenMoreFilters={() => setMoreFilters(true)}
          onSupplierLabel={setSupplierLabel}
        />

        <FilterChips
          filters={state.filters}
          options={data.options}
          supplierLabel={resolvedSupplier}
          onFilters={setFilters}
          onClearAll={() => {
            setSupplierLabel(null)
            reset()
          }}
        />

        {state.view === 'list' && (
          <BulkBar
            count={selected.size}
            approvable={approvable.length}
            canApprove={permissions.approve}
            busy={busy}
            onApprove={() => void approveSelected()}
            onClear={() => setSelected(new Set())}
          />
        )}

        {data.register.error ? (
          <ErrorState what="The register" message={data.register.error} onRetry={data.register.reload} />
        ) : data.register.loading && data.register.data === null ? (
          <SkeletonRows rows={8} columns={7} />
        ) : rows.length === 0 ? (
          isFiltered ? (
            <EmptyState
              icon={<RotateCcw size={26} />}
              title="No returns match your filters"
              actions={
                <button
                  type="button"
                  className="pr-btn pr-btn--primary"
                  onClick={() => {
                    setSupplierLabel(null)
                    reset()
                  }}
                >
                  Clear filters
                </button>
              }
            >
              Nothing in this company matches what is set above. Try a wider period, or clear the filters and start
              again.
            </EmptyState>
          ) : (
            <EmptyState
              icon={<RotateCcw size={26} />}
              title="No purchase returns yet"
              actions={
                permissions.create ? (
                  <>
                    <button type="button" className="pr-btn pr-btn--primary" onClick={() => setCreating({ seed: null })}>
                      <Plus size={16} aria-hidden /> New purchase return
                    </button>
                    <button type="button" className="pr-btn" onClick={() => setImporting(true)}>
                      <Download size={15} aria-hidden /> Import a file
                    </button>
                  </>
                ) : undefined
              }
            >
              Create your first purchase return when goods need to go back to a supplier. Inventory moves the stock out
              and Smart Books raises the debit note — this screen keeps the three in step.
            </EmptyState>
          )
        ) : state.view === 'list' ? (
          <RegisterTable
            rows={rows}
            selected={selected}
            sort={state.sort}
            order={state.order}
            can={permissions}
            handlers={handlers}
            onSort={setSort}
            onSelect={(id, checked) => {
              const next = new Set(selected)
              if (checked) next.add(id)
              else next.delete(id)
              setSelected(next)
            }}
            onSelectAll={(checked) => setSelected(checked ? new Set(rows.map((row) => row.return_id)) : new Set())}
          />
        ) : state.view === 'calendar' ? (
          <CalendarView rows={rows} anchorMonth={anchorMonth} onOpen={(row) => open(row.return_id)} />
        ) : (
          <KanbanView rows={rows} onOpen={(row) => open(row.return_id)} />
        )}

        {rows.length > 0 && (
          <footer className="pr-register-foot">
            <span>
              {state.view === 'list'
                ? `Showing ${(state.page - 1) * PAGE_SIZE + 1}–${Math.min(state.page * PAGE_SIZE, total)} of ${total} return${total === 1 ? '' : 's'}`
                : `Showing ${rows.length} of ${total} return${total === 1 ? '' : 's'} in this period`}
              {data.register.loading && <span style={{ marginLeft: 8, color: 'var(--pr-muted)' }}>refreshing…</span>}
            </span>

            {state.view === 'list' && pages > 1 && (
              <nav className="pr-pagination" aria-label="Register pages">
                <button
                  type="button"
                  className="pr-page-btn"
                  disabled={state.page <= 1}
                  aria-label="Previous page"
                  onClick={() => setPage(state.page - 1)}
                >
                  <ChevronLeft size={15} aria-hidden />
                </button>

                {pageNumbers(state.page, pages).map((entry, index) =>
                  entry === null ? (
                    <span className="pr-page-gap" key={`gap-${index}`}>
                      …
                    </span>
                  ) : (
                    <button
                      key={entry}
                      type="button"
                      className={entry === state.page ? 'pr-page-btn is-active' : 'pr-page-btn'}
                      aria-current={entry === state.page ? 'page' : undefined}
                      aria-label={`Page ${entry}`}
                      onClick={() => setPage(entry)}
                    >
                      {entry}
                    </button>
                  ),
                )}

                <button
                  type="button"
                  className="pr-page-btn"
                  disabled={state.page >= pages}
                  aria-label="Next page"
                  onClick={() => setPage(state.page + 1)}
                >
                  <ChevronRight size={15} aria-hidden />
                </button>
              </nav>
            )}
          </footer>
        )}
      </section>

      <ReturnDrawer
        id={state.openId}
        can={permissions}
        busy={busy}
        printOnOpen={printOnOpen}
        onPrinted={() => setPrintOnOpen(false)}
        onClose={() => {
          setPrintOnOpen(false)
          open(null)
        }}
        onAct={act}
        onCancel={(row) => setDialog({ kind: 'cancel', row })}
        onCredit={(row) => setDialog({ kind: 'credit', row })}
      />

      <MoreFilters
        open={moreFilters}
        filters={state.filters}
        options={data.options}
        onApply={setFilters}
        onClose={() => setMoreFilters(false)}
      />

      <NewReturnDrawer
        open={creating !== null}
        options={data.options}
        seed={creating?.seed ?? null}
        onClose={() => setCreating(null)}
        onCreated={(created) => {
          setCreating(null)
          data.reloadAll()
          push('success', `${created.return_no} created`, 'It is a draft until somebody approves it.')
          open(created.return_id)
        }}
      />

      <ImportDrawer
        open={importing}
        onClose={() => setImporting(false)}
        onImported={(created, failed) => {
          setImporting(false)
          data.reloadAll()
          push(
            failed > 0 ? 'danger' : 'success',
            `${created} draft return${created === 1 ? '' : 's'} created`,
            failed > 0 ? `${failed} row${failed === 1 ? '' : 's'} could not be created.` : undefined,
          )
        }}
      />

      <FollowUpDrawer open={followUp} filters={state.filters} onClose={() => setFollowUp(false)} />

      {dialog?.kind === 'cancel' && (
        <CancelDialog
          row={dialog.row}
          busy={busy}
          onClose={() => setDialog(null)}
          onConfirm={async (reason) => {
            const ok = await act(`${dialog.row.return_no} cancelled`, () =>
              purchaseReturnsApi.cancel(dialog.row.return_id, reason),
            )
            if (ok) setDialog(null)
          }}
        />
      )}

      {dialog?.kind === 'credit' && (
        <SupplierCreditDialog
          row={dialog.row}
          busy={busy}
          onClose={() => setDialog(null)}
          onConfirm={async (payload) => {
            const ok = await act(`Supplier credit recorded on ${dialog.row.return_no}`, () =>
              purchaseReturnsApi.supplierCredit(dialog.row.return_id, payload),
            )
            if (ok) setDialog(null)
          }}
        />
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />

      {/* The name is read from the session rather than written in, the same way
          the shell reads the company and the financial year. */}
      <span className="pr-sr-only">Signed in as {session?.display_name ?? 'this user'}.</span>

      {/* The detail page and the drawer are the same component; this is the
          link for anyone who lands here with a return open. */}
      {state.openId !== null && (
        <span className="pr-sr-only">
          <Link to={`/returns/${state.openId}`}>Open this return on its own page</Link>
        </span>
      )}
    </main>
  )
}

/** 1 … 4 5 6 … 20 — never more than seven buttons, however many pages there are. */
function pageNumbers(current: number, pages: number): Array<number | null> {
  if (pages <= 7) return Array.from({ length: pages }, (_, index) => index + 1)

  const list: Array<number | null> = [1]
  const from = Math.max(2, current - 1)
  const to = Math.min(pages - 1, current + 1)

  if (from > 2) list.push(null)
  for (let page = from; page <= to; page++) list.push(page)
  if (to < pages - 1) list.push(null)
  list.push(pages)

  return list
}

// ---------------------------------------------------------------------------
// The detail, as a drawer and as a page
// ---------------------------------------------------------------------------

function useReturn(id: number | string | null) {
  return useApi(
    (signal) => purchaseReturnsApi.getById(id as number, signal),
    [id],
    id !== null && id !== undefined && id !== '',
  )
}

/** The buttons that move a return along, with what each one will actually do. */
function DetailActions({
  record,
  can,
  busy,
  onAct,
  onCancel,
  onCredit,
  onPrint,
  onDone,
}: {
  record: PurchaseReturnDetail
  can: { create: boolean; approve: boolean }
  busy: boolean
  onAct: (what: string, run: () => Promise<unknown>) => Promise<boolean>
  onCancel: (row: PurchaseReturnRow) => void
  onCredit: (row: PurchaseReturnRow) => void
  onPrint: () => void
  onDone: () => void
}) {
  const run = async (what: string, call: () => Promise<unknown>) => {
    const ok = await onAct(what, call)
    if (ok) onDone()
  }

  return (
    <>
      <button type="button" className="pr-btn pr-btn--quiet" onClick={onPrint}>
        <Printer size={14} aria-hidden /> Print
      </button>

      <span style={{ flex: 1 }} />

      {record.status === 'DRAFT' && can.approve && (
        <button
          type="button"
          className="pr-btn pr-btn--primary"
          disabled={busy}
          onClick={() => void run(`${record.return_no} approved`, () => purchaseReturnsApi.approve(record.return_id))}
        >
          <Stamp size={14} aria-hidden /> Approve
        </button>
      )}

      {record.status === 'APPROVED' && can.approve && (
        <button
          type="button"
          className="pr-btn pr-btn--primary"
          disabled={busy}
          title="Inventory moves the stock out of the warehouse."
          onClick={() =>
            void run(`${record.return_no} sent back through Inventory`, () => purchaseReturnsApi.dispatch(record.return_id))
          }
        >
          Send the goods back
        </button>
      )}

      {['APPROVED', 'DISPATCHED'].includes(record.status) && record.books.status !== 'POSTED' && can.approve && (
        <button
          type="button"
          className="pr-btn pr-btn--primary"
          disabled={busy}
          title="Smart Books raises the debit note against the payable."
          onClick={() =>
            void run(`Debit note raised for ${record.return_no}`, () => purchaseReturnsApi.debitNote(record.return_id))
          }
        >
          Raise the debit note
        </button>
      )}

      {can.approve && record.status !== 'CANCELLED' && (
        <button type="button" className="pr-btn" disabled={busy} onClick={() => onCredit(record)}>
          Record supplier credit
        </button>
      )}

      {can.approve && ['DRAFT', 'APPROVED'].includes(record.status) && record.inventory.status !== 'POSTED' && (
        <button type="button" className="pr-btn pr-btn--danger" disabled={busy} onClick={() => onCancel(record)}>
          Cancel
        </button>
      )}
    </>
  )
}

function ReturnDrawer({
  id,
  can,
  busy,
  printOnOpen,
  onPrinted,
  onClose,
  onAct,
  onCancel,
  onCredit,
}: {
  id: number | null
  can: { create: boolean; approve: boolean }
  busy: boolean
  printOnOpen: boolean
  onPrinted: () => void
  onClose: () => void
  onAct: (what: string, run: () => Promise<unknown>) => Promise<boolean>
  onCancel: (row: PurchaseReturnRow) => void
  onCredit: (row: PurchaseReturnRow) => void
}) {
  const { data, loading, error, reload } = useReturn(id)
  const record = data?.data ?? null

  // Printing waits for the record, because printing a skeleton is printing
  // nothing. It fires once and clears the flag.
  if (printOnOpen && record !== null) {
    onPrinted()
    window.setTimeout(() => window.print(), 80)
  }

  const heading = record ? ReturnDetailHeading({ record }) : null

  return (
    <Drawer
      open={id !== null}
      wide
      title={record?.return_no ?? 'Return'}
      badge={heading?.badge}
      subtitle={heading?.subtitle}
      onClose={onClose}
      footer={
        record ? (
          <DetailActions
            record={record}
            can={can}
            busy={busy}
            onAct={onAct}
            onCancel={onCancel}
            onCredit={onCredit}
            onPrint={() => window.print()}
            onDone={reload}
          />
        ) : undefined
      }
    >
      {loading && record === null && <SkeletonRows rows={5} columns={3} />}
      {error && <ErrorState what="This return" message={error} onRetry={reload} compact />}
      {record && <ReturnDetailBody record={record} busy={busy} onRetryCommand={reload} />}
    </Drawer>
  )
}

/**
 * /returns/:id — the same detail, on its own page.
 *
 * A real route rather than only a drawer, because a return number is something
 * people paste into a chat and a ticket, and a link that only works if you were
 * already on the register is not a link.
 */
export function PurchaseReturnPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { can } = usePurchases()
  const { toasts, push, dismiss } = useToasts()
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState<Dialog>(null)

  const { data, loading, error, reload } = useReturn(id ?? null)
  const record = data?.data ?? null

  const act = useCallback(
    async (what: string, run: () => Promise<unknown>) => {
      setBusy(true)
      try {
        await run()
        reload()
        push('success', what)

        return true
      } catch (failure) {
        push('danger', `${what} did not work`, failure instanceof ApiError ? failure.message : String(failure))

        return false
      } finally {
        setBusy(false)
      }
    },
    [reload, push],
  )

  const permissions = { create: can('return.create'), approve: can('return.approve') }

  return (
    <main className="purchase-returns-workspace">
      <header className="pr-header">
        <div className="pr-title-wrap">
          <span className="pr-title-icon" aria-hidden="true">
            <Undo2 size={22} />
          </span>
          <div style={{ minWidth: 0 }}>
            <Link to="/returns" className="pr-link" style={{ fontSize: 12.5 }}>
              ← Purchase returns
            </Link>
            <h1 className="pr-heading" style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
              {record?.return_no ?? (loading ? 'Loading…' : 'Return')}
              {record && <StatusPill status={record.status} label={record.status_label} />}
            </h1>
            {record && <p className="pr-subtitle">{ReturnDetailHeading({ record }).subtitle}</p>}
          </div>
        </div>

        {record && (
          <div className="pr-header-actions">
            <DetailActions
              record={record}
              can={permissions}
              busy={busy}
              onAct={act}
              onCancel={(row) => setDialog({ kind: 'cancel', row })}
              onCredit={(row) => setDialog({ kind: 'credit', row })}
              onPrint={() => window.print()}
              onDone={reload}
            />
          </div>
        )}
      </header>

      <div style={{ display: 'grid', gap: 14, maxWidth: 900 }}>
        {loading && record === null && (
          <div className="pr-card" style={{ padding: 16 }}>
            <SkeletonRows rows={5} columns={3} />
          </div>
        )}

        {error && (
          <div className="pr-card" style={{ padding: 16 }}>
            <ErrorState what="This return" message={error} onRetry={reload} />
          </div>
        )}

        {!loading && !error && record === null && (
          <EmptyState
            icon={<RotateCcw size={26} />}
            title="That return does not exist"
            actions={
              <button type="button" className="pr-btn pr-btn--primary" onClick={() => navigate('/returns')}>
                Back to the register
              </button>
            }
          >
            It may have been in a different company or financial year from the one you have open.
          </EmptyState>
        )}

        {record && <ReturnDetailBody record={record} busy={busy} onRetryCommand={reload} />}
      </div>

      {dialog?.kind === 'cancel' && (
        <CancelDialog
          row={dialog.row}
          busy={busy}
          onClose={() => setDialog(null)}
          onConfirm={async (reason) => {
            const ok = await act(`${dialog.row.return_no} cancelled`, () =>
              purchaseReturnsApi.cancel(dialog.row.return_id, reason),
            )
            if (ok) setDialog(null)
          }}
        />
      )}

      {dialog?.kind === 'credit' && (
        <SupplierCreditDialog
          row={dialog.row}
          busy={busy}
          onClose={() => setDialog(null)}
          onConfirm={async (payload) => {
            const ok = await act(`Supplier credit recorded on ${dialog.row.return_no}`, () =>
              purchaseReturnsApi.supplierCredit(dialog.row.return_id, payload),
            )
            if (ok) setDialog(null)
          }}
        />
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </main>
  )
}

// ---------------------------------------------------------------------------
// Two small dialogs
// ---------------------------------------------------------------------------

function CancelDialog({
  row,
  busy,
  onClose,
  onConfirm,
}: {
  row: PurchaseReturnRow
  busy: boolean
  onClose: () => void
  onConfirm: (reason: string) => void
}) {
  const [reason, setReason] = useState('')

  return (
    <Drawer
      open
      title={`Cancel ${row.return_no}`}
      subtitle="The return stays on the register, marked cancelled, with the reason beside it."
      onClose={onClose}
      footer={
        <>
          <button type="button" className="pr-btn" onClick={onClose}>
            Keep it
          </button>
          <button
            type="button"
            className="pr-btn pr-btn--danger"
            disabled={busy || reason.trim() === ''}
            onClick={() => onConfirm(reason.trim())}
          >
            {busy ? 'Cancelling…' : 'Cancel the return'}
          </button>
        </>
      }
    >
      <div className="pr-section">
        <label className="pr-field">
          <span>Why is it being cancelled?</span>
          <textarea
            value={reason}
            autoFocus
            placeholder="The supplier took it back against the next delivery instead."
            onChange={(event) => setReason(event.target.value)}
          />
          <span className="pr-field__hint">
            Required, and kept on the return. A cancelled document with no reason beside it is a question somebody has
            to go and ask a person.
          </span>
        </label>
      </div>

      <Notice tone="plain">
        This is possible only because nothing has moved yet. Once Inventory holds the stock movement or Smart Books
        holds the debit note, reversing it is that product's operation, not a status change here.
      </Notice>
    </Drawer>
  )
}

function SupplierCreditDialog({
  row,
  busy,
  onClose,
  onConfirm,
}: {
  row: PurchaseReturnRow
  busy: boolean
  onClose: () => void
  onConfirm: (payload: {
    supplier_credit_status: string
    supplier_credit_ref?: string
    supplier_credit_date?: string
    supplier_credit_amount?: number
  }) => void
}) {
  const [status, setStatus] = useState(row.supplier_credit.status)
  const [reference, setReference] = useState(row.supplier_credit.reference ?? '')
  const [date, setDate] = useState(row.supplier_credit.date ?? new Date().toISOString().slice(0, 10))
  const [amount, setAmount] = useState(row.supplier_credit.amount ?? '')

  const needsReference = status === 'RECEIVED'

  return (
    <Drawer
      open
      title={`Supplier credit on ${row.return_no}`}
      subtitle="The credit note the supplier issued against this return."
      onClose={onClose}
      footer={
        <>
          <button type="button" className="pr-btn" onClick={onClose}>
            Cancel
          </button>
          <button
            type="button"
            className="pr-btn pr-btn--primary"
            disabled={busy || (needsReference && reference.trim() === '')}
            onClick={() =>
              onConfirm({
                supplier_credit_status: status,
                supplier_credit_ref: reference.trim() || undefined,
                supplier_credit_date: date || undefined,
                supplier_credit_amount: amount === '' ? undefined : Number.parseFloat(String(amount)),
              })
            }
          >
            {busy ? 'Saving…' : 'Save'}
          </button>
        </>
      }
    >
      <div className="pr-section">
        <label className="pr-field">
          <span>Status</span>
          <select value={status} onChange={(event) => setStatus(event.target.value as typeof status)}>
            <option value="PENDING">Credit pending</option>
            <option value="RECEIVED">Credit received</option>
            <option value="NOT_REQUIRED">No credit due</option>
          </select>
        </label>

        {needsReference && (
          <>
            <label className="pr-field" style={{ marginTop: 12 }}>
              <span>Credit note reference</span>
              <input
                value={reference}
                autoFocus
                placeholder="CN-4433"
                onChange={(event) => setReference(event.target.value)}
              />
              <span className="pr-field__hint">The number on the supplier's own document.</span>
            </label>

            <div className="pr-grid-2" style={{ marginTop: 12 }}>
              <label className="pr-field">
                <span>Credit note date</span>
                <input type="date" value={date} onChange={(event) => setDate(event.target.value)} />
              </label>
              <label className="pr-field">
                <span>Amount credited</span>
                <input
                  type="number"
                  inputMode="decimal"
                  value={amount}
                  placeholder={row.total_value}
                  onChange={(event) => setAmount(event.target.value)}
                />
                <span className="pr-field__hint">Leave blank if it is the full return value.</span>
              </label>
            </div>
          </>
        )}
      </div>

      <Notice tone="plain">
        <RefreshCw size={13} aria-hidden style={{ verticalAlign: -2, marginRight: 5 }} />
        This records THEIR document. It does not post anything: what the credit does to the payable is Smart Books', and
        is done there through the debit note.
      </Notice>
    </Drawer>
  )
}
