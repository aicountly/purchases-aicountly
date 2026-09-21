/**
 * Recent activity and exceptions.
 *
 * One feed across four record types, ordered by what costs most to ignore
 * rather than by what happened last — a week-old failed match matters more than
 * this morning's routine order, and a feed sorted purely by time buries it.
 *
 * Every row ends somewhere: the menu only lists screens this product actually
 * has, because a menu entry that opens nothing is worse than no menu at all.
 */

import { useEffect, useRef, useState } from 'react'
import { ChevronRight, MoreHorizontal } from 'lucide-react'
import { Badge, DashboardPanel, EmptyState, PanelUnavailable } from '../../shell'
import type { ActivityPanel, ActivityRow, FlowTone } from '../../types'

const TONE_TO_BADGE: Record<FlowTone, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  success: 'success',
  info: 'info',
  warning: 'warning',
  danger: 'danger',
  neutral: 'neutral',
}

export function ActivityTable({
  panel,
  onOpen,
}: {
  panel: ActivityPanel
  onOpen: (route: string, filters?: Record<string, string>) => void
}) {
  return (
    <DashboardPanel
      title="Recent activity & exceptions"
      description={panel.available ? panel.basis : undefined}
      className="purchase-span-all"
      action={
        <button type="button" className="purchase-button purchase-button--quiet" onClick={() => onOpen('/purchase-orders')}>
          View all <ChevronRight size={13} aria-hidden />
        </button>
      }
      flush
    >
      {!panel.available ? (
        <div style={{ padding: 20 }}>
          <PanelUnavailable reason={panel.reason} kind={panel.kind} />
        </div>
      ) : panel.rows.length === 0 ? (
        <EmptyState title="No procurement activity in this period.">
          Nothing is late, nothing failed a match and nothing is waiting for an approver.
        </EmptyState>
      ) : (
        <div className="purchase-table-scroll">
          <table className="purchase-table purchase-table--activity">
            <caption className="purchase-sr-only">{panel.basis}</caption>
            <thead>
              <tr>
                <th scope="col">Date</th>
                <th scope="col">Type</th>
                <th scope="col">Ref no.</th>
                <th scope="col">Supplier</th>
                <th scope="col">Description</th>
                <th scope="col" className="is-numeric">Value</th>
                <th scope="col">Status</th>
                <th scope="col">
                  <span className="purchase-sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {panel.rows.map((row) => (
                <tr key={row.id}>
                  <td>{row.date_label}</td>
                  <td>
                    <span className="purchase-type">{row.type}</span>
                  </td>
                  <td>
                    <button type="button" className="purchase-table__link" onClick={() => onOpen(row.route)}>
                      {row.reference}
                    </button>
                  </td>
                  <td className="purchase-table__supplier">
                    {row.supplier_name ?? <span className="purchase-muted">—</span>}
                  </td>
                  <td className="purchase-table__description">{row.description}</td>
                  <td className="is-numeric">
                    {row.value_formatted ?? <span className="purchase-muted">—</span>}
                  </td>
                  <td>
                    <Badge tone={TONE_TO_BADGE[row.tone]}>{row.status}</Badge>
                  </td>
                  <td>
                    <RowMenu row={row} onOpen={onOpen} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </DashboardPanel>
  )
}

function RowMenu({ row, onOpen }: { row: ActivityRow; onOpen: (route: string) => void }) {
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return

    const onDocument = (event: MouseEvent) => {
      if (box.current && !box.current.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onDocument)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDocument)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <div className="purchase-rowmenu" ref={box}>
      <button
        type="button"
        className="purchase-rowmenu__toggle"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Actions for ${row.reference}`}
        onClick={() => setOpen((shown) => !shown)}
      >
        <MoreHorizontal size={15} aria-hidden />
      </button>

      {open && (
        <div className="purchase-rowmenu__list" role="menu">
          {row.actions.map((action) => (
            <button
              key={action.route + action.label}
              type="button"
              role="menuitem"
              onClick={() => {
                setOpen(false)
                onOpen(action.route)
              }}
            >
              {action.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
