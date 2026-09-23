/**
 * The filter toolbar, and the drawer behind "More filters".
 *
 * Four controls are on the bar because four are used daily: find it, narrow the
 * status, narrow the department, narrow the dates. Everything rarer is one
 * click away rather than on screen competing with them — a toolbar of eleven
 * controls is a toolbar nobody reads.
 *
 * Search is debounced by the page, not here: this component only reports what
 * was typed.
 */

import { useEffect, useRef, useState } from 'react'
import { CalendarDays, ListFilter, Search, X } from 'lucide-react'
import type { FyOption } from '../../services/manage'
import { date as formatDate, money } from '../../ui'
import { Popover } from './ui'
import type { RequisitionFilters } from './filters'

/**
 * The statuses the tabs cannot express.
 *
 * Four tabs cover what a buyer thinks about; the stored statuses are finer than
 * that, and somebody chasing "which of these is already on order" needs the
 * finer one. Choosing here sets the raw status and the tabs fall back to All,
 * which is what the chip underneath then explains.
 */
const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: '', label: 'All statuses' },
  { value: 'DRAFT', label: 'Draft' },
  { value: 'SUBMITTED', label: 'Submitted' },
  { value: 'APPROVAL_PENDING', label: 'Approval pending' },
  { value: 'APPROVED', label: 'Approved' },
  { value: 'SOURCING', label: 'Sourcing' },
  { value: 'ORDERED', label: 'Ordered' },
  { value: 'CLOSED', label: 'Closed' },
  { value: 'REJECTED', label: 'Rejected' },
  { value: 'CANCELLED', label: 'Cancelled' },
]

const PRIORITIES = [
  { value: '', label: 'Any priority' },
  { value: 'high', label: 'High' },
  { value: 'normal', label: 'Normal' },
  { value: 'low', label: 'Low' },
]

const EXCEPTIONS = [
  { value: '', label: 'Any' },
  { value: 'emergency', label: 'Emergency purchase' },
  { value: 'single_source', label: 'Single source' },
  { value: 'non_preferred_vendor', label: 'Non-preferred vendor' },
]

function dateRangeLabel(from: string, to: string, fy: FyOption | null): string {
  if (from && to) return `${formatDate(from)} – ${formatDate(to)}`
  if (from) return `From ${formatDate(from)}`
  if (to) return `Up to ${formatDate(to)}`
  // No range chosen means the financial year in the header, because every query
  // in this API is already scoped to it. Saying so is more honest than "All
  // dates", which would suggest this list reaches outside the year.
  if (fy && fy.start && fy.end) return `${formatDate(fy.start)} – ${formatDate(fy.end)}`
  return fy?.label ?? 'Financial year'
}

