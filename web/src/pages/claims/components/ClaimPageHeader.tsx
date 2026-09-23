/**
 * Breadcrumb, title and the two actions that can be taken from anywhere.
 *
 * Save as draft and Submit sit here as well as in the footer deliberately:
 * somebody who has finished on step 2 should not have to walk to step 5 to save
 * what they have. Submit stays disabled until the claim would actually be
 * accepted — a button that produces a validation error is a button that lies.
 */

import { Link } from 'react-router-dom'
import { ChevronRight, Loader2, ReceiptText } from 'lucide-react'
import { Button } from './ui'

export function ClaimPageHeader({
  canSubmit,
  canSaveDraft,
  canCreate,
  saving,
  submitting,
  onSaveDraft,
  onSubmit,
}: {
  canSubmit: boolean
  canSaveDraft: boolean
  canCreate: boolean
  saving: boolean
  submitting: boolean
  onSaveDraft: () => void
  onSubmit: () => void
}) {
  const busy = saving || submitting

  return (
    <>
      <nav className="claim-breadcrumb" aria-label="Breadcrumb">
        <Link to="/dashboard/overview">Purchases</Link>
        <ChevronRight size={12} aria-hidden />
        <Link to="/claims">Supplier claims</Link>
        <ChevronRight size={12} aria-hidden />
        <span aria-current="page">New claim</span>
      </nav>

      <header className="claim-page-header">
        <div className="claim-page-title-wrap">
          <span className="claim-page-icon" aria-hidden>
            <ReceiptText size={21} />
          </span>
          <div>
            <h1>New supplier claim</h1>
            <p>
              Raise a claim to recover money or obtain an adjustment for goods and services not received, defective,
              short supplied, incorrectly priced or otherwise disputed.
            </p>
          </div>
        </div>

        <div className="claim-header-actions">
          <Button
            onClick={onSaveDraft}
            disabled={busy || !canCreate || !canSaveDraft}
            title={
              !canCreate
                ? 'You do not have permission to raise claims.'
                : canSaveDraft
                  ? undefined
                  : 'A draft needs a supplier, a claim type and an amount.'
            }
          >
            {saving && <Loader2 size={14} className="sc-spin" aria-hidden />}
            Save as draft
          </Button>
          <Button
            tone="primary"
            onClick={onSubmit}
            disabled={busy || !canSubmit || !canCreate}
            title={canSubmit ? undefined : 'Complete the required fields before submitting.'}
          >
            {submitting && <Loader2 size={14} className="sc-spin" aria-hidden />}
            Submit claim
          </Button>
        </div>
      </header>
    </>
  )
}
