/**
 * The purchase profile as the SCREEN holds it, and the rules for turning that
 * into something the server will accept.
 *
 * Kept away from the components on purpose. Validation, the save payload, the
 * export file and the setup review are all answers to "what is in this form",
 * and a component that re-derives any of them is a component that can disagree
 * with the others.
 *
 * Money and percentages live here as STRINGS. A number input bound to a number
 * cannot hold "1." while somebody is typing the rest of it, and rounding a
 * half-typed figure under the cursor is how a 1.5% tolerance becomes 2%.
 */

import type { MatchPolicy, ProfileSavePayload, PurchaseSettings } from '../../../services/types'

export interface ProfileNumbering {
  requisitionPrefix: string
  rfqPrefix: string
  purchaseOrderPrefix: string
  returnPrefix: string
  claimPrefix: string
}

export interface ProfileApprovals {
  requisitionLimit: string
  purchaseOrderLimit: string
  approvedSuppliersOnly: boolean
  blockBillOnMatchException: boolean
}

export interface ProfileForm {
  code: string
  name: string
  type: string
  description: string
  numbering: ProfileNumbering
  approvals: ProfileApprovals
  active: boolean
}

export const DESCRIPTION_LIMIT = 500
export const CODE_LIMIT = 12
export const NAME_LIMIT = 120
export const PREFIX_LIMIT = 12

/**
 * What a brand new profile starts as.
 *
 * The prefixes match the defaults in the `purchase_settings` table, so a
 * profile saved without touching this card numbers documents exactly as this
 * product already numbers them.
 */
export const DEFAULT_PROFILE: ProfileForm = {
  code: '',
  name: '',
  type: 'STANDARD',
  description: '',
  numbering: {
    requisitionPrefix: 'PR',
    rfqPrefix: 'RFQ',
    purchaseOrderPrefix: 'PO',
    returnPrefix: 'PRET',
    claimPrefix: 'CLM',
  },
  approvals: {
    requisitionLimit: '0',
    purchaseOrderLimit: '0',
    approvedSuppliersOnly: false,
    blockBillOnMatchException: true,
  },
  active: true,
}

/** The five prefix fields, in the order they are shown and validated. */
export const PREFIX_FIELDS = [
  { key: 'requisitionPrefix', label: 'Requisition Prefix' },
  { key: 'rfqPrefix', label: 'RFQ Prefix' },
  { key: 'purchaseOrderPrefix', label: 'Purchase Order Prefix' },
  { key: 'returnPrefix', label: 'Return Prefix' },
  { key: 'claimPrefix', label: 'Claim Prefix' },
] as const satisfies ReadonlyArray<{ key: keyof ProfileNumbering; label: string }>

// ---------------------------------------------------------------------------
// Server row <-> form
// ---------------------------------------------------------------------------

function text(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : value === null || value === undefined ? fallback : String(value)
}

/**
 * Trailing zeros off a stored amount.
 *
 * PostgreSQL hands back NUMERIC(18,4) as "0.0000", and an approval threshold
 * that reads 0.0000 is the single ugliest thing on the current screen.
 */
function amountForDisplay(value: unknown): string {
  const raw = text(value, '0').trim()
  if (raw === '') return '0'
  const parsed = Number(raw)
  if (!Number.isFinite(parsed)) return raw
  return String(Number(parsed.toFixed(4)))
}

export function fromSettings(row: Partial<PurchaseSettings> | null | undefined): ProfileForm {
  if (!row) return { ...DEFAULT_PROFILE }

  return {
    code: text(row.profile_code),
    name: text(row.profile_name),
    type: text(row.profile_type, DEFAULT_PROFILE.type) || DEFAULT_PROFILE.type,
    description: text(row.description),
    numbering: {
      requisitionPrefix: text(row.requisition_prefix, DEFAULT_PROFILE.numbering.requisitionPrefix),
      rfqPrefix: text(row.rfq_prefix, DEFAULT_PROFILE.numbering.rfqPrefix),
      purchaseOrderPrefix: text(row.po_prefix, DEFAULT_PROFILE.numbering.purchaseOrderPrefix),
      returnPrefix: text(row.return_prefix, DEFAULT_PROFILE.numbering.returnPrefix),
      claimPrefix: text(row.claim_prefix, DEFAULT_PROFILE.numbering.claimPrefix),
    },
    approvals: {
      requisitionLimit: amountForDisplay(row.requisition_approval_above_amount),
      purchaseOrderLimit: amountForDisplay(row.po_approval_above_amount),
      approvedSuppliersOnly: Boolean(row.enforce_approved_vendors),
      // Absent means the column default, which is TRUE. Reading a missing
      // value as false would quietly unblock bills on a broken response.
      blockBillOnMatchException: row.block_bill_on_match_failure === undefined
        ? true
        : Boolean(row.block_bill_on_match_failure),
    },
    active: row.is_active === undefined ? true : Boolean(row.is_active),
  }
}

