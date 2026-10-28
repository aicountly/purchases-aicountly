/**
 * The one analytics consent choice every AICOUNTLY app shares.
 *
 * Cookie `aic_consent` = `analytics-granted` | `analytics-denied`. On aicountly.com and every
 * *.aicountly.com host it is written for `Domain=.aicountly.com`, so a choice made in one app
 * applies to all of them; anywhere else (localhost, another domain) it is host-only. It lasts
 * 180 days (`Max-Age=15552000`), after which the person is asked again. A missing or unreadable
 * cookie means no choice has been made yet.
 *
 * utils/analytics.ts reads it before anything analytics-related runs;
 * shell/AnalyticsConsentBanner.tsx asks for it, and "Analytics preferences" in the sidebar's legal
 * links (shell/AppShell.tsx) shows the banner again (showAnalyticsPreferences).
 */

export type ConsentChoice = 'granted' | 'denied'

export const CONSENT_COOKIE = 'aic_consent'
export const CONSENT_GRANTED = 'analytics-granted'
export const CONSENT_DENIED = 'analytics-denied'
/** 180 days, in seconds. */
export const CONSENT_MAX_AGE_SECONDS = 15552000
/** Shared by every app on the AICOUNTLY domain. */
export const CONSENT_SHARED_DOMAIN = '.aicountly.com'

/**
 * A choice made on this page. It wins over the cookie, so a browser that refuses cookies still
 * gets the answer it gave (for this page only) instead of being asked again at once.
 */
let choiceOnThisPage: ConsentChoice | null = null

/** The choice a Cookie header holds: 'granted', 'denied', or null (no cookie, or another value). */
export function parseConsentCookie(cookieHeader: string): ConsentChoice | null {
  for (const part of String(cookieHeader || '').split(';')) {
    const [name, ...rest] = part.split('=')
    if (name.trim() !== CONSENT_COOKIE) continue
    const value = rest.join('=').trim()
    if (value === CONSENT_GRANTED) return 'granted'
    if (value === CONSENT_DENIED) return 'denied'
  }
  return null
}

/** The person's current choice; null when there is none yet (or cookies cannot be read). */
export function readConsentChoice(): ConsentChoice | null {
  if (choiceOnThisPage) return choiceOnThisPage
  try {
    return parseConsentCookie(typeof document === 'undefined' ? '' : document.cookie)
  } catch {
    return null // cookies blocked: no choice can have been stored
  }
}

/** `.aicountly.com` for aicountly.com and its subdomains; '' (host-only) for every other host. */
export function consentCookieDomain(hostname = ''): string {
  const host = String(hostname || '').trim().toLowerCase().replace(/\.$/, '')
  return host === 'aicountly.com' || host.endsWith('.aicountly.com') ? CONSENT_SHARED_DOMAIN : ''
}

/** The cookie string for a choice, as written to document.cookie. */
export function consentCookieString(
  choice: ConsentChoice,
  { hostname = '', protocol = '' }: { hostname?: string; protocol?: string } = {},
): string {
  const domain = consentCookieDomain(hostname)
  return [
    `${CONSENT_COOKIE}=${choice === 'granted' ? CONSENT_GRANTED : CONSENT_DENIED}`,
    ...(domain ? [`Domain=${domain}`] : []),
    'Path=/',
    `Max-Age=${CONSENT_MAX_AGE_SECONDS}`,
    'SameSite=Lax',
    ...(protocol === 'https:' ? ['Secure'] : []),
  ].join('; ')
}

/**
 * Store the person's choice for every AICOUNTLY app (and for this page, whatever the browser does
 * with the cookie).
 */
export function writeConsentChoice(
  choice: ConsentChoice,
  location: { hostname?: string; protocol?: string } | undefined = typeof window === 'undefined' ? undefined : window.location,
): void {
  choiceOnThisPage = choice
  try {
    document.cookie = consentCookieString(choice, location)
  } catch {
    // Cookies blocked: the choice holds for this page only (choiceOnThisPage).
  }
}

// --- the banner's state: shown again from "Analytics preferences" ----------

const listeners = new Set<() => void>()
let version = 0
let preferencesShown = false

function notify(): void {
  version += 1
  for (const listener of listeners) listener()
}

/** For useSyncExternalStore: re-render the banner when the choice or its visibility changes. */
export function subscribeConsent(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function consentVersion(): number {
  return version
}

/** True while "Analytics preferences" has the banner open over an earlier choice. */
export function analyticsPreferencesShown(): boolean {
  return preferencesShown
}

/** "Analytics preferences": show the banner again, whatever was chosen before. */
export function showAnalyticsPreferences(): void {
  preferencesShown = true
  notify()
}

/** A choice was made: the banner closes and re-reads it. */
export function consentChanged(): void {
  preferencesShown = false
  notify()
}
