/**
 * The companies most recently opened in this browser.
 *
 * Written when a company is opened (the picker) and read in two places: the picker's "Recently
 * opened" row and the account menu's Company block, which lists up to three of them with their
 * ids (AICOUNTLY_ACCOUNT_MENU.md §3). One store, one key — two copies of this list drift, and
 * the one in the menu is the copy nobody notices has gone stale.
 */

const RECENT_KEY = 'purchases:recent-companies'
const RECENT_LIMIT = 4

/** How many of them the account menu shows — Books' shape, which every product's menu matches. */
export const MENU_RECENT_LIMIT = 3

export interface RecentEntry {
  cmpId: number
  name: string
  /** When it was opened, newest first in the stored list. */
  at: number
}

/** Browser storage that never throws and never blocks a render. */
function readStore<T>(key: string, fallback: T): T {
  try {
    const raw = window.localStorage.getItem(key)
    return raw === null ? fallback : (JSON.parse(raw) as T)
  } catch {
    return fallback
  }
}

function writeStore(key: string, value: unknown): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(value))
  } catch {
    /* private window, or storage the browser refuses; the choice still applies now */
  }
}

/** Everything stored, newest first. Anything that is not a company row is dropped. */
export function readRecentCompanies(): RecentEntry[] {
  const rows = readStore<unknown>(RECENT_KEY, [])
  if (!Array.isArray(rows)) return []

  return rows.filter(
    (row): row is RecentEntry =>
      typeof row === 'object'
      && row !== null
      && Number.isFinite((row as RecentEntry).cmpId)
      && typeof (row as RecentEntry).name === 'string',
  )
}

/** The handful the account menu lists: the most recent three. */
export function recentCompaniesForMenu(rows: RecentEntry[] = readRecentCompanies()): RecentEntry[] {
  return rows.slice(0, MENU_RECENT_LIMIT)
}

export function rememberOpened(cmpId: number, name: string): void {
  const rest = readRecentCompanies().filter((r) => r.cmpId !== cmpId)
  writeStore(RECENT_KEY, [{ cmpId, name, at: Date.now() }, ...rest].slice(0, RECENT_LIMIT))
}
