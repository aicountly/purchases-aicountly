/**
 * Analytics consent and masking (the shared AICOUNTLY design): what reaches gtag, and when gtag may
 * load at all. analytics.ts and analyticsConsent.ts are loaded through Vite's SSR module graph
 * (import.meta.env, extensionless imports) under a minimal fake browser: a cookie jar that keeps
 * name=value pairs as a browser does, a <head> that records what is appended, and a location.
 */
import { after, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

const GA_ID = 'G-TEST000000'
const ORIGIN = 'https://purchase.aicountly.com'

/** document.cookie as a browser keeps it: name=value pairs; Max-Age=0 removes one. */
const jar = new Map<string, string>()
const written: string[] = []
const appended: Array<Record<string, unknown>> = []

const g = globalThis as unknown as Record<string, unknown>
const fakeWindow: Record<string, unknown> = {
  location: { origin: ORIGIN, protocol: 'https:', hostname: 'purchase.aicountly.com', pathname: '/', search: '', hash: '' },
}
const fakeDocument: Record<string, unknown> = {
  referrer: '',
  title: 'Purchases · Aicountly',
  head: { appendChild: (node: Record<string, unknown>) => (appended.push(node), node) },
  createElement: (tagName: string) => ({ tagName, async: false, src: '' }),
}
Object.defineProperty(fakeDocument, 'cookie', {
  get: () => [...jar].map(([name, value]) => `${name}=${value}`).join('; '),
  set: (value: string) => {
    written.push(value)
    const [pair, ...attributes] = value.split(';').map((part) => part.trim())
    const [name, ...rest] = pair.split('=')
    if (attributes.some((attribute) => /^max-age=0$/i.test(attribute))) jar.delete(name)
    else jar.set(name, rest.join('='))
  },
})
g.window = fakeWindow
g.document = fakeDocument

process.env.VITE_GA4_SAAS_PURCHASE_MEASUREMENT_ID = GA_ID
const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '../..'),
  // No WebSocket server: the default port may be taken by another test file running alongside.
  server: { middlewareMode: true, hmr: false, ws: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})

/** A fresh analytics module (and consent module): their state starts empty. */
async function loadAnalytics() {
  server.moduleGraph.invalidateAll()
  return server.ssrLoadModule('/src/utils/analytics.ts')
}

async function loadConsent() {
  server.moduleGraph.invalidateAll()
  return server.ssrLoadModule('/src/utils/analyticsConsent.ts')
}

/** Every gtag command, as gtag.js would read it off the dataLayer. */
function calls(): unknown[][] {
  return ((fakeWindow.dataLayer as unknown[]) ?? []).map((args) => Array.from(args as ArrayLike<unknown>))
}

function pageViews(): Record<string, unknown>[] {
  return calls()
    .filter(([command, name]) => command === 'event' && name === 'page_view')
    .map(([, , params]) => params as Record<string, unknown>)
}

function at(pathname: string) {
  ;(fakeWindow.location as Record<string, unknown>).pathname = pathname
}

beforeEach(() => {
  jar.clear()
  written.length = 0
  appended.length = 0
  delete fakeWindow.dataLayer
  delete fakeWindow.gtag
  delete fakeWindow[`ga-disable-${GA_ID}`]
  fakeDocument.referrer = ''
  at('/')
})

after(async () => {
  await server.close()
})

const DEFAULT_DENIED = {
  ad_storage: 'denied',
  ad_user_data: 'denied',
  ad_personalization: 'denied',
  analytics_storage: 'denied',
  functionality_storage: 'denied',
  personalization_storage: 'denied',
  security_storage: 'granted',
  wait_for_update: 500,
}

