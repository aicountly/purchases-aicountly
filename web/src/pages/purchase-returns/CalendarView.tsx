/**
 * The register as a month.
 *
 * Useful for one question the table answers badly: what is happening this week.
 * It plots the returns ALREADY FETCHED for the current filters — it does not
 * run a second, wider query — so the month it draws and the list behind it are
 * the same set of returns.
 *
 * TWO DATES, and only the ones that exist. Every return has a raised date; only
 * some have an expected pickup date, and the toggle for it is offered only when
 * something in view actually carries one. An empty calendar because everything
 * was plotted on a field nobody fills in is worse than no calendar.
 */

import { useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, CalendarDays } from 'lucide-react'
import type { PurchaseReturnRow } from './types'
import { EmptyState, inrShort } from './ui'

type DateField = 'return_date' | 'expected_pickup_date'

const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

function monthKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

/** The six-week grid a month is drawn on, Monday first. */
function gridFor(year: number, month: number): Date[] {
  const first = new Date(year, month, 1)
  // getDay() is Sunday-first; Indian calendars start on Monday.
  const offset = (first.getDay() + 6) % 7
  const start = new Date(year, month, 1 - offset)

  return Array.from({ length: 42 }, (_, index) => new Date(start.getFullYear(), start.getMonth(), start.getDate() + index))
}

export function CalendarView({
  rows,
  anchorMonth,
  onOpen,
}: {
  rows: PurchaseReturnRow[]
  /** The month to open on — normally the end of the filtered period. */
  anchorMonth: string
  onOpen: (row: PurchaseReturnRow) => void
}) {
  const [field, setField] = useState<DateField>('return_date')
  const [cursor, setCursor] = useState(() => {
    const [year, month] = anchorMonth.split('-').map((part) => Number.parseInt(part, 10))
    return Number.isFinite(year) && Number.isFinite(month)
      ? new Date(year, month - 1, 1)
      : new Date(new Date().getFullYear(), new Date().getMonth(), 1)
  })
  const [expanded, setExpanded] = useState<string | null>(null)

  const hasPickup = useMemo(() => rows.some((row) => row.expected_pickup_date !== null), [rows])
  const active: DateField = field === 'expected_pickup_date' && !hasPickup ? 'return_date' : field

  const byDay = useMemo(() => {
    const map = new Map<string, PurchaseReturnRow[]>()
    for (const row of rows) {
      const value = active === 'return_date' ? row.return_date : row.expected_pickup_date
      if (!value) continue
      const list = map.get(value) ?? []
      list.push(row)
      map.set(value, list)
    }

    return map
  }, [rows, active])

  const days = gridFor(cursor.getFullYear(), cursor.getMonth())
  const today = new Date()
  const todayKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`

  if (rows.length === 0) {
    return (
      <EmptyState icon={<CalendarDays size={26} />} title="Nothing to plot">
        No returns match the current filters, so there is nothing to show on a calendar.
      </EmptyState>
    )
  }

  return (
    <div className="pr-calendar">
      <div className="pr-calendar__head">
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <button
            type="button"
            className="pr-btn pr-btn--small"
            aria-label="Previous month"
            onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))}
          >
            <ChevronLeft size={15} aria-hidden />
          </button>
          <h3 className="pr-calendar__month" aria-live="polite">
            {new Intl.DateTimeFormat('en-IN', { month: 'long', year: 'numeric' }).format(cursor)}
          </h3>
          <button
            type="button"
            className="pr-btn pr-btn--small"
            aria-label="Next month"
            onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))}
          >
            <ChevronRight size={15} aria-hidden />
          </button>
        </div>

        <div className="pr-viewswitch" role="group" aria-label="Which date to plot">
          <button
            type="button"
            className={active === 'return_date' ? 'is-active' : undefined}
            aria-pressed={active === 'return_date'}
            onClick={() => setField('return_date')}
          >
            Raised
          </button>
          <button
            type="button"
            className={active === 'expected_pickup_date' ? 'is-active' : undefined}
            aria-pressed={active === 'expected_pickup_date'}
            disabled={!hasPickup}
            title={hasPickup ? undefined : 'None of these returns has an expected pickup date.'}
            onClick={() => setField('expected_pickup_date')}
          >
            Expected pickup
          </button>
        </div>
      </div>

      <div className="pr-calendar__grid" role="grid" aria-label="Returns by day">
        {DOW.map((day) => (
          <div className="pr-calendar__dow" key={day}>
            {day}
          </div>
        ))}

        {days.map((day) => {
          const key = `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`
          const list = byDay.get(key) ?? []
          const outside = day.getMonth() !== cursor.getMonth()
          const showAll = expanded === key
          const shown = showAll ? list : list.slice(0, 2)

          return (
            <div
              key={key}
              className={`pr-calendar__day${outside ? ' is-outside' : ''}${key === todayKey ? ' is-today' : ''}`}
            >
              <span className="pr-calendar__date">{day.getDate()}</span>

              {shown.map((row) => (
                <button
                  key={row.return_id}
                  type="button"
                  className="pr-calendar__card"
                  onClick={() => onOpen(row)}
                  title={`${row.return_no} · ${row.supplier_name ?? `Account ${row.supplier_account_id}`} · ${row.total_value_formatted} · ${row.status_label}`}
                >
                  <strong>{row.return_no}</strong>
                  <span>{row.supplier_name ?? `Account ${row.supplier_account_id}`}</span>
                  <span>
                    {inrShort(row.total_value)} · {row.status_label}
                  </span>
                </button>
              ))}

              {list.length > shown.length && (
                <button type="button" className="pr-calendar__more" onClick={() => setExpanded(key)}>
                  +{list.length - shown.length} more
                </button>
              )}
              {showAll && list.length > 2 && (
                <button type="button" className="pr-calendar__more" onClick={() => setExpanded(null)}>
                  Show fewer
                </button>
              )}
            </div>
          )
        })}
      </div>

      <p className="pr-card__note" style={{ marginTop: 12, display: 'block' }}>
        {`Plotting ${rows.length} return${rows.length === 1 ? '' : 's'} by the date ${
          active === 'return_date' ? 'each was raised' : 'the supplier is expected to collect'
        }.`}
        {monthKey(cursor) !== anchorMonth && ' Some of them are in other months — step through with the arrows.'}
      </p>
    </div>
  )
}
