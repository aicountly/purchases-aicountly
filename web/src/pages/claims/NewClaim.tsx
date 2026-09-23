/**
 * Purchase processing → Supplier claims → New claim.
 *
 * THE WHOLE CLAIM IS ONE PIECE OF STATE, HELD HERE. The five steps, the
 * summary rail, the stepper and the footer all read the same draft and all ask
 * `model.ts` the same questions, which is why none of them can disagree about
 * whether the claim is ready. Moving between steps is a state change, not a
 * navigation, so nothing typed is lost by going back to check something.
 *
 * WHAT THE SERVER DECIDES, AND THIS SCREEN DOES NOT:
 *
 *   the claim number      composed by NumberSeries from the company's prefix
 *   the total             recomputed from the lines, whatever was posted
 *   which kinds exist     read from /v1/claims/meta, never a list in this file
 *   whether it is allowed permissions are checked again on every call
 *   what can be attached  capabilities.attachments, and it is honest about it
 *
 * The screen renders what the server says it can do. That is the difference
 * between a form and a demonstration of one.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Lock, TriangleAlert } from 'lucide-react'
import { ApiError } from '../../services/api'
import { useApi } from '../../hooks/useApi'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { usePurchases } from '../../context/PurchasesContext'
import { money } from '../../ui'

import { ClaimPageHeader } from './components/ClaimPageHeader'
import { ClaimHero } from './components/ClaimHero'
import { ClaimStepper } from './components/ClaimStepper'
import { ClaimSummary } from './components/ClaimSummary'
import { ClaimAICopilot } from './components/ClaimAICopilot'
import { ClaimFooterActions } from './components/ClaimFooterActions'
import { ClaimSuccess } from './components/ClaimSuccess'
import { LeaveDialog } from './components/LeaveDialog'
import { Button, Notice, Skeleton } from './components/ui'

import { ClaimDetailsStep } from './steps/ClaimDetailsStep'
import { ClaimReferencesStep } from './steps/ClaimReferencesStep'
import { ClaimItemsStep } from './steps/ClaimItemsStep'
import { ClaimAdditionalInfoStep } from './steps/ClaimAdditionalInfoStep'
import { ClaimReviewStep } from './steps/ClaimReviewStep'

import { createClaim, fetchClaimMeta, fetchSimilarClaims } from './service'
import {
  CLAIM_STEPS,
  emptyDraft,
  isDirty,
  isDraftComplete,
  isStepValid,
  stepIndex,
  toAssistDraft,
  toCreatePayload,
  totalAmount,
  validateStep,
} from './model'
import { useClaimAssist } from './useClaimAssist'
import type { AssistIntent, ClaimDraft, ClaimStepId, CreatedClaim, SimilarClaim } from './types'
import './supplier-claim.css'

export default function NewSupplierClaimPage() {
  const navigate = useNavigate()
  const { scope, can, session } = usePurchases()

  const [draft, setDraft] = useState<ClaimDraft>(() => emptyDraft())
  const [step, setStep] = useState<ClaimStepId>('details')
  const [furthest, setFurthest] = useState<ClaimStepId>('details')
  /** Errors are shown once a step has been left or an attempt has been made. */
  const [touched, setTouched] = useState<Record<string, boolean>>({})
  const [saving, setSaving] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  const [created, setCreated] = useState<CreatedClaim | null>(null)
  const [similar, setSimilar] = useState<SimilarClaim[]>([])
  const [similarDismissed, setSimilarDismissed] = useState(false)

  const assistant = useClaimAssist()

  const canCreate = can('claim.create')

  const { data: meta, loading: metaLoading, error: metaError, reload: reloadMeta } = useApi(
    (signal) => fetchClaimMeta(signal),
    [scope?.cmp_id, scope?.fy_id],
    Boolean(scope),
  )

  const patch = useCallback((change: Partial<ClaimDraft>) => {
    setDraft((current) => ({ ...current, ...change }))
  }, [])

  // The claim is gone from this screen once it is raised, so the browser must
  // stop asking about unsaved work the moment it has been saved.
  const dirty = created === null && isDirty(draft)
  const { pendingHref, cancelLeave } = useUnsavedChangesGuard(dirty)

  const errors = useMemo(
    () => (touched[step] ? validateStep(step, draft, meta) : {}),
    [touched, step, draft, meta],
  )

  const currentIndex = stepIndex(step)
  const canAdvance = isStepValid(step, draft, meta)
  const complete = isDraftComplete(draft, meta)
  const total = totalAmount(draft)
  // The API refuses a claim with no supplier, no kind or no amount, draft or
  // not. Offering Save with any of them missing is offering a 400.
  const canSaveDraft = Boolean(draft.supplier) && draft.claimKind !== '' && total > 0

  // ---------------------------------------------------------------------
  // The duplicate check. Advisory: it never blocks, and a failure is silent.
  // ---------------------------------------------------------------------
  useEffect(() => {
    const supplierId = draft.supplier?.id
    if (!supplierId || (draft.claimKind === '' && !draft.purchaseOrder && !draft.purchaseBill)) {
      setSimilar([])

      return
    }

    const controller = new AbortController()
    const timer = setTimeout(() => {
      fetchSimilarClaims(
        {
          supplierId,
          claimKind: draft.claimKind,
          poId: draft.purchaseOrder?.id ?? null,
          billId: draft.purchaseBill?.id ?? null,
        },
        controller.signal,
      )
        .then((rows) => {
          if (!controller.signal.aborted) {
            setSimilar(rows)
            setSimilarDismissed(false)
          }
        })
        .catch(() => {
          // A warning that could not be fetched must never stop a legitimate
          // claim being raised, so this is deliberately swallowed.
        })
    }, 400)

    return () => {
      controller.abort()
      clearTimeout(timer)
    }
  }, [draft.supplier?.id, draft.claimKind, draft.purchaseOrder?.id, draft.purchaseBill?.id])

  // ---------------------------------------------------------------------
  // Steps
  // ---------------------------------------------------------------------

  const goTo = useCallback(
    (next: ClaimStepId) => {
      setStep(next)
      setFurthest((current) => (stepIndex(next) > stepIndex(current) ? next : current))
      // A step somebody has opened deliberately should show them what it wants.
      window.scrollTo({ top: 0, behavior: 'smooth' })
    },
    [],
  )

  function goNext() {
    setTouched((current) => ({ ...current, [step]: true }))
    if (!canAdvance) return
    const next = CLAIM_STEPS[Math.min(currentIndex + 1, CLAIM_STEPS.length - 1)]
    goTo(next.id)
  }

  function goBack() {
    const previous = CLAIM_STEPS[Math.max(currentIndex - 1, 0)]
    goTo(previous.id)
  }

  // ---------------------------------------------------------------------
  // The assistant
  // ---------------------------------------------------------------------

  const kindLabel = meta?.kinds.find((kind) => kind.value === draft.claimKind)?.label ?? null

  const runAssist = useCallback(
    (intent: AssistIntent) => {
      void assistant.run(intent, toAssistDraft(draft, kindLabel))
    },
    [assistant, draft, kindLabel],
  )

  const acceptAssist = useCallback(() => {
    const { intent, result } = assistant.state
    if (!result?.available) return

    if (intent === 'suggest_type' && result.kind) {
      patch({ claimKind: result.kind })
    } else if ((intent === 'draft_description' || intent === 'improve_description') && result.text) {
      patch({ description: result.text })
    }

    assistant.dismiss()
  }, [assistant, patch])

  const assistDisabled = !draft.supplier && draft.subject.trim() === '' && draft.description.trim() === ''

  // ---------------------------------------------------------------------
  // Saving
  // ---------------------------------------------------------------------

  async function save(submit: boolean) {
    setFailure(null)
    if (submit) setSubmitting(true)
    else setSaving(true)

    try {
      const claim = await createClaim(toCreatePayload(draft, submit))
      setCreated(claim)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (error) {
      // The server's own words. It knows things this screen does not — that a
      // reference belongs to another supplier, that a prefix is not configured.
      setFailure(error instanceof ApiError ? error.message : 'The claim could not be saved.')
      // Field-level refusals belong beside the field, so the step that owns it
      // is opened and marked as attempted.
      const field = error instanceof ApiError ? (error.details.field as string | undefined) : undefined
      if (field) {
        const owner: ClaimStepId = ['supplier_account_id', 'claim_kind', 'subject', 'description', 'claim_date'].includes(field)
          ? 'details'
          : field === 'lines' || field === 'claimed_amount'
            ? 'items'
            : 'more'
        setTouched((current) => ({ ...current, [owner]: true }))
        goTo(owner)
      }
    } finally {
      setSaving(false)
      setSubmitting(false)
    }
  }

  function submitClaim() {
    setTouched({ details: true, references: true, items: true, more: true, review: true })
    if (!complete) {
      goTo('review')

      return
    }
    void save(true)
  }

  function reset() {
    setDraft(emptyDraft())
    setStep('details')
    setFurthest('details')
    setTouched({})
    setCreated(null)
    setFailure(null)
    setSimilar([])
    assistant.dismiss()
  }

  // ---------------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------------

  if (created) {
    return (
      <div className="supplier-claim-page">
        <div className="supplier-claim-page__inner">
          <ClaimSuccess claim={created} supplierName={draft.supplier?.name ?? null} onCreateAnother={reset} />
        </div>
      </div>
    )
  }

  const stepProps = {
    draft,
    meta,
    errors,
    patch,
    goTo,
    assist: assistant.state,
    runAssist,
    acceptAssist,
    dismissAssist: assistant.dismiss,
    assistIsFor: assistant.isFor,
  }

  return (
    <div className="supplier-claim-page">
      <div className="supplier-claim-page__inner">
        <ClaimPageHeader
          canCreate={canCreate}
          canSubmit={complete && canCreate}
          canSaveDraft={canSaveDraft}
          saving={saving}
          submitting={submitting}
          onSaveDraft={() => void save(false)}
          onSubmit={submitClaim}
        />

        <ClaimHero />

        <ClaimStepper
          current={step}
          furthest={furthest}
          completed={(candidate) => stepIndex(candidate) < currentIndex && isStepValid(candidate, draft, meta)}
          onSelect={goTo}
        />

        {!canCreate && session !== null && (
          <div style={{ marginBottom: 14 }}>
            <Notice tone="warning" title="You cannot raise claims in this company">
              <Lock size={12} aria-hidden style={{ verticalAlign: '-1px', marginRight: 5 }} />
              You can fill this in to see what a claim needs, but saving it needs the <code>claim.create</code>{' '}
              permission. Ask whoever administers access for this company.
            </Notice>
          </div>
        )}

        {metaError && (
          <div style={{ marginBottom: 14 }}>
            <Notice
              tone="danger"
              title="The claim options could not be loaded"
              actions={<Button small onClick={reloadMeta}>Retry</Button>}
            >
              {metaError} Claim types, resolutions and limits all come from the server, so this screen cannot offer them
              until it answers.
            </Notice>
          </div>
        )}

        {failure && (
          <div style={{ marginBottom: 14 }}>
            <Notice
              tone="danger"
              title="That claim was not saved"
              actions={<Button small onClick={() => setFailure(null)}>Dismiss</Button>}
            >
              {failure}
            </Notice>
          </div>
        )}

        {similar.length > 0 && !similarDismissed && (
          <div style={{ marginBottom: 14 }}>
            <Notice
              tone="warning"
              title={`Possible similar claim${similar.length === 1 ? '' : 's'} found`}
              actions={<Button small onClick={() => setSimilarDismissed(true)}>Dismiss</Button>}
            >
              <TriangleAlert size={12} aria-hidden style={{ verticalAlign: '-1px', marginRight: 5 }} />
              {similar
                .slice(0, 3)
                .map((claim) => `${claim.claim_no} (${money(claim.claimed_amount)}, ${claim.status.toLowerCase().replace(/_/g, ' ')})`)
                .join(' · ')}
              . Two genuine claims against one order are normal — this is a check, not a refusal.
            </Notice>
          </div>
        )}

        <div className="claim-content-grid">
          <main className="claim-main">
            {metaLoading && meta === null ? (
              <section className="claim-card">
                <Skeleton height={22} width="40%" />
                <div style={{ display: 'grid', gap: 12, marginTop: 16 }}>
                  <Skeleton height={40} />
                  <Skeleton height={40} />
                  <Skeleton height={110} />
                </div>
              </section>
            ) : (
              <>
                {step === 'details' && <ClaimDetailsStep {...stepProps} />}
                {step === 'references' && <ClaimReferencesStep {...stepProps} />}
                {step === 'items' && <ClaimItemsStep {...stepProps} />}
                {step === 'more' && <ClaimAdditionalInfoStep {...stepProps} />}
                {step === 'review' && <ClaimReviewStep {...stepProps} />}
              </>
            )}
          </main>

          <aside className="claim-intelligence-rail" aria-label="Claim assistance">
            <ClaimAICopilot
              assist={assistant.state}
              onRun={runAssist}
              onAccept={acceptAssist}
              onDismiss={assistant.dismiss}
              disabled={assistDisabled}
              disabledReason="Choose a supplier or write something about the problem first — the assistant works from the claim on screen."
            />
            <ClaimSummary draft={draft} meta={meta} step={step} />
          </aside>
        </div>

        <ClaimFooterActions
          isFirst={currentIndex === 0}
          isLast={currentIndex === CLAIM_STEPS.length - 1}
          canAdvance={canAdvance}
          canSubmit={complete && canCreate}
          canSaveDraft={canSaveDraft && canCreate}
          saving={saving}
          submitting={submitting}
          onBack={goBack}
          onNext={goNext}
          onSaveDraft={() => void save(false)}
          onSubmit={submitClaim}
          onCancel={() => navigate('/claims')}
        />

        <LeaveDialog
          open={pendingHref !== null}
          saving={saving}
          canSaveDraft={canSaveDraft && canCreate}
          onStay={cancelLeave}
          onDiscard={() => {
            const target = pendingHref
            cancelLeave()
            setDraft(emptyDraft())
            if (target) navigate(target)
          }}
          onSaveDraft={() => {
            cancelLeave()
            void save(false)
          }}
        />
      </div>
    </div>
  )
}
