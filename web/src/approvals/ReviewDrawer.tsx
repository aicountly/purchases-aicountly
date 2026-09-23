/**
 * Everything this product knows about one approval, before anybody decides it.
 *
 * NOTHING HERE IS GENERATED. Each section is a field off the approval request
 * or the document behind it, and the "worth checking" list is the same rule set
 * that produced the risk chip in the table — printed in full rather than folded
 * into one word. A review panel that paraphrases is a review panel that can be
 * wrong about a purchase order.
 */

import { AlertTriangle, ShieldCheck } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { Drawer } from '../dashboards/Drawer'
import type { ApprovalQueueRow } from './types'

export function ReviewDrawer({
  row,
  onClose,
  onApprove,
  onReject,
}: {
  row: ApprovalQueueRow | null
  onClose: () => void
  onApprove: (row: ApprovalQueueRow) => void
  onReject: (row: ApprovalQueueRow) => void
}) {
  const navigate = useNavigate()

  return (
    <Drawer
      open={row !== null}
      title={row === null ? 'Approval review' : row.document_label}
      subtitle={row === null ? undefined : `${row.type_label} · ${row.age_note}`}
      onClose={onClose}
    >
      {row !== null && (
        <div className="purchase-intel-drawer">
          <section>
            <h3>Summary</h3>
            <p>
              {row.document_title ?? row.type_label}
              {row.supplier_name === null ? '' : ` for ${row.supplier_name}`}
              {row.amount_formatted === null ? '' : `, at ${row.amount_formatted}`}. Raised by {row.requester_label}
              {row.requested_at_label === null ? '' : ` on ${row.requested_at_label}`}.
            </p>
          </section>

          <section>
            <h3>Why it needs approval</h3>
            {/* The reason the server recorded, as it recorded it. It already
                names the threshold where a threshold is what forced the
                approval, so it is not said twice. */}
            <p>{row.reason_detail ?? `Recorded as ${row.reason_kind.replace(/_/g, ' ')}.`}</p>
            {row.stage_name && <p className="purchase-muted">Stage: {row.stage_name}</p>}
          </section>

          {row.supplier_account_id !== null && (
            <section>
              <h3>Supplier context</h3>
              <p>{row.supplier_note ?? 'No procurement profile recorded in Purchases.'}</p>
              <p className="purchase-muted">
                Supplier identity, GSTIN and the ledger stay in Contacts and Books. What Purchases holds is this
                procurement profile.
              </p>
            </section>
          )}

          <section>
            <h3>Worth checking</h3>
            {row.risk_reasons.length === 0 ? (
              <p className="purchase-muted">
                Nothing recorded against this document meets any of the checks this screen applies, so its risk is shown
                as {row.risk_label.toLowerCase()}.
              </p>
            ) : (
              <ul className="purchase-approvals-checks">
                {row.risk_reasons.map((reason) => (
                  <li key={reason}>
                    <AlertTriangle size={14} aria-hidden />
                    <span>{reason}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {row.status !== 'PENDING' && (
            <section>
              <h3>Decision</h3>
              <p>
                {row.status_label}
                {row.decided_at_label === null ? '' : ` on ${row.decided_at_label}`}.
              </p>
              {row.decision_note && <p className="purchase-muted">{row.decision_note}</p>}
            </section>
          )}

          <div className="purchase-intel-drawer__actions">
            {row.route !== null && (
              <button
                type="button"
                className="purchase-button"
                onClick={() => {
                  onClose()
                  navigate(row.route as string)
                }}
              >
                Open the document
              </button>
            )}
            {row.status === 'PENDING' && (
              <>
                <button
                  type="button"
                  className="purchase-button purchase-button--primary"
                  disabled={!row.may_approve}
                  title={row.block_reason ?? undefined}
                  onClick={() => onApprove(row)}
                >
                  Approve
                </button>
                <button
                  type="button"
                  className="purchase-button purchase-button--destructive"
                  disabled={!row.may_approve}
                  title={row.block_reason ?? undefined}
                  onClick={() => onReject(row)}
                >
                  Reject
                </button>
              </>
            )}
          </div>

          {row.block_reason && <p className="purchase-muted purchase-approvals-blocked">{row.block_reason}</p>}

          <p className="purchase-intel-drawer__note purchase-muted">
            <ShieldCheck size={14} aria-hidden />
            <span>
              These checks are advisory and are applied to the records this product holds. The decision, and the
              authority for it, stay with you.
            </span>
          </p>
        </div>
      )}
    </Drawer>
  )
}