describe('consent: Consent Mode v2 before anything is configured', () => {
  it('sets the consent default (ads denied), ads_data_redaction and url_passthrough before any config', async () => {
    const { trackPageView } = await loadAnalytics()
    trackPageView('/orders')

    const list = calls()
    const configAt = list.findIndex(([command]) => command === 'config')
    assert.deepEqual(list[0], ['consent', 'default', DEFAULT_DENIED])
    const before = list.slice(0, configAt).map((entry) => JSON.stringify(entry))
    assert.ok(before.includes(JSON.stringify(['set', 'ads_data_redaction', true])))
    assert.ok(before.includes(JSON.stringify(['set', 'url_passthrough', false])))
    const config = list[configAt][2] as Record<string, unknown>
    assert.equal(config.send_page_view, false)
    assert.equal(config.allow_google_signals, false)
    assert.equal(config.allow_ad_personalization_signals, false)
    assert.equal(config.cookie_flags, 'SameSite=Lax;Secure')
  })

  it("pushes every gtag command as the arguments object, as Google's snippet does, never an array", async () => {
    const { allowAnalytics, trackPageView } = await loadAnalytics()
    trackPageView('/orders')
    allowAnalytics()

    const dataLayer = fakeWindow.dataLayer as unknown[]
    assert.ok(dataLayer.length > 0)
    for (const entry of dataLayer) {
      assert.equal(Array.isArray(entry), false)
      assert.equal(Object.prototype.toString.call(entry), '[object Arguments]')
    }
  })

  it('before a choice, loads the tag cookieless: analytics storage denied', async () => {
    const { ANALYTICS_BEFORE_CHOICE, trackPageView } = await loadAnalytics()
    assert.equal(ANALYTICS_BEFORE_CHOICE, 'cookieless')
    trackPageView('/orders')
    assert.equal(appended.length, 1)
    assert.equal(appended[0].src, `https://www.googletagmanager.com/gtag/js?id=${GA_ID}`)
    assert.equal((calls()[0][2] as Record<string, unknown>).analytics_storage, 'denied')
  })

  it('with ANALYTICS_BEFORE_CHOICE = "none", loads nothing before a choice, and loads on Allow', async () => {
    const { allowAnalytics, initAnalytics, tagMayLoad } = await loadAnalytics()
    assert.equal(tagMayLoad(null, 'none'), false)
    assert.equal(tagMayLoad(null, 'cookieless'), true)
    assert.equal(tagMayLoad('denied', 'cookieless'), false)
    assert.equal(tagMayLoad('granted', 'none'), true)

    initAnalytics('none')
    assert.equal(appended.length, 0)
    assert.equal(fakeWindow.dataLayer, undefined)

    allowAnalytics()
    assert.equal(appended.length, 1)
    assert.deepEqual(calls()[0], ['consent', 'default', { ...DEFAULT_DENIED, analytics_storage: 'granted' }])
  })

  it('with analytics allowed earlier, grants analytics storage only — ads stay denied', async () => {
    jar.set('aic_consent', 'analytics-granted')
    const { trackPageView } = await loadAnalytics()
    trackPageView('/orders')

    assert.deepEqual(calls()[0], ['consent', 'default', { ...DEFAULT_DENIED, analytics_storage: 'granted' }])
    const granted = calls()
      .filter(([command]) => command === 'consent')
      .flatMap(([, , state]) =>
        Object.entries(state as Record<string, unknown>).filter(([, value]) => value === 'granted').map(([key]) => key),
      )
    assert.deepEqual(granted.sort(), ['analytics_storage', 'security_storage'])
  })

  it('after "No thanks", loads nothing at all and sets ga-disable', async () => {
    jar.set('aic_consent', 'analytics-denied')
    const { initAnalytics, trackEvent, trackPageView } = await loadAnalytics()
    initAnalytics()
    trackPageView('/orders')
    trackEvent('order_sent')

    assert.equal(appended.length, 0)
    assert.equal(fakeWindow.dataLayer, undefined)
    assert.equal(fakeWindow[`ga-disable-${GA_ID}`], true)
  })

  it('Allow updates consent to analytics storage granted — nothing for ads — and stores the choice', async () => {
    const { allowAnalytics, trackPageView } = await loadAnalytics()
    trackPageView('/orders')

    allowAnalytics()

    assert.deepEqual(calls().at(-1), ['consent', 'update', { analytics_storage: 'granted' }])
    assert.equal(jar.get('aic_consent'), 'analytics-granted')
    assert.equal(appended.length, 1)
  })

  it('"No thanks" sets ga-disable at once and sends nothing more', async () => {
    const { declineAnalytics, trackEvent, trackPageView } = await loadAnalytics()
    trackPageView('/orders')
    const before = calls().length

    declineAnalytics()
    trackPageView('/suppliers')
    trackEvent('order_sent')

    assert.equal(fakeWindow[`ga-disable-${GA_ID}`], true)
    assert.equal(jar.get('aic_consent'), 'analytics-denied')
    assert.equal(calls().length, before)
  })

  it('counts the page the person is on when they allow analytics on a page without the tag', async () => {
    jar.set('aic_consent', 'analytics-denied')
    const { allowAnalytics, trackPageView } = await loadAnalytics()
    trackPageView('/orders')
    assert.equal(appended.length, 0)

    allowAnalytics()

    assert.equal(fakeWindow[`ga-disable-${GA_ID}`], false)
    assert.equal(appended.length, 1)
    assert.deepEqual(pageViews().map((view) => view.page_path), ['/orders'])
  })

  it('never loads while the address bar holds the sign-in hand-off', async () => {
    at('/auth/callback')
    const { initAnalytics, trackPageView } = await loadAnalytics()
    initAnalytics()
    trackPageView('/auth/callback?auth_token=DUMMYTOKEN')
    assert.equal(appended.length, 0)
    assert.doesNotMatch(JSON.stringify(calls()), /DUMMYTOKEN/)
  })
})

