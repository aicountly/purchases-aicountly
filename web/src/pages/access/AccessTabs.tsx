/**
 * The four sections of this screen, as a real tablist.
 *
 * Arrow keys move between tabs and select as they go, Home and End jump to the
 * ends, and only the selected tab is in the tab order — the WAI-ARIA pattern,
 * because a row of buttons that merely looks like tabs makes a keyboard user
 * press Tab four times to reach the panel.
 *
 * Which tab is open lives in the URL, so a link to the activity log is a link
 * to the activity log and Back returns to the tab you came from. That is the
 * same reason `useUrlFilter` exists for every list in this product.
 */

import { useRef } from 'react'
import { History, ShieldCheck, UserCheck, Users } from 'lucide-react'
import type { AccessTabId } from './types'

export const TAB_PANEL_ID: Record<AccessTabId, string> = {
  profiles: 'access-panel-profiles',
  people: 'access-panel-people',
  requests: 'access-panel-requests',
  activity: 'access-panel-activity',
}

const TABS: { id: AccessTabId; label: string; icon: typeof Users }[] = [
  { id: 'profiles', label: 'Profiles', icon: Users },
  { id: 'people', label: 'People with access', icon: UserCheck },
  { id: 'requests', label: 'Access requests', icon: ShieldCheck },
  { id: 'activity', label: 'Activity log', icon: History },
]

export function AccessTabs({
  active,
  onChange,
  counts,
}: {
  active: AccessTabId
  onChange: (tab: AccessTabId) => void
  counts: Partial<Record<AccessTabId, number | null>>
}) {
  const list = useRef<HTMLDivElement>(null)

  const move = (index: number) => {
    const next = TABS[(index + TABS.length) % TABS.length]
    onChange(next.id)
    list.current?.querySelector<HTMLButtonElement>(`#access-tab-${next.id}`)?.focus()
  }

  return (
    <div className="access-tabs" role="tablist" aria-label="Access sections" ref={list}>
      {TABS.map((tab, index) => {
        const Icon = tab.icon
        const selected = tab.id === active
        const count = counts[tab.id]

        return (
          <button
            key={tab.id}
            id={`access-tab-${tab.id}`}
            type="button"
            role="tab"
            aria-selected={selected}
            aria-controls={TAB_PANEL_ID[tab.id]}
            tabIndex={selected ? 0 : -1}
            className={selected ? 'access-tab is-active' : 'access-tab'}
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
            {typeof count === 'number' && <span className="access-tab__count">{count}</span>}
          </button>
        )
      })}
    </div>
  )
}
