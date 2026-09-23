/**
 * Search, dates and everything else.
 *
 * The search box is debounced and REPLACES the history entry rather than
 * pushing one: a push per keystroke means Back has to be pressed eleven times
 * to undo "laptops". Everything else pushes, so Back undoes one filter.
 *
 * There is no category control on this screen. Nothing in this product — not
 * the RFQ, not its lines, not the item read through from Inventory — carries a
 * category, so a category filter would be a control that either did nothing or
 * quietly filtered on something else.
 */

import { useEffect, useRef, useState } from 'react'
import { CalendarRange, Search, SlidersHorizontal, X } from 'lucide-react'
import type { RfqFilters, SortField } from '../useSourcing'

const SORTS: { value: SortField; label: string }[] = [
  { value: 'rfq_date', label: 'Date raised' },
  { value: 'response_deadline', label: 'Response deadline' },
  { value: 'rfq_no', label: 'RFQ number' },
  { value: 'status', label: 'Status' },
  { value: 'created_at', label: 'Recently created' },
]

export function RfqFilterBar({
  filters,
  activeCount,
  onChange,
  onOpenAdvanced,
}: {
  filters: RfqFilters
  activeCount: number
  onChange: (patch: Partial<RfqFilters>, options?: { replace?: boolean }) => void
  onOpenAdvanced: () => void
}) {
  const [term, setTerm] = useState(filters.q)
  const typed = useRef(false)

  // The URL can change without this box (a chip is cleared, a link is opened),
  // and when it does the box has to follow it.
  useEffect(() => {
    if (!typed.current) setTerm(filters.q)
  }, [filters.q])

  useEffect(() => {
    if (!typed.current) return
    const timer = setTimeout(() => {
      typed.current = false
      onChange({ q: term.trim() }, { replace: true })
    }, 300)

    return () => clearTimeout(timer)
  }, [term, onChange])

  const dateLabel =
    filters.from && filters.to
      ? `${filters.from} → ${filters.to}`
      : filters.from
        ? `From ${filters.from}`
        : filters.to
          ? `Until ${filters.to}`
          : 'Any date'

  return (
    <div className="sq-toolbar">
      <div className="sq-search">
        <Search size={16} aria-hidden />
        <input
          type="search"
          value={term}
          onChange={(event) => {
            typed.current = true
            setTerm(event.target.value)
          }}
          placeholder="Search RFQ number or title…"
          aria-label="Search RFQs by number or title"
        />
        {term !== '' && (
          <button
            type="button"
            className="sq-search__clear"
            aria-label="Clear the search"
            onClick={() => {
              typed.current = false
              setTerm('')
              onChange({ q: '' })
            }}
          >
            <X size={14} aria-hidden />
          </button>
        )}
      </div>

      <label className="sq-field">
        <span className="sq-visually-hidden">Quotations received</span>
        <select
          value={filters.quotes}
          onChange={(event) => onChange({ quotes: event.target.value as RfqFilters['quotes'] })}
          aria-label="Quotations received"
        >
          <option value="">Any quotations</option>
          <option value="none">Nobody has quoted</option>
          <option value="any">At least one quotation</option>
          <option value="comparable">Two or more (comparable)</option>
        </select>
      </label>

      <label className="sq-field">
        <span className="sq-visually-hidden">Sort by</span>
        <select
          value={filters.sort}
          onChange={(event) => onChange({ sort: event.target.value as SortField })}
          aria-label="Sort by"
        >
          {SORTS.map((sort) => (
            <option key={sort.value} value={sort.value}>
              Sort: {sort.label}
            </option>
          ))}
        </select>
      </label>

      <div className="sq-dates">
        <CalendarRange size={15} aria-hidden />
        <label className="sq-dates__input">
          <span className="sq-visually-hidden">Raised on or after</span>
          <input
            type="date"
            value={filters.from}
            max={filters.to || undefined}
            onChange={(event) => onChange({ from: event.target.value })}
          />
        </label>
        <span className="sq-dates__dash" aria-hidden>
          –
        </span>
        <label className="sq-dates__input">
          <span className="sq-visually-hidden">Raised on or before</span>
          <input
            type="date"
            value={filters.to}
            min={filters.from || undefined}
            onChange={(event) => onChange({ to: event.target.value })}
          />
        </label>
        <span className="sq-visually-hidden">{dateLabel}</span>
      </div>

      <button
        type="button"
        className={activeCount > 0 ? 'sq-button sq-button--outline is-on' : 'sq-button sq-button--outline'}
        onClick={onOpenAdvanced}
        aria-haspopup="dialog"
      >
        <SlidersHorizontal size={15} aria-hidden />
        Filters
        {activeCount > 0 && <span className="sq-button__count">{activeCount}</span>}
      </button>
    </div>
  )
}
