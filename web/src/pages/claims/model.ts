/**
 * The claim draft: what it starts as, what it adds up to, what is wrong with
 * it, and what it becomes when it is sent.
 *
 * All of it is pure. No component in this folder computes a total or decides
 * whether a step is complete — they ask here, which is why the footer, the
 * stepper, the summary rail and the review screen can never disagree about
 * whether the claim is ready.
 */

import type {
  ClaimDraft,
  ClaimLineDraft,
  ClaimMeta,
  ClaimStepId,
  CreateClaimPayload,
} from './types'

export interface StepDefinition {
  id: ClaimStepId
  title: string
  caption: string
}

export const CLAIM_STEPS: StepDefinition[] = [
  { id: 'details', title: 'Claim details', caption: 'What is the issue?' },
  { id: 'references', title: 'References', caption: 'Link related documents' },
  { id: 'items', title: 'Items & amounts', caption: 'Add claim lines' },
  { id: 'more', title: 'More information', caption: 'Additional details' },
  { id: 'review', title: 'Review & submit', caption: 'Confirm and raise' },
]

export function stepIndex(step: ClaimStepId): number {
  return Math.max(0, CLAIM_STEPS.findIndex((candidate) => candidate.id === step))
}

/** Today, in the browser's own timezone — the same day the user's calendar shows. */
export function todayIso(): string {
  const now = new Date()
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000)

  return local.toISOString().slice(0, 10)
}

let lineCounter = 0

export function emptyLine(seed: Partial<ClaimLineDraft> = {}): ClaimLineDraft {
  lineCounter += 1

  return {
    key: `line-${lineCounter}-${Math.random().toString(36).slice(2, 8)}`,
    itemId: null,
    description: '',
    referenceKind: 'none',
    referenceNo: '',
    orderedQty: '',
    receivedQty: '',
    claimQty: '',
    rate: '',
    claimAmount: '',
    reason: '',
    ...seed,
  }
}

export function emptyDraft(): ClaimDraft {
  return {
    supplier: null,
    claimKind: '',
    claimDate: todayIso(),
    subject: '',
    description: '',
    purchaseOrder: null,
    purchaseBill: null,
    delivery: null,
    returnRef: null,
    lines: [emptyLine()],
    requestedResolution: '',
    expectedResolutionDate: '',
    supplierContact: '',
    internalOwner: '',
    priority: 'normal',
    internalNotes: '',
    supplierNotes: '',
    tags: [],
    notifySupplier: false,
    attachments: [],
  }
}

function num(value: string): number {
  if (value.trim() === '') return 0
  const parsed = Number.parseFloat(value)

  return Number.isFinite(parsed) ? parsed : 0
}

/**
 * WHAT A LINE IS WORTH, AND WHY IT IS NOT ALWAYS QUANTITY x RATE.
 *
 * A shortage is ten pieces at the agreed rate. A scheme that was not passed on
 * is an amount with no quantity behind it at all, and a rate difference is a
 * gap per piece rather than a price. So an amount that has been typed wins, and
 * the multiplication is only a default for the case where none has been.
 *
 * The server does exactly this same thing with the same precedence, and it is
 * the server's answer that is stored — this one is what the user watches while
 * they type.
 */
export function lineAmount(line: ClaimLineDraft): number {
  if (line.claimAmount.trim() !== '') return round(num(line.claimAmount))

  return round(num(line.claimQty) * num(line.rate))
}

export function totalAmount(draft: ClaimDraft): number {
  return round(draft.lines.reduce((sum, line) => sum + lineAmount(line), 0))
}

function round(value: number): number {
  return Math.round((value + Number.EPSILON) * 10000) / 10000
}

/** A line nobody has typed in yet. The table always keeps one at the bottom. */
export function isBlankLine(line: ClaimLineDraft): boolean {
  return (
    line.description.trim() === '' &&
    line.itemId === null &&
    line.claimQty.trim() === '' &&
    line.rate.trim() === '' &&
    line.claimAmount.trim() === '' &&
    line.reason.trim() === ''
  )
}

