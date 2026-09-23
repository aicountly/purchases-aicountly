/**
 * The exported profile file, and the reader that takes one back in.
 *
 * TWO RULES, and they are the whole reason this is its own module.
 *
 * 1. NOTHING LEAVES THAT SHOULD NOT. The export carries the configuration a
 *    person would set up by hand on this screen and nothing else — no company
 *    id, no financial year, no policy ids, no session key, no user. A profile
 *    file is a thing people email each other, and an internal id in one is an
 *    internal id in somebody's inbox.
 *
 * 2. AN IMPORTED FILE IS UNTRUSTED INPUT. It is parsed as JSON — never
 *    evaluated — and then every field is checked for type and range before it
 *    is allowed near the form. A file that fails says why, field by field,
 *    rather than half-applying itself.
 */

import type { MatchPolicy } from '../../../services/types'
import {
  CODE_LIMIT,
  DEFAULT_PROFILE,
  DESCRIPTION_LIMIT,
  NAME_LIMIT,
  PREFIX_LIMIT,
  type ProfileForm,
} from './profileModel'

export const PROFILE_FILE_VERSION = 1

export interface ExportedPolicy {
  policy_name: string
  qty_tolerance_pc: number
  rate_tolerance_pc: number
  value_tolerance_amt: number
  freight_tolerance_amt: number
  auto_match_below_amt: number
  is_default: boolean
}

export interface ProfileFile {
  aicountly: {
    product: 'purchases'
    artifact: 'purchase-profile'
    version: number
    exported_at: string
  }
  profile: {
    code: string
    name: string
    type: string
    description: string
    active: boolean
    numbering: {
      requisition_prefix: string
      rfq_prefix: string
      po_prefix: string
      return_prefix: string
      claim_prefix: string
    }
    approvals: {
      requisition_approval_above_amount: number
      po_approval_above_amount: number
      enforce_approved_vendors: boolean
      block_bill_on_match_failure: boolean
    }
  }
  match_policies: ExportedPolicy[]
}

function amount(value: string | number): number {
  const parsed = typeof value === 'number' ? value : Number(String(value).trim())
  return Number.isFinite(parsed) ? parsed : 0
}

export function buildExport(form: ProfileForm, policies: MatchPolicy[]): ProfileFile {
  return {
    aicountly: {
      product: 'purchases',
      artifact: 'purchase-profile',
      version: PROFILE_FILE_VERSION,
      exported_at: new Date().toISOString(),
    },
    profile: {
      code: form.code.trim().toUpperCase(),
      name: form.name.trim(),
      type: form.type,
      description: form.description.trim(),
      active: form.active,
      numbering: {
        requisition_prefix: form.numbering.requisitionPrefix.trim(),
        rfq_prefix: form.numbering.rfqPrefix.trim(),
        po_prefix: form.numbering.purchaseOrderPrefix.trim(),
        return_prefix: form.numbering.returnPrefix.trim(),
        claim_prefix: form.numbering.claimPrefix.trim(),
      },
      approvals: {
        requisition_approval_above_amount: amount(form.approvals.requisitionLimit),
        po_approval_above_amount: amount(form.approvals.purchaseOrderLimit),
        enforce_approved_vendors: form.approvals.approvedSuppliersOnly,
        block_bill_on_match_failure: form.approvals.blockBillOnMatchException,
      },
    },
    // Named tolerances only. `policy_id` is this database's key and means
    // nothing in another company's, so it is left out rather than exported and
    // silently ignored on the way back in.
    match_policies: policies.map((policy) => ({
      policy_name: policy.policy_name,
      qty_tolerance_pc: amount(policy.qty_tolerance_pc),
      rate_tolerance_pc: amount(policy.rate_tolerance_pc),
      value_tolerance_amt: amount(policy.value_tolerance_amt),
      freight_tolerance_amt: amount(policy.freight_tolerance_amt),
      auto_match_below_amt: amount(policy.auto_match_below_amt),
      is_default: Boolean(policy.is_default),
    })),
  }
}

