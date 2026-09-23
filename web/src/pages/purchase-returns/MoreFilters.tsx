/**
 * The filters that do not fit on the bar.
 *
 * Applied together, on Apply, rather than one at a time as they are touched: a
 * panel that refetched on every field would have the register flickering
 * underneath somebody who is still deciding. Reset clears what is in the panel;
 * Clear all clears the bar as well, which is a different thing and is labelled
 * as one.
 */

import { useEffect, useState } from 'react'
import { ADVANCED_KEYS, EMPTY_FILTERS, type ReturnsFilters } from './filters'
import type { ReturnOptions } from './types'
import { Drawer, Notice } from './ui'

const LINK_STATES = [
  { value: '', label: 'Any' },
  { value: 'PENDING', label: 'Nothing yet' },
  { value: 'POSTED', label: 'Document held' },
]

export function MoreFilters({
  open,
  filters,
  options,
  onApply,
  onClose,
}: {
  open: boolean
  filters: ReturnsFilters
  options: ReturnOptions | null
  onApply: (next: Partial<ReturnsFilters>) => void
  onClose: () => void
}) {
  const [draft, setDraft] = useState<ReturnsFilters>(filters)

  // Reopening shows what is actually applied, not what was abandoned last time.
  useEffect(() => {
    if (open) setDraft(filters)
  }, [open, filters])

  const set = (changes: Partial<ReturnsFilters>) => setDraft((current) => ({ ...current, ...changes }))

  const clearPanel = () => {
    const cleared: Partial<ReturnsFilters> = {}
    for (const key of ADVANCED_KEYS) cleared[key] = EMPTY_FILTERS[key]
    setDraft((current) => ({ ...current, ...cleared }))
  }

  return (
    <Drawer
      open={open}
      title="More filters"
      subtitle="Narrow the register by reason, by where the return has got to, or by what it is worth."
      onClose={onClose}
      footer={
        <>
          <button type="button" className="pr-btn pr-btn--quiet" onClick={clearPanel}>
            Reset these
          </button>
          <button type="button" className="pr-btn" onClick={onClose}>
            Cancel
          </button>
          <button
            type="button"
            className="pr-btn pr-btn--primary"
            onClick={() => {
              const changes: Partial<ReturnsFilters> = {}
              for (const key of ADVANCED_KEYS) changes[key] = draft[key]
              onApply(changes)
              onClose()
            }}
          >
            Apply filters
          </button>
        </>
      }
    >
      <div className="pr-section">
        <h3>Why it went back</h3>
        <label className="pr-field">
          <span>Return reason</span>
          <select value={draft.reason} onChange={(event) => set({ reason: event.target.value })}>
            <option value="">Any reason</option>
            {(options?.reasons ?? []).map((reason) => (
              <option key={reason.value} value={reason.value}>
                {reason.label}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="pr-section">
        <h3>Money</h3>
        <div className="pr-grid-2">
          <label className="pr-field">
            <span>Worth at least</span>
            <input
              type="number"
              inputMode="decimal"
              min={0}
              value={draft.min_value}
              placeholder="0"
              onChange={(event) => set({ min_value: event.target.value })}
            />
          </label>
          <label className="pr-field">
            <span>Worth at most</span>
            <input
              type="number"
              inputMode="decimal"
              min={0}
              value={draft.max_value}
              placeholder="Any"
              onChange={(event) => set({ max_value: event.target.value })}
            />
          </label>
        </div>

        <label className="pr-field" style={{ marginTop: 12 }}>
          <span>Supplier credit</span>
          <select value={draft.supplier_credit} onChange={(event) => set({ supplier_credit: event.target.value })}>
            <option value="">Any</option>
            {(options?.credit_statuses ?? []).map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </select>
          <span className="pr-field__hint">
            The credit note the supplier issues. Separate from the debit note this company raises in Smart Books.
          </span>
        </label>
      </div>

      <div className="pr-section">
        <h3>Where it has got to</h3>
        <div className="pr-grid-2">
          <label className="pr-field">
            <span>Stock movement in Inventory</span>
            <select value={draft.inventory} onChange={(event) => set({ inventory: event.target.value })}>
              {LINK_STATES.map((state) => (
                <option key={state.value} value={state.value}>
                  {state.label}
                </option>
              ))}
            </select>
          </label>
          <label className="pr-field">
            <span>Debit note in Smart Books</span>
            <select value={draft.books} onChange={(event) => set({ books: event.target.value })}>
              {LINK_STATES.map((state) => (
                <option key={state.value} value={state.value}>
                  {state.label}
                </option>
              ))}
            </select>
          </label>
        </div>
        <Notice tone="plain">
          These filter on whether this product holds a reference to the other document. What that document says is read
          from Inventory and Smart Books when you open the return.
        </Notice>
      </div>

      <div className="pr-section">
        <h3>Source document</h3>
        <label className="pr-field">
          <span>Purchase order</span>
          <input
            type="number"
            inputMode="numeric"
            min={1}
            value={draft.po_id}
            placeholder="Order id"
            onChange={(event) => set({ po_id: event.target.value })}
          />
          <span className="pr-field__hint">
            Every return raised against one purchase order. Open an order and choose “Returns against this order” to
            arrive here with it filled in.
          </span>
        </label>
      </div>
    </Drawer>
  )
}
