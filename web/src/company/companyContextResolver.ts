/**
 * Which financial year and branch a company opens on — the same rule as AICOUNTLY Books
 * (books-react-app web/src/utils/companyContextResolver.js), so opening a company is one
 * click in every product and lands on the same year and branch:
 *
 *   1. the person's saved default for this company, while that year and branch still exist;
 *   2. otherwise the latest financial year (by end date, then start date) and the head-office
 *      branch (companyinfo `ho_id` when listed, else the branch flagged head office, else the
 *      first branch);
 *   3. no year or no branch: nothing opens, and the picker says why.
 *
 * The year and branch stay changeable afterwards from the top bar, whose star saves the
 * current pair as this company's default. Branch 0 ("All branches", consolidated) is a real
 * scope in Purchases, so a saved 0 is honoured; the fallback is still the head office.
 *
 * Pure apart from the default's storage, which never throws, so it is unit-tested directly.
 */

import type { CompanyScope } from '../services/api.ts'
import type { BranchOption, CompanyInfo, FyOption } from './manageShapes.ts'

const DEFAULTS_KEY_PREFIX = 'aic_purchases_company_default:'

export interface CompanyDefault {
  fy_id: number
  bo_id: number
}

export interface ResolvedContext {
  fyId: number | null
  boId: number | null
  source: 'saved' | 'latest'
  error: string | null
}

export const NO_FY_ERROR = 'This company has no financial year set up. Add one in Aicountly Manage — every document here is filed against a year, so it cannot be opened until one exists.'
export const NO_BRANCH_ERROR = 'This company has no branch set up. Add one in Aicountly Manage, then open it again.'

/** The year with the latest end (then start) date; the highest id when dates are missing. */
export function pickLatestFy(list: FyOption[]): number | null {
  if (list.length === 0) return null
  const sorted = [...list].sort(
    (a, b) => (b.end || '').localeCompare(a.end || '') || (b.start || '').localeCompare(a.start || '') || b.fyId - a.fyId,
  )
  return sorted[0].fyId
}

/** Head-office branch: `ho_id` when it is listed, else the flagged head office, else the first. */
export function pickHeadOfficeBranch(branches: BranchOption[], hoId: number | null): number | null {
  if (branches.length === 0) return null
  if (hoId !== null && hoId > 0 && branches.some((b) => b.boId === hoId)) return hoId
  return (branches.find((b) => b.isHeadOffice) ?? branches[0]).boId
}

export function resolveCompanyContext({
  saved,
  fyList,
  branches,
  hoId,
}: {
  saved: CompanyDefault | null
  fyList: FyOption[]
  branches: BranchOption[]
  hoId: number | null
}): ResolvedContext {
  if (
    saved !== null
    && fyList.some((f) => f.fyId === saved.fy_id)
    && (saved.bo_id === 0 || branches.some((b) => b.boId === saved.bo_id))
  ) {
    return { fyId: saved.fy_id, boId: saved.bo_id, source: 'saved', error: null }
  }
  const fyId = pickLatestFy(fyList)
  if (fyId === null) return { fyId: null, boId: null, source: 'latest', error: NO_FY_ERROR }
  const boId = pickHeadOfficeBranch(branches, hoId)
  if (boId === null) return { fyId, boId: null, source: 'latest', error: NO_BRANCH_ERROR }
  return { fyId, boId, source: 'latest', error: null }
}

/**
 * Asks Manage for the company and resolves the scope it opens on, or why it cannot open.
 * A failed request is thrown as it came, so the caller can tell a refusal from an outage.
 */
export async function resolveCompanyScope(
  cmpId: number,
  fetchInfo: (cmpId: number, signal?: AbortSignal) => Promise<CompanyInfo>,
  signal?: AbortSignal,
): Promise<{ scope: CompanyScope; error: null } | { scope: null; error: string }> {
  const info = await fetchInfo(cmpId, signal)
  const resolved = resolveCompanyContext({
    saved: getCompanyDefault(cmpId),
    fyList: info.fyList,
    branches: info.branches,
    hoId: info.hoId,
  })
  if (resolved.error !== null || resolved.fyId === null || resolved.boId === null) {
    return { scope: null, error: resolved.error ?? NO_FY_ERROR }
  }
  return { scope: { cmp_id: cmpId, fy_id: resolved.fyId, bo_id: resolved.boId }, error: null }
}

/**
 * The person's default year and branch for a company (the top bar's star). Kept in this
 * browser; a stale one (year or branch since removed) is ignored by the resolver.
 */
export function getCompanyDefault(cmpId: number): CompanyDefault | null {
  try {
    const raw = globalThis.localStorage.getItem(`${DEFAULTS_KEY_PREFIX}${cmpId}`)
    const parsed = raw ? (JSON.parse(raw) as { fy_id?: unknown; bo_id?: unknown }) : null
    if (parsed === null || parsed.fy_id == null || parsed.bo_id == null) return null
    const fyId = Number(parsed.fy_id)
    const boId = Number(parsed.bo_id)
    return Number.isInteger(fyId) && fyId > 0 && Number.isInteger(boId) && boId >= 0 ? { fy_id: fyId, bo_id: boId } : null
  } catch {
    return null
  }
}

export function saveCompanyDefault(cmpId: number, fyId: number, boId: number): boolean {
  try {
    globalThis.localStorage.setItem(
      `${DEFAULTS_KEY_PREFIX}${cmpId}`,
      JSON.stringify({ fy_id: String(fyId), bo_id: String(boId) }),
    )
    return true
  } catch {
    return false
  }
}