export function exportFilename(form: ProfileForm): string {
  const code = form.code.trim().toLowerCase().replace(/[^a-z0-9._-]+/g, '-') || 'profile'
  return `aicountly-purchase-profile-${code}.json`
}

/** Hand the browser a file. Revoked straight away; an object URL that is never released is a leak. */
export function downloadJson(filename: string, payload: unknown): void {
  const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

// ---------------------------------------------------------------------------
// Reading one back
// ---------------------------------------------------------------------------

export type ImportResult =
  | { ok: true; form: ProfileForm; policies: ExportedPolicy[]; warnings: string[] }
  | { ok: false; errors: string[] }

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readString(
  source: Record<string, unknown>,
  key: string,
  label: string,
  limit: number,
  errors: string[],
  fallback: string,
): string {
  const raw = source[key]
  if (raw === undefined || raw === null) return fallback
  if (typeof raw !== 'string') {
    errors.push(`${label} must be text.`)
    return fallback
  }
  const value = raw.trim()
  if (value.length > limit) {
    errors.push(`${label} is longer than ${limit} characters.`)
    return fallback
  }
  return value
}

function readPrefix(
  source: Record<string, unknown>,
  key: string,
  label: string,
  errors: string[],
  fallback: string,
): string {
  const value = readString(source, key, label, PREFIX_LIMIT, errors, fallback)
  if (value === '') {
    errors.push(`${label} cannot be empty.`)
    return fallback
  }
  if (/\s/.test(value)) {
    errors.push(`${label} cannot contain spaces.`)
    return fallback
  }
  return value
}

function readAmount(
  source: Record<string, unknown>,
  key: string,
  label: string,
  errors: string[],
  fallback: number,
  max?: number,
): number {
  const raw = source[key]
  if (raw === undefined || raw === null) return fallback
  const value = typeof raw === 'number' ? raw : typeof raw === 'string' ? Number(raw.trim()) : Number.NaN
  if (!Number.isFinite(value)) {
    errors.push(`${label} must be a number.`)
    return fallback
  }
  if (value < 0) {
    errors.push(`${label} cannot be negative.`)
    return fallback
  }
  if (max !== undefined && value > max) {
    errors.push(`${label} cannot be more than ${max}.`)
    return fallback
  }
  return value
}

function readBoolean(source: Record<string, unknown>, key: string, fallback: boolean): boolean {
  const raw = source[key]
  return typeof raw === 'boolean' ? raw : fallback
}

/**
 * Turn the text of a file into a form, or into the reasons it cannot be one.
 *
 * `knownTypes` comes from the server. A file naming a profile type this
 * deployment does not have is not rejected outright — that would make a file
 * from a newer release unusable for everything else it carries — but the type
 * is left alone and the reader is told.
 */
export function parseProfileFile(text: string, knownTypes: string[]): ImportResult {
  let parsed: unknown
  try {
    parsed = JSON.parse(text) as unknown
  } catch {
    return { ok: false, errors: ['That file is not valid JSON.'] }
  }

  if (!isRecord(parsed)) {
    return { ok: false, errors: ['That file does not contain a purchase profile.'] }
  }

  const header = isRecord(parsed.aicountly) ? parsed.aicountly : null
  if (!header || header.artifact !== 'purchase-profile') {
    return {
      ok: false,
      errors: ['That file is not an Aicountly purchase profile. Export one from this screen to see the format.'],
    }
  }

  const errors: string[] = []
  const warnings: string[] = []

  const version = typeof header.version === 'number' ? header.version : 0
  if (version > PROFILE_FILE_VERSION) {
    warnings.push(
      `This file was written by a newer version of Purchases (v${version}). Anything it carries that this version does not know is ignored.`,
    )
  }

  const profile = isRecord(parsed.profile) ? parsed.profile : null
  if (!profile) {
    return { ok: false, errors: ['That file has no `profile` section.'] }
  }

  const numbering = isRecord(profile.numbering) ? profile.numbering : {}
  const approvals = isRecord(profile.approvals) ? profile.approvals : {}

  const type = readString(profile, 'type', 'Profile type', 40, errors, DEFAULT_PROFILE.type).toUpperCase()
  let resolvedType = DEFAULT_PROFILE.type
  if (type === '') {
    resolvedType = DEFAULT_PROFILE.type
  } else if (knownTypes.length === 0 || knownTypes.includes(type)) {
    resolvedType = type
  } else {
    warnings.push(`This deployment has no profile type "${type}", so the type was left as it was.`)
  }

  const form: ProfileForm = {
    code: readString(profile, 'code', 'Profile code', CODE_LIMIT, errors, '').toUpperCase(),
    name: readString(profile, 'name', 'Profile name', NAME_LIMIT, errors, ''),
    type: resolvedType,
    description: readString(profile, 'description', 'Description', DESCRIPTION_LIMIT, errors, ''),
    numbering: {
      requisitionPrefix: readPrefix(numbering, 'requisition_prefix', 'Requisition prefix', errors, DEFAULT_PROFILE.numbering.requisitionPrefix),
      rfqPrefix: readPrefix(numbering, 'rfq_prefix', 'RFQ prefix', errors, DEFAULT_PROFILE.numbering.rfqPrefix),
      purchaseOrderPrefix: readPrefix(numbering, 'po_prefix', 'Purchase order prefix', errors, DEFAULT_PROFILE.numbering.purchaseOrderPrefix),
      returnPrefix: readPrefix(numbering, 'return_prefix', 'Return prefix', errors, DEFAULT_PROFILE.numbering.returnPrefix),
      claimPrefix: readPrefix(numbering, 'claim_prefix', 'Claim prefix', errors, DEFAULT_PROFILE.numbering.claimPrefix),
    },
    approvals: {
      requisitionLimit: String(
        readAmount(approvals, 'requisition_approval_above_amount', 'Requisition approval limit', errors, 0),
      ),
      purchaseOrderLimit: String(
        readAmount(approvals, 'po_approval_above_amount', 'Purchase order approval limit', errors, 0),
      ),
      approvedSuppliersOnly: readBoolean(approvals, 'enforce_approved_vendors', false),
      blockBillOnMatchException: readBoolean(approvals, 'block_bill_on_match_failure', true),
    },
    active: readBoolean(profile, 'active', true),
  }

  const policies: ExportedPolicy[] = []
  if (Array.isArray(parsed.match_policies)) {
    parsed.match_policies.forEach((entry, index) => {
      if (!isRecord(entry)) {
        errors.push(`Tolerance policy ${index + 1} is not an object.`)
        return
      }
      const name = readString(entry, 'policy_name', `Tolerance policy ${index + 1} name`, 80, errors, '')
      if (name === '') {
        errors.push(`Tolerance policy ${index + 1} has no name.`)
        return
      }
      policies.push({
        policy_name: name,
        qty_tolerance_pc: readAmount(entry, 'qty_tolerance_pc', `${name}: quantity %`, errors, 0, 100),
        rate_tolerance_pc: readAmount(entry, 'rate_tolerance_pc', `${name}: rate %`, errors, 0, 100),
        value_tolerance_amt: readAmount(entry, 'value_tolerance_amt', `${name}: value tolerance`, errors, 0),
        freight_tolerance_amt: readAmount(entry, 'freight_tolerance_amt', `${name}: freight tolerance`, errors, 0),
        auto_match_below_amt: readAmount(entry, 'auto_match_below_amt', `${name}: auto-match below`, errors, 0),
        is_default: readBoolean(entry, 'is_default', false),
      })
    })
  }

  if (errors.length > 0) return { ok: false, errors }

  return { ok: true, form, policies, warnings }
}
