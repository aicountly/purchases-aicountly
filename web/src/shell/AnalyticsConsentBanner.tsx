/** GA4 and its consent UI retired on 2026-11-25.
 * Compatibility exports do not load tags, send events or grant consent.
 * Internal estate analytics is configured separately.
 */
export const CONSENT_BANNER_TEXT =
  'AICOUNTLY uses privacy-friendly analytics to improve the product — no ads, and nothing that identifies you. Allow analytics cookies?'

export const PRIVACY_POLICY_URL = 'https://aicountly.com/privacy-policy'

export function AnalyticsConsentBanner() { return null; }
