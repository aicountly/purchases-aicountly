/**
 * "You have unsaved changes", asked properly.
 *
 * Three answers, because there are three things somebody might mean: keep the
 * work by saving it, abandon it deliberately, or go back to what they were
 * doing. A two-button version forces "discard" on anybody who only wanted to
 * check something.
 *
 * It traps the keyboard and returns focus on close through the same hook every
 * other dialog in this product uses.
 */

import { useFocusTrap } from '../../../hooks/useFocusTrap'
import { Button } from './ui'

export function LeaveDialog({
  open,
  saving,
  canSaveDraft,
  onStay,
  onDiscard,
  onSaveDraft,
}: {
  open: boolean
  saving: boolean
  /** False while the claim is too thin for the API to accept even a draft. */
  canSaveDraft: boolean
  onStay: () => void
  onDiscard: () => void
  onSaveDraft: () => void
}) {
  const panel = useFocusTrap<HTMLDivElement>(open, onStay)

  if (!open) return null

  return (
    <div className="sc-dialog-scrim" onMouseDown={(event) => event.target === event.currentTarget && onStay()}>
      <div
        className="sc-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sc-leave-title"
        ref={panel}
        tabIndex={-1}
      >
        <h2 id="sc-leave-title">You have unsaved changes</h2>
        <p>This claim has not been saved. Leaving now loses what you have entered.</p>
        <div className="sc-dialog__actions">
          <Button onClick={onStay}>Continue editing</Button>
          <Button
            onClick={onSaveDraft}
            disabled={saving || !canSaveDraft}
            title={canSaveDraft ? undefined : 'A draft needs a supplier, a claim type and an amount.'}
          >
            Save draft
          </Button>
          <Button tone="danger" onClick={onDiscard}>
            Discard changes
          </Button>
        </div>
      </div>
    </div>
  )
}
