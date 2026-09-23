/**
 * The queue itself.
 *
 * ONE TABLE, FOUR TABS. Pending for me, raised by me, everything pending and
 * everything decided are the same rows under different filters, so they are the
 * same table — a second table for "recently actioned" would drift from this one
 * by the second change to either.
 *
 * WHY THE BUTTONS SAY WHY THEY ARE OFF. A greyed Approve with no explanation is
 * read as a broken screen. Every disabled control here carries the sentence the
 * API would have answered with, which is nearly always the segregation-of-duties
 * rule: you may not approve what you raised.
 */

import { ChevronRight, FileText, ShieldAlert, ShoppingCart } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { DashboardPanel, EmptyState } from '../dashboards/shell'
import type { ApprovalQueueMeta, ApprovalQueueRow, ApprovalTabId } from './types'
import { PAGE_SIZES } from './state'

const RISK_TONE: Record<string, string> = {
  high: 'danger',
  medium: 'warning',
  low: 'good',
  not_assessed: 'neutral',
}

const AGE_TONE: Record<string, string> = {
  late: 'danger',
  waiting: 'warning',
  fresh: 'neutral',
}

const STATUS_TONE: Record<string, string> = {
  PENDING: 'warning',
  APPROVED: 'good',
  REJECTED: 'danger',
  WITHDRAWN: 'neutral',
  SKIPPED: 'neutral',
}

