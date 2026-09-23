/**
 * The bill workspace: tabs, toolbar, table, paging.
 *
 * It owns its own request, so changing a tab or turning a page costs one call
 * to one endpoint rather than rebuilding the whole dashboard — the panels
 * above it each cost Smart Books a round trip per supplier, and paging through
 * a list should not.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Check,
  Columns3,
  Download,
  FileSearch,
  RefreshCw,
  Search,
  Send,
  X,
} from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import { Chevron, EmptyState, Menu, WidgetError } from './parts'
import { buildColumns, DEFAULT_COLUMNS, PayablesTable } from './PayablesTable'
import { useDebounced, usePayables, type PayablesQuery } from './usePayables'
import { formatMoney, PAYABLE_TABS, type BooksOpenItem, type PayableRow, type PayableTabId } from './types'

const COLUMN_PREFERENCE_KEY = 'purchases:payables:columns'
const PER_PAGE_OPTIONS = [25, 50, 100]

/**
 * Which columns this reader chose, remembered between visits.
 *
 * There is no server-side preference store in this product, so it goes in
 * localStorage — and every read of it is guarded, because a private window or
 * blocked site data makes the accessor throw rather than return nothing.
 */
function readColumnPreference(): string[] {
  try {
    const raw = window.localStorage.getItem(COLUMN_PREFERENCE_KEY)
    if (!raw) return DEFAULT_COLUMNS
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed) || parsed.length === 0) return DEFAULT_COLUMNS
    return parsed.filter((id): id is string => typeof id === 'string')
  } catch {
    return DEFAULT_COLUMNS
  }
}