function amountForPayload(value: string): number {
  const raw = value.trim()
  if (raw === '') return 0
  const parsed = Number(raw)
  return Number.isFinite(parsed) ? parsed : 0
}

/**
 * What goes on the wire.
 *
 * Trimmed, numbers as numbers, booleans as booleans, and nothing the screen
 * invented for its own use. The server validates all of it again — this is
 * about not sending rubbish, not about being the only check.
 */
export function toPayload(form: ProfileForm): ProfileSavePayload {
  return {
    profile_code: form.code.trim().toUpperCase(),
    profile_name: form.name.trim(),
    profile_type: form.type,
    description: form.description.trim(),
    is_active: form.active,
    requisition_prefix: form.numbering.requisitionPrefix.trim(),
    rfq_prefix: form.numbering.rfqPrefix.trim(),
    po_prefix: form.numbering.purchaseOrderPrefix.trim(),
    return_prefix: form.numbering.returnPrefix.trim(),
    claim_prefix: form.numbering.claimPrefix.trim(),
    requisition_approval_above_amount: amountForPayload(form.approvals.requisitionLimit),
    po_approval_above_amount: amountForPayload(form.approvals.purchaseOrderLimit),
    enforce_approved_vendors: form.approvals.approvedSuppliersOnly,
    block_bill_on_match_failure: form.approvals.blockBillOnMatchException,
  }
}

/**
 * Is this the same configuration, as the SERVER would see it?
 *
 * Field by field rather than by stringifying both, and the amounts are compared
 * as numbers: typing "0.00" over "0" changes the text and changes nothing about
 * the profile, and treating that as unsaved work means warning somebody about
 * losing a change they did not make.
 */
