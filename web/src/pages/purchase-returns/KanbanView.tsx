/**
 * The register as a workflow.
 *
 * The columns are the BACKEND's states, in the order a return passes through
 * them — not a set of stages invented for a board. A return that is cancelled
 * has left the workflow and gets its own column at the end rather than being
 * dropped, because a board that silently hides records is a board that makes
 * people count twice.
 *
 * DELIBERATELY NOT DRAGGABLE. Three of these transitions are writes into other
 * products: sending goods back moves stock in Inventory, and raising a debit
 * note posts a document in Smart Books. Neither is something to set off by
 * dropping a card, and neither can be undone by dragging it back. The cards
 * open the return, where each step is a button that says what it will do.
 */

import { Columns3 } from 'lucide-react'
import type { PurchaseReturnRow, ReturnStatus } from './types'
import { ageInDays, EmptyState, LinkDot, inrShort } from './ui'

const COLUMNS: Array<{ status: ReturnStatus; title: string; hint: string }> = [
  { status: 'DRAFT', title: 'Draft', hint: 'Raised, not yet approved' },
  { status: 'APPROVED', title: 'Approved', hint: 'Waiting for the goods to go back' },
  { status: 'DISPATCHED', title: 'Goods returned', hint: 'Inventory has moved the stock out' },
  { status: 'DEBITED', title: 'Debit note raised', hint: 'Smart Books holds the adjustment' },
  { status: 'CLOSED', title: 'Completed', hint: 'Nothing outstanding' },
  { status: 'CANCELLED', title: 'Cancelled', hint: 'Abandoned before anything moved' },
]

export function KanbanView({
  rows,
  onOpen,
}: {
  rows: PurchaseReturnRow[]
  onOpen: (row: PurchaseReturnRow) => void
}) {
  if (rows.length === 0) {
    return (
      <EmptyState icon={<Columns3 size={26} />} title="Nothing on the board">
        No returns match the current filters, so there is nothing to lay out.
      </EmptyState>
    )
  }

  // An empty CANCELLED column at the end of every board is a column that only
  // ever says nothing. The workflow columns always show, so the shape of the
  // process is visible even on a quiet week.
  const visible = COLUMNS.filter(
    (column) =>
      column.status !== 'CANCELLED' && column.status !== 'CLOSED'
        ? true
        : rows.some((row) => row.status === column.status),
  )

  return (
    <div className="pr-kanban">
      {visible.map((column) => {
        const cards = rows.filter((row) => row.status === column.status)

        return (
          <section className="pr-kanban__col" key={column.status} aria-label={`${column.title}: ${cards.length}`}>
            <header className="pr-kanban__col-head">
              <h3 className="pr-kanban__col-title" title={column.hint}>
                {column.title}
              </h3>
              <span className="pr-kanban__count">{cards.length}</span>
            </header>

            {cards.length === 0 ? (
              <p className="pr-kanban__empty">Nothing here.</p>
            ) : (
              cards.map((row) => {
                const age = ageInDays(row.return_date)

                return (
                  <button key={row.return_id} type="button" className="pr-kanban__card" onClick={() => onOpen(row)}>
                    <span className="pr-kanban__card-top">
                      <span className="pr-kanban__no">{row.return_no}</span>
                      <span className="pr-kanban__value">{inrShort(row.total_value)}</span>
                    </span>

                    <span className="pr-kanban__supplier">
                      {row.supplier_name ?? `Account ${row.supplier_account_id}`}
                    </span>

                    <span className="pr-kanban__meta">
                      <span>
                        {row.item_count} item{row.item_count === 1 ? '' : 's'}
                        {age !== null && ` · ${age === 0 ? 'today' : `${age}d old`}`}
                      </span>
                      <span className="pr-links">
                        <LinkDot label="Stock" status={row.inventory.status} reference={row.inventory.reference} />
                        <LinkDot label="Books" status={row.books.status} reference={row.books.reference} />
                      </span>
                    </span>
                  </button>
                )
              })
            )}
          </section>
        )
      })}
    </div>
  )
}