describe('masking: nothing that identifies a supplier, an order or a person', () => {
  it('masks every identifier-like segment: ids, UUIDs, long tokens, e-mails; no query or fragment', async () => {
    const { maskPagePath } = await loadAnalytics()
    assert.equal(maskPagePath('/orders/12345/edit'), '/orders/:id/edit')
    assert.equal(maskPagePath('/suppliers/0f8fad5b-d9cb-469f-a165-70867728950e'), '/suppliers/:id')
    assert.equal(maskPagePath('/share/9f86d081884c7d659a2feaa0c55ad015'), '/share/:id')
    assert.equal(maskPagePath('/share/eyJhbGciOiJIUzI1NiJ9_x-Yz'), '/share/:id')
    assert.equal(maskPagePath('/suppliers/sales@acme.example/bills'), '/suppliers/:id/bills')
    assert.equal(maskPagePath('/suppliers/sales%40acme.example'), '/suppliers/:id')
    assert.equal(maskPagePath('/orders?status=open#lines'), '/orders')
    assert.equal(maskPagePath('/purchase-orders'), '/purchase-orders')
  })

  it('names every page by the product, never the tab title, and masks ids on the page and its context', async () => {
    fakeDocument.title = 'ACME Traders — PO 4521 · Purchases'
    const { trackPageView } = await loadAnalytics()
    trackPageView('/orders/4521?tab=lines')

    assert.deepEqual(pageViews(), [
      { page_location: `${ORIGIN}/orders/:id`, page_referrer: '', page_title: 'Purchases · Aicountly', page_path: '/orders/:id' },
    ])
    const sent = JSON.stringify(calls())
    assert.doesNotMatch(sent, /ACME|4521|tab=lines/)
  })
})

describe('the aic_consent cookie', () => {
  it('is shared across .aicountly.com on aicountly.com and every subdomain, Secure on https', async () => {
    const { consentCookieString } = await loadConsent()
    for (const hostname of ['purchase.aicountly.com', 'purchase.gh.aicountly.com', 'aicountly.com', 'PURCHASE.AICOUNTLY.COM']) {
      assert.equal(
        consentCookieString('granted', { hostname, protocol: 'https:' }),
        'aic_consent=analytics-granted; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure',
      )
    }
  })

  it('is host-only anywhere else, and not Secure over http', async () => {
    const { consentCookieDomain, consentCookieString } = await loadConsent()
    for (const hostname of ['aicountly.io', 'purchase.aicountly.io', 'localhost', 'notaicountly.com', 'aicountly.com.evil.test']) {
      assert.equal(consentCookieDomain(hostname), '')
    }
    assert.equal(
      consentCookieString('denied', { hostname: 'localhost', protocol: 'http:' }),
      'aic_consent=analytics-denied; Path=/; Max-Age=15552000; SameSite=Lax',
    )
  })

  it('reads back as granted, denied, or no choice for anything else', async () => {
    const { parseConsentCookie } = await loadConsent()
    assert.equal(parseConsentCookie('a=1; aic_consent=analytics-granted; b=2'), 'granted')
    assert.equal(parseConsentCookie('aic_consent=analytics-denied'), 'denied')
    assert.equal(parseConsentCookie(''), null)
    assert.equal(parseConsentCookie('aic_consent=yes'), null)
    assert.equal(parseConsentCookie('x_aic_consent=analytics-granted'), null)
  })

  it('is written for this host with every attribute, and read back', async () => {
    const consent = await loadConsent()
    assert.equal(consent.readConsentChoice(), null)
    consent.writeConsentChoice('denied')
    assert.deepEqual(written, ['aic_consent=analytics-denied; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure'])
    assert.equal(consent.readConsentChoice(), 'denied')
  })
})
