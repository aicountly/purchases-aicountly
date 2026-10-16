/**
 * Company, branch and financial-year truth, read live from Manage through this
 * product's own API (`/api/v1/manage/*`, read-only).
 *
 * Nothing here is cached or stored. The switcher asks on the request that draws
 * it, which is the only way a list of companies cannot go stale when someone is
 * added to one elsewhere.
 *
 * The payloads are normalised by manageShapes.ts, which is shared verbatim with
 * Inventory: Manage's list envelope has grown several shapes over the years and
 * the row fields differ between `companies` and `companyinfo`. Parsing it in
 * one audited place beats re-deriving it per product.
 */

import { api } from './api'
import { companyListTotal, extractCompanyRows, parseCompanyInfo, parseCompanyRow } from '../company/manageShapes'
import type { CompanyInfo, CompanyOption } from '../company/manageShapes'
import { noteCompanyInfo } from '../lifecycle/companyLifecycle'

/**
 * A company row plus what Manage says about opening it IN PURCHASES.
 *
 * Manage adds `app_access_status` / `can_open_in_current_app` to its list rows when the
 * caller names the product — this API's ManageClient sends `X-Saas-Origin: purchases` on
 * every call, so the relayed rows carry them. Absent (an older Manage) means unknown: the
 * row is treated as before, and the server still decides on open.
 */
export interface CompanyListOption extends CompanyOption {
  appAccessStatus: string | null
  /** false = visible in the workspace but not open to this person in Purchases. null = not reported. */
  canOpenInCurrentApp: boolean | null
}

function listOptions(body: unknown): CompanyListOption[] {
  const seen = new Set<number>()
  const out: CompanyListOption[] = []
  for (const row of extractCompanyRows(body)) {
    const c = parseCompanyRow(row)
    if (!c || seen.has(c.cmpId)) continue
    seen.add(c.cmpId)
    const status = typeof row.app_access_status === 'string' ? row.app_access_status : null
    const canOpen = typeof row.can_open_in_current_app === 'boolean' ? row.can_open_in_current_app : null
    out.push({ ...c, appAccessStatus: status, canOpenInCurrentApp: canOpen })
  }
  return out
}

const PER_PAGE = 100
const MAX_PAGES = 20

/**
 * Every company the signed-in user can open.
 *
 * Paged, because an accountant with three hundred clients is a real user and a
 * single unbounded response is how that person's switcher times out. MAX_PAGES
 * bounds it so a malformed `total` cannot spin forever.
 */
export async function fetchAllCompanies(signal?: AbortSignal): Promise<CompanyListOption[]> {
  const merged: CompanyListOption[] = []
  const seen = new Set<number>()
  let reportedTotal: number | null = null

  for (let page = 1; page <= MAX_PAGES; page += 1) {
    const body = await api.unscoped<unknown>('v1/manage/companies', {
      filter: 'all',
      page,
      per_page: PER_PAGE,
    }, signal)

    const rows = listOptions(body)
    if (reportedTotal === null) reportedTotal = companyListTotal(body, rows)

    for (const row of rows) {
      if (seen.has(row.cmpId)) continue
      seen.add(row.cmpId)
      merged.push(row)
    }

    if (rows.length === 0 || rows.length < PER_PAGE || merged.length >= reportedTotal) break
  }

  return merged
}

/** One company, with its financial years and branches — Manage returns all three together. */
export async function fetchCompanyInfo(cmpId: number, signal?: AbortSignal): Promise<CompanyInfo> {
  const body = await api.unscoped<unknown>('v1/manage/companyinfo', { comp_id: cmpId }, signal)
  // Manage's lifecycle for the company in Purchases (restricted / purge_pending → the read-only banner).
  noteCompanyInfo(cmpId, body)
  return parseCompanyInfo(body)
}

export type { BranchOption, CompanyInfo, CompanyOption, FyOption } from '../company/manageShapes'
