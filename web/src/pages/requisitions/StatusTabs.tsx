/**
 * The five views of the same list, as a real tablist.
 *
 * Arrow keys move between tabs and select as they go, Home and End jump to the
 * ends, and only the selected tab is in the tab order — the WAI-ARIA pattern,
 * because a row of buttons that merely looks like tabs makes a keyboard user
 * press Tab five times to reach the table.
 *
 * The counts are the server's, over everything the other filters match. They
 * are NOT derived from the rows on screen: a "Pending (4)" that meant "4 on
 * this page" would say 4 on a page of ten and 0 on the next one.
 */

import { useRef } from 'react'
import { CircleCheck, CircleX, Clock, FileEdit, Layers } from 'lucide-react'
import type { Bucket } from './model'

const TABS: { id: Bucket; label: string; icon: typeof Layers }[] = [
  { id: 'all', label: 'All', icon: Layers },
  { id: 'draft', label: 'Draft', icon: FileEdit },
  { id: 'pending', label: 'Pending', icon: Clock },
  { id: 'approved', label: 'Approved', icon: CircleCheck },
  { id: 'rejected', label: 'Rejected', icon: CircleX },
]

export function StatusTabs({
  active,
  counts,
  onChange,
}: {
  active: Bucket
  /** null while the counts are still being fetched — a tab shows no number rather than a stale or invented one. */
  counts: Record<Bucket, number> | null
  onChange: (bucket: Bucket) => void
}) {
  const list = useRef<HTMLDivElement>(null)

  const move = (index: number) => {
    const next = TABS[(index + TABS.length) % TABS.length]
    onChange(next.id)
    list.current?.querySelector<HTMLButtonElement>(`#rq-tab-${next.id}`)?.focus()
  }

  return (
    <div className="rq-tabs" role="tablist" aria-label="Requisition status" ref={list}>
      {TABS.map((tab, index) => {
        const Icon = tab.icon
        const selected = tab.id === active
        const count = counts?.[tab.id]

        return (
          <button
            key={tab.id}
            id={`rq-tab-${tab.id}`}
            type="button"
            role="tab"
            aria-selected={selected}
            aria-controls="rq-table-panel"
            tabIndex={selected ? 0 : -1}
            className={selected ? `rq-tab rq-tab--${tab.id} is-active` : `rq-tab rq-tab--${tab.id}`}
            onClick={() => onChange(tab.id)}
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
                move(TABS.length - 1)
              }
            }}
          >
            <Icon size={15} aria-hidden />
            {tab.label}
            {typeof count === 'number' && <span className="rq-tab__count">{count}</span>}
          </button>
        )
      })}
    </div>
  )
}
