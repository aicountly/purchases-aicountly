/**
 * The analytics consent card: asks once for every AICOUNTLY app (the shared aic_consent cookie,
 * utils/analyticsConsent.ts), and again from "Analytics preferences" in the sidebar's legal links.
 *
 * Shown only when this build reports to GA4 and no choice has been made — never where analytics
 * is off, and never on the sign-in hand-off. A small card at the bottom-left
 * (`.aic-consent-banner` in shell/app-shell.css): it does not block the page, traps no focus and
 * sits below the navigation drawer and every dialog, clear of the Connect launcher in the
 * bottom-right corner.
 */

import { useEffect, useRef, useSyncExternalStore } from 'react'

import { allowAnalytics, declineAnalytics, isAnalyticsConfigured, isUntrackedRoute } from '../utils/analytics'
import {
  analyticsPreferencesShown,
  consentVersion,
  readConsentChoice,
  subscribeConsent,
} from '../utils/analyticsConsent'

/** The wording every AICOUNTLY app shows; keep it identical (and untranslated). */
export const CONSENT_BANNER_TEXT =
  'AICOUNTLY uses privacy-friendly analytics to improve the product — no ads, and nothing that identifies you. Allow analytics cookies?'

export const PRIVACY_POLICY_URL = 'https://aicountly.com/privacy-policy'

export function AnalyticsConsentBanner() {
  useSyncExternalStore(subscribeConsent, consentVersion, consentVersion)
  const reopened = analyticsPreferencesShown()
  const region = useRef<HTMLElement>(null)
  const visible =
    isAnalyticsConfigured() &&
    !isUntrackedRoute(window.location.pathname) &&
    (reopened || readConsentChoice() === null)

  // Opened from "Analytics preferences": move there, so a keyboard user is not left in the sidebar.
  useEffect(() => {
    if (visible && reopened) region.current?.focus()
  }, [visible, reopened])

  if (!visible) return null

  return (
    <section ref={region} role="region" aria-label="Analytics cookies" tabIndex={-1} className="aic-consent-banner">
      <p className="aic-consent-banner__text">{CONSENT_BANNER_TEXT}</p>
      <div className="aic-consent-banner__actions">
        <button type="button" className="aic-consent-banner__allow" onClick={allowAnalytics}>
          Allow
        </button>
        <button type="button" className="aic-consent-banner__decline" onClick={declineAnalytics}>
          No thanks
        </button>
        <a className="aic-consent-banner__link" href={PRIVACY_POLICY_URL} target="_blank" rel="noopener noreferrer">
          Privacy policy
        </a>
      </div>
    </section>
  )
}
