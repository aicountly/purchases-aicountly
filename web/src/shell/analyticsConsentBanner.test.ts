/**
 * The analytics consent card (shell/AnalyticsConsentBanner.tsx): asked only where this build reports
 * to GA4 and no choice exists, never on the sign-in hand-off; "Analytics preferences" brings it
 * back; its buttons store the shared aic_consent cookie. The card comes through Vite's SSR module
 * graph (React itself is external there, so it is the same React as the one imported here) and is
 * rendered with react-dom/server under a minimal fake browser — once with a measurement id, once
 * without.
 */
import { after, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createElement, type FunctionComponent } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createServer } from 'vite'

const GA_ID = 'G-TEST000000'
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..')
const TEXT =
  'AICOUNTLY uses privacy-friendly analytics to improve the product — no ads, and nothing that identifies you. Allow analytics cookies?'

const jar = new Map<string, string>()
const g = globalThis as unknown as Record<string, unknown>
const fakeWindow: Record<string, unknown> = {
  location: { origin: 'https://purchase.aicountly.com', protocol: 'https:', hostname: 'purchase.aicountly.com', pathname: '/orders', search: '', hash: '' },
}
const fakeDocument: Record<string, unknown> = {
  referrer: '',
  head: { appendChild: (node: unknown) => node },
  createElement: (tagName: string) => ({ tagName, async: false, src: '' }),
}
Object.defineProperty(fakeDocument, 'cookie', {
  get: () => [...jar].map(([name, value]) => `${name}=${value}`).join('; '),
  set: (value: string) => {
    const [pair, ...attributes] = value.split(';').map((part) => part.trim())
    const [name, ...rest] = pair.split('=')
    if (attributes.some((attribute) => /^max-age=0$/i.test(attribute))) jar.delete(name)
    else jar.set(name, rest.join('='))
  },
})
g.window = fakeWindow
g.document = fakeDocument

/** A Vite SSR server whose build does, or does not, carry a measurement id. */
async function serverWith(id: string) {
  process.env.VITE_GA4_SAAS_PURCHASE_MEASUREMENT_ID = id
  process.env.VITE_GA4_MEASUREMENT_ID = ''
  return createServer({
    root: ROOT,
    // No WebSocket server: two of these run at once, and the default port may be taken.
    server: { middlewareMode: true, hmr: false, ws: false, watch: null },
    appType: 'custom',
    logLevel: 'error',
  })
}

const configured = await serverWith(GA_ID)
const unconfigured = await serverWith('')

/** Fresh modules, and the card as markup. */
async function load(server = configured) {
  server.moduleGraph.invalidateAll()
  const banner = await server.ssrLoadModule('/src/shell/AnalyticsConsentBanner.tsx')
  const consent = await server.ssrLoadModule('/src/utils/analyticsConsent.ts')
  const analytics = await server.ssrLoadModule('/src/utils/analytics.ts')
  const card = banner.AnalyticsConsentBanner as FunctionComponent
  const render = () => renderToStaticMarkup(createElement(card))
  return { render, consent, analytics }
}

beforeEach(() => {
  jar.clear()
  delete fakeWindow.dataLayer
  delete fakeWindow.gtag
  delete fakeWindow[`ga-disable-${GA_ID}`]
  ;(fakeWindow.location as Record<string, unknown>).pathname = '/orders'
})

after(async () => {
  await configured.close()
  await unconfigured.close()
})

describe('AnalyticsConsentBanner', () => {
  it('asks, as a labelled region with Allow, No thanks and the privacy policy, when no choice exists', async () => {
    const { render } = await load()
    const html = render()
    assert.match(html, /^<section[^>]* role="region"[^>]* aria-label="Analytics cookies"/)
    assert.match(html, /class="aic-consent-banner"/)
    assert.ok(html.includes(TEXT))
    assert.match(html, /<button[^>]*>Allow<\/button>/)
    assert.match(html, /<button[^>]*>No thanks<\/button>/)
    assert.match(html, /<a [^>]*href="https:\/\/aicountly\.com\/privacy-policy"[^>]*target="_blank"[^>]*>Privacy policy<\/a>/)
    // Not a dialog: nothing modal, nothing that traps focus.
    assert.doesNotMatch(html, /role="dialog"|aria-modal/)
  })

  it('is not shown where analytics is off', async () => {
    const { render, analytics } = await load(unconfigured)
    assert.equal(analytics.isAnalyticsConfigured(), false)
    assert.equal(render(), '')
  })

  it('is not shown on the sign-in hand-off, or once a choice exists', async () => {
    ;(fakeWindow.location as Record<string, unknown>).pathname = '/auth/callback'
    assert.equal((await load()).render(), '')

    ;(fakeWindow.location as Record<string, unknown>).pathname = '/orders'
    jar.set('aic_consent', 'analytics-denied')
    assert.equal((await load()).render(), '')
  })

  it('comes back from "Analytics preferences" over an earlier choice, and goes once a new one is made', async () => {
    jar.set('aic_consent', 'analytics-granted')
    const { render, consent, analytics } = await load()
    assert.equal(render(), '')

    consent.showAnalyticsPreferences()
    assert.ok(render().includes(TEXT))

    analytics.declineAnalytics()
    assert.equal(jar.get('aic_consent'), 'analytics-denied')
    assert.equal(render(), '')
  })

  it('Allow and No thanks are the calls that store the choice', async () => {
    const source = readFileSync(join(ROOT, 'src/shell/AnalyticsConsentBanner.tsx'), 'utf8')
    assert.match(source, /onClick=\{allowAnalytics\}>\s*Allow\s*</)
    assert.match(source, /onClick=\{declineAnalytics\}>\s*No thanks\s*</)

    const { analytics } = await load()
    analytics.allowAnalytics()
    assert.equal(jar.get('aic_consent'), 'analytics-granted')
    analytics.declineAnalytics()
    assert.equal(jar.get('aic_consent'), 'analytics-denied')
    assert.equal(fakeWindow[`ga-disable-${GA_ID}`], true)
  })

  it('offers "Analytics preferences" in the sidebar only where analytics is configured', () => {
    const shell = readFileSync(join(ROOT, 'src/shell/AppShell.tsx'), 'utf8')
    assert.match(shell, /isAnalyticsConfigured\(\) \? \([\s\S]*?showAnalyticsPreferences\(\)[\s\S]*?Analytics preferences/)
  })
})