export function sameProfile(a: ProfileForm, b: ProfileForm): boolean {
  const sameAmount = (x: string, y: string) => (Number(x.trim() || 0) || 0) === (Number(y.trim() || 0) || 0)

  return (
    a.code.trim().toUpperCase() === b.code.trim().toUpperCase() &&
    a.name.trim() === b.name.trim() &&
    a.type === b.type &&
    a.description.trim() === b.description.trim() &&
    a.active === b.active &&
    PREFIX_FIELDS.every((field) => a.numbering[field.key].trim() === b.numbering[field.key].trim()) &&
    sameAmount(a.approvals.requisitionLimit, b.approvals.requisitionLimit) &&
    sameAmount(a.approvals.purchaseOrderLimit, b.approvals.purchaseOrderLimit) &&
    a.approvals.approvedSuppliersOnly === b.approvals.approvedSuppliersOnly &&
    a.approvals.blockBillOnMatchException === b.approvals.blockBillOnMatchException
  )
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/** Field name -> message. Field names match the ids the inputs carry. */
export type ProfileErrors = Record<string, string>

const CODE_PATTERN = /^[A-Z0-9][A-Z0-9._-]*$/
const DECIMAL_PATTERN = /^\d*(\.\d{1,4})?$/

export function validateProfile(form: ProfileForm): ProfileErrors {
  const errors: ProfileErrors = {}

  const code = form.code.trim().toUpperCase()
  if (code === '') {
    errors.code = 'A profile code is required.'
  } else if (code.length > CODE_LIMIT) {
    errors.code = `${CODE_LIMIT} characters at most.`
  } else if (!CODE_PATTERN.test(code)) {
    errors.code = 'Letters, digits, dot, dash and underscore only.'
  }

  const name = form.name.trim()
  if (name === '') {
    errors.name = 'A profile name is required.'
  } else if (name.length > NAME_LIMIT) {
    errors.name = `${NAME_LIMIT} characters at most.`
  }

  if (form.description.trim().length > DESCRIPTION_LIMIT) {
    errors.description = `${DESCRIPTION_LIMIT} characters at most.`
  }

  for (const field of PREFIX_FIELDS) {
    const value = form.numbering[field.key].trim()
    if (value === '') {
      errors[field.key] = 'A prefix is required — every document number starts with it.'
    } else if (value.length > PREFIX_LIMIT) {
      errors[field.key] = `${PREFIX_LIMIT} characters at most.`
    } else if (/\s/.test(value)) {
      errors[field.key] = 'A prefix cannot contain spaces.'
    }
  }

  const limits: Array<[keyof ProfileApprovals, string]> = [
    ['requisitionLimit', 'Requisition approval limit'],
    ['purchaseOrderLimit', 'Purchase order approval limit'],
  ]
  for (const [key, label] of limits) {
    const raw = String(form.approvals[key]).trim()
    if (raw === '') continue
    if (!DECIMAL_PATTERN.test(raw)) {
      errors[key] = `${label} must be a number, four decimal places at most.`
    } else if (Number(raw) < 0) {
      errors[key] = `${label} cannot be negative.`
    }
  }

  return errors
}

/** Which card an invalid field sits in, so a failed save can scroll to it. */
export const FIELD_SECTIONS: Record<string, string> = {
  code: 'basic-details',
  name: 'basic-details',
  type: 'basic-details',
  description: 'basic-details',
  requisitionPrefix: 'numbering',
  rfqPrefix: 'numbering',
  purchaseOrderPrefix: 'numbering',
  returnPrefix: 'numbering',
  claimPrefix: 'numbering',
  requisitionLimit: 'approvals',
  purchaseOrderLimit: 'approvals',
}

/**
 * The server names the column it refused; the form knows its own field names.
 *
 * Without this map a 422 on `po_prefix` would show a message with nothing
 * highlighted, which is the same as no message at all.
 */
const COLUMN_TO_FIELD: Record<string, string> = {
  profile_code: 'code',
  profile_name: 'name',
  profile_type: 'type',
  description: 'description',
  requisition_prefix: 'requisitionPrefix',
  rfq_prefix: 'rfqPrefix',
  po_prefix: 'purchaseOrderPrefix',
  return_prefix: 'returnPrefix',
  claim_prefix: 'claimPrefix',
  requisition_approval_above_amount: 'requisitionLimit',
  po_approval_above_amount: 'purchaseOrderLimit',
}

export function fieldForColumn(column: unknown): string | null {
  return typeof column === 'string' ? (COLUMN_TO_FIELD[column] ?? null) : null
}

// ---------------------------------------------------------------------------
// Document number preview
// ---------------------------------------------------------------------------

/**
 * What the next document number will actually look like.
 *
 * NumberSeries builds `{prefix}/{fy_id}/0001`. Showing "PR0001" under the
 * prefix field would be inventing a format this product has never issued, and
 * the first person to go looking for that number would not find it.
 */
export function numberExample(prefix: string, fyId: number | null | undefined): string {
  const stem = prefix.trim() === '' ? '—' : prefix.trim()
  if (stem === '—') return '—'
  return fyId ? `${stem}/${fyId}/0001` : `${stem}/0001`
}

// ---------------------------------------------------------------------------
// Setup review
// ---------------------------------------------------------------------------

export interface SetupSection {
  id: string
  label: string
  /** Has a deliberate configuration been recorded for this section? */
  configured: boolean
  /** Said in words, because a tick on its own is not a status a screen reader can read. */
  detail: string
}

/**
 * How much of this profile has been set up, said honestly.
 *
 * "Not configured" is NOT "wrong". Exact matching with no tolerance policy is
 * the safe default and this product says so elsewhere; a zero approval
 * threshold is a real choice. So each row says what it found rather than
 * scoring the administrator, and the count is there to show what is left to
 * look at, not to be completed for its own sake.
 */
export function setupReview(
  form: ProfileForm,
  policies: MatchPolicy[],
  permissionsResolved: boolean,
): { sections: SetupSection[]; configured: number; total: number; percent: number } {
  const prefixesSet = PREFIX_FIELDS.every((f) => form.numbering[f.key].trim() !== '')
  const hasThreshold =
    Number(form.approvals.requisitionLimit || 0) > 0 || Number(form.approvals.purchaseOrderLimit || 0) > 0
  const hasControl = form.approvals.approvedSuppliersOnly || form.approvals.blockBillOnMatchException

  const sections: SetupSection[] = [
    {
      id: 'basic-details',
      label: 'Basic details',
      configured: form.code.trim() !== '' && form.name.trim() !== '',
      detail:
        form.code.trim() !== '' && form.name.trim() !== ''
          ? `${form.code.trim().toUpperCase()} — ${form.name.trim()}`
          : 'A code and a name are still needed.',
    },
    {
      id: 'numbering',
      label: 'Document numbering',
      configured: prefixesSet,
      detail: prefixesSet ? 'All five document series have a prefix.' : 'One or more prefixes are blank.',
    },
    {
      id: 'approvals',
      label: 'Approvals and controls',
      configured: hasThreshold || hasControl,
      detail: hasThreshold
        ? 'Approval thresholds are set.'
        : hasControl
          ? 'No threshold, so everything auto-approves; procurement controls are on.'
          : 'Everything auto-approves and both controls are off.',
    },
    {
      id: 'matching',
      label: 'Match tolerances',
      configured: policies.length > 0,
      detail:
        policies.length > 0
          ? `${policies.length} tolerance ${policies.length === 1 ? 'policy' : 'policies'}.`
          : 'No policy, so matching is exact — the safe default.',
    },
    {
      id: 'permissions',
      label: 'Permissions',
      configured: permissionsResolved,
      detail: permissionsResolved
        ? 'Access is resolved for this company.'
        : 'Aicountly Manage has not named a role for you here.',
    },
  ]

  const configured = sections.filter((s) => s.configured).length

  return {
    sections,
    configured,
    total: sections.length,
    percent: Math.round((configured / sections.length) * 100),
  }
}
