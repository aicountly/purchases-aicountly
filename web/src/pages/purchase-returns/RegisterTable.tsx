/**
 * The register.
 *
 * Dense on purpose: a buyer working through returns wants to see twenty of
 * them, and the colour that is spent goes on status because status is what
 * they are scanning for. The header sticks, the table scrolls sideways rather
 * than dropping columns, and every row opens.
 *
 * The row-action menu offers only what the return's status and this user's
 * permissions actually allow. An action that would be refused is shown disabled
 * with the reason on it rather than hidden, so "why can I not do this" has an
 * answer without anybody having to guess.
 */

import { Link } from 'react-router-dom'
import {
  ArrowDown,
  ArrowUp,
  Ban,
  ChevronsUpDown,
  Copy,
  Eye,
  FileCheck2,
  FileText,
  Package,
  PenLine,
  Receipt,
  Stamp,
  Truck,
} from 'lucide-react'
import type { PurchaseReturnRow } from './types'
import type { SortKey } from './filters'
import { CreditPill, LinkDot, RowMenu, StatusPill, shortDate, type MenuAction } from './ui'

export interface RowActions {
  onOpen: (row: PurchaseReturnRow) => void
  onApprove: (row: PurchaseReturnRow) => void
  onDispatch: (row: PurchaseReturnRow) => void
  onDebitNote: (row: PurchaseReturnRow) => void
  onCancel: (row: PurchaseReturnRow) => void
  onSupplierCredit: (row: PurchaseReturnRow) => void
  onDuplicate: (row: PurchaseReturnRow) => void
  onPrint: (row: PurchaseReturnRow) => void
}

export interface Permissions {
  create: boolean
  approve: boolean
}

const COLUMNS: Array<{ key: string; label: string; sort?: SortKey; numeric?: boolean }> = [
  { key: 'no', label: 'Return no.', sort: 'return_no' },
  { key: 'date', label: 'Date', sort: 'return_date' },
  { key: 'supplier', label: 'Supplier', sort: 'supplier' },
  { key: 'reference', label: 'Reference' },
  { key: 'items', label: 'Items', numeric: true },
  { key: 'value', label: 'Value (INR)', sort: 'return_value', numeric: true },
  { key: 'status', label: 'Status', sort: 'status' },
  { key: 'credit', label: 'Supplier credit' },
  { key: 'links', label: 'Linked to' },
  { key: 'actions', label: '' },
]

/**
 * What can be done to a return in the state it is in.
 *
 * The same rules the API enforces, stated here so a button that would be
 * refused is not offered. This is a courtesy and not a control: every one of
 * these is checked again in ReturnClaimService, one curl away from this bundle.
 */
