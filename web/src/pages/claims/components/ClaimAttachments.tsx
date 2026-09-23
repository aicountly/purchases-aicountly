/**
 * Supporting documents.
 *
 * WHAT THIS DEPLOYMENT CAN ACTUALLY DO IS READ FROM THE SERVER, not assumed
 * here. `/v1/claims/meta` answers with `capabilities.attachments`, and when that
 * says files cannot be stored this says so where the dropzone would be, in the
 * server's own words, and refuses the drop.
 *
 * That is deliberate and it is the honest version. The alternative — an upload
 * button that accepts a file, shows a tick and posts it nowhere — loses a
 * buyer's photograph of a damaged carton and tells them it was saved.
 *
 * When a document store exists behind Purchases, `uploadClaimAttachment` in
 * service.ts becomes a real call and the capability flips on the server. Every
 * state below — uploading, failed with a retry, done — is already here.
 */

import { useRef, useState } from 'react'
import { FileText, Paperclip, RotateCcw, UploadCloud, X } from 'lucide-react'
import type { Capability, ClaimAttachmentDraft } from '../types'
import { Button, Notice } from './ui'

const ACCEPT = '.pdf,.jpg,.jpeg,.png'
const ACCEPTED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const MAX_BYTES = 10 * 1024 * 1024

function readableSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

let attachmentSeq = 0

export function ClaimAttachments({
  attachments,
  capability,
  onChange,
  onRetry,
}: {
  attachments: ClaimAttachmentDraft[]
  capability: Capability
  onChange: (next: ClaimAttachmentDraft[]) => void
  /**
   * Retry one failed upload. Absent while there is nothing to upload TO — the
   * button is not rendered rather than rendered doing nothing.
   */
  onRetry?: (key: string) => void
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const enabled = capability.available

  /**
   * The browser's idea of a file's type is a hint, not a fact — it comes from
   * the extension. It is checked here so somebody is told immediately, and the
   * server checks it again properly, because only the server's answer counts.
   */
  function accept(files: FileList | null) {
    if (!files || files.length === 0) return

    const next: ClaimAttachmentDraft[] = []
    for (const file of Array.from(files)) {
      attachmentSeq += 1
      const tooBig = file.size > MAX_BYTES
      const wrongType = file.type !== '' && !ACCEPTED_TYPES.includes(file.type)

      next.push({
        key: `file-${attachmentSeq}`,
        name: file.name,
        size: file.size,
        type: file.type,
        status: tooBig || wrongType ? 'unsupported' : 'pending',
        error: tooBig
          ? `Larger than ${readableSize(MAX_BYTES)}.`
          : wrongType
            ? 'Only PDF, JPG and PNG can be attached.'
            : null,
        file,
      })
    }

    onChange([...attachments, ...next])
  }

  function remove(key: string) {
    onChange(attachments.filter((attachment) => attachment.key !== key))
  }

  return (
    <div className="claim-attachments">
      <div className="claim-card-header">
        <h3>
          <Paperclip size={14} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6, color: 'var(--sc-primary)' }} />
          Attachments
        </h3>
        <span className="claim-card-hint">{attachments.length} chosen</span>
      </div>

      {!enabled && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="warning" title="Supporting documents cannot be attached yet">
            {capability.reason ?? 'This deployment has no document store for Purchases.'} Quote the invoice, delivery
            note or inspection report number in the description, and send the file to the supplier alongside the claim.
          </Notice>
        </div>
      )}

      <div
        className={`sc-dropzone${dragging ? ' is-over' : ''}${enabled ? '' : ' is-disabled'}`}
        onDragOver={(event) => {
          if (!enabled) return
          event.preventDefault()
          setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          if (!enabled) return
          event.preventDefault()
          setDragging(false)
          accept(event.dataTransfer.files)
        }}
      >
        <div className="sc-dropzone__copy">
          <span className="sc-dropzone__art" aria-hidden>
            <UploadCloud size={20} />
          </span>
          <span>
            <strong>{enabled ? 'Drag & drop files here or click to upload' : 'Attachments are unavailable'}</strong>
            <span>Invoices, photographs, inspection reports or any supporting document. PDF, JPG, PNG up to 10 MB each.</span>
          </span>
        </div>

        <Button disabled={!enabled} onClick={() => inputRef.current?.click()}>
          Choose files
        </Button>

        <input
          ref={inputRef}
          type="file"
          multiple
          accept={ACCEPT}
          className="sc-sr-only"
          tabIndex={-1}
          aria-hidden
          onChange={(event) => {
            accept(event.target.files)
            // Cleared so choosing the same file twice in a row still fires.
            event.target.value = ''
          }}
        />
      </div>

      {attachments.length > 0 && (
        <ul className="sc-file-list">
          {attachments.map((attachment) => (
            <li key={attachment.key} className="sc-file">
              <span className="sc-file__mark" aria-hidden>
                <FileText size={16} />
              </span>
              <span className="sc-file__body">
                <strong>{attachment.name}</strong>
                <span className={attachment.error ? 'is-error' : undefined}>
                  {readableSize(attachment.size)}
                  {attachment.error ? ` · ${attachment.error}` : attachment.status === 'uploaded' ? ' · attached' : ''}
                </span>
              </span>

              {attachment.status === 'failed' && onRetry && (
                <Button small ariaLabel={`Retry ${attachment.name}`} onClick={() => onRetry(attachment.key)}>
                  <RotateCcw size={13} aria-hidden />
                  Retry
                </Button>
              )}

              <button
                type="button"
                className="claim-line-remove"
                onClick={() => remove(attachment.key)}
                aria-label={`Remove ${attachment.name}`}
              >
                <X size={15} aria-hidden />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
