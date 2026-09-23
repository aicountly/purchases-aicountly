/**
 * The payables list.
 *
 * Two products answer the columns of this table and the table never lets them
 * blur together. Everything to the left of Due date is OURS — the bill, what
 * it is against, what it is worth, what is holding it up. Due date and Payment
 * are Smart Books', merged in from the open items the planning panel read, and
 * a bill Books was not asked about says exactly that rather than showing an
 * empty cell a reader would take for "nothing due".
 */

import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowDownUp,
  ChevronDown,
  ChevronUp,
  Copy,
  CornerUpRight,
  ExternalLink,
  Eye,
  FileText,
  MoreHorizontal,
  RefreshCw,
  Scale,
  Send,
  Truck,
} from 'lucide-react'
import { Avatar, Badge, Menu } from './parts'
import {
  daysUntil,
  formatDate,
  formatMoney,
  STATUS_FACE,
  type BooksOpenItem,
  type PayableRow,
} from './types'

export interface ColumnDef {
  id: string
  header: string
  /** Columns the table cannot be read without. They never leave. */
  fixed?: boolean
  numeric?: boolean
  sortKey?: string
  render: (row: PayableRow, books: BooksOpenItem | undefined) => ReactNode
}

// ---------------------------------------------------------------------------
// Cells
// ---------------------------------------------------------------------------

function SupplierCell({ row }: { row: PayableRow }) {
  return (
    <div className="aic-supplier-cell">
      <Avatar name={row.supplier_name} id={row.supplier_account_id} />
      <span className="aic-cell-stack">
        <strong>{row.supplier_name ?? `Account ${row.supplier_account_id}`}</strong>
        <small className="aic-sub">SUPP-{String(row.supplier_account_id).padStart(3, '0')}</small>
      </span>
    </div>
  )
}

/**
 * The due date, and how long there is.
 *
 * The wording carries the urgency as well as the colour: "Overdue by 4 days"
 * reads the same to everybody, whereas red text alone reaches only the readers
 * who can see it as red.
 */
function DueDateCell({ books }: { books: BooksOpenItem | undefined }) {
  if (books === undefined) {
    return (
      <span className="aic-sub" title="Smart Books reports open items one supplier at a time, and this bill's supplier was not among those read for this period.">
        Not read from Books
      </span>
    )
  }

  if (books.due_date === null) {
    return <span className="aic-sub">No due date recorded</span>
  }

  const days = daysUntil(books.due_date)
  const overdue = books.days_overdue !== null && books.days_overdue > 0

  return (
    <span className="aic-cell-stack">
      <span className={overdue ? 'aic-due--overdue' : days !== null && days <= 7 ? 'aic-due--soon' : undefined}>
        {formatDate(books.due_date)}
      </span>
      <small className="aic-sub">
        {overdue
          ? `Overdue by ${books.days_overdue} day${books.days_overdue === 1 ? '' : 's'}`
          : days === null
            ? ''
            : days === 0
              ? 'Due today'
              : `in ${days} day${days === 1 ? '' : 's'}`}
      </small>
    </span>
  )
}

function StatusCell({ row }: { row: PayableRow }) {
  const face = STATUS_FACE[row.status] ?? { label: row.status, tone: 'neutral' as const }

  return (
    <span className="aic-cell-stack" style={{ alignItems: 'flex-start', gap: 4 }}>
      <Badge tone={face.tone}>{face.label}</Badge>
      {row.open_exceptions > 0 && (
        <Badge tone="danger">
          <AlertTriangle size={11} aria-hidden />
          {row.open_exceptions} exception{row.open_exceptions === 1 ? '' : 's'}
        </Badge>
      )}
      {row.duplicate_of !== null && (
        <Badge tone="purple">
          <Copy size={11} aria-hidden />
          Possible duplicate
        </Badge>
      )}
    </span>
  )
}

/**
 * What Books says has been settled.
 *
 * There is no "Schedule payment" or "Make payment" here, and that is not an
 * omission: Aicountly Pay is not integrated with this product, and a button
 * that looked like it paid a supplier but only recorded an intention would be
 * the single most dangerous control on the screen.
 */
function PaymentCell({ row, books }: { row: PayableRow; books: BooksOpenItem | undefined }) {
  if (row.status !== 'POSTED') {
    return <span className="aic-payment aic-payment--muted">Not payable yet</span>
  }
  if (books === undefined) {
    return <span className="aic-payment aic-payment--muted">Not read from Books</span>
  }
  if (books.held) {
    return (
      <span className="aic-payment aic-payment--muted" title={books.held_reason ?? undefined}>
        <AlertTriangle size={12} aria-hidden /> Held
      </span>
    )
  }
  if (Number.parseFloat(books.pending) <= 0) {
    return <span className="aic-payment aic-payment--success">Settled in Books</span>
  }

  return (
    <span className="aic-payment" title="Open amount from Smart Books. Payment is recorded in Books, not here.">
      {books.part_paid ? 'Part paid' : 'Open'} · {books.pending_formatted}
    </span>
  )
}

