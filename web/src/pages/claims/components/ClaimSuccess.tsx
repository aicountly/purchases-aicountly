/**
 * What happened, and the three things anybody wants next.
 *
 * The claim number comes from the server — it is composed from this company's
 * claim prefix and financial year when the row is written, so this screen shows
 * what was actually issued rather than what was expected.
 */

import { Link } from 'react-router-dom'
import { CheckCircle2 } from 'lucide-react'
import { date, money } from '../../../ui'
import type { CreatedClaim } from '../types'
import { Button } from './ui'

export function ClaimSuccess({
  claim,
  supplierName,
  onCreateAnother,
}: {
  claim: CreatedClaim
  supplierName: string | null
  onCreateAnother: () => void
}) {
  const submitted = claim.status !== 'DRAFT'

  return (
    <div className="claim-success" role="status">
      <span className="claim-success__mark" aria-hidden>
        <CheckCircle2 size={28} />
      </span>

      <h2>{submitted ? 'Supplier claim raised successfully' : 'Claim saved as a draft'}</h2>
      <p>
        {submitted
          ? 'It is now with your approvers. You can follow it from the claims list until it settles.'
          : 'Nothing has gone to the supplier. Open it from the claims list to finish and submit it.'}
      </p>

      <dl className="claim-success__facts">
        <div>
          <dt>Claim number</dt>
          <dd>{claim.claim_no}</dd>
        </div>
        <div>
          <dt>Supplier</dt>
          <dd>{supplierName ?? `Account ${claim.supplier_account_id}`}</dd>
        </div>
        <div>
          <dt>Claim amount</dt>
          <dd>{money(claim.claimed_amount)}</dd>
        </div>
        <div>
          <dt>Status</dt>
          <dd>{claim.status.replace(/_/g, ' ').toLowerCase()}</dd>
        </div>
        <div>
          <dt>Raised on</dt>
          <dd>{date(claim.claim_date)}</dd>
        </div>
        <div>
          <dt>Subject</dt>
          <dd>{claim.subject || '—'}</dd>
        </div>
      </dl>

      <div className="claim-success__actions">
        <Link className="sc-btn sc-btn--primary" to="/claims">
          View claim
        </Link>
        <Button onClick={onCreateAnother}>Create another claim</Button>
        <Link className="sc-btn sc-btn--ghost" to="/claims">
          Back to claims
        </Link>
      </div>
    </div>
  )
}
