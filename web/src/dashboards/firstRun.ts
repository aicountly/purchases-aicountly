/**
 * The first-run checklist on the overview (launch-readiness P1-P8, as Inventory's and Billing's): what
 * a new company needs before its first purchase order, in the order it is needed. Pure, so node:test
 * checks it; FirstRunChecklist.tsx reads the counts and draws it.
 *
 * Nothing is created for the person: a supplier is a Smart Books creditor ledger and an item is an
 * Inventory item, both owned there; the checklist only says what is missing and where it is added.
 */

export interface FirstRunCounts {
  /** Creditor ledgers in Smart Books (null: could not be read — never shown as "to do"). */
  suppliers: number | null
  /** Items in Inventory. */
  items: number | null
  /** Purchase orders raised in Purchases. */
  orders: number | null
}

export interface FirstRunCan {
  addSupplier: boolean
  createOrder: boolean
}

export interface FirstRunStep {
  key: 'supplier' | 'item' | 'order'
  title: string
  hint: string
  done: boolean
  /** Where it is done in this app; null when it is done in another product (`external`) or the reader may not. */
  to: string | null
  /** Done in another AICOUNTLY product, opened in a new tab. */
  external: 'inventory' | null
  action: string
}

const isDone = (count: number | null): boolean => count === null || count > 0

export function firstRunSteps(counts: FirstRunCounts, can: FirstRunCan): FirstRunStep[] {
  return [
    {
      key: 'supplier',
      title: 'Add a supplier',
      hint: 'A supplier is a creditor ledger in Smart Books. Add one from a new purchase order — it is created in Smart Books for you.',
      done: isDone(counts.suppliers),
      to: can.addSupplier ? '/purchase-orders/new?new_supplier=1' : null,
      external: null,
      action: 'New supplier',
    },
    {
      key: 'item',
      title: 'Add the items you buy',
      hint: 'Items live in Inventory, with their unit, HSN/SAC and tax. Add them there; they appear here at once.',
      done: isDone(counts.items),
      to: null,
      external: 'inventory',
      action: 'Open Inventory',
    },
    {
      key: 'order',
      title: 'Raise your first purchase order',
      hint: 'Choose the supplier and items, set the agreed rates, and send it for approval.',
      done: isDone(counts.orders),
      to: can.createOrder ? '/purchase-orders/new' : null,
      external: null,
      action: 'New purchase order',
    },
  ]
}

/** True while at least one step is known to be still to do. */
export function needsFirstRun(counts: FirstRunCounts): boolean {
  return !isDone(counts.suppliers) || !isDone(counts.items) || !isDone(counts.orders)
}

/** How many rows a list answer holds: its data array, else null (unreadable). */
export function countOf(answer: unknown): number | null {
  if (answer && typeof answer === 'object' && Array.isArray((answer as { data?: unknown }).data)) {
    return (answer as { data: unknown[] }).data.length
  }
  return null
}
