/**
 * Step 1 — what the issue is.
 *
 * The five answers a claim cannot be raised without: who it is against, what
 * kind of problem it is, when it is dated, one line naming it and the account
 * of what happened. Everything after this step is optional to the API and
 * valuable to the negotiation.
 */

import { useCallback, useId, useMemo } from 'react'
import { Building2, CalendarDays, Hash, Sparkles } from 'lucide-react'
import { searchSuppliers } from '../service'
import type { ReferenceOption } from '../types'
import { RecordCombobox } from '../components/RecordCombobox'
import { AiSuggestion } from '../components/AiSuggestion'
import { Field, Skeleton, describedBy } from '../components/ui'
import { todayIso } from '../model'
import type { StepProps } from './props'

/** The seven a buyer reaches for most, in the order they happen. */
const QUICK_KINDS = ['shortage', 'quality', 'rate_difference', 'wrong_item', 'damage', 'service_issue', 'other']

export function ClaimDetailsStep({
  draft,
  meta,
  errors,
  patch,
  assist,
  runAssist,
  acceptAssist,
  dismissAssist,
  assistIsFor,
}: StepProps) {
  const kindId = useId()
  const dateId = useId()
  const noId = useId()
  const subjectId = useId()
  const descriptionId = useId()

  const subjectMax = meta?.limits.subject_max ?? 160
  const descriptionMax = meta?.limits.description_max ?? 1000

  // Books' party ledgers, live. Mapped into the one option shape the combobox
  // renders, with the facts that confirm the right party was picked.
  const supplierSearch = useCallback(async (term: string, signal: AbortSignal): Promise<ReferenceOption[]> => {
    const rows = await searchSuppliers(term, signal)

    return rows.map((supplier) => {
      const status = supplier.procurement_profile?.qualification_status

      return {
        id: supplier.acc_id,
        primary: supplier.acc_name,
        secondary: status ? status.replace(/_/g, ' ') : 'no procurement profile',
        meta: supplier.gstin ? [{ label: 'GSTIN', value: supplier.gstin }] : [],
      }
    })
  }, [])

  const selectedSupplier = useMemo<ReferenceOption | null>(
    () =>
      draft.supplier
        ? {
            id: draft.supplier.id,
            primary: draft.supplier.name,
            secondary: null,
            meta: draft.supplier.gstin ? [{ label: 'GSTIN', value: draft.supplier.gstin }] : [],
          }
        : null,
    [draft.supplier],
  )

  const kinds = meta?.kinds ?? []
  const quickKinds = kinds.filter((kind) => QUICK_KINDS.includes(kind.value))

  return (
    <>
      <section className="claim-card claim-step-panel">
        <div className="claim-card-header">
          <h2>
            <Building2 size={15} aria-hidden />
            Basic information
          </h2>
          <span className="claim-card-hint">Fields marked * are required</span>
        </div>

        <div className="claim-form-grid claim-form-grid--4">
          <RecordCombobox
            label="Supplier"
            required
            placeholder="Search or select supplier"
            hint="Searches the party ledgers in Books by name, code or GSTIN."
            error={errors.supplier}
            selected={selectedSupplier}
            search={supplierSearch}
            onPick={(option) =>
              patch({
                supplier: {
                  id: option.id,
                  name: option.primary,
                  code: null,
                  gstin: option.meta.find((fact) => fact.label === 'GSTIN')?.value ?? null,
                },
                // The references belong to the old supplier and mean nothing
                // against the new one. Clearing them is safer than leaving an
                // order from another party attached to this claim.
                purchaseOrder: null,
                purchaseBill: null,
                delivery: null,
                returnRef: null,
              })
            }
            onClear={() => patch({ supplier: null })}
          />

          <Field label="Claim type" htmlFor={kindId} required error={errors.claimKind}>
            {meta === null ? (
              <Skeleton height={40} />
            ) : (
              <select
                id={kindId}
                className="sc-select"
                value={draft.claimKind}
                aria-invalid={errors.claimKind ? true : undefined}
                aria-describedby={describedBy(kindId, null, errors.claimKind)}
                onChange={(event) => patch({ claimKind: event.target.value })}
              >
                <option value="">Select claim type</option>
                {kinds.map((kind) => (
                  <option key={kind.value} value={kind.value}>
                    {kind.label}
                  </option>
                ))}
              </select>
            )}
          </Field>

          <Field label="Claim date" htmlFor={dateId} required error={errors.claimDate}>
            <input
              id={dateId}
              className="sc-input"
              type="date"
              value={draft.claimDate}
              max={todayIso()}
              aria-invalid={errors.claimDate ? true : undefined}
              aria-describedby={describedBy(dateId, null, errors.claimDate)}
              onChange={(event) => patch({ claimDate: event.target.value })}
            />
          </Field>

          {/* The number is the server's. NumberSeries composes it from this
              company's claim prefix and financial year when the claim is
              written, and a number guessed here would be a number that turns
              out to belong to somebody else's claim. */}
          <Field
            label="Claim no."
            htmlFor={noId}
            hint="Issued when the claim is saved, from your company's claim series."
          >
            <input
              id={noId}
              className="sc-input"
              value="Auto-generated"
              readOnly
              aria-describedby={`${noId}-hint`}
            />
          </Field>
        </div>

        <div style={{ marginTop: 14 }}>
          <Field
            label="Subject"
            htmlFor={subjectId}
            required
            error={errors.subject}
            hint="One line a supplier will recognise on an email."
          >
            <input
              id={subjectId}
              className="sc-input"
              type="text"
              maxLength={subjectMax}
              placeholder="E.g. Short supply against PO #PO-1245"
              value={draft.subject}
              aria-invalid={errors.subject ? true : undefined}
              aria-describedby={describedBy(subjectId, true, errors.subject)}
              onChange={(event) => patch({ subject: event.target.value })}
            />
          </Field>
        </div>

        <div style={{ marginTop: 14 }}>
          <Field
            label="Description"
            htmlFor={descriptionId}
            required
            error={errors.description}
            action={
              <button
                type="button"
                className="sc-ai-inline"
                disabled={assist.busy}
                onClick={() => runAssist(draft.description.trim() === '' ? 'draft_description' : 'improve_description')}
              >
                <Sparkles size={12} aria-hidden />
                {draft.description.trim() === '' ? 'Draft with AI' : 'Improve with AI'}
              </button>
            }
          >
            <textarea
              id={descriptionId}
              className="sc-textarea"
              rows={5}
              maxLength={descriptionMax}
              placeholder="Provide details of the issue, what happened, and what resolution you are seeking…"
              value={draft.description}
              aria-invalid={errors.description ? true : undefined}
              aria-describedby={describedBy(descriptionId, null, errors.description)}
              onChange={(event) => patch({ description: event.target.value })}
            />
          </Field>

          <div className="sc-textarea-meta">
            <span>Dates, quantities and document numbers are what get a claim settled.</span>
            <span className={draft.description.length > descriptionMax ? 'is-over' : undefined}>
              {draft.description.length}/{descriptionMax}
            </span>
          </div>

          {(assistIsFor('draft_description') || assistIsFor('improve_description')) && (
            <AiSuggestion
              result={assist.result}
              busy={assist.busy}
              error={assist.error}
              applyLabel="Use this description"
              onApply={acceptAssist}
              onDismiss={dismissAssist}
              onRetry={() => runAssist(assist.intent === 'improve_description' ? 'improve_description' : 'draft_description')}
            />
          )}
        </div>

        {quickKinds.length > 0 && (
          <div className="claim-type-chips" role="group" aria-label="Common claim types">
            {quickKinds.map((kind) => (
              <button
                key={kind.value}
                type="button"
                className={draft.claimKind === kind.value ? 'is-selected' : undefined}
                aria-pressed={draft.claimKind === kind.value}
                onClick={() => patch({ claimKind: kind.value })}
              >
                {kind.label}
              </button>
            ))}
          </div>
        )}
      </section>

      <section className="claim-card claim-step-panel">
        <div className="claim-card-header">
          <h2>
            <Hash size={15} aria-hidden />
            Where this claim will sit
          </h2>
        </div>
        <p style={{ margin: 0, fontSize: 12.5, color: 'var(--sc-text-secondary)', lineHeight: 1.6 }}>
          <CalendarDays size={13} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
          Claims are held against the company and financial year you are working in, not against a branch — a shortage
          is owed by the supplier, wherever the goods were delivered. Where a settled claim has an accounting
          consequence, Smart Books records it.
        </p>
      </section>
    </>
  )
}
