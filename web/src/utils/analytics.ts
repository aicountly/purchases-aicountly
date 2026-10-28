/**
 * Google Analytics 4 (gtag) for purchases.aicountly.com.
 *
 * Set at build time: VITE_GA4_SAAS_PURCHASE_MEASUREMENT_ID=G-…
 * Flow backend: GA4_PROPERTY_ID_SAAS_PURCHASE (numeric property ID).
 *
 * Analytics is a third party: it receives route paths only — never query
 * strings, record ids or anything from the sign-in hand-off — and is not
 * loaded while the address bar still holds /auth/*, ?auth_token= or another
 * credential (gtag.js reads the URL itself).
 *
 * Consent (the shared AICOUNTLY design, DPDP Act / GDPR-style): the person's choice is the
 * aic_consent cookie every AICOUNTLY app shares (utils/analyticsConsent.ts), read before
 * anything here runs. Consent Mode v2's default goes on the dataLayer before the first
 * config — ads storage, ad user data and ad personalisation always denied, analytics storage
 * only once the person allowed it. Before a choice the tag loads cookieless
 * (ANALYTICS_BEFORE_CHOICE); after "No thanks" it is never loaded and ga-disable-<ID> is set.
 *
 * Whatever the choice, nothing that names a supplier, an order or a person is sent: every
 * identifier-like path segment is ":id", the page title is the product name (a page can be
 * titled after a supplier), and no caller can set either.
 */

import { consentChanged, readConsentChoice, writeConsentChoice } from './analyticsConsent'
import type { ConsentChoice } from './analyticsConsent'

declare global {
  interface Window {
    dataLayer: unknown[]
    gtag: (...args: unknown[]) => void
    /** gtag.js sends nothing for a measurement id whose flag is true ("No thanks"). */
    [gaDisable: `ga-disable-${string}`]: boolean | undefined
  }
}

const GA4_ID: string =
  import.meta.env.VITE_GA4_SAAS_PURCHASE_MEASUREMENT_ID ||
  import.meta.env.VITE_GA4_MEASUREMENT_ID ||
  ''

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

let initialized = false
/** The last page_location sent: the next page view's referrer. */
let lastPageLocation = ''
/** The path of the latest page view asked for: counted when the person allows analytics. */
let lastPath = ''

export function getGa4MeasurementId(): string {
  return GA4_ID
}

/** True when this build reports to a GA4 property: the consent banner asks only then. */
export function isAnalyticsConfigured(): boolean {
  return Boolean(GA4_ID)
}

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

/** The page fields gtag.js would otherwise take from window.location, document.referrer and document.title. */
function pageContext(pagePath: string): { page_location: string; page_referrer: string; page_title: string } {
  return {
    // Never window.location.href: it would carry the query string.
    page_location: `${window.location.origin}${pagePath}`,
    page_referrer: lastPageLocation || sanitizeReferrer(document.referrer),
    // Never document.title: a screen may be titled after a supplier.
    page_title: ANALYTICS_PAGE_TITLE,
  }
}

/** Loads the Google tag when the person's choice allows it (tests pass the other beforeChoice). */
export function initAnalytics(beforeChoice: 'cookieless' | 'none' = ANALYTICS_BEFORE_CHOICE): void {
  if (initialized || !GA4_ID || typeof window === 'undefined') return
  const choice = readConsentChoice()
  if (choice === 'denied') {
    // "No thanks": the tag is not loaded, and gtag.js would send nothing if anything else loaded it.
    window[`ga-disable-${GA4_ID}`] = true
    return
  }
  if (!tagMayLoad(choice, beforeChoice)) return
  // Not yet: the app has not cleared the hand-off. trackPageView() calls back
  // here on the next route, once it has.
  if (urlCarriesCredential()) return
  initialized = true

  window.dataLayer = window.dataLayer || []
  // Google's snippet pushes the `arguments` object itself: gtag.js does not run
  // a plain array (what rest parameters produce) as a gtag command — the consent
  // default and the config below would be ignored.
  window.gtag = function gtag(..._args: unknown[]) {
    // eslint-disable-next-line prefer-rest-params
    window.dataLayer.push(arguments)
  }
  // Consent Mode first: nothing is configured before it knows what is allowed.
  window.gtag('consent', 'default', consentDefaults(choice))
  window.gtag('set', 'ads_data_redaction', true)
  window.gtag('set', 'url_passthrough', false)
  window.gtag('js', new Date())
  window.gtag('config', GA4_ID, {
    ...GA4_CONFIG,
    ...pageContext(sanitizePagePath(window.location.pathname)),
  })

  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA4_ID}`
  document.head.appendChild(script)
}

/** A page view for a route. The title is the product name, whatever the screen shows. */
export function trackPageView(path: string): void {
  if (!GA4_ID || typeof window === 'undefined') return
  const pagePath = sanitizePagePath(path)
  if (isUntrackedRoute(pagePath)) return
  lastPath = path
  initAnalytics()
  if (!initialized || readConsentChoice() === 'denied') return
  const context = pageContext(pagePath)
  // GA4 requires a `page_view` *event*, not a repeated `config` call: once
  // `send_page_view: false` is set (above), gtag.js suppresses page_view on
  // every subsequent config call for this measurement ID too, so re-calling
  // config here silently sends nothing. See Google's SPA tracking guide.
  // The config call below therefore sends nothing either: it only moves the
  // page fields that automatic events (scroll, engagement) report, so none of
  // them falls back to the raw URL or the tab title.
  window.gtag('config', GA4_ID, { ...GA4_CONFIG, ...context })
  window.gtag('event', 'page_view', {
    ...context,
    page_path: pagePath,
  })
  lastPageLocation = context.page_location
}

/** Location and title fields come from the sanitised page context only, never from a caller. */
const LOCATION_PARAMS = ['page_location', 'page_referrer', 'page_path', 'page_title', 'document_title']

export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void {
  if (!initialized || readConsentChoice() === 'denied') return
  const safeParams = Object.fromEntries(
    Object.entries(params).filter(([key]) => !LOCATION_PARAMS.includes(key)),
  )
  window.gtag('event', eventName, safeParams)
}

/**
 * "Allow": analytics cookies for every AICOUNTLY app. A tag already on the page (cookieless) is
 * told analytics storage is granted — ads stay denied; a page without one loads it now and
 * counts the page the person is on.
 */
export function allowAnalytics(): void {
  writeConsentChoice('granted')
  if (GA4_ID && typeof window !== 'undefined') {
    if (window[`ga-disable-${GA4_ID}`]) window[`ga-disable-${GA4_ID}`] = false
    if (initialized) window.gtag('consent', 'update', { analytics_storage: 'granted' })
    else if (lastPath) trackPageView(lastPath)
    else initAnalytics()
  }
  consentChanged()
}

/**
 * "No thanks": gtag.js stops sending at once (ga-disable-<ID>), and from the next page load the
 * tag is not loaded at all.
 */
export function declineAnalytics(): void {
  writeConsentChoice('denied')
  if (GA4_ID && typeof window !== 'undefined') window[`ga-disable-${GA4_ID}`] = true
  consentChanged()
}
