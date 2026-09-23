/**
 * The way forward, always on screen.
 *
 * Next is disabled when the current step is not complete, and says why on hover
 * rather than failing silently. On the last step Next becomes Submit, because a
 * screen with both is a screen where somebody presses the wrong one.
 */

import { ArrowLeft, ArrowRight, Loader2, Send } from 'lucide-react'
import { Button } from './ui'

export function ClaimFooterActions({
  isFirst,
  isLast,
  canAdvance,
  canSubmit,
  canSaveDraft,
  saving,
  submitting,
  onBack,
  onNext,
  onSaveDraft,
  onSubmit,
  onCancel,
}: {
  isFirst: boolean
  isLast: boolean
  canAdvance: boolean
  canSubmit: boolean
  /** A draft still needs a supplier, a kind and an amount — the API refuses less. */
  canSaveDraft: boolean
  saving: boolean
  submitting: boolean
  onBack: () => void
  onNext: () => void
  onSaveDraft: () => void
  onSubmit: () => void
  onCancel: () => void
}) {
  const busy = saving || submitting

  return (
    <footer className="claim-action-footer">
      <div className="claim-action-footer__group">
        {isFirst ? (
          <Button tone="ghost" onClick={onCancel}>
            Cancel
          </Button>
        ) : (
          <Button onClick={onBack} disabled={busy}>
            <ArrowLeft size={14} aria-hidden />
            Previous
          </Button>
        )}
      </div>

      <div className="claim-action-footer__group">
        <Button
          onClick={onSaveDraft}
          disabled={busy || !canSaveDraft}
          title={canSaveDraft ? undefined : 'A draft needs a supplier, a claim type and an amount.'}
        >
          {saving && <Loader2 size={14} className="sc-spin" aria-hidden />}
          Save as draft
        </Button>

        {isLast ? (
          <Button
            tone="primary"
            onClick={onSubmit}
            disabled={busy || !canSubmit}
            title={canSubmit ? undefined : 'Some required fields still need answering.'}
          >
            {submitting ? <Loader2 size={14} className="sc-spin" aria-hidden /> : <Send size={14} aria-hidden />}
            Submit claim
          </Button>
        ) : (
          <Button
            tone="primary"
            onClick={onNext}
            disabled={busy || !canAdvance}
            title={canAdvance ? undefined : 'Answer the required fields on this step first.'}
          >
            Next
            <ArrowRight size={14} aria-hidden />
          </Button>
        )}
      </div>
    </footer>
  )
}
