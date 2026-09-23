/**
 * Confirmation for the things that cannot be undone from this page.
 *
 * Revoking access and deleting a profile both take effect immediately and
 * neither has an undo, so both get a dialog that names what is about to happen
 * to whom. The confirming button carries the verb — "Revoke access", not "OK" —
 * because a dialog whose buttons read Cancel and OK is a dialog people dismiss
 * without reading.
 */

import type { ReactNode } from 'react'
import { AccessDialog } from './AccessDialog'

export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel,
  icon,
  tone = 'danger',
  busy,
  onConfirm,
  onClose,
  children,
}: {
  open: boolean
  title: string
  description?: ReactNode
  confirmLabel: string
  icon?: ReactNode
  tone?: 'danger' | 'primary'
  busy: boolean
  onConfirm: () => void
  onClose: () => void
  children?: ReactNode
}) {
  return (
    <AccessDialog
      open={open}
      title={title}
      description={description}
      onClose={onClose}
      busy={busy}
      footer={
        <>
          <button type="button" className="access-btn access-btn--secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button
            type="button"
            className={tone === 'danger' ? 'access-btn access-btn--danger' : 'access-btn access-btn--primary'}
            onClick={onConfirm}
            disabled={busy}
          >
            {icon}
            {busy ? 'Working…' : confirmLabel}
          </button>
        </>
      }
    >
      {children}
    </AccessDialog>
  )
}
