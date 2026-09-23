/**
 * The enquiries themselves.
 *
 * A real <table>: a header row that says what each column is, a caption for
 * anyone who cannot see the heading above it, and an RFQ number that is a link
 * — so the keyboard reaches every record without the row needing a tabindex and
 * a key handler pretending to be one. The row click is a convenience on top of
 * the link, never the only way in.
 *
 * The three counts in the middle are the reason this screen exists: who was
 * asked, how many answered, and whether there is enough to compare.
 */

import { useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { ChevronRight, MoreVertical } from 'lucide-react'
import type { RfqView } from '../model'
import { dayOf, relativeDay, timeOf } from '../model'
import { StatusPill, Skeleton } from './parts'

export interface RowAction {
  key: string
  label: string
  onSelect: (row: RfqView) => void
  /** False hides it entirely — a permission the user has not got is not a greyed-out menu item. */
  visible: (row: RfqView) => boolean
  /** True when the action exists for this user but not for this row, with a reason. */
  disabled?: (row: RfqView) => string | null
  danger?: boolean
}

function RowMenu({ row, actions }: { row: RfqView; actions: RowAction[] }) {
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const menuId = useId()
  const available = actions.filter((action) => action.visible(row))

  useEffect(() => {
    if (!open) return

    const onAway = (event: MouseEvent) => {
      if (box.current && !box.current.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onAway)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onAway)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  if (available.length === 0) return null

  return (
    <div className="sq-menu" ref={box}>
      <button
        type="button"
        className="sq-icon-button"
        aria-label={`More actions for ${row.number}`}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={(event) => {
          event.stopPropagation()
          setOpen((value) => !value)
        }}
      >
        <MoreVertical size={16} aria-hidden />
      </button>

      {open && (
        <div className="sq-menu__list" id={menuId} role="menu">
          {available.map((action) => {
            const reason = action.disabled?.(row) ?? null
            return (
              <button
                key={action.key}
                type="button"
                role="menuitem"
                className={action.danger ? 'sq-menu__item is-danger' : 'sq-menu__item'}
                disabled={reason !== null}
                title={reason ?? undefined}
                onClick={(event) => {
                  event.stopPropagation()
                  setOpen(false)
                  action.onSelect(row)
                }}
              >
                {action.label}
                {reason && <span className="sq-menu__why">{reason}</span>}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}

export function RfqTable({
  rows,
  loading,
  refreshing,
  selected,
  onToggle,
  onToggleAll,
  onOpen,
  actions,
  pageSize,
}: {
  rows: RfqView[]
  /** The first load: there is nothing on screen to keep. */
  loading: boolean
  /** A later load: the previous page stays put and dims. */
  refreshing: boolean
  selected: ReadonlySet<number>
  onToggle: (id: number) => void
  onToggleAll: (checked: boolean) => void
  onOpen: (row: RfqView) => void
  actions: RowAction[]
  pageSize: number
}) {
  const all = useRef<HTMLInputElement>(null)
  const selectable = rows.length > 0
  const everySelected = selectable && rows.every((row) => selected.has(row.id))
  const someSelected = rows.some((row) => selected.has(row.id))

  useEffect(() => {
    if (all.current) all.current.indeterminate = someSelected && !everySelected
  }, [someSelected, everySelected])

  // The scroll container is focusable because it scrolls: a keyboard user has
  // to be able to reach the right-hand columns without a mouse.
  return (
    <div className="sq-table-scroll" role="group" aria-label="RFQ table" tabIndex={0}>
      <table className="sq-table" aria-busy={refreshing || loading}>
        <caption className="sq-visually-hidden">
          Requests for quotation, with the suppliers invited and the quotations received for each.
        </caption>
        <thead>
          <tr>
            <th scope="col" className="sq-table__check">
              <input
                ref={all}
                type="checkbox"
                checked={everySelected}
                disabled={!selectable}
                onChange={(event) => onToggleAll(event.target.checked)}
                aria-label="Select every RFQ on this page"
              />
            </th>
            <th scope="col">RFQ no.</th>
            <th scope="col">Title / items</th>
            <th scope="col" className="num">
              Suppliers invited
            </th>
            <th scope="col" className="num">
              Quotes received
            </th>
            <th scope="col">Status</th>
            <th scope="col">Responses by</th>
            <th scope="col">Last updated</th>
            <th scope="col" className="sq-table__actions">
              <span className="sq-visually-hidden">Actions</span>
            </th>
          </tr>
        </thead>

        <tbody>
          {loading &&
            Array.from({ length: Math.min(pageSize, 6) }, (_, index) => (
              <tr key={`skeleton-${index}`} className="sq-row is-skeleton">
                <td>
                  <Skeleton width="14px" height={14} />
                </td>
                <td>
                  <Skeleton width="84px" />
                </td>
                <td>
                  <Skeleton width="68%" />
                  <Skeleton width="42%" height={9} />
                </td>
                <td>
                  <Skeleton width="22px" />
                </td>
                <td>
                  <Skeleton width="22px" />
                </td>
                <td>
                  <Skeleton width="84px" height={18} />
                </td>
                <td>
                  <Skeleton width="72px" />
                </td>
                <td>
                  <Skeleton width="72px" />
                </td>
                <td />
              </tr>
            ))}

          {!loading &&
            rows.map((row) => {
              const deadlineNote = relativeDay(row.deadline)
              const updatedTime = timeOf(row.updated)

              return (
                <tr
                  key={row.id}
                  className={selected.has(row.id) ? 'sq-row is-selected' : 'sq-row'}
                  onClick={(event) => {
                    // A click on a control inside the row belongs to that
                    // control. Only the empty space opens the record.
                    const target = event.target as HTMLElement
                    if (target.closest('a, button, input, [role="menu"]')) return
                    onOpen(row)
                  }}
                >
                  <td className="sq-table__check">
                    <input
                      type="checkbox"
                      checked={selected.has(row.id)}
                      onChange={() => onToggle(row.id)}
                      aria-label={`Select ${row.number}`}
                    />
                  </td>

                  <td>
                    <Link to={`/rfqs/${row.id}`} className="sq-table__no">
                      {row.number}
                    </Link>
                    <span className="sq-table__sub">{dayOf(row.raised)}</span>
                  </td>

                  <td className="sq-table__title">
                    <span className="sq-table__name" title={row.title}>
                      {row.title}
                    </span>
                    <span className="sq-table__sub" title={row.items ?? undefined}>
                      {row.items ?? (row.lines === 0 ? 'No lines yet' : `${row.lines} lines`)}
                      {row.items !== null && row.lines > 3 ? ` +${row.lines - 3} more` : ''}
                    </span>
                  </td>

                  <td className="num">
                    {row.invited}
                    {row.invited > 0 && (
                      <span className="sq-table__sub">
                        {row.responded} replied
                      </span>
                    )}
                  </td>

                  <td className="num">
                    <span className={row.quotes >= 2 ? 'sq-count is-comparable' : 'sq-count'}>{row.quotes}</span>
                    {row.quotes >= 2 && <span className="sq-table__sub">comparable</span>}
                  </td>

                  <td>
                    <StatusPill tone={row.status.tone} title={row.status.meaning}>
                      {row.status.label}
                    </StatusPill>
                  </td>

                  <td>
                    {row.deadline === null ? (
                      <span className="sq-table__absent">Not set</span>
                    ) : (
                      <>
                        {dayOf(row.deadline)}
                        <span className={row.overdue ? 'sq-table__sub is-late' : 'sq-table__sub'}>
                          {row.overdue ? `overdue ${deadlineNote}` : deadlineNote}
                        </span>
                      </>
                    )}
                  </td>

                  <td>
                    {dayOf(row.updated)}
                    {updatedTime && <span className="sq-table__sub">{updatedTime}</span>}
                  </td>

                  <td className="sq-table__actions">
                    <div className="sq-table__buttons">
                      <Link to={`/rfqs/${row.id}`} className="sq-button sq-button--tiny">
                        View
                        <ChevronRight size={13} aria-hidden />
                      </Link>
                      <RowMenu row={row} actions={actions} />
                    </div>
                  </td>
                </tr>
              )
            })}
        </tbody>
      </table>
    </div>
  )
}
