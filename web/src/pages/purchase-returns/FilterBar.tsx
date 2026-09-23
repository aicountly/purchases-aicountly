/**
 * The toolbar above the register, and the chips that say what it is doing.
 *
 * Every control writes to the URL rather than to component state, so a filtered
 * register is a link somebody can send. The chips underneath exist because a
 * filter you cannot see is a filter that makes the screen look broken: "there
 * are no returns" and "there are no returns matching the four things you set
 * last Tuesday" are different sentences.
 */

import { useEffect, useRef, useState } from 'react'
import {
  CalendarDays,
  Columns3,
  LayoutList,
  Search,
  SlidersHorizontal,
  Users,
  X,
} from 'lucide-react'
import { SupplierPicker } from '../../components/LivePicker'
import { DATE_PRESETS, type ReturnsFilters, type ReturnsView } from './filters'
import type { ReturnOptions } from './types'
import { Popover, shortDate } from './ui'

const VIEW_LABELS: Array<{ id: ReturnsView; label: string; icon: typeof LayoutList }> = [
  { id: 'list', label: 'List', icon: LayoutList },
  { id: 'calendar', label: 'Calendar', icon: CalendarDays },
  { id: 'kanban', label: 'Kanban', icon: Columns3 },
]

export function FilterBar({
  filters,
  view,
  options,
  advancedCount,
  supplierLabel,
  onFilters,
  onView,
  onOpenMoreFilters,
  onSupplierLabel,
}: {
  filters: ReturnsFilters
  view: ReturnsView
  options: ReturnOptions | null
  advancedCount: number
  supplierLabel: string | null
  onFilters: (next: Partial<ReturnsFilters>, options?: { replace?: boolean }) => void
  onView: (view: ReturnsView) => void
  onOpenMoreFilters: () => void
  onSupplierLabel: (label: string | null) => void
}) {
  // The box holds what was typed; the URL is updated from it. Driving the input
  // straight off the URL makes the cursor jump on every keystroke, because
  // React re-renders it from a value that is one render behind.
  const [term, setTerm] = useState(filters.q)
  const typing = useRef(false)

  useEffect(() => {
    // A change that did not come from this box — a cleared chip, a link, Back.
    if (!typing.current) setTerm(filters.q)
  }, [filters.q])

  const dateLabel =
    filters.from === '' && filters.to === ''
      ? 'All time'
      : `${shortDate(filters.from)} – ${shortDate(filters.to)}`

  const statusLabel =
    filters.status === ''
      ? 'All statuses'
      : (options?.statuses.find((status) => status.value === filters.status)?.label ?? filters.status)

  return (
    <div className="pr-toolbar">
      <div className="pr-toolbar__left">
        <div className="pr-search">
          <Search size={15} aria-hidden />
          <input
            type="search"
            value={term}
            placeholder="Search returns by no., supplier or reference…"
            aria-label="Search purchase returns"
            onChange={(event) => {
              typing.current = true
              setTerm(event.target.value)
              // `replace` so Back undoes the search rather than stepping through
              // every keystroke of it.
              onFilters({ q: event.target.value }, { replace: true })
            }}
            onBlur={() => {
              typing.current = false
            }}
          />
          {term !== '' && (
            <button
              type="button"
              className="pr-search__clear"
              aria-label="Clear the search"
              onClick={() => {
                typing.current = false
                setTerm('')
                onFilters({ q: '' }, { replace: true })
              }}
            >
              <X size={14} aria-hidden />
            </button>
          )}
        </div>

        <Popover
          label="Date range"
          trigger={({ open, toggle, ref }) => (
            <button
              ref={ref}
              type="button"
              className={filters.from !== '' || filters.to !== '' ? 'pr-control is-set' : 'pr-control'}
              aria-haspopup="dialog"
              aria-expanded={open}
              onClick={toggle}
            >
              <CalendarDays size={14} aria-hidden />
              <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{dateLabel}</span>
            </button>
          )}
        >
          {(close) => (
            <>
              <p className="pr-pop__title">Period</p>
              {DATE_PRESETS.map((preset) => {
                const range = preset.range()
                const active = range.from === filters.from && range.to === filters.to

                return (
                  <button
                    key={preset.id}
                    type="button"
                    className={active ? 'pr-pop__option is-active' : 'pr-pop__option'}
                    onClick={() => {
                      onFilters({ from: range.from, to: range.to })
                      close()
                    }}
                  >
                    {preset.label}
                  </button>
                )
              })}

              <div className="pr-pop__foot" style={{ display: 'grid', gap: 10 }}>
                <label className="pr-field">
                  <span>From</span>
                  <input
                    type="date"
                    value={filters.from}
                    max={filters.to || undefined}
                    onChange={(event) => onFilters({ from: event.target.value, to: filters.to })}
                  />
                </label>
                <label className="pr-field">
                  <span>To</span>
                  <input
                    type="date"
                    value={filters.to}
                    min={filters.from || undefined}
                    onChange={(event) => onFilters({ from: filters.from, to: event.target.value })}
                  />
                </label>
              </div>
            </>
          )}
        </Popover>

        <Popover
          label="Supplier"
          trigger={({ open, toggle, ref }) => (
            <button
              ref={ref}
              type="button"
              className={filters.supplier_id !== '' ? 'pr-control is-set' : 'pr-control'}
              aria-haspopup="dialog"
              aria-expanded={open}
              onClick={toggle}
            >
              <Users size={14} aria-hidden />
              <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {filters.supplier_id === ''
                  ? 'All suppliers'
                  : (supplierLabel ?? `Account ${filters.supplier_id}`)}
              </span>
            </button>
          )}
        >
          {(close) => (
            <>
              <p className="pr-pop__title">Supplier</p>
              {/* Suppliers are Smart Books' party ledgers, searched live. This
                  product holds no list of them to search instead. */}
              <SupplierPicker
                selectedLabel={supplierLabel}
                onPick={(supplier) => {
                  onSupplierLabel(supplier.acc_name)
                  onFilters({ supplier_id: String(supplier.acc_id) })
                  close()
                }}
              />
              {filters.supplier_id !== '' && (
                <div className="pr-pop__foot">
                  <button
                    type="button"
                    className="pr-btn pr-btn--small"
                    onClick={() => {
                      onSupplierLabel(null)
                      onFilters({ supplier_id: '' })
                      close()
                    }}
                  >
                    Any supplier
                  </button>
                </div>
              )}
            </>
          )}
        </Popover>

        <Popover
          label="Status"
          trigger={({ open, toggle, ref }) => (
            <button
              ref={ref}
              type="button"
              className={filters.status !== '' ? 'pr-control is-set' : 'pr-control'}
              aria-haspopup="dialog"
              aria-expanded={open}
              onClick={toggle}
            >
              <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{statusLabel}</span>
            </button>
          )}
        >
          {(close) => (
            <>
              <p className="pr-pop__title">Status</p>
              <button
                type="button"
                className={filters.status === '' ? 'pr-pop__option is-active' : 'pr-pop__option'}
                onClick={() => {
                  onFilters({ status: '' })
                  close()
                }}
              >
                All statuses
              </button>
              {(options?.statuses ?? []).map((status) => (
                <button
                  key={status.value}
                  type="button"
                  className={filters.status === status.value ? 'pr-pop__option is-active' : 'pr-pop__option'}
                  onClick={() => {
                    onFilters({ status: status.value })
                    close()
                  }}
                >
                  {status.label}
                </button>
              ))}
            </>
          )}
        </Popover>

        <button
          type="button"
          className={advancedCount > 0 ? 'pr-control is-set' : 'pr-control'}
          onClick={onOpenMoreFilters}
        >
          <SlidersHorizontal size={14} aria-hidden />
          More filters
          {advancedCount > 0 && <span className="pr-control__count">{advancedCount}</span>}
        </button>
      </div>

      <div className="pr-viewswitch" role="group" aria-label="How to show the returns">
        {VIEW_LABELS.map((entry) => {
          const Icon = entry.icon

          return (
            <button
              key={entry.id}
              type="button"
              className={view === entry.id ? 'is-active' : undefined}
              aria-pressed={view === entry.id}
              onClick={() => onView(entry.id)}
            >
              <Icon size={14} aria-hidden />
              {entry.label}
            </button>
          )
        })}
      </div>
    </div>
  )
}

