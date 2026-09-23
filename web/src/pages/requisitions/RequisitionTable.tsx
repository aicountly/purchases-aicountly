/**
 * The requisitions table.
 *
 * A real <table>: ten columns of one kind of record, read down as often as
 * across, which is exactly what a table is for. The row is not a link — the
 * number is — because a row that navigates on click cannot also carry a
 * checkbox, a menu and a popover without every one of them having to stop the
 * click it is inside.
 */

import { Link } from 'react-router-dom'
import { CircleCheck, CircleX, Clock, FileEdit, MinusCircle } from 'lucide-react'
import type { RequisitionRow } from '../../services/types'
import { date as formatDate, money } from '../../ui'
import {
  approvalProgress,
  departmentTone,
  initialsOf,
  itemCountLabel,
  requisitionTitle,
  shortUuid,
  statusLabel,
  statusShortLabel,
  statusTone,
} from './model'
import { Popover, RowMenu, type MenuAction } from './ui'

const STATUS_ICON = {
  draft: FileEdit,
  pending: Clock,
  approved: CircleCheck,
  rejected: CircleX,
  neutral: MinusCircle,
}

function StatusBadge({ status }: { status: string }) {
  const tone = statusTone(status)
  const Icon = STATUS_ICON[tone]

  return (
    <span className={`rq-status rq-status--${tone}`}>
      {/* The icon is decoration; the word is the status. Colour is never the
          only thing carrying the meaning here. */}
      <Icon size={12} aria-hidden />
      {statusShortLabel(status)}
    </span>
  )
}

function DepartmentChip({ department }: { department: string | null }) {
  if (!department) return <span className="rq-muted">—</span>

  return <span className={`rq-dept rq-dept--${departmentTone(department)}`}>{department}</span>
}

/**
 * Who raised it.
 *
 * Purchases stores the requester's uuid and is not given a people directory —
 * names belong to the AICOUNTLY portal, and this product deliberately keeps no
 * copy of them. So the one person it can name is the person reading the screen;
 * everybody else is their id, shown short, with the whole thing on hover.
 *
 * An invented name would be worse than an id. If a people directory is ever
 * added to this product, this function is the only thing that has to change.
 */
function Requester({ row, meLabel }: { row: RequisitionRow; meLabel: string }) {
  const label = row.is_mine ? meLabel : shortUuid(row.requester_uuid)

  return (
    <span className="rq-user" title={row.is_mine ? meLabel : `Requester ${row.requester_uuid}`}>
      <span className={row.is_mine ? 'rq-avatar rq-avatar--me' : 'rq-avatar'} aria-hidden="true">
        {row.is_mine ? initialsOf(meLabel) : '••'}
      </span>
      <span className="rq-user__name">{label}</span>
      {row.is_mine && <span className="rq-user__you">You</span>}
    </span>
  )
}

/**
 * How far through approval, and who is still holding it.
 *
 * The bar is what the approval rows say and nothing more: a requisition that
 * needed no approval shows a dash rather than a full bar, because nobody
 * signed anything and a full bar would say somebody did.
 */
function Approval({ row }: { row: RequisitionRow }) {
  const progress = approvalProgress(row)

  if (progress.ratio === null) {
    return (
      <span className="rq-muted" title="This requisition did not need an approval.">
        —
      </span>
    )
  }

  const summary = `${progress.done}/${progress.total}`
  // Read out here, not inside the render callback: a property narrowed by the
  // guard above widens again inside a closure, and this one is a closure.
  const percent = Math.round(progress.ratio * 100)

  return (
    <Popover
      label={`Approval progress for ${row.requisition_no}`}
      triggerClassName="rq-approval"
      trigger={() => (
        <>
          <span className="rq-approval__count">{summary}</span>
          <span className="rq-approval__track">
            <span
              className={`rq-approval__value rq-approval__value--${progress.tone}`}
              style={{ width: `${percent}%` }}
            />
          </span>
          <span className="rq-sr-only">
            {progress.done} of {progress.total} approval stages decided
          </span>
        </>
      )}
    >
      <p className="rq-popover__title">Approval progress</p>
      <ol className="rq-stages">
        {row.approval_chain.map((stage, index) => (
          <li key={`${stage.stage_no ?? index}-${stage.reason_kind}`}>
            <span className={`rq-stages__dot rq-stages__dot--${statusTone(stage.status)}`} aria-hidden="true" />
            <span className="rq-stages__body">
              <strong>{stage.stage_name ?? stage.reason_detail ?? `Stage ${stage.stage_no ?? index + 1}`}</strong>
              <span>
                {statusLabel(stage.status)}
                {stage.decided_at ? ` · ${formatDate(stage.decided_at)}` : ''}
              </span>
            </span>
          </li>
        ))}
      </ol>
    </Popover>
  )
}

