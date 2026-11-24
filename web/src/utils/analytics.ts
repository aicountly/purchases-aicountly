/** GA4 and its consent UI retired on 2026-11-25.
 * Compatibility exports do not load tags, send events or grant consent.
 * Internal estate analytics is configured separately.
 */
import type { ConsentChoice } from './analyticsConsent'

declare global {
  interface Window {
    dataLayer: unknown[]
    gtag: (...args: unknown[]) => void
    /** gtag.js sends nothing for a measurement id whose flag is true ("No thanks"). */
    [gaDisable: `ga-disable-${string}`]: boolean | undefined
  }
}

/**
 * What the Google tag does before the person has chosen:
 *   'cookieless' — it loads with analytics_storage denied (Consent Mode's cookieless
 *                  measurement: GA sets no cookies);
 *   'none'       — it is not loaded at all until the person allows analytics.
 * One constant, so the owner's counsel can flip it in one place.
 */
export const ANALYTICS_BEFORE_CHOICE: 'cookieless' | 'none' = 'cookieless'

/** The tag's own settings: no automatic page view, no Google signals, no ad personalisation. */
export const GA4_CONFIG = Object.freeze({
  send_page_view: false,
  allow_google_signals: false,
  allow_ad_personalization_signals: false,
  cookie_flags: 'SameSite=Lax;Secure',
})

/** The title every page view carries: the product name (index.html's <title>). */
export const ANALYTICS_PAGE_TITLE = 'Purchases · Aicountly'

/** Consent Mode v2's default for a choice. Ads consent is never granted by anything. */
export function consentDefaults(choice: ConsentChoice | null): Record<string, string | number> {
  return {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: choice === 'granted' ? 'granted' : 'denied',
    functionality_storage: 'denied',
    personalization_storage: 'denied',
    security_storage: 'granted',
    wait_for_update: 500,
  }
}

/**
 * Whether the tag may load for a choice: always once allowed, never after "No thanks", and
 * before a choice only when ANALYTICS_BEFORE_CHOICE is 'cookieless'.
 */
export function tagMayLoad(
  choice: ConsentChoice | null,
  beforeChoice: 'cookieless' | 'none' = ANALYTICS_BEFORE_CHOICE,
): boolean {
  if (choice === 'granted') return true
  if (choice === 'denied') return false
  return beforeChoice === 'cookieless'
}

export function getGa4MeasurementId(): string { return ''; }

/** True when this build reports to a GA4 property: the consent banner asks only then. */
export function isAnalyticsConfigured(): boolean { return false; }

/** Sign-in hand-off routes carry tokens in the URL. */
export function isUntrackedRoute(pathname = ''): boolean {
  return /^\/auth(\/|$)/.test(String(pathname || ''))
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
/** A query or hash parameter that is a credential: auth_token, a ses_key, a one-time ticket or code. */
const CREDENTIAL_PARAM = /[?&#](?:[\w.-]*token|ses_?key|ticket|code|otp|password|secret|signature|sig)=/i

/**
 * A path segment that names a record or a person rather than a screen: all digits, a UUID,
 * a long hex or base64-ish token (16+ characters), or anything with an "@" in it.
 */
export function isIdentifierSegment(segment: string): boolean {
  let text = String(segment || '')
  try {
    text = decodeURIComponent(text)
  } catch {
    // A malformed escape is judged as written.
  }
  if (!text) return false
  return (
    text.includes('@') ||
    /^\d+$/.test(text) ||
    UUID.test(text) ||
    (text.length >= 16 && /^[0-9a-f]+$/i.test(text)) ||
    (text.length >= 16 && /^[A-Za-z0-9+/=_.~-]+$/.test(text) && /\d/.test(text) && /[A-Za-z]/.test(text))
  )
}

/** Route path without query/hash, with every identifier-like segment replaced by ":id". */
export function sanitizePagePath(pathname = '/'): string {
  const path = String(pathname || '/').split(/[?#]/)[0] || '/'
  return path
    .split('/')
    .map((segment) => (isIdentifierSegment(segment) ? ':id' : segment))
    .join('/')
}

/** The brief's name for the same mask: the only path GA is given. */
export const maskPagePath = sanitizePagePath

/** A referrer reduced to another site's origin, or to this site's sanitised path. */
export function sanitizeReferrer(referrer = ''): string {
  if (!referrer) return ''
  try {
    const url = new URL(referrer)
    if (url.origin !== window.location.origin) return `${url.protocol}//${url.host}/`
    return `${url.origin}${sanitizePagePath(url.pathname)}`
  } catch {
    return String(referrer).split(/[?#]/)[0]
  }
}

/** True while the address bar still holds the sign-in hand-off or another credential. */
export function urlCarriesCredential(
  location: Pick<Location, 'pathname' | 'search' | 'hash'> = window.location,
): boolean {
  const hashRoute = String(location.hash || '').replace(/^#/, '').split(/[?#]/)[0]
  return (
    isUntrackedRoute(location.pathname) ||
    isUntrackedRoute(hashRoute) ||
    CREDENTIAL_PARAM.test(`${location.search || ''}${location.hash || ''}`)
  )
}

/** Loads the Google tag when the person's choice allows it (tests pass the other beforeChoice). */
export function initAnalytics(beforeChoice: 'cookieless' | 'none' = ANALYTICS_BEFORE_CHOICE): void { void [beforeChoice];  }

/** A page view for a route. The title is the product name, whatever the screen shows. */
export function trackPageView(path: string): void { void [path];  }

export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void { void [eventName, params];  }

/**
 * "Allow": analytics cookies for every AICOUNTLY app. A tag already on the page (cookieless) is
 * told analytics storage is granted — ads stay denied; a page without one loads it now and
 * counts the page the person is on.
 */
export function allowAnalytics(): void {  }

/**
 * "No thanks": gtag.js stops sending at once (ga-disable-<ID>), and from the next page load the
 * tag is not loaded at all.
 */
export function declineAnalytics(): void {  }