export function filledLines(draft: ClaimDraft): ClaimLineDraft[] {
  return draft.lines.filter((line) => !isBlankLine(line))
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

export type FieldErrors = Record<string, string>

/**
 * What is wrong with one step, keyed by field.
 *
 * Keyed rather than listed because the error has to appear BESIDE the control
 * it is about. A toast saying "5 fields are invalid" is a toast that makes
 * somebody hunt.
 */
export function validateStep(step: ClaimStepId, draft: ClaimDraft, meta: ClaimMeta | null): FieldErrors {
  const errors: FieldErrors = {}
  const subjectMax = meta?.limits.subject_max ?? 160
  const descriptionMax = meta?.limits.description_max ?? 1000

  if (step === 'details') {
    if (!draft.supplier) errors.supplier = 'Choose the supplier this claim is against.'
    if (draft.claimKind.trim() === '') errors.claimKind = 'Say what kind of claim this is.'
    if (draft.claimDate.trim() === '') errors.claimDate = 'A claim needs a date.'
    else if (draft.claimDate > todayIso()) errors.claimDate = 'A claim cannot be dated in the future.'
    if (draft.subject.trim() === '') errors.subject = 'Give the claim a subject.'
    else if (draft.subject.trim().length > subjectMax) errors.subject = `Keep the subject under ${subjectMax} characters.`
    if (draft.description.trim() === '') errors.description = 'Describe what happened and what you are asking for.'
    else if (draft.description.trim().length > descriptionMax) {
      errors.description = `Keep the description under ${descriptionMax} characters.`
    }
  }

  if (step === 'items') {
    const lines = filledLines(draft)
    if (lines.length === 0) {
      errors.lines = 'Add at least one line saying what is being claimed.'
    } else if (lines.some((line) => lineAmount(line) < 0 || num(line.claimQty) < 0 || num(line.rate) < 0)) {
      errors.lines = 'A claim line cannot carry a negative quantity, rate or amount.'
    } else if (totalAmount(draft) <= 0) {
      errors.lines = 'The claim adds up to nothing. Enter the amount being claimed.'
    }
    const overClaimed = lines.find(
      (line) => num(line.orderedQty) > 0 && num(line.claimQty) > num(line.orderedQty),
    )
    if (!errors.lines && overClaimed) {
      errors.lines = `You are claiming more than was ordered on "${overClaimed.description || 'a line'}". Check the quantity.`
    }
  }

  if (step === 'more' && draft.expectedResolutionDate.trim() !== '' && draft.expectedResolutionDate < draft.claimDate) {
    errors.expectedResolutionDate = 'The date you expect this resolved cannot be before the claim itself.'
  }

  return errors
}

/** Every step's errors at once — what Review shows and what Submit checks. */
export function validateAll(draft: ClaimDraft, meta: ClaimMeta | null): Record<ClaimStepId, FieldErrors> {
  return {
    details: validateStep('details', draft, meta),
    references: validateStep('references', draft, meta),
    items: validateStep('items', draft, meta),
    more: validateStep('more', draft, meta),
    review: {},
  }
}

export function isStepValid(step: ClaimStepId, draft: ClaimDraft, meta: ClaimMeta | null): boolean {
  return Object.keys(validateStep(step, draft, meta)).length === 0
}

export function isDraftComplete(draft: ClaimDraft, meta: ClaimMeta | null): boolean {
  const all = validateAll(draft, meta)

  return Object.values(all).every((errors) => Object.keys(errors).length === 0)
}

export interface ClaimIssue {
  step: ClaimStepId
  field: string
  message: string
}

export function issuesFor(draft: ClaimDraft, meta: ClaimMeta | null): ClaimIssue[] {
  const all = validateAll(draft, meta)

  return (Object.keys(all) as ClaimStepId[]).flatMap((step) =>
    Object.entries(all[step]).map(([field, message]) => ({ step, field, message })),
  )
}

/** Is there anything here worth warning somebody about before they leave? */
export function isDirty(draft: ClaimDraft): boolean {
  const fresh = emptyDraft()

  return (
    draft.supplier !== null ||
    draft.claimKind !== '' ||
    draft.subject.trim() !== '' ||
    draft.description.trim() !== '' ||
    draft.claimDate !== fresh.claimDate ||
    draft.purchaseOrder !== null ||
    draft.purchaseBill !== null ||
    draft.delivery !== null ||
    draft.returnRef !== null ||
    filledLines(draft).length > 0 ||
    draft.requestedResolution !== '' ||
    draft.expectedResolutionDate !== '' ||
    draft.supplierContact.trim() !== '' ||
    draft.internalOwner.trim() !== '' ||
    draft.priority !== 'normal' ||
    draft.internalNotes.trim() !== '' ||
    draft.supplierNotes.trim() !== '' ||
    draft.tags.length > 0 ||
    draft.notifySupplier ||
    draft.attachments.length > 0
  )
}

// ---------------------------------------------------------------------------
// What gets sent
// ---------------------------------------------------------------------------

function optional(value: string): string | null {
  return value.trim() === '' ? null : value.trim()
}

/**
 * The draft, as the API takes it.
 *
 * The total is sent for the case where there are no lines, and ignored by the
 * server when there are — the server adds the lines up itself. It is here so a
 * claim without lines still has an amount, not so the browser can decide one.
 */
export function toCreatePayload(draft: ClaimDraft, submit: boolean): CreateClaimPayload {
  const lines = filledLines(draft)

  return {
    supplier_account_id: draft.supplier?.id ?? 0,
    claim_kind: draft.claimKind,
    claim_date: draft.claimDate,
    subject: draft.subject.trim(),
    description: draft.description.trim(),
    po_id: draft.purchaseOrder?.id ?? null,
    bill_request_id: draft.purchaseBill?.id ?? null,
    receipt_request_id: draft.delivery?.id ?? null,
    return_id: draft.returnRef?.id ?? null,
    claimed_amount: totalAmount(draft),
    requested_resolution: optional(draft.requestedResolution),
    expected_resolution_date: optional(draft.expectedResolutionDate),
    supplier_contact: optional(draft.supplierContact),
    internal_owner: optional(draft.internalOwner),
    priority: draft.priority || 'normal',
    internal_notes: optional(draft.internalNotes),
    supplier_notes: optional(draft.supplierNotes),
    tags: draft.tags,
    notify_supplier: draft.notifySupplier,
    submit,
    lines: lines.map((line) => ({
      item_id: line.itemId,
      description: optional(line.description),
      reference_kind: line.referenceKind,
      reference_no: optional(line.referenceNo),
      ordered_qty: num(line.orderedQty),
      received_qty: num(line.receivedQty),
      claim_qty: num(line.claimQty),
      rate: num(line.rate),
      claim_amount: lineAmount(line),
      reason: optional(line.reason),
    })),
  }
}

/** The bounded picture of the draft the AI assistant is allowed to be given. */
export function toAssistDraft(draft: ClaimDraft, kindLabel: string | null): Record<string, unknown> {
  return {
    supplier_name: draft.supplier?.name ?? null,
    claim_kind: kindLabel ?? draft.claimKind,
    claim_date: draft.claimDate,
    subject: draft.subject,
    description: draft.description,
    requested_resolution: draft.requestedResolution,
    total_amount: String(totalAmount(draft)),
    references: {
      purchase_order: draft.purchaseOrder?.primary ?? null,
      purchase_bill: draft.purchaseBill?.primary ?? null,
      delivery: draft.delivery?.primary ?? null,
      return: draft.returnRef?.primary ?? null,
    },
    lines: filledLines(draft).map((line) => ({
      description: line.description,
      ordered_qty: line.orderedQty,
      received_qty: line.receivedQty,
      claim_qty: line.claimQty,
      rate: line.rate,
      claim_amount: String(lineAmount(line)),
      reason: line.reason,
    })),
  }
}