export function PayablesWorkspace({
  tab,
  onTabChange,
  search,
  onSearchChange,
  supplierId,
  from,
  to,
  onClearFilters,
  filtersApplied,
  booksItems,
  canExport,
  onExport,
  exporting,
  onChanged,
}: {
  tab: PayableTabId
  onTabChange: (tab: PayableTabId) => void
  search: string
  onSearchChange: (value: string) => void
  supplierId: string | null
  from: string | null
  to: string | null
  onClearFilters: () => void
  filtersApplied: boolean
  booksItems: Map<string, BooksOpenItem>
  canExport: boolean
  onExport: (format: 'csv' | 'pdf') => void
  exporting: string | null
  onChanged: () => void
}) {
  const navigate = useNavigate()
  const { scope, can } = usePurchases()

  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [sort, setSort] = useState('invoice_date')
  const [order, setOrder] = useState<'asc' | 'desc'>('desc')
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [visible, setVisible] = useState<string[]>(readColumnPreference)
  const [pending, setPending] = useState<{ action: 'rematch' | 'post'; rows: PayableRow[] } | null>(null)

  const debouncedSearch = useDebounced(search)

  // A filter change re-pages to the first page: page four of a list that no
  // longer has four pages is an empty screen nobody asked for.
  useEffect(() => {
    setPage(1)
    setSelected(new Set())
  }, [tab, debouncedSearch, supplierId, from, to, perPage])

  const query: PayablesQuery = useMemo(
    () => ({
      tab,
      q: debouncedSearch,
      supplierId,
      poId: null,
      from,
      to,
      status: null,
      sort,
      order,
      page,
      perPage,
    }),
    [tab, debouncedSearch, supplierId, from, to, sort, order, page, perPage],
  )

  const { rows, total, counts, loading, refreshing, error, refresh } = usePayables(query, Boolean(scope))

  const columns = useMemo(() => buildColumns(navigate), [navigate])

  const setColumns = useCallback((next: string[]) => {
    setVisible(next)
    try {
      window.localStorage.setItem(COLUMN_PREFERENCE_KEY, JSON.stringify(next))
    } catch {
      /* storage refused; the choice still applies for this visit */
    }
  }, [])

  const sortBy = (key: string) => {
    if (sort === key) {
      setOrder((current) => (current === 'asc' ? 'desc' : 'asc'))
      return
    }
    setSort(key)
    setOrder('desc')
  }

  const selectedRows = rows.filter((row) => selected.has(row.request_id))
  const pages = Math.max(1, Math.ceil(total / perPage))
  const firstShown = total === 0 ? 0 : (page - 1) * perPage + 1
  const lastShown = Math.min(page * perPage, total)

  return (
    <section className="aic-workspace" aria-label="Supplier bills">
      <div className="aic-tabs" role="tablist" aria-label="Bill status">
        {PAYABLE_TABS.map((entry) => (
          <button
            key={entry.id}
            type="button"
            role="tab"
            aria-selected={tab === entry.id}
            className={tab === entry.id ? 'aic-tab is-active' : 'aic-tab'}
            onClick={() => onTabChange(entry.id)}
          >
            {entry.label}
            {counts && <span>{counts[entry.id]}</span>}
          </button>
        ))}
      </div>

      <div className="aic-toolbar">
        <div className="aic-search">
          <Search size={14} aria-hidden />
          <label className="aic-sr-only" htmlFor="aic-bill-search">
            Search bills
          </label>
          <input
            id="aic-bill-search"
            type="search"
            value={search}
            placeholder="Search bills, suppliers or orders…"
            onChange={(event) => onSearchChange(event.target.value)}
          />
        </div>

        <div className="aic-toolbar__end">
          <Menu
            label="Choose columns"
            trigger={(props) => (
              <button type="button" className="aic-btn aic-btn--secondary" {...props}>
                <Columns3 size={14} aria-hidden /> Columns <Chevron />
              </button>
            )}
          >
            {() => (
              <>
                <p className="aic-menu__heading">Visible columns</p>
                {columns.map((column) => (
                  <label key={column.id}>
                    <input
                      type="checkbox"
                      checked={visible.includes(column.id)}
                      disabled={column.fixed}
                      onChange={(event) =>
                        setColumns(
                          event.target.checked
                            ? columns.filter((c) => c.id === column.id || visible.includes(c.id)).map((c) => c.id)
                            : visible.filter((id) => id !== column.id),
                        )
                      }
                    />
                    {column.header}
                    {column.fixed && <span className="aic-sub">always</span>}
                  </label>
                ))}
                <hr />
                <button type="button" onClick={() => setColumns(DEFAULT_COLUMNS)}>
                  Reset to default
                </button>
              </>
            )}
          </Menu>

          {canExport && (
            <Menu
              label="Export"
              trigger={(props) => (
                <button type="button" className="aic-btn aic-btn--secondary" disabled={exporting !== null} {...props}>
                  <Download size={14} aria-hidden /> {exporting === null ? 'Export' : 'Exporting…'} <Chevron />
                </button>
              )}
            >
              {(close) => (
                <>
                  {/* Both run the same code as the screen, with the same
                      filters, so an export cannot disagree with what is on it. */}
                  <button type="button" onClick={() => { close(); onExport('csv') }}>
                    CSV of this dashboard
                  </button>
                  <button type="button" onClick={() => { close(); onExport('pdf') }}>
                    Print-ready PDF
                  </button>
                </>
              )}
            </Menu>
          )}

          <button
            type="button"
            className="aic-icon-btn"
            onClick={refresh}
            disabled={refreshing}
            aria-label="Refresh this list"
            title="Refresh this list"
          >
            <RefreshCw size={15} aria-hidden />
          </button>
        </div>
      </div>

      {selectedRows.length > 0 && (
        <div className="aic-bulkbar" role="status">
          <strong>{selectedRows.length} selected</strong>
          {can('bill.enter') && (
            <button
              type="button"
              className="aic-btn aic-btn--secondary"
              onClick={() => setPending({ action: 'rematch', rows: selectedRows.filter((row) => row.can_rematch) })}
              disabled={!selectedRows.some((row) => row.can_rematch)}
            >
              <RefreshCw size={13} aria-hidden /> Re-run the match
            </button>
          )}
          {can('bill.post') && (
            <button
              type="button"
              className="aic-btn aic-btn--primary"
              onClick={() => setPending({ action: 'post', rows: selectedRows.filter((row) => row.can_post) })}
              disabled={!selectedRows.some((row) => row.can_post)}
            >
              <Send size={13} aria-hidden /> Post to Smart Books…
            </button>
          )}
          <button type="button" className="aic-btn aic-btn--quiet" onClick={() => setSelected(new Set())}>
            <X size={13} aria-hidden /> Clear
          </button>
        </div>
      )}

      {error !== null ? (
        <div style={{ padding: 14 }}>
          <WidgetError message={error} onRetry={refresh} />
        </div>
      ) : loading ? (
        <div style={{ padding: '4px 14px 14px' }} aria-busy="true">
          <span className="aic-sr-only">Loading bills</span>
          {Array.from({ length: 6 }, (_, index) => (
            <div key={index} className="aic-skeleton aic-skeleton--row" style={{ height: 34 }} />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<FileSearch size={24} aria-hidden />}
          title="No bills found"
          actions={
            <>
              {filtersApplied && (
                <button type="button" className="aic-btn aic-btn--secondary" onClick={onClearFilters}>
                  Clear filters
                </button>
              )}
              {can('bill.enter') && (
                <button type="button" className="aic-btn aic-btn--primary" onClick={() => navigate('/bills')}>
                  Enter a bill
                </button>
              )}
            </>
          }
        >
          No supplier bills match the selected filters.
        </EmptyState>
      ) : (
        <>
          <PayablesTable
            rows={rows}
            books={booksItems}
            columns={columns}
            visible={visible}
            selected={selected}
            onSelect={setSelected}
            sort={sort}
            order={order}
            onSort={sortBy}
            refreshing={refreshing}
            onAct={(action, row) => setPending({ action, rows: [row] })}
          />

          <div className="aic-pagination">
            <span>
              Showing {firstShown}–{lastShown} of {total} bill{total === 1 ? '' : 's'}
            </span>

            <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              Rows per page
              <select value={perPage} onChange={(event) => setPerPage(Number(event.target.value))}>
                {PER_PAGE_OPTIONS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            </label>

            <div className="aic-pagination__pages">
              <button
                type="button"
                className="aic-page-btn"
                onClick={() => setPage((current) => Math.max(1, current - 1))}
                disabled={page <= 1}
              >
                Previous
              </button>
              {pageWindow(page, pages).map((entry, index) =>
                entry === null ? (
                  <span key={`gap-${index}`} aria-hidden>
                    …
                  </span>
                ) : (
                  <button
                    key={entry}
                    type="button"
                    className={entry === page ? 'aic-page-btn is-on' : 'aic-page-btn'}
                    aria-current={entry === page ? 'page' : undefined}
                    aria-label={`Page ${entry}`}
                    onClick={() => setPage(entry)}
                  >
                    {entry}
                  </button>
                ),
              )}
              <button
                type="button"
                className="aic-page-btn"
                onClick={() => setPage((current) => Math.min(pages, current + 1))}
                disabled={page >= pages}
              >
                Next
              </button>
            </div>
          </div>
        </>
      )}

      <ConfirmAction
        pending={pending}
        onClose={() => setPending(null)}
        onDone={() => {
          setPending(null)
          setSelected(new Set())
          refresh()
          onChanged()
        }}
      />
    </section>
  )
}

/** First, last, and a window around the current page. */
function pageWindow(page: number, pages: number): (number | null)[] {
  if (pages <= 7) return Array.from({ length: pages }, (_, index) => index + 1)

  const out: (number | null)[] = [1]
  const from = Math.max(2, page - 1)
  const to = Math.min(pages - 1, page + 1)

  if (from > 2) out.push(null)
  for (let index = from; index <= to; index++) out.push(index)
  if (to < pages - 1) out.push(null)
  out.push(pages)

  return out
}

// ---------------------------------------------------------------------------

/**
 * The confirmation in front of anything that reaches Smart Books.
 *
 * Posting a bill creates a purchase voucher and a payable in another product.
 * It is the one action on this screen a reader cannot undo from this screen,
 * so it is named in full, counted, totalled and confirmed — never fired from a
 * single click on a row menu.
 */
function ConfirmAction({
  pending,
  onClose,
  onDone,
}: {
  pending: { action: 'rematch' | 'post'; rows: PayableRow[] } | null
  onClose: () => void
  onDone: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState(0)

  useEffect(() => {
    if (!pending) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [pending, busy, onClose])

  if (!pending || pending.rows.length === 0) return null

  const posting = pending.action === 'post'

  const run = async () => {
    setBusy(true)
    setError(null)
    let completed = 0

    try {
      // One at a time, in order. The post endpoint carries an idempotency key
      // of its own, so a retry after a failure part-way through cannot produce
      // a second voucher for a bill that already got one.
      for (const row of pending.rows) {
        await api.post(`v1/bills/${row.request_id}/${posting ? 'post' : 'rematch'}`, {})
        completed += 1
        setDone(completed)
      }
      onDone()
    } catch (err) {
      setError(
        (err instanceof ApiError ? err.message : 'That could not be completed.') +
          (completed > 0 ? ` ${completed} of ${pending.rows.length} finished before this.` : ''),
      )
    } finally {
      setBusy(false)
    }
  }

  const total = pending.rows.reduce((sum, row) => sum + (Number.parseFloat(row.subtotal) || 0), 0)

  return (
    <>
      <div className="aic-drawer-backdrop" onClick={() => !busy && onClose()} aria-hidden />
      <aside className="aic-drawer" role="dialog" aria-modal="true" aria-labelledby="aic-confirm-title">
        <header className="aic-drawer__head">
          <div>
            <h2 id="aic-confirm-title">
              {posting ? 'Post to Smart Books' : 'Re-run the match'}
            </h2>
            <p>
              {pending.rows.length} bill{pending.rows.length === 1 ? '' : 's'}
              {posting && ` · ${formatMoney(String(total), pending.rows[0]?.currency ?? 'INR')} excluding tax`}
            </p>
          </div>
          {/* "Close", not "Cancel": the footer already has a Cancel and two
              controls answering to the same name is one too many, for a screen
              reader and for anybody driving this from the keyboard. */}
          <button type="button" className="aic-icon-btn" onClick={onClose} disabled={busy} aria-label="Close">
            <X size={16} aria-hidden />
          </button>
        </header>

        <div className="aic-drawer__body">
          <ul style={{ margin: 0, padding: 0, listStyle: 'none', display: 'grid', gap: 8 }}>
            {pending.rows.slice(0, 12).map((row) => (
              <li key={row.request_id} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, fontSize: 12.5 }}>
                <span>
                  {row.invoice_no ?? `#${row.request_id}`}
                  <span className="aic-sub" style={{ display: 'block' }}>
                    {row.supplier_name ?? `Account ${row.supplier_account_id}`}
                  </span>
                </span>
                <b style={{ fontVariantNumeric: 'tabular-nums' }}>{formatMoney(row.subtotal, row.currency)}</b>
              </li>
            ))}
            {pending.rows.length > 12 && (
              <li className="aic-sub">and {pending.rows.length - 12} more</li>
            )}
          </ul>

          <div className={posting ? 'aic-notice aic-notice--warning' : 'aic-notice aic-notice--info'}>
            <div>
              <strong>{posting ? 'This reaches another product' : 'Nothing financial happens'}</strong>
              <p>
                {posting
                  ? 'Smart Books creates the purchase voucher and owns everything financial about it from then on: the input GST, the TDS, the payable and the bill-by-bill allocation. It cannot be undone from this screen. It does not pay anybody — Aicountly Pay is not integrated with this product.'
                  : 'The bill is checked against the order and against what Inventory says arrived. Nothing is posted and nothing is paid.'}
              </p>
            </div>
          </div>

          {busy && (
            <p className="aic-sub" aria-live="polite">
              {done} of {pending.rows.length} finished…
            </p>
          )}

          {error && (
            <div className="aic-notice aic-notice--danger" role="alert">
              <div>
                <strong>Not completed</strong>
                <p>{error}</p>
              </div>
            </div>
          )}
        </div>

        <footer className="aic-drawer__foot">
          <button type="button" className="aic-btn aic-btn--secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="aic-btn aic-btn--primary" onClick={() => void run()} disabled={busy}>
            <Check size={14} aria-hidden />
            {busy ? 'Working…' : posting ? 'Post to Smart Books' : 'Re-run the match'}
          </button>
        </footer>
      </aside>
    </>
  )
}
