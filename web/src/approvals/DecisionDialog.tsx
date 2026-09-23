/**
 * Approve, or reject with a reason.
 *
 * WHY A DIALOG AT ALL. Approving is the one irreversible thing on this screen —
 * it releases an order or a requisition — and the row it is fired from is nine
 * columns wide. The dialog restates what is about to be approved, in words,
 * before anybody commits to it.
 *
 * A REJECTION NEEDS A REASON, because the domain services refuse one without a
 * note. Asking here rather than letting the API answer 422 means the person
 * types the sentence once.
 */

import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { AlertTriangle, X } from 'lucide-react'
import type { ApprovalQueueRow } from './types'

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

export function DecisionDialog({
  row,
  action,
  busy,
  error,
  onCancel,
  onConfirm,
}: {
  row: ApprovalQueueRow
  action: 'approve' | 'reject'
  busy: boolean
  error: string | null
  onCancel: () => void
  onConfirm: (note: string) => void
}) {
  const [note, setNote] = useState('')
  const [touched, setTouched] = useState(false)
  const panel = useRef<HTMLDivElement>(null)
  const returnFocusTo = useRef<HTMLElement | null>(null)
  const titleId = useId()
  const noteId = useId()

  const rejecting = action === 'reject'
  const noteMissing = rejecting && note.trim() === ''

  const focusables = useCallback(
    () => Array.from(panel.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []).filter((el) => el.offsetParent !== null),
    [],
  )

  useEffect(() => {
    returnFocusTo.current = document.activeElement as HTMLElement | null
    panel.current?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onCancel()
        return
      }
      if (event.key !== 'Tab') return

      const items = focusables()
      if (items.length === 0) {
        event.preventDefault()
        panel.current?.focus()
        return
      }
      const first = items[0]
      const last = items[items.length - 1]
      if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.current)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      document.body.style.overflow = previousOverflow
      returnFocusTo.current?.focus?.()
    }
  }, [focusables, onCancel])

  const submit = () => {
    setTouched(true)
    if (noteMissing) return
    onConfirm(note.trim())
  }

  return (
    <>
      <div className="purchase-drawer-backdrop" onClick={busy ? undefined : onCancel} aria-hidden />
      <div
        className="purchase-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        ref={panel}
      >
        <header className="purchase-drawer__header">
          <div>
            <h2 id={titleId}>{rejecting ? 'Reject this document?' : 'Approve this document?'}</h2>
            <p className="purchase-muted" style={{ margin: '0.25rem 0 0', fontSize: '0.8rem' }}>
              {rejecting
                ? 'The document goes back to whoever raised it, with your reason attached.'
                : 'This releases the document for the next step in its workflow.'}
            </p>
          </div>
          <button type="button" className="purchase-drawer__close" onClick={onCancel} disabled={busy} aria-label="Close">
            <X size={16} aria-hidden />
          </button>
        </header>

        <div className="purchase-modal__body">
          <dl className="purchase-dl">
            <div>
              <dt>Document</dt>
              <dd>{row.document_label}</dd>
            </div>
            <div>
              <dt>Type</dt>
              <dd>{row.type_label}</dd>
            </div>
            {row.supplier_name && (
              <div>
                <dt>Supplier</dt>
                <dd>{row.supplier_name}</dd>
              </div>
            )}
            <div>
              <dt>Amount</dt>
              <dd>{row.amount_formatted ?? 'Not stated'}</dd>
            </div>
            <div>
              <dt>Raised by</dt>
              <dd>{row.requester_label}</dd>
            </div>
            <div>
              <dt>Waiting</dt>
              <dd>{row.age_note}</dd>
            </div>
          </dl>

          {row.reason_detail && (
            <p className="purchase-modal__why">
              <strong>Why it needs approval: </strong>
              {row.reason_detail}
            </p>
          )}

          {row.risk === 'high' && row.risk_reasons.length > 0 && (
            <div className="purchase-notice purchase-notice--warning purchase-modal__risk">
              <AlertTriangle size={16} aria-hidden />
              <div>
                <strong>Worth a second look</strong>
                <ul>
                  {row.risk_reasons.map((reason) => (
                    <li key={reason}>{reason}</li>
                  ))}
                </ul>
              </div>
            </div>
          )}

          <label className="purchase-field purchase-modal__note" htmlFor={noteId}>
            <span>
              {rejecting ? 'Reason for rejection' : 'Approval note'}
              {rejecting ? '' : ' (optional)'}
            </span>
            <textarea
              id={noteId}
              value={note}
              rows={3}
              onChange={(event) => setNote(event.target.value)}
              onBlur={() => setTouched(true)}
              aria-invalid={touched && noteMissing}
              aria-describedby={touched && noteMissing ? `${noteId}-error` : undefined}
              placeholder={rejecting ? 'Say what has to change before this can be approved.' : 'Anything the next person should know.'}
            />
            {touched && noteMissing && (
              <span id={`${noteId}-error`} className="purchase-danger" style={{ fontSize: '0.75rem' }}>
                A rejection needs a reason.
              </span>
            )}
          </label>

          {error && (
            <div className="purchase-notice purchase-notice--danger" role="alert">
              {error}
            </div>
          )}
        </div>

        <footer className="purchase-modal__footer">
          <button type="button" className="purchase-button" onClick={onCancel} disabled={busy}>
            Cancel
          </button>
          <button
            type="button"
            className={rejecting ? 'purchase-button purchase-button--destructive' : 'purchase-button purchase-button--primary'}
            onClick={submit}
            disabled={busy}
          >
            {busy ? (rejecting ? 'Rejecting…' : 'Approving…') : rejecting ? 'Reject' : 'Approve'}
          </button>
        </footer>
      </div>
    </>
  )
}