export function FilterBar({
  filters,
  search,
  onSearch,
  onChange,
  onReset,
  departments,
  financialYear,
  activeCount,
  searchRef,
}: {
  filters: RequisitionFilters
  /** What is in the box right now, which leads the applied filter by the debounce. */
  search: string
  onSearch: (value: string) => void
  onChange: (patch: Partial<RequisitionFilters>) => void
  onReset: () => void
  departments: { department: string; total: number }[]
  financialYear: FyOption | null
  activeCount: number
  searchRef: React.RefObject<HTMLInputElement | null>
}) {
  return (
    <div className="rq-filters">
      <div className="rq-filters__bar">
        <div className="rq-search">
          <Search size={16} aria-hidden />
          <input
            ref={searchRef}
            type="search"
            value={search}
            onChange={(event) => onSearch(event.target.value)}
            placeholder="Search by requisition no, item, department, requester…"
            aria-label="Search requisitions"
          />
          <kbd className="rq-search__hint" aria-hidden="true">
            {typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl'} K
          </kbd>
        </div>

        <select
          className="rq-control"
          aria-label="Status"
          value={filters.status !== '' ? filters.status : ''}
          onChange={(event) => onChange({ status: event.target.value, bucket: 'all' })}
        >
          {STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <select
          className="rq-control"
          aria-label="Department"
          value={filters.department}
          onChange={(event) => onChange({ department: event.target.value })}
        >
          <option value="">All departments</option>
          {/* Every department that has a requisition, from the server. There is
              no department master in this product — it is free text on the
              record — so this list is what has actually been used. */}
          {departments.map((option) => (
            <option key={option.department} value={option.department}>
              {option.department} ({option.total})
            </option>
          ))}
          {/* A department chosen from a link that nothing currently matches
              would otherwise vanish from the box it is filtering by. */}
          {filters.department !== '' && !departments.some((d) => d.department === filters.department) && (
            <option value={filters.department}>{filters.department}</option>
          )}
        </select>

        <Popover
          label="Choose a date range"
          triggerClassName={filters.dateFrom || filters.dateTo ? 'rq-control rq-control--button is-set' : 'rq-control rq-control--button'}
          trigger={() => (
            <>
              <CalendarDays size={15} aria-hidden />
              <span>{dateRangeLabel(filters.dateFrom, filters.dateTo, financialYear)}</span>
            </>
          )}
        >
          <div className="rq-daterange">
            <label>
              <span>From</span>
              <input
                type="date"
                value={filters.dateFrom}
                min={financialYear?.start || undefined}
                max={filters.dateTo || financialYear?.end || undefined}
                onChange={(event) => onChange({ dateFrom: event.target.value })}
              />
            </label>
            <label>
              <span>To</span>
              <input
                type="date"
                value={filters.dateTo}
                min={filters.dateFrom || financialYear?.start || undefined}
                max={financialYear?.end || undefined}
                onChange={(event) => onChange({ dateTo: event.target.value })}
              />
            </label>

            <div className="rq-daterange__actions">
              {financialYear?.start && financialYear.end && (
                <button
                  type="button"
                  className="rq-textbtn"
                  onClick={() => onChange({ dateFrom: financialYear.start, dateTo: financialYear.end })}
                >
                  Whole {financialYear.label}
                </button>
              )}
              <button type="button" className="rq-textbtn" onClick={() => onChange({ dateFrom: '', dateTo: '' })}>
                Clear dates
              </button>
            </div>
          </div>
        </Popover>

        <MoreFilters filters={filters} onChange={onChange} activeCount={activeCount} />
      </div>

      <Chips filters={filters} onChange={onChange} onReset={onReset} />
    </div>
  )
}

/**
 * The rarer filters, in a drawer.
 *
 * Rendered only while open — it carries four controls nobody needs on a first
 * load, and mounting it with the page would put them in the tab order of every
 * visit for the one visit in ten that uses them.
 */
function MoreFilters({
  filters,
  onChange,
  activeCount,
}: {
  filters: RequisitionFilters
  onChange: (patch: Partial<RequisitionFilters>) => void
  activeCount: number
}) {
  const [open, setOpen] = useState(false)
  const panel = useRef<HTMLDivElement>(null)
  const opener = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!open) return
    panel.current?.querySelector<HTMLElement>('select, input, button')?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        setOpen(false)
        opener.current?.focus()
      }
    }
    document.addEventListener('keydown', onKeyDown, true)
    return () => document.removeEventListener('keydown', onKeyDown, true)
  }, [open])

  return (
    <>
      <button
        ref={opener}
        type="button"
        className={activeCount > 0 ? 'rq-control rq-control--button is-set' : 'rq-control rq-control--button'}
        aria-expanded={open}
        onClick={() => setOpen((was) => !was)}
      >
        <ListFilter size={15} aria-hidden />
        <span>More filters</span>
        {activeCount > 0 && <span className="rq-control__badge">{activeCount}</span>}
      </button>

      {open && (
        <div className="rq-more" role="group" aria-label="More filters" ref={panel}>
          <label className="rq-field">
            <span>Priority</span>
            <select value={filters.priority} onChange={(event) => onChange({ priority: event.target.value })}>
              {PRIORITIES.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="rq-field">
            <span>Routing exception</span>
            <select value={filters.exception} onChange={(event) => onChange({ exception: event.target.value })}>
              {EXCEPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="rq-field">
            <span>Needed by (on or before)</span>
            <input
              type="date"
              value={filters.requiredByBefore}
              onChange={(event) => onChange({ requiredByBefore: event.target.value })}
            />
          </label>

          <div className="rq-field">
            <span>Estimated value</span>
            <div className="rq-field__pair">
              <input
                type="number"
                min={0}
                inputMode="decimal"
                placeholder="Minimum"
                aria-label="Minimum estimated value"
                value={filters.minValue}
                onChange={(event) => onChange({ minValue: event.target.value })}
              />
              <input
                type="number"
                min={0}
                inputMode="decimal"
                placeholder="Maximum"
                aria-label="Maximum estimated value"
                value={filters.maxValue}
                onChange={(event) => onChange({ maxValue: event.target.value })}
              />
            </div>
          </div>

          <label className="rq-check">
            <input type="checkbox" checked={filters.mine} onChange={(event) => onChange({ mine: event.target.checked })} />
            <span>Only requisitions I raised</span>
          </label>

          <div className="rq-more__actions">
            <button
              type="button"
              className="rq-btn rq-btn--quiet"
              onClick={() =>
                onChange({
                  priority: '',
                  exception: '',
                  requiredByBefore: '',
                  minValue: '',
                  maxValue: '',
                  mine: false,
                })
              }
            >
              Reset these
            </button>
            <button type="button" className="rq-btn rq-btn--secondary rq-btn--sm" onClick={() => setOpen(false)}>
              Done
            </button>
          </div>
        </div>
      )}
    </>
  )
}

/**
 * What is currently narrowing the list, and how to stop it narrowing.
 *
 * Only shown when something is on. A filter applied in a drawer that is now
 * closed is otherwise invisible, and an empty table with no explanation is the
 * most common way a list screen wastes somebody's afternoon.
 */
function Chips({
  filters,
  onChange,
  onReset,
}: {
  filters: RequisitionFilters
  onChange: (patch: Partial<RequisitionFilters>) => void
  onReset: () => void
}) {
  const chips: { key: string; label: string; clear: Partial<RequisitionFilters> }[] = []

  if (filters.status) {
    chips.push({
      key: 'status',
      label: `Status: ${STATUS_OPTIONS.find((o) => o.value === filters.status)?.label ?? filters.status}`,
      clear: { status: '' },
    })
  }
  if (filters.department) chips.push({ key: 'department', label: `Department: ${filters.department}`, clear: { department: '' } })
  if (filters.dateFrom || filters.dateTo) {
    chips.push({
      key: 'dates',
      label: `Raised: ${filters.dateFrom ? formatDate(filters.dateFrom) : 'any'} – ${filters.dateTo ? formatDate(filters.dateTo) : 'any'}`,
      clear: { dateFrom: '', dateTo: '' },
    })
  }
  if (filters.requiredByBefore) {
    chips.push({ key: 'needed', label: `Needed by ${formatDate(filters.requiredByBefore)}`, clear: { requiredByBefore: '' } })
  }
  if (filters.priority) {
    chips.push({ key: 'priority', label: `Priority: ${filters.priority}`, clear: { priority: '' } })
  }
  if (filters.minValue || filters.maxValue) {
    const min = filters.minValue ? money(Number(filters.minValue), 'INR', 0) : null
    const max = filters.maxValue ? money(Number(filters.maxValue), 'INR', 0) : null
    chips.push({
      key: 'value',
      label: min && max ? `Value: ${min} – ${max}` : min ? `Value: over ${min}` : `Value: under ${max}`,
      clear: { minValue: '', maxValue: '' },
    })
  }
  if (filters.exception) {
    chips.push({
      key: 'exception',
      label: EXCEPTIONS.find((o) => o.value === filters.exception)?.label ?? filters.exception,
      clear: { exception: '' },
    })
  }
  if (filters.mine) chips.push({ key: 'mine', label: 'Raised by me', clear: { mine: false } })
  if (filters.q) chips.push({ key: 'q', label: `Matching “${filters.q}”`, clear: { q: '' } })

  if (chips.length === 0) return null

  return (
    <div className="rq-chips">
      {chips.map((chip) => (
        <span className="rq-chip" key={chip.key}>
          {chip.label}
          <button type="button" onClick={() => onChange(chip.clear)} aria-label={`Remove filter: ${chip.label}`}>
            <X size={12} aria-hidden />
          </button>
        </span>
      ))}
      <button type="button" className="rq-textbtn" onClick={onReset}>
        Clear all
      </button>
    </div>
  )
}