// ---------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------

export function buildColumns(navigate: (to: string) => void): ColumnDef[] {
  return [
    {
      id: 'invoice_no',
      header: 'Invoice no.',
      fixed: true,
      sortKey: 'invoice_no',
      render: (row) => (
        <span className="aic-cell-stack">
          <button type="button" className="aic-link" onClick={() => navigate(row.route)}>
            {row.invoice_no ?? `#${row.request_id}`}
          </button>
          {row.po_no && <small className="aic-sub">{row.po_no}</small>}
        </span>
      ),
    },
    {
      id: 'supplier',
      header: 'Supplier',
      fixed: true,
      sortKey: 'supplier',
      render: (row) => <SupplierCell row={row} />,
    },
    {
      id: 'invoice_date',
      header: 'Invoice date',
      sortKey: 'invoice_date',
      render: (row) => formatDate(row.invoice_date),
    },
    {
      id: 'due_date',
      header: 'Due date',
      render: (_row, books) => <DueDateCell books={books} />,
    },
    {
      id: 'amount',
      header: 'Amount',
      numeric: true,
      sortKey: 'amount',
      render: (row) => (
        <span className="aic-amount">
          <strong>{formatMoney(row.subtotal, row.currency)}</strong>
          <small className="aic-sub" title={row.tax_basis}>
            excl. tax
          </small>
        </span>
      ),
    },
    {
      id: 'status',
      header: 'Status',
      fixed: true,
      sortKey: 'status',
      render: (row) => <StatusCell row={row} />,
    },
    {
      id: 'payment',
      header: 'Payment',
      render: (row, books) => <PaymentCell row={row} books={books} />,
    },
    // --- off by default, available from the Columns menu -------------------
    {
      id: 'po_no',
      header: 'Order',
      render: (row) =>
        row.po_no ? (
          <button type="button" className="aic-link" onClick={() => navigate(`/purchase-orders/${row.po_id}`)}>
            {row.po_no}
          </button>
        ) : (
          <span className="aic-sub">Non-PO</span>
        ),
    },
    {
      id: 'voucher',
      header: 'Books voucher',
      render: (row) => row.books_voucher_no ?? <span className="aic-sub">—</span>,
    },
    {
      id: 'payment_terms',
      header: 'Payment terms',
      render: (row) => row.payment_terms ?? <span className="aic-sub">—</span>,
    },
    {
      id: 'lines',
      header: 'Lines',
      numeric: true,
      render: (row) => row.line_count,
    },
    {
      id: 'entered_by',
      header: 'Entered by',
      render: (row) => (
        <span className="aic-cell-stack">
          <span>{row.entered_by ?? '—'}</span>
          <small className="aic-sub">{formatDate(row.entered_at?.slice(0, 10) ?? null)}</small>
        </span>
      ),
    },
  ]
}

export const DEFAULT_COLUMNS = [
  'invoice_no', 'supplier', 'invoice_date', 'due_date', 'amount', 'status', 'payment',
]

// ---------------------------------------------------------------------------
// The table
// ---------------------------------------------------------------------------