export function actionsFor(
  row: PurchaseReturnRow,
  can: Permissions,
  handlers: RowActions,
): MenuAction[] {
  const isDraft = row.status === 'DRAFT'
  const isApproved = row.status === 'APPROVED'
  const finished = row.status === 'CANCELLED' || row.status === 'CLOSED'
  const accounted = row.books.status === 'POSTED'

  return [
    { label: 'Open', icon: <Eye size={15} aria-hidden />, onSelect: () => handlers.onOpen(row) },
    {
      label: 'Edit',
      icon: <PenLine size={15} aria-hidden />,
      onSelect: () => handlers.onOpen(row),
      disabled: !isDraft || !can.create,
      title: isDraft ? undefined : 'Only a draft can be changed.',
    },
    {
      label: 'Print the return note',
      icon: <FileText size={15} aria-hidden />,
      onSelect: () => handlers.onPrint(row),
    },
    {
      label: 'Approve',
      icon: <Stamp size={15} aria-hidden />,
      onSelect: () => handlers.onApprove(row),
      disabled: !isDraft || !can.approve,
      title: !can.approve ? 'You cannot approve returns.' : isDraft ? undefined : 'This return is past approval.',
      separatorBefore: true,
    },
    {
      label: 'Send the goods back',
      icon: <Truck size={15} aria-hidden />,
      onSelect: () => handlers.onDispatch(row),
      disabled: !isApproved || !can.approve,
      title: isApproved ? 'Inventory moves the stock out.' : 'Approve the return first.',
    },
    {
      label: 'Raise the debit note',
      icon: <Receipt size={15} aria-hidden />,
      onSelect: () => handlers.onDebitNote(row),
      disabled: accounted || !(isApproved || row.status === 'DISPATCHED') || !can.approve,
      title: accounted ? 'Smart Books already holds a debit note for this return.' : 'Smart Books raises the debit note.',
    },
    {
      label: 'Record the supplier credit',
      icon: <FileCheck2 size={15} aria-hidden />,
      onSelect: () => handlers.onSupplierCredit(row),
      disabled: !can.approve || finished,
      title: 'The credit note the supplier issued against this return.',
      separatorBefore: true,
    },
    {
      label: 'Duplicate',
      icon: <Copy size={15} aria-hidden />,
      onSelect: () => handlers.onDuplicate(row),
      disabled: !can.create,
    },
    {
      label: 'Cancel this return',
      icon: <Ban size={15} aria-hidden />,
      onSelect: () => handlers.onCancel(row),
      // Never offered once Inventory has moved the goods or Books has raised
      // the note: cancelling here would leave this product saying a return
      // never happened while two others hold documents that say it did.
      disabled: !(isDraft || isApproved) || !can.approve || row.inventory.status === 'POSTED' || accounted,
      title:
        row.inventory.status === 'POSTED' || accounted
          ? 'The goods have moved or the debit note is raised. Reverse it in the product that holds the document.'
          : undefined,
      danger: true,
      separatorBefore: true,
    },
  ]
}

function SortHeader({
  label,
  column,
  sort,
  order,
  onSort,
}: {
  label: string
  column?: SortKey
  sort: SortKey
  order: 'asc' | 'desc'
  onSort: (sort: SortKey) => void
}) {
  if (!column) return <>{label}</>

  const active = sort === column
  const Icon = !active ? ChevronsUpDown : order === 'asc' ? ArrowUp : ArrowDown

  return (
    <button
      type="button"
      className="pr-table__sort"
      onClick={() => onSort(column)}
      aria-label={`Sort by ${label}${active ? (order === 'asc' ? ', currently ascending' : ', currently descending') : ''}`}
    >
      {label}
      <Icon size={12} aria-hidden style={{ opacity: active ? 1 : 0.45 }} />
    </button>
  )
}

