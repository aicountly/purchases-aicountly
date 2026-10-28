/**
 * The session Purchases hands the Connect widget — its `getSesKey(forceRefresh)` (connect-aicountly
 * docs/EMBED_SDK.md, "Session and auth"): the key in hand when it asks normally; after Connect
 * answered 401, a new key that replaces Purchases' own only once it has arrived, so a refresh that
 * fails costs Purchases' own requests nothing; and null — never an exception, never a trip to the
 * portal — when nobody is signed in or the mint fails.
 *
 * portal.ts is loaded through Vite's SSR module graph (extensionless imports, import.meta.env),
 * under a minimal fake browser.
 */
import { after, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

const ORIGIN = 'https://purchase.aicountly.com'

function memoryStorage() {
  const data = new Map<string, string>()
  return {
    getItem: (key: string) => (data.has(key) ? (data.get(key) as string) : null),
    setItem: (key: string, value: string) => void data.set(key, String(value)),
    removeItem: (key: string) => void data.delete(key),
    clear: () => data.clear(),
  }
}

const navigated: string[] = []
const url = new URL(`${ORIGIN}/`)
const g = globalThis as unknown as Record<string, unknown>
const fakeWindow = {
  location: {
    href: url.href,
    origin: url.origin,
    protocol: url.protocol,
    host: url.host,
    hostname: url.hostname,
    pathname: '/',
    search: '',
    hash: '',
    assign: (to: string) => void navigated.push(String(to)),
    replace: (to: string) => void navigated.push(String(to)),
  },
  history: { replaceState: () => {} },
  localStorage: memoryStorage(),
  sessionStorage: memoryStorage(),
}
g.window = fakeWindow
g.localStorage = fakeWindow.localStorage
g.sessionStorage = fakeWindow.sessionStorage
g.document = { cookie: '' }

const calls: Array<{ url: string; init: RequestInit }> = []
/** What the portal answers the next mint with; each test sets it. */
let answer: () => Response | Promise<Response> = () => down()
const realFetch = globalThis.fetch
globalThis.fetch = (async (input: unknown, init: RequestInit = {}) => {
  calls.push({ url: String(input), init })
  return answer()
}) as typeof fetch

const minted = (key: string) =>
  new Response(JSON.stringify({ ses_key: key, expires_in: 900 }), { status: 200, headers: { 'Content-Type': 'application/json' } })
const down = () => new Response('down', { status: 503 })

const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '../..'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const portal = await server.ssrLoadModule('/src/auth/portal.ts')
const tokens = await server.ssrLoadModule('/src/auth/tokens.ts')

after(async () => {
  await server.close()
  globalThis.fetch = realFetch
})

beforeEach(() => {
  calls.length = 0
  navigated.length = 0
  answer = () => down()
  tokens.clearAllTokens()
  fakeWindow.localStorage.clear()
  fakeWindow.sessionStorage.clear()
})

describe('sesKeyForEmbed — the ses_key Connect is given', () => {
  it('returns the key in hand, and mints a new one only when forced', async () => {
    tokens.setAuthToken('auth-token-1')
    answer = () => minted('key-1')
    assert.equal(await portal.sesKeyForEmbed(false), 'key-1')
    assert.equal(await portal.sesKeyForEmbed(false), 'key-1')
    assert.equal(calls.length, 1)

    answer = () => minted('key-2')
    assert.equal(await portal.sesKeyForEmbed(true), 'key-2')
    assert.equal(calls.length, 2)
    assert.match(calls[1].url, /\/global\/seskey$/)
    assert.equal(new Headers(calls[1].init.headers).get('Authorization'), 'Bearer auth-token-1')
    assert.equal(tokens.getSesKey(), 'key-2', 'the new key is the one Purchases holds from now on')
  })

  it('keeps the held key for Purchases until the new one has arrived', async () => {
    tokens.setAuthToken('auth-token-1')
    tokens.saveSession('key-1', 900)
    let arrive: (response: Response) => void = () => {}
    answer = () => new Promise<Response>((resolve) => (arrive = resolve))

    const refreshed = portal.sesKeyForEmbed(true)
    try {
      await new Promise((resolve) => setTimeout(resolve, 0))
      assert.equal(calls.length, 1)
      assert.equal(tokens.getSesKey(), 'key-1')
      assert.equal(await portal.ensureSesKey(), 'key-1', "Purchases' own requests go on with the key they had")
      assert.equal(calls.length, 1)
    } finally {
      arrive(minted('key-2'))
    }
    assert.equal(await refreshed, 'key-2')
    assert.equal(tokens.getSesKey(), 'key-2')
  })

  it('is null when the refresh fails, and leaves the held key in place — no navigation, no exception', async () => {
    tokens.setAuthToken('auth-token-1')
    tokens.saveSession('key-1', 900)

    answer = () => down()
    assert.equal(await portal.sesKeyForEmbed(true), null)
    assert.equal(tokens.getSesKey(), 'key-1')

    answer = () => Promise.reject(new TypeError('Failed to fetch'))
    assert.equal(await portal.sesKeyForEmbed(true), null)
    assert.equal(tokens.getSesKey(), 'key-1')
    assert.equal(tokens.getAuthToken(), 'auth-token-1')

    assert.equal(await portal.ensureSesKey(), 'key-1')
    assert.deepEqual(navigated, [])
  })

  it('shares one mint between simultaneous forced asks', async () => {
    tokens.setAuthToken('auth-token-1')
    tokens.saveSession('key-1', 900)
    answer = () => minted('key-2')
    assert.deepEqual(await Promise.all([portal.sesKeyForEmbed(true), portal.sesKeyForEmbed(true)]), ['key-2', 'key-2'])
    assert.equal(calls.length, 1)
  })

  it('is null when signed out, or while a sign-out is in progress, without asking anyone', async () => {
    answer = () => minted('never')
    assert.equal(await portal.sesKeyForEmbed(false), null)
    assert.equal(await portal.sesKeyForEmbed(true), null)

    tokens.setAuthToken('auth-token-1')
    fakeWindow.sessionStorage.setItem('aic_logout', '1')
    assert.equal(await portal.sesKeyForEmbed(false), null)
    assert.equal(await portal.sesKeyForEmbed(true), null)
    assert.equal(calls.length, 0)
    assert.deepEqual(navigated, [])
  })

  it("leaves the API client's own retry as it was: ensureSesKey(true) drops the refused key first", async () => {
    tokens.setAuthToken('auth-token-1')
    tokens.saveSession('key-1', 900)
    answer = () => down()
    await assert.rejects(portal.ensureSesKey(true))
    assert.equal(tokens.getSesKey(), null)
  })
})