/**
 * What is currently being filtered, as removable chips.
 *
 * Only the filters that are SET appear, and each one removes itself. Without
 * this the More filters panel is the only place four narrowing conditions are
 * visible, and nobody opens it to find out why the register looks empty.
 */
export function FilterChips({
  filters,
  options,
  supplierLabel,
  onFilters,
  onClearAll,
}: {
  filters: ReturnsFilters
  options: ReturnOptions | null
  supplierLabel: string | null
  onFilters: (next: Partial<ReturnsFilters>) => void
  onClearAll: () => void
}) {
  const chips: Array<{ key: keyof ReturnsFilters | 'range'; label: string; clear: () => void }> = []

  if (filters.q !== '') {
    chips.push({ key: 'q', label: `“${filters.q}”`, clear: () => onFilters({ q: '' }) })
  }
  if (filters.status !== '') {
    chips.push({
      key: 'status',
      label: options?.statuses.find((status) => status.value === filters.status)?.label ?? filters.status,
      clear: () => onFilters({ status: '' }),
    })
  }
  if (filters.supplier_id !== '') {
    chips.push({
      key: 'supplier_id',
      label: supplierLabel ?? `Account ${filters.supplier_id}`,
      clear: () => onFilters({ supplier_id: '' }),
    })
  }
  if (filters.reason !== '') {
    chips.push({
      key: 'reason',
      label: options?.reasons.find((reason) => reason.value === filters.reason)?.label ?? filters.reason,
      clear: () => onFilters({ reason: '' }),
    })
  }
  if (filters.supplier_credit !== '') {
    chips.push({
      key: 'supplier_credit',
      label:
        options?.credit_statuses.find((status) => status.value === filters.supplier_credit)?.label ??
        filters.supplier_credit,
      clear: () => onFilters({ supplier_credit: '' }),
    })
  }
  if (filters.inventory !== '') {
    chips.push({
      key: 'inventory',
      label: `Inventory ${filters.inventory.toLowerCase()}`,
      clear: () => onFilters({ inventory: '' }),
    })
  }
  if (filters.books !== '') {
    chips.push({
      key: 'books',
      label: `Books ${filters.books.toLowerCase()}`,
      clear: () => onFilters({ books: '' }),
    })
  }
  if (filters.min_value !== '' || filters.max_value !== '') {
    chips.push({
      key: 'min_value',
      label:
        filters.min_value !== '' && filters.max_value !== ''
          ? `₹${filters.min_value} – ₹${filters.max_value}`
          : filters.min_value !== ''
            ? `Over ₹${filters.min_value}`
            : `Under ₹${filters.max_value}`,
      clear: () => onFilters({ min_value: '', max_value: '' }),
    })
  }
  if (filters.po_id !== '') {
    chips.push({ key: 'po_id', label: `Order #${filters.po_id}`, clear: () => onFilters({ po_id: '' }) })
  }
  if (filters.created_by !== '') {
    chips.push({ key: 'created_by', label: 'Raised by one person', clear: () => onFilters({ created_by: '' }) })
  }

  if (chips.length === 0) return null

  return (
    <div className="pr-chips">
      {chips.map((chip) => (
        <span className="pr-chip" key={chip.key}>
          {chip.label}
          <button type="button" onClick={chip.clear} aria-label={`Remove the ${chip.label} filter`}>
            <X size={12} aria-hidden />
          </button>
        </span>
      ))}
      <button type="button" className="pr-btn pr-btn--quiet pr-btn--small" onClick={onClearAll}>
        Clear all
      </button>
    </div>
  )
}