export function PayablesTable({
  rows,
  books,
  columns,
  visible,
  selected,
  onSelect,
  sort,
  order,
  onSort,
  refreshing,
  onAct,
}: {
  rows: PayableRow[]
  books: Map<string, BooksOpenItem>
  columns: ColumnDef[]
  visible: string[]
  selected: Set<number>
  onSelect: (next: Set<number>) => void
  sort: string
  order: 'asc' | 'desc'
  onSort: (key: string) => void
  refreshing: boolean
  onAct: (action: 'rematch' | 'post', row: PayableRow) => void
}) {
  const navigate = useNavigate()
  const shown = columns.filter((column) => visible.includes(column.id))
  const allSelected = rows.length > 0 && rows.every((row) => selected.has(row.request_id))

  const toggleAll = () => {
    if (allSelected) {
      onSelect(new Set())
      return
    }
    onSelect(new Set(rows.map((row) => row.request_id)))
  }

  const toggleOne = (id: number) => {
    const next = new Set(selected)
    if (next.has(id)) {
      next.delete(id)
    } else {
      next.add(id)
    }
    onSelect(next)
  }

  return (
    <div className="aic-table-wrap" aria-busy={refreshing}>
      <table className="aic-table">
        <caption className="aic-sr-only">
          Supplier bills. Invoice, supplier, value and workflow state are from this application; due date and
          payment are read live from Smart Books.
        </caption>
        <thead>
          <tr>
            <th scope="col" className="is-checkbox">
              <input
                type="checkbox"
                checked={allSelected}
                onChange={toggleAll}
                aria-label={allSelected ? 'Clear selection' : 'Select every bill on this page'}
              />
            </th>
            {shown.map((column) => (
              <th key={column.id} scope="col" className={column.numeric ? 'is-numeric' : undefined}>
                {column.sortKey ? (
                  <button
                    type="button"
                    onClick={() => onSort(column.sortKey as string)}
                    aria-label={`Sort by ${column.header}`}
                  >
                    {column.header}
                    {sort === column.sortKey ? (
                      order === 'asc' ? <ChevronUp size={12} aria-hidden /> : <ChevronDown size={12} aria-hidden />
                    ) : (
                      <ArrowDownUp size={11} aria-hidden style={{ opacity: 0.35 }} />
                    )}
                  </button>
                ) : (
                  column.header
                )}
              </th>
            ))}
            <th scope="col">
              <span className="aic-sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const item = books.get(`${row.supplier_account_id}|${row.invoice_no ?? ''}`)
            const isSelected = selected.has(row.request_id)

            return (
              <tr key={row.request_id} className={isSelected ? 'is-selected' : undefined}>
                <td className="is-checkbox">
                  <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={() => toggleOne(row.request_id)}
                    aria-label={`Select bill ${row.invoice_no ?? row.request_id}`}
                  />
                </td>
                {shown.map((column) => (
                  <td key={column.id} className={column.numeric ? 'is-numeric' : undefined}>
                    {column.render(row, item)}
                  </td>
                ))}
                <td>
                  <RowActions row={row} onAct={onAct} navigate={navigate} />
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

/**
 * The row menu.
 *
 * Only what this user may actually do, and only what this product actually
 * does. An entry that is hidden here is hidden because the server would refuse
 * it anyway — the menu is the polite version of the same answer, not the
 * control itself.
 */
function RowActions({
  row,
  onAct,
  navigate,
}: {
  row: PayableRow
  onAct: (action: 'rematch' | 'post', row: PayableRow) => void
  navigate: (to: string) => void
}) {
  return (
    <Menu
      label={`Actions for bill ${row.invoice_no ?? row.request_id}`}
      trigger={(props) => (
        <button type="button" className="aic-icon-btn" style={{ width: 30, height: 30 }} {...props}>
          <MoreHorizontal size={15} aria-hidden />
          <span className="aic-sr-only">Actions for bill {row.invoice_no ?? row.request_id}</span>
        </button>
      )}
    >
      {(close) => (
        <>
          <button type="button" role="menuitem" onClick={() => { close(); navigate(row.route) }}>
            <Eye size={14} aria-hidden /> View bill
          </button>

          {row.open_exceptions > 0 && (
            <button type="button" role="menuitem" onClick={() => { close(); navigate(row.route) }}>
              <Scale size={14} aria-hidden /> {row.can_resolve ? 'Decide the exception' : 'See the exception'}
            </button>
          )}

          {row.can_rematch && (
            <button type="button" role="menuitem" onClick={() => { close(); onAct('rematch', row) }}>
              <RefreshCw size={14} aria-hidden /> Re-run the match
            </button>
          )}

          {row.can_post && (
            <button type="button" role="menuitem" onClick={() => { close(); onAct('post', row) }}>
              <Send size={14} aria-hidden /> Post to Smart Books…
            </button>
          )}

          <hr />

          {row.po_no && (
            <button type="button" role="menuitem" onClick={() => { close(); navigate(`/purchase-orders/${row.po_id}`) }}>
              <Truck size={14} aria-hidden /> View the order
            </button>
          )}
          <button
            type="button"
            role="menuitem"
            onClick={() => { close(); navigate(`/suppliers?supplier_id=${row.supplier_account_id}`) }}
          >
            <CornerUpRight size={14} aria-hidden /> View the supplier
          </button>
          {row.duplicate_of !== null && (
            <button type="button" role="menuitem" onClick={() => { close(); navigate(`/bills/${row.duplicate_of}`) }}>
              <Copy size={14} aria-hidden /> Compare with {row.duplicate_of_no ?? `#${row.duplicate_of}`}
            </button>
          )}
          {row.books_voucher_no && (
            <span className="aic-menu__heading" style={{ textTransform: 'none', letterSpacing: 0 }}>
              <FileText size={12} aria-hidden style={{ verticalAlign: '-2px' }} /> Books voucher {row.books_voucher_no}
            </span>
          )}
          {row.last_error && (
            <span className="aic-menu__heading" style={{ textTransform: 'none', letterSpacing: 0, color: 'var(--aic-danger)' }}>
              <ExternalLink size={12} aria-hidden style={{ verticalAlign: '-2px' }} /> {row.last_error}
            </span>
          )}
        </>
      )}
    </Menu>
  )
}