export interface RowActionSet {
  (row: RequisitionRow): MenuAction[]
}

export function RequisitionTable({
  rows,
  meLabel,
  selected,
  onSelect,
  onSelectAll,
  actionsFor,
}: {
  rows: RequisitionRow[]
  meLabel: string
  selected: Set<number>
  onSelect: (id: number, checked: boolean) => void
  onSelectAll: (checked: boolean) => void
  actionsFor: RowActionSet
}) {
  const allSelected = rows.length > 0 && rows.every((row) => selected.has(row.requisition_id))
  const someSelected = rows.some((row) => selected.has(row.requisition_id))

  return (
    <div className="rq-table-scroll">
      <table className="rq-table">
        <thead>
          <tr>
            <th scope="col" className="rq-table__pick">
              <input
                type="checkbox"
                checked={allSelected}
                ref={(node) => {
                  // Some but not all: the box says "partly", which is what the
                  // next click will change rather than what is true now.
                  if (node) node.indeterminate = someSelected && !allSelected
                }}
                onChange={(event) => onSelectAll(event.target.checked)}
                aria-label="Select every requisition on this page"
              />
            </th>
            <th scope="col">Req. no.</th>
            <th scope="col">Date</th>
            <th scope="col" className="rq-table__title">
              Title / items
            </th>
            <th scope="col">Department</th>
            <th scope="col">Requested by</th>
            <th scope="col" className="rq-table__num">
              Amount
            </th>
            <th scope="col">Status</th>
            <th scope="col">Approval</th>
            <th scope="col" className="rq-table__actions">
              <span className="rq-sr-only">Actions</span>
            </th>
          </tr>
        </thead>

        <tbody>
          {rows.map((row) => {
            const title = requisitionTitle(row)
            const isSelected = selected.has(row.requisition_id)

            return (
              <tr key={row.requisition_id} className={isSelected ? 'is-selected' : undefined}>
                <td className="rq-table__pick">
                  <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={(event) => onSelect(row.requisition_id, event.target.checked)}
                    aria-label={`Select ${row.requisition_no}`}
                  />
                </td>

                <td>
                  <Link className="rq-number" to={`/requisitions/${row.requisition_id}`}>
                    {row.requisition_no}
                  </Link>
                </td>

                <td className="rq-nowrap">{formatDate(row.requisition_date)}</td>

                <td className="rq-table__title">
                  <span className="rq-title">
                    {/* The full text on hover and in the accessible name: a
                        requisition called "Replacement bearings for line 3
                        conveyor" must not become "Replacement bearin…" with no
                        way to read the rest. */}
                    <strong title={title}>{title}</strong>
                    <span>
                      {itemCountLabel(row.line_count)}
                      {row.required_by ? ` · needed by ${formatDate(row.required_by)}` : ''}
                    </span>
                  </span>
                </td>

                <td>
                  <DepartmentChip department={row.department} />
                </td>

                <td>
                  <Requester row={row} meLabel={meLabel} />
                </td>

                {/* No paise. This is what the requester expects it to cost —
                    an estimate that routes the approval, not a ledger amount —
                    and ".00" on every row is a column of noise. */}
                <td className="rq-table__num rq-amount">{money(row.estimated_value, undefined, 0)}</td>

                <td>
                  <StatusBadge status={row.status} />
                </td>

                <td>
                  <Approval row={row} />
                </td>

                <td className="rq-table__actions">
                  <RowMenu label={`Actions for ${row.requisition_no}`} actions={actionsFor(row)} />
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
