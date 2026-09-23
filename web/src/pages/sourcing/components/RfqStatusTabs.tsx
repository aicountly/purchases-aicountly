/**
 * The lifecycle, as a real tablist.
 *
 * Arrow keys move and select as they go, Home and End jump to the ends, and
 * only the selected tab is in the tab order — the WAI-ARIA pattern, the same
 * one the Access workspace follows. A row of buttons that merely looks like
 * tabs makes a keyboard user press Tab eight times to reach the table.
 *
 * THE COUNTS ARE COUNTED UNDER THE CURRENT SEARCH. A tab that advertised four
 * awarded enquiries and then landed on an empty table — because the search box
 * still said "steel" — would be worse than no count at all.
 */

import { useRef } from 'react'
import { RFQ_STATUSES } from '../model'

export const RFQ_PANEL_ID = 'sq-rfq-panel'

export function RfqStatusTabs({
  status,
  counts,
  known,
  onChange,
}: {
  status: string
  counts: Record<string, number>
  /** False while the first page is still in flight: no count is better than a wrong one. */
  known: boolean
  onChange: (status: string) => void
}) {
  const strip = useRef<HTMLDivElement>(null)

  const tabs = [
    { value: '', label: 'All RFQs', meaning: 'Every enquiry in this financial year.' },
    ...RFQ_STATUSES.map((entry) => ({ value: entry.value, label: entry.label, meaning: entry.meaning })),
  ]

  const total = Object.values(counts).reduce((sum, n) => sum + n, 0)
  const countFor = (value: string): number => (value === '' ? total : (counts[value] ?? 0))

  const move = (index: number) => {
    const next = tabs[(index + tabs.length) % tabs.length]
    onChange(next.value)
    strip.current?.querySelector<HTMLButtonElement>(`#sq-tab-${next.value || 'all'}`)?.focus()
  }

  return (
    <div className="sq-tabs" role="tablist" aria-label="RFQ status" ref={strip}>
      {tabs.map((tab, index) => {
        const selected = tab.value === status
        const count = countFor(tab.value)

        return (
          <button
            key={tab.value || 'all'}
            id={`sq-tab-${tab.value || 'all'}`}
            type="button"
            role="tab"
            aria-selected={selected}
            aria-controls={RFQ_PANEL_ID}
            tabIndex={selected ? 0 : -1}
            title={tab.meaning}
            className={selected ? 'sq-tab is-active' : 'sq-tab'}
            onClick={() => onChange(tab.value)}
            onKeyDown={(event) => {
              if (event.key === 'ArrowRight') {
                event.preventDefault()
                move(index + 1)
              } else if (event.key === 'ArrowLeft') {
                event.preventDefault()
                move(index - 1)
              } else if (event.key === 'Home') {
                event.preventDefault()
                move(0)
              } else if (event.key === 'End') {
                event.preventDefault()
                move(tabs.length - 1)
              }
            }}
          >
            {tab.label}
            {known && (
              <span className={count === 0 ? 'sq-tab__count is-empty' : 'sq-tab__count'}>{count}</span>
            )}
          </button>
        )
      })}
    </div>
  )
}
