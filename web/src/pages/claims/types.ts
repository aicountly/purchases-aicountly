/**
 * The shapes the New Claim screen works in.
 *
 * Two families, deliberately kept apart:
 *
 *  - `ClaimDraft` is what the FORM holds. It is the user's work in progress,
 *    with empty strings and nulls where they have not answered yet.
 *  - `CreateClaimPayload` is what the API is SENT. It is built from the draft
 *    once, at the point of saving, by `toCreatePayload`.
 *
 * Keeping them separate is what stops the form's convenience fields — the
 * supplier's name, a purchase order's number, an unsent file — from being
 * posted to an endpoint that has no column for them.
 */

import type { CatalogSupplier } from '../../services/types'

export type ClaimStepId = 'details' | 'references' | 'items' | 'more' | 'review'

export interface ClaimOption {
  value: string
  label: string
}

/** What `/v1/claims/meta` answers. The screen renders from this, not from a copy. */
export interface ClaimMeta {
  kinds: ClaimOption[]
  resolutions: ClaimOption[]
  priorities: ClaimOption[]
  limits: {
    subject_max: number
    description_max: number
    lines_max: number
    tags_max: number
  }
  permissions: {
    create: boolean
    settle: boolean
  }
  capabilities: {
    attachments: Capability
    autosave: Capability
  }
}

export interface Capability {
  available: boolean
  reason: string | null
}

/**
 * A record this claim points at, normalised.
 *
 * Purchase orders, bills, deliveries and returns are four different rows from
 * four different tables, and the combobox that finds them does not need to know
 * that. Each search converts its own rows into this, so one component renders
 * all four.
 */
export interface ReferenceOption {
  id: number
  /** The number a supplier would recognise. */
  primary: string
  secondary: string | null
  meta: Array<{ label: string; value: string }>
}

export type ReferenceKind = 'po' | 'bill' | 'grn' | 'return'

export interface ClaimLineDraft {
  /** Local only. Rows are reordered and removed, so React needs a stable key. */
  key: string
  itemId: number | null
  description: string
  referenceKind: ReferenceKind | 'none'
  referenceNo: string
  orderedQty: string
  receivedQty: string
  claimQty: string
  rate: string
  /** Empty means "quantity times rate". Typed means the user overrode it. */
  claimAmount: string
  reason: string
}

/**
 * A file the buyer has chosen.
 *
 * `status` covers the whole life of one: chosen, refused by this deployment,
 * uploading, failed with a retry, done. It exists in full even though this
 * deployment cannot store files, because the difference between "we cannot"
 * and "it did not work" is the difference the user has to be told.
 */
export interface ClaimAttachmentDraft {
  key: string
  name: string
  size: number
  type: string
  status: 'pending' | 'uploading' | 'uploaded' | 'failed' | 'unsupported'
  error: string | null
  file: File
}

export interface ClaimDraft {
  supplier: { id: number; name: string; code: string | null; gstin: string | null } | null
  claimKind: string
  claimDate: string
  subject: string
  description: string

  purchaseOrder: ReferenceOption | null
  purchaseBill: ReferenceOption | null
  delivery: ReferenceOption | null
  returnRef: ReferenceOption | null

  lines: ClaimLineDraft[]

  requestedResolution: string
  expectedResolutionDate: string
  supplierContact: string
  internalOwner: string
  priority: string
  internalNotes: string
  supplierNotes: string
  tags: string[]
  notifySupplier: boolean

  attachments: ClaimAttachmentDraft[]
}

/** Exactly what `POST /v1/claims` accepts. Nothing here is decorative. */
export interface CreateClaimPayload {
  supplier_account_id: number
  claim_kind: string
  claim_date: string
  subject: string
  description: string
  po_id: number | null
  bill_request_id: number | null
  receipt_request_id: number | null
  return_id: number | null
  claimed_amount: number
  requested_resolution: string | null
  expected_resolution_date: string | null
  supplier_contact: string | null
  internal_owner: string | null
  priority: string
  internal_notes: string | null
  supplier_notes: string | null
  tags: string[]
  notify_supplier: boolean
  submit: boolean
  lines: Array<{
    item_id: number | null
    description: string | null
    reference_kind: string
    reference_no: string | null
    ordered_qty: number
    received_qty: number
    claim_qty: number
    rate: number
    claim_amount: number
    reason: string | null
  }>
}

/** What a claim looks like coming back, with the parts this screen reads. */
export interface CreatedClaim {
  claim_id: number
  claim_no: string
  claim_date: string
  status: string
  claimed_amount: string
  supplier_account_id: number
  subject: string | null
}

export interface SimilarClaim {
  claim_id: number
  claim_no: string
  claim_date: string
  claim_kind: string
  subject: string | null
  status: string
  claimed_amount: string
}

export type AssistIntent =
  | 'draft_description'
  | 'improve_description'
  | 'suggest_type'
  | 'suggest_documents'
  | 'summarise'

export interface AssistResult {
  available: boolean
  intent: string
  /** Set only by `suggest_type`, and always one of the server's own kinds. */
  kind: string | null
  text: string | null
  reason: string | null
}

export type SupplierOption = CatalogSupplier