export function ApprovalQueue({
  tab,
  rows,
  meta,
  loading,
  narrowed,
  busyId,
  onApprove,
  onReject,
  onOpenReview,
  onPage,
  onPageSize,
  onClearFilters,
}: {
  tab: ApprovalTabId
  rows: ApprovalQueueRow[]
  meta: ApprovalQueueMeta | null
  loading: boolean
  narrowed: boolean
  /** The row a decision is in flight for. Every row's buttons lock, not one. */
  busyId: number | null
  onApprove: (row: ApprovalQueueRow) => void
  onReject: (row: ApprovalQueueRow) => void
  onOpenReview: (row: ApprovalQueueRow) => void
  onPage: (page: number) => void
  onPageSize: (size: number) => void
  onClearFilters: () => void
}) {
  const navigate = useNavigate()
  const decided = tab === 'actioned'
  const total = meta?.total ?? 0
  const pageSize = meta?.limit ?? PAGE_SIZES[0]
  const page = Math.floor((meta?.offset ?? 0) / pageSize) + 1
  const pages = Math.max(1, Math.ceil(total / pageSize))
  const first = total === 0 ? 0 : (meta?.offset ?? 0) + 1
  const last = Math.min((meta?.offset ?? 0) + rows.length, total)

  return (
    <DashboardPanel
      title={decided ? 'Recently actioned' : 'Approval queue'}
      description={
        decided
          ? 'Approval requests decided in the selected period.'
          : 'Documents currently waiting for a decision.'
      }
      className="purchase-approvals-queue"
      flush
      action={
        total > 0 ? (
          <span className="purchase-panel__tag">
            {total} {total === 1 ? 'document' : 'documents'}
          </span>
        ) : undefined
      }
    >
      {loading ? (
        <QueueSkeleton rows={Math.min(pageSize, 6)} />
      ) : rows.length === 0 ? (
        <div className="purchase-approvals-empty">
          <EmptyState title={emptyTitle(tab, narrowed)}>
            {narrowed ? (
              <>
                Nothing matches the filters applied.{' '}
                <button type="button" className="purchase-panel__action" onClick={onClearFilters}>
                  Clear them
                </button>
              </>
            ) : (
              emptyBody(tab)
            )}
          </EmptyState>
        </div>
      ) : (
        <div className="purchase-table-scroll">
          <table className="purchase-table purchase-approvals-table">
            <caption className="purchase-sr-only">
              {decided
                ? 'Approval requests decided in the selected period'
                : 'Documents waiting for an approval decision'}
            </caption>
            <thead>
              <tr>
                <th scope="col">Document</th>
                <th scope="col">Supplier / party</th>
                <th scope="col" className="is-numeric">Amount</th>
                <th scope="col">Requested by</th>
                <th scope="col">{decided ? 'Decided' : 'Waiting'}</th>
                <th scope="col">Risk</th>
                <th scope="col">{decided ? 'Outcome' : 'Actions'}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.approval_id}>
                  <td>
                    <span className="purchase-approvals-doc">
                      <span className="purchase-approvals-doc__icon" aria-hidden>
                        {row.entity_type === 'purchase_order' ? <ShoppingCart size={15} /> : <FileText size={15} />}
                      </span>
                      <span className="purchase-approvals-doc__copy">
                        {row.route === null ? (
                          <strong>{row.document_label}</strong>
                        ) : (
                          <button
                            type="button"
                            className="purchase-table__link"
                            onClick={() => navigate(row.route as string)}
                          >
                            {row.document_label}
                          </button>
                        )}
                        <span className="purchase-table__sub">
                          {row.document_title === null ? row.type_label : `${row.type_label} · ${row.document_title}`}
                        </span>
                      </span>
                    </span>
                  </td>

                  <td>
                    <span className="purchase-approvals-primary">{row.supplier_name ?? '—'}</span>
                    {row.supplier_note && <span className="purchase-table__sub">{row.supplier_note}</span>}
                  </td>

                  {/* The threshold is one company setting repeated on every
                      row, so it is not printed here — it is in the tooltip, the
                      review drawer and the confirmation dialog, where it is
                      actually being weighed. */}
                  <td className="is-numeric">
                    <span
                      className="purchase-approvals-money"
                      title={row.threshold_formatted === null ? undefined : `Approval threshold ${row.threshold_formatted}`}
                    >
                      {row.amount_formatted ?? '—'}
                    </span>
                  </td>

                  <td>
                    <span className="purchase-approvals-person">
                      <span className="purchase-approvals-avatar" aria-hidden>
                        {row.requester_initials}
                      </span>
                      <span className="purchase-approvals-doc__copy">
                        <span className="purchase-approvals-primary">{row.requester_label}</span>
                        {/* The department where there is one, and nothing where
                            there is not — the raised-on date is in the next
                            column and does not need saying twice. */}
                        {row.requester_department && (
                          <span className="purchase-table__sub">{row.requester_department}</span>
                        )}
                      </span>
                    </span>
                  </td>

                  <td>
                    {decided ? (
                      <>
                        <span className="purchase-approvals-primary">{row.decided_at_label ?? '—'}</span>
                        <span className="purchase-table__sub">raised {row.requested_at_label}</span>
                      </>
                    ) : (
                      <>
                        <span className={`purchase-badge purchase-badge--${AGE_TONE[row.age_band]}`} title={row.age_note}>
                          {row.age_label}
                        </span>
                        <span className="purchase-table__sub">{row.requested_at_label}</span>
                      </>
                    )}
                  </td>

                  <td>
                    <span className={`purchase-badge purchase-badge--${RISK_TONE[row.risk]}`} title={riskTitle(row)}>
                      {row.risk === 'high' && <ShieldAlert size={12} aria-hidden />}
                      {row.risk_label}
                    </span>
                  </td>

                  <td>
                    {decided || row.status !== 'PENDING' ? (
                      <span className={`purchase-badge purchase-badge--${STATUS_TONE[row.status] ?? 'neutral'}`}>
                        {row.status_label}
                      </span>
                    ) : (
                      <span className="purchase-approvals-actions">
                        <button
                          type="button"
                          className="purchase-button purchase-button--primary purchase-button--tiny"
                          disabled={!row.may_approve || busyId !== null}
                          title={row.block_reason ?? undefined}
                          onClick={() => onApprove(row)}
                        >
                          Approve
                        </button>
                        <button
                          type="button"
                          className="purchase-button purchase-button--destructive purchase-button--tiny"
                          disabled={!row.may_approve || busyId !== null}
                          title={row.block_reason ?? undefined}
                          onClick={() => onReject(row)}
                        >
                          Reject
                        </button>
                        <button
                          type="button"
                          className="purchase-button purchase-button--tiny purchase-approvals-more"
                          onClick={() => onOpenReview(row)}
                          aria-label={`Review ${row.document_label} before deciding`}
                          title="Review before deciding"
                        >
                          <ChevronRight size={14} aria-hidden />
                        </button>
                      </span>
                    )}
                    {/* Colour is never the only signal, and a disabled button
                        that does not say why is a support ticket. */}
                    {!decided && row.status === 'PENDING' && row.block_reason && (
                      <span className="purchase-table__sub purchase-approvals-blocked">{row.block_reason}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && rows.length > 0 && (
        <div className="purchase-approvals-foot">
          <p>
            Showing {first}–{last} of {total} {total === 1 ? 'approval' : 'approvals'}
          </p>

          <label className="purchase-inline-select purchase-approvals-size">
            <span className="purchase-sr-only">Rows per page</span>
            <select value={pageSize} onChange={(event) => onPageSize(Number.parseInt(event.target.value, 10))}>
              {PAGE_SIZES.map((size) => (
                <option key={size} value={size}>
                  {size} per page
                </option>
              ))}
            </select>
          </label>

          <div className="purchase-approvals-pager">
            <button
              type="button"
              className="purchase-button purchase-button--tiny"
              disabled={page <= 1}
              onClick={() => onPage(page - 1)}
            >
              Previous
            </button>
            <span aria-live="polite">
              Page {page} of {pages}
            </span>
            <button
              type="button"
              className="purchase-button purchase-button--tiny"
              disabled={page >= pages}
              onClick={() => onPage(page + 1)}
            >
              Next
            </button>
          </div>
        </div>
      )}
    </DashboardPanel>
  )
}

function riskTitle(row: ApprovalQueueRow): string {
  if (row.risk_reasons.length > 0) return row.risk_reasons.join(' ')

  return 'Nothing recorded against this document to judge it on.'
}

function emptyTitle(tab: ApprovalTabId, narrowed: boolean): string {
  if (narrowed) return 'Nothing matches those filters'

  return tab === 'mine'
    ? "You're all caught up"
    : tab === 'raised_by_me'
      ? 'Nothing of yours is waiting'
      : tab === 'actioned'
        ? 'Nothing was decided in this period'
        : 'Nothing is waiting for approval'
}

function emptyBody(tab: ApprovalTabId): string {
  return tab === 'mine'
    ? 'Nothing is currently waiting for your decision.'
    : tab === 'raised_by_me'
      ? 'Everything you raised has been decided.'
      : tab === 'actioned'
        ? 'Change the period to look further back.'
        : 'Every document in this company and financial year has been decided.'
}

/**
 * The table, at the size it will be.
 *
 * Sized to the real row rather than to a generic bar, so the page does not jump
 * when the answer arrives — a layout shift at the moment somebody reaches for
 * Approve is how the wrong document gets approved.
 */
export function QueueSkeleton({ rows = 6 }: { rows?: number }) {
  return (
    <div className="purchase-approvals-skeleton" aria-busy="true" aria-label="Loading approvals">
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="purchase-approvals-skeleton__row">
          <span className="purchase-skeleton purchase-approvals-skeleton__icon" />
          <span className="purchase-skeleton purchase-approvals-skeleton__bar" />
          <span className="purchase-skeleton purchase-approvals-skeleton__bar is-short" />
          <span className="purchase-skeleton purchase-approvals-skeleton__bar is-short" />
          <span className="purchase-skeleton purchase-approvals-skeleton__pill" />
        </div>
      ))}
    </div>
  )
}