export function RegisterTable({
  rows,
  selected,
  sort,
  order,
  can,
  handlers,
  onSort,
  onSelect,
  onSelectAll,
}: {
  rows: PurchaseReturnRow[]
  selected: Set<number>
  sort: SortKey
  order: 'asc' | 'desc'
  can: Permissions
  handlers: RowActions
  onSort: (sort: SortKey) => void
  onSelect: (id: number, checked: boolean) => void
  onSelectAll: (checked: boolean) => void
}) {
  const allSelected = rows.length > 0 && rows.every((row) => selected.has(row.return_id))

  return (
    <div className="pr-table-scroll">
      <table className="pr-table">
        <caption className="pr-sr-only">
          Purchase returns, with their supplier, value, status and whether Inventory and Smart Books hold a document
          for each.
        </caption>
        <thead>
          <tr>
            <th scope="col" style={{ width: 40 }}>
              <input
                type="checkbox"
                className="pr-checkbox"
                checked={allSelected}
                aria-label="Select every return on this page"
                onChange={(event) => onSelectAll(event.target.checked)}
              />
            </th>
            {COLUMNS.map((column) => (
              <th
                key={column.key}
                scope="col"
                className={
                  column.key === 'actions' ? 'pr-table__stick' : column.numeric ? 'is-numeric' : undefined
                }
                aria-sort={
                  column.sort === sort ? (order === 'asc' ? 'ascending' : 'descending') : undefined
                }
              >
                {column.key === 'actions' ? (
                  <span className="pr-sr-only">Actions</span>
                ) : (
                  <SortHeader label={column.label} column={column.sort} sort={sort} order={order} onSort={onSort} />
                )}
              </th>
            ))}
          </tr>
        </thead>

        <tbody>
          {rows.map((row) => (
            <tr key={row.return_id} className={selected.has(row.return_id) ? 'is-selected' : undefined}>
              <td>
                <input
                  type="checkbox"
                  className="pr-checkbox"
                  checked={selected.has(row.return_id)}
                  aria-label={`Select ${row.return_no}`}
                  onChange={(event) => onSelect(row.return_id, event.target.checked)}
                />
              </td>

              <td>
                {/* A real link, so it opens in a new tab and copies as a URL.
                    The detail page and the drawer show the same thing. */}
                <Link className="pr-link" to={`/returns/${row.return_id}`}>
                  {row.return_no}
                </Link>
              </td>

              <td>{shortDate(row.return_date)}</td>

              <td>
                <span className="pr-truncate pr-cell-strong" title={row.supplier_name ?? undefined}>
                  {row.supplier_name ?? `Account ${row.supplier_account_id}`}
                </span>
                {row.reason && <span className="pr-cell-sub">{row.reason.label}</span>}
              </td>

              <td>
                {row.source?.number ? (
                  <Link className="pr-link" to={`/purchase-orders/${row.source.id}`}>
                    {row.source.number}
                  </Link>
                ) : (
                  <span style={{ color: 'var(--pr-muted)' }}>—</span>
                )}
              </td>

              <td className="is-numeric">{row.item_count}</td>

              <td className="is-numeric pr-cell-strong">{row.total_value_formatted}</td>

              <td>
                <StatusPill status={row.status} label={row.status_label} />
              </td>

              <td>
                <CreditPill status={row.supplier_credit.status} label={row.supplier_credit.label} />
                {row.supplier_credit.reference && (
                  <span className="pr-cell-sub">{row.supplier_credit.reference}</span>
                )}
              </td>

              <td>
                <span className="pr-links">
                  <LinkDot label="Stock" status={row.inventory.status} reference={row.inventory.reference} />
                  <LinkDot label="Books" status={row.books.status} reference={row.books.reference} />
                </span>
              </td>

              {/* Pinned to the right edge: on a narrow window the register
                  scrolls sideways, and the actions must not scroll away with
                  it. */}
              <td className="pr-table__stick">
                <RowMenu label={`Actions for ${row.return_no}`} actions={actionsFor(row, can, handlers)} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * The selection bar, only while something is selected.
 *
 * ONE BULK ACTION, because there is exactly one the backend supports safely.
 * Approving is this product's own decision and is validated per return; sending
 * goods back and raising debit notes are writes into Inventory and Smart Books
 * and are not things to fire twenty of from a checkbox. Those stay on the
 * return, where the consequence is spelled out.
 */
export function BulkBar({
  count,
  approvable,
  canApprove,
  busy,
  onApprove,
  onClear,
}: {
  count: number
  approvable: number
  canApprove: boolean
  busy: boolean
  onApprove: () => void
  onClear: () => void
}) {
  if (count === 0) return null

  return (
    <div className="pr-bulkbar">
      <Package size={15} aria-hidden />
      <span>
        {count} return{count === 1 ? '' : 's'} selected
      </span>
      <span style={{ flex: 1 }} />
      <button
        type="button"
        className="pr-btn pr-btn--small"
        disabled={busy || !canApprove || approvable === 0}
        title={
          !canApprove
            ? 'You cannot approve returns.'
            : approvable === 0
              ? 'None of the selected returns is a draft.'
              : undefined
        }
        onClick={onApprove}
      >
        <Stamp size={13} aria-hidden />
        {busy ? 'Approving…' : `Approve ${approvable} draft${approvable === 1 ? '' : 's'}`}
      </button>
      <button type="button" className="pr-btn pr-btn--quiet pr-btn--small" onClick={onClear}>
        Clear
      </button>
    </div>
  )
}
