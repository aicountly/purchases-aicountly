/**
 * Deep links that carry a scope — `?cmp_id=&fy_id=&bo_id=` — from Insights (or any AICOUNTLY
 * product) into Aicountly Purchase (MNY-15, docs/contracts/insights/routes.md).
 *
 * Before this, the app took its company, year and branch from what this browser last used
 * (localStorage) and ignored the URL, so a link from company A opened company B — silently. Now:
 *
 *   • the scope in the URL is CHECKED with Manage, live, as the signed-in person (the same
 *     companyinfo read the company picker makes): a company they cannot open, a year that is not
 *     that company's, a branch that is not its — refused, never swapped for another;
 *   • a link that checks out is applied, and the address bar loses the scope parameters (the
 *     destination page stays);
 *   • the destination — path, query and hash — survives the sign-in round trip through the portal.
 *
 * Pure functions only, no React and no fetch, so the rules are tested without a browser.
 */

export interface LinkScope {
  cmp_id: number
  fy_id: number | null
  bo_id: number | null
}

export interface ScopeFacts {
  /** The years Manage lists for the company (newest first), as the picker reads them. */
  fyIds: number[]
  /** The branches Manage lists for the company. */
  branchIds: number[]
}

export type ScopeVerdict =
  | { kind: 'apply'; cmp_id: number; fy_id: number | null; bo_id: number }
  | { kind: 'refuse'; reason: 'company' | 'year' | 'branch' | 'malformed'; message: string }

export const SCOPE_PARAMS = ['cmp_id', 'fy_id', 'bo_id'] as const

/** Whether this product keeps a financial year in its scope (Pay does not). */
export const HAS_FY = true

const DESTINATION_KEY = 'purchases:deep-link-destination'
const AUTH_PARAMS = ['auth_token', 'auth_error', 'token', 'code', 'state']

function positiveInt(raw: string | null): number | null | 'bad' {
  if (raw === null || raw.trim() === '') return null
  if (!/^\d{1,12}$/.test(raw.trim())) return 'bad'
  const n = Number(raw.trim())
  return Number.isSafeInteger(n) ? n : 'bad'
}

/**
 * The scope a URL asks for: null when it names no company; 'malformed' when a scope parameter is
 * present but not a whole number (refused, never guessed at).
 */
export function readLinkScope(search: string): LinkScope | null | 'malformed' {
  const params = new URLSearchParams(search)
  if (!SCOPE_PARAMS.some((p) => params.has(p))) return null
  const cmp = positiveInt(params.get('cmp_id'))
  const fy = positiveInt(params.get('fy_id'))
  const bo = params.get('bo_id') === '0' ? 0 : positiveInt(params.get('bo_id'))
  if (cmp === 'bad' || fy === 'bad' || bo === 'bad' || cmp === null || cmp <= 0) return 'malformed'
  return { cmp_id: cmp, fy_id: HAS_FY ? fy : null, bo_id: bo }
}

/** The same location without the scope parameters (path, other query and hash kept). */
export function withoutScopeParams(pathname: string, search: string, hash: string): string {
  const params = new URLSearchParams(search)
  for (const p of SCOPE_PARAMS) params.delete(p)
  const rest = params.toString()
  return `${pathname}${rest ? `?${rest}` : ''}${hash}`
}

/**
 * Decide a link's scope against what Manage says about that company for this person.
 * `facts` null = Manage refused the company to them (403/404): not theirs to open.
 */
export function judgeLinkScope(link: LinkScope | 'malformed', facts: ScopeFacts | null): ScopeVerdict {
  if (link === 'malformed') {
    return { kind: 'refuse', reason: 'malformed', message: 'This link names a company, year or branch that is not a valid id, so it was not opened.' }
  }
  if (facts === null) {
    return { kind: 'refuse', reason: 'company', message: `This link opens company ${link.cmp_id}, which your sign-in cannot open. Nothing was changed.` }
  }
  let fy: number | null = null
  if (HAS_FY) {
    if (link.fy_id !== null) {
      if (!facts.fyIds.includes(link.fy_id)) {
        return { kind: 'refuse', reason: 'year', message: `Financial year ${link.fy_id} is not a year of company ${link.cmp_id}. Nothing was changed.` }
      }
      fy = link.fy_id
    } else {
      // The link names no year: the company's latest, as the picker defaults — not a swap, the link chose none.
      fy = facts.fyIds[0] ?? null
      if (fy === null) {
        return { kind: 'refuse', reason: 'year', message: `Company ${link.cmp_id} has no financial year to open. Nothing was changed.` }
      }
    }
  }
  const bo = link.bo_id ?? 0
  if (bo !== 0 && !facts.branchIds.includes(bo)) {
    return { kind: 'refuse', reason: 'branch', message: `Branch ${bo} is not a branch of company ${link.cmp_id} you can open. Nothing was changed.` }
  }
  return { kind: 'apply', cmp_id: link.cmp_id, fy_id: fy, bo_id: bo }
}

/** A same-origin path to come back to, or null: never another origin, never a protocol-relative URL. */
export function safeDestination(raw: string | null): string | null {
  if (!raw || !raw.startsWith('/') || raw.startsWith('//') || raw.startsWith('/\\')) return null
  return raw.length > 2000 ? null : raw
}

interface StorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

/** Keep where the person was going before the app leaves for the portal to sign them in. */
export function rememberDestination(storage: StorageLike, pathname: string, search: string, hash: string, callbackPath: string): void {
  try {
    if (pathname === callbackPath) return
    const params = new URLSearchParams(search)
    for (const p of AUTH_PARAMS) params.delete(p)
    const rest = params.toString()
    const target = `${pathname}${rest ? `?${rest}` : ''}${hash}`
    if (target === '/' || safeDestination(target) === null) {
      storage.removeItem(DESTINATION_KEY)
      return
    }
    storage.setItem(DESTINATION_KEY, target)
  } catch {
    /* storage refused: the person lands on the home page after sign-in, as before */
  }
}

/** Where to land after sign-in (taken once), or null for the home page. */
export function takeDestination(storage: StorageLike): string | null {
  try {
    const raw = storage.getItem(DESTINATION_KEY)
    storage.removeItem(DESTINATION_KEY)
    return safeDestination(raw)
  } catch {
    return null
  }
}
