/**
 * Access requests — a workflow this product does not have yet.
 *
 * There is no request table, no endpoint and no approval step anywhere in
 * Purchases, so this tab says so in one sentence rather than staging a queue
 * out of browser storage. A fake pending request is worse than an empty tab:
 * somebody would approve it, and nothing would happen.
 *
 * TODO(access-requests): when a request workflow exists server-side, replace
 * the notice below with the queue — requester, requested profile, reason, date,
 * and Approve / Reject calling the real endpoints. The layout around it does
 * not need to change.
 *
 * What IS real is underneath it: people who have used Purchases in this company
 * and hold no profile, read from this product's own audit trail. It is the
 * nearest honest answer to "who is waiting on access", and it is data this
 * product already has.
 */

import { Inbox, UserPlus } from 'lucide-react'
import type { Candidate } from './types'
import { EmptyState, ErrorState, LoadingRows, fullTimestamp, relativeTime, shortUuid } from './ui'

export function RequestsPanel({
  candidates,
  loading,
  error,
  onRetry,
  busy,
  onGiveAccess,
}: {
  candidates: Candidate[]
  loading: boolean
  error: string | null
  onRetry: () => void
  busy: boolean
  onGiveAccess: (uuid: string) => void
}) {
  const body = () => {
    if (error) return <ErrorState what="the people waiting on access" message={error} onRetry={onRetry} />
    if (loading && candidates.length === 0) return <LoadingRows rows={3} columns={3} />

    if (candidates.length === 0) {
      return (
        <EmptyState
          icon={<Inbox size={26} />}
          title="No access requests"
          note="Purchases has no request-and-approve workflow yet, so nothing can be pending here. Access is granted directly, on the Profiles tab."
        >
          Nobody has used Purchases in this company without already holding a profile, so there is nobody
          waiting on access.
        </EmptyState>
      )
    }

    return (
      <div className="access-table-scroll">
        <table className="access-table">
          <caption className="access-sr-only">
            People who have used Purchases in this company and hold no permission profile.
          </caption>
          <thead>
            <tr>
              <th scope="col">Person</th>
              <th scope="col" className="is-numeric">
                Actions taken
              </th>
              <th scope="col">Last seen</th>
              <th scope="col" className="is-actions">
                <span className="access-sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {candidates.map((candidate) => (
              <tr key={candidate.user_uuid}>
                <th scope="row">
                  <span className="access-uuid" title={candidate.user_uuid}>
                    {shortUuid(candidate.user_uuid, 14)}
                  </span>
                  {candidate.is_you && <span className="access-badge access-badge--you" style={{ marginLeft: 6 }}>You</span>}
                </th>
                <td className="is-numeric">{candidate.actions}</td>
                <td>
                  <span title={fullTimestamp(candidate.last_seen)}>{relativeTime(candidate.last_seen)}</span>
                </td>
                <td className="is-actions">
                  <button
                    type="button"
                    className="access-btn access-btn--secondary access-btn--sm"
                    disabled={busy}
                    onClick={() => onGiveAccess(candidate.user_uuid)}
                  >
                    <UserPlus size={13} aria-hidden /> Give access
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )
  }

  return (
    <section className="access-panel" aria-labelledby="access-requests-heading">
      <header className="access-panel__header">
        <div>
          <h2 id="access-requests-heading">Access requests</h2>
          <p>Purchases grants access directly — there is no request queue to approve yet.</p>
        </div>
      </header>

      <div style={{ padding: '14px 16px 0' }}>
        <div className="access-notice access-notice--info" role="status">
          <Inbox size={15} aria-hidden />
          <div>
            <strong>Requests are not enabled in Aicountly Purchase yet</strong>
            Access is granted on this page by an administrator. Until a request workflow exists, the closest
            thing to a queue is below: people who have used Purchases here and hold no profile, taken from
            this product's own audit trail.
          </div>
        </div>
      </div>

      <div className="access-panel__body--flush">{body()}</div>
    </section>
  )
}
