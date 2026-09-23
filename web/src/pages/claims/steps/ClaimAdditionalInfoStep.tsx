/**
 * Step 4 — how this claim is going to be chased.
 *
 * None of it is required and all of it is what turns a claim into something
 * somebody follows up: what we are asking for, by when, who owns it here and
 * who we are speaking to there.
 *
 * THE TWO NOTE FIELDS ARE NOT THE SAME FIELD. What we say to each other about a
 * supplier and what we are willing to send them are different things, stored in
 * different columns, and the one that is internal is labelled as internal every
 * time it is shown.
 */

import { useId, useState } from 'react'
import { Flag, Lock, Send, Tag, UserRound, X } from 'lucide-react'
import { Field, Notice, describedBy } from '../components/ui'
import type { StepProps } from './props'

export function ClaimAdditionalInfoStep({ draft, meta, errors, patch }: StepProps) {
  const resolutionId = useId()
  const expectedId = useId()
  const contactId = useId()
  const ownerId = useId()
  const priorityId = useId()
  const internalId = useId()
  const supplierNotesId = useId()
  const tagId = useId()

  const [tagDraft, setTagDraft] = useState('')
  const tagsMax = meta?.limits.tags_max ?? 10

  function addTag() {
    const tag = tagDraft.trim()
    if (tag === '' || draft.tags.includes(tag) || draft.tags.length >= tagsMax) {
      setTagDraft('')

      return
    }
    patch({ tags: [...draft.tags, tag] })
    setTagDraft('')
  }

  return (
    <>
      <section className="claim-card claim-step-panel">
        <div className="claim-card-header">
          <h2>
            <Send size={15} aria-hidden />
            Resolution requested
          </h2>
          <span className="claim-card-hint">What you are asking the supplier for</span>
        </div>

        <div className="claim-form-grid claim-form-grid--3">
          <Field label="Resolution" htmlFor={resolutionId}>
            <select
              id={resolutionId}
              className="sc-select"
              value={draft.requestedResolution}
              onChange={(event) => patch({ requestedResolution: event.target.value })}
            >
              <option value="">Not decided yet</option>
              {(meta?.resolutions ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </Field>

          <Field
            label="Expected resolution date"
            htmlFor={expectedId}
            error={errors.expectedResolutionDate}
            hint="When you need this settled by."
          >
            <input
              id={expectedId}
              className="sc-input"
              type="date"
              value={draft.expectedResolutionDate}
              min={draft.claimDate}
              aria-invalid={errors.expectedResolutionDate ? true : undefined}
              aria-describedby={describedBy(expectedId, true, errors.expectedResolutionDate)}
              onChange={(event) => patch({ expectedResolutionDate: event.target.value })}
            />
          </Field>

          <Field label="Priority" htmlFor={priorityId}>
            <select
              id={priorityId}
              className="sc-select"
              value={draft.priority}
              onChange={(event) => patch({ priority: event.target.value })}
            >
              {(meta?.priorities ?? [{ value: 'normal', label: 'Normal' }]).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </Field>
        </div>
      </section>

      <section className="claim-card claim-step-panel">
        <div className="claim-card-header">
          <h2>
            <UserRound size={15} aria-hidden />
            Who is chasing it
          </h2>
        </div>

        <div className="claim-form-grid claim-form-grid--2">
          <Field
            label="Supplier contact"
            htmlFor={contactId}
            hint="The person at the supplier this is being taken up with."
          >
            <input
              id={contactId}
              className="sc-input"
              value={draft.supplierContact}
              placeholder="Name, or name and number"
              aria-describedby={`${contactId}-hint`}
              onChange={(event) => patch({ supplierContact: event.target.value })}
            />
          </Field>

          <Field label="Internal owner" htmlFor={ownerId} hint="Leave empty and it stays with you.">
            <input
              id={ownerId}
              className="sc-input"
              value={draft.internalOwner}
              placeholder="Who follows this up here"
              aria-describedby={`${ownerId}-hint`}
              onChange={(event) => patch({ internalOwner: event.target.value })}
            />
          </Field>
        </div>

        <div className="claim-form-grid claim-form-grid--2" style={{ marginTop: 14 }}>
          <Field
            label="Internal notes"
            htmlFor={internalId}
            hint="Never shown to the supplier."
            action={
              <span className="claim-draft-state">
                <Lock size={11} aria-hidden />
                Internal only
              </span>
            }
          >
            <textarea
              id={internalId}
              className="sc-textarea"
              rows={4}
              value={draft.internalNotes}
              placeholder="History with this supplier, what was agreed on the phone, what to do if they refuse…"
              aria-describedby={`${internalId}-hint`}
              onChange={(event) => patch({ internalNotes: event.target.value })}
            />
          </Field>

          <Field label="Supplier-facing notes" htmlFor={supplierNotesId} hint="Sent with the claim.">
            <textarea
              id={supplierNotesId}
              className="sc-textarea"
              rows={4}
              value={draft.supplierNotes}
              placeholder="Anything you want the supplier to read alongside the claim"
              aria-describedby={`${supplierNotesId}-hint`}
              onChange={(event) => patch({ supplierNotes: event.target.value })}
            />
          </Field>
        </div>
      </section>

      <section className="claim-card claim-step-panel">
        <div className="claim-card-header">
          <h2>
            <Tag size={15} aria-hidden />
            Tags &amp; notification
          </h2>
        </div>

        <Field
          label="Tags"
          htmlFor={tagId}
          hint={`Up to ${tagsMax}. Press Enter to add — useful for grouping a season's claims or one depot's.`}
        >
          <input
            id={tagId}
            className="sc-input"
            value={tagDraft}
            placeholder="E.g. monsoon-damage"
            disabled={draft.tags.length >= tagsMax}
            aria-describedby={`${tagId}-hint`}
            onChange={(event) => setTagDraft(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                addTag()
              }
            }}
            onBlur={addTag}
          />
        </Field>

        {draft.tags.length > 0 && (
          <ul className="sc-tag-list" style={{ listStyle: 'none', padding: 0, margin: '9px 0 0' }}>
            {draft.tags.map((tag) => (
              <li key={tag} className="sc-tag">
                {tag}
                <button type="button" onClick={() => patch({ tags: draft.tags.filter((t) => t !== tag) })} aria-label={`Remove tag ${tag}`}>
                  <X size={12} aria-hidden />
                </button>
              </li>
            ))}
          </ul>
        )}

        <div style={{ marginTop: 16 }}>
          <label className="sc-checkbox">
            <input
              type="checkbox"
              checked={draft.notifySupplier}
              onChange={(event) => patch({ notifySupplier: event.target.checked })}
            />
            <span>
              <strong>Notify the supplier after submission</strong>
              Recorded with the claim as your intent.
            </span>
          </label>
        </div>

        {draft.notifySupplier && (
          <div style={{ marginTop: 12 }}>
            <Notice tone="info" title="Nothing is sent from here yet">
              Purchases does not email suppliers today. The claim records that you want it sent, and your internal notes
              are never part of anything a supplier is shown.
            </Notice>
          </div>
        )}

        <p className="sc-field__hint" style={{ marginTop: 14 }}>
          <Flag size={12} aria-hidden style={{ verticalAlign: '-1px', marginRight: 5 }} />
          Priority and owner are this product's own record of the follow-up. They have no effect on approval, which
          follows the thresholds set in your purchase profile.
        </p>
      </section>
    </>
  )
}
