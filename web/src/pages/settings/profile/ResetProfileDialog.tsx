/**
 * Confirming a reset, because one stray click on a quick action should not
 * cost somebody the configuration they have spent ten minutes on.
 *
 * It resets the FORM, not the saved profile: nothing reaches the server until
 * Save Profile is pressed, and the dialog says so rather than leaving the
 * reader to guess how much has just been undone.
 */

import { PpButton, PpDialog, PpStrip } from './ProfileUi'

export function ResetProfileDialog({
  open,
  onClose,
  onConfirm,
}: {
  open: boolean
  onClose: () => void
  onConfirm: () => void
}) {
  return (
    <PpDialog
      open={open}
      size="narrow"
      title="Reset profile configuration?"
      onClose={onClose}
      footer={
        <>
          <PpButton onClick={onClose}>Cancel</PpButton>
          <PpButton tone="danger" onClick={onConfirm}>
            Reset Configuration
          </PpButton>
        </>
      }
    >
      <p style={{ margin: '0 0 14px' }}>
        This restores the form to the default configuration. Unsaved changes will be lost.
      </p>
      <PpStrip tone="info">
        The saved profile is not touched. Nothing changes for your documents until you press Save Profile — and
        leaving this screen without saving discards the reset too.
      </PpStrip>
    </PpDialog>
  )
}
