/**
 * The accounts signed in on this browser, as the portal's readable summary
 * cookie reports them (my-aicountly-com `App\Libraries\AccountRoster`).
 *
 * Loaded through Vite rather than imported directly, exactly as
 * `portal.test.ts` is: `hostnames.ts` reads `import.meta.env`, which does not
 * exist under `node --test --experimental-strip-types`.
 */

import { after, afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

const g = globalThis as unknown as Record<string, unknown>

/** The page's host and cookie jar; each test sets them before reading. */
function onHost(hostname: string, cookie = ''): void {
  g.window = { location: { hostname } }
  g.document = { cookie: cookie === '' ? '' : `AIC_ACCOUNTS_VIEW=${cookie}` }
}

onHost('purchase.aicountly.com')

const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '../..'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const accounts = await server.ssrLoadModule('/src/auth/accounts.ts')

const {
  MAX_ACCOUNTS,
  buildAddAccountUrl,
  buildChooseAccountUrl,
  buildSwitchAccountUrl,
  canAddAccount,
  readAccountRoster,
} = accounts as {
  MAX_ACCOUNTS: number
  buildAddAccountUrl: () => string
  buildChooseAccountUrl: () => string
  buildSwitchAccountUrl: (uuid: number) => string
  canAddAccount: (roster?: unknown[]) => boolean
  readAccountRoster: () => Array<{
    uuid: number
    name: string
    maskedEmail: string
    maskedMobile: string
    active: boolean
  }>
}

/** Encode a roster the way AccountRoster::summaryCookieValue does. */
function viewCookie(entries: Array<Record<string, unknown>>): string {
  const json = JSON.stringify({ v: 1, max: MAX_ACCOUNTS, a: entries })
  return Buffer.from(json, 'utf8').toString('base64url')
}

afterEach(() => {
  onHost('purchase.aicountly.com')
})

after(async () => {
  await server.close()
})

describe('reading the roster of accounts signed in on this browser', () => {
  it('reads the portal’s summary cookie', () => {
    onHost('purchase.aicountly.com', viewCookie([
      { u: 88, n: 'Rahul Gupta', e: 'ra•••@cagupta.in', m: '+91••••••••78', a: 1 },
      { u: 77, n: 'Priya Sharma', e: 'pr•••@example.com', a: 0 },
    ]))

    const roster = readAccountRoster()
    assert.equal(roster.length, 2)
    assert.deepEqual(roster[0], {
      uuid: 88,
      name: 'Rahul Gupta',
      maskedEmail: 'ra•••@cagupta.in',
      maskedMobile: '+91••••••••78',
      active: true,
    })
    assert.equal(roster[1].active, false)
  })

  it('puts the active account first however the cookie ordered them', () => {
    onHost('purchase.aicountly.com', viewCookie([
      { u: 77, n: 'Priya', a: 0 },
      { u: 88, n: 'Rahul', a: 1 },
    ]))
    assert.deepEqual(readAccountRoster().map((a) => a.uuid), [88, 77])
  })

  it('keeps the masking the portal applied — a full address never reaches here', () => {
    onHost('purchase.aicountly.com', viewCookie([{ u: 88, n: 'Rahul Gupta', e: 'ra•••@cagupta.in', a: 1 }]))
    assert.ok(!readAccountRoster()[0].maskedEmail.includes('rahul@'))
  })

  // A browser signed in before the roster shipped, local development, and a
  // cleared cookie jar all land here. None is an error: the menu falls back to
  // the portal chooser, which always knows the truth.
  it('is empty when there is no cookie', () => {
    onHost('purchase.aicountly.com', '')
    assert.deepEqual(readAccountRoster(), [])
  })

  it('is empty rather than throwing when the cookie is corrupt', () => {
    onHost('purchase.aicountly.com', '!!!not-base64!!!')
    assert.deepEqual(readAccountRoster(), [])
  })

  it('drops entries with no usable subscriber id', () => {
    onHost('purchase.aicountly.com', viewCookie([
      { u: 0, n: 'Nobody' },
      { n: 'Also nobody' },
      { u: 88, n: 'Rahul', a: 1 },
    ]))
    assert.deepEqual(readAccountRoster().map((a) => a.uuid), [88])
  })

  it('never reports more accounts than the portal allows', () => {
    onHost('purchase.aicountly.com', viewCookie(
      Array.from({ length: 8 }, (_, index) => ({ u: index + 1, n: `User ${index + 1}` })),
    ))
    assert.ok(readAccountRoster().length <= MAX_ACCOUNTS)
  })
})

describe('whether another account may be added', () => {
  it('allows one while there is room', () => {
    assert.equal(canAddAccount([]), true)
    assert.equal(canAddAccount(Array.from({ length: MAX_ACCOUNTS - 1 }, () => ({}))), true)
  })

  it('refuses once the browser is full', () => {
    assert.equal(canAddAccount(Array.from({ length: MAX_ACCOUNTS }, () => ({}))), false)
  })
})

describe('the portal URLs the menu navigates to', () => {
  it('names this product so the portal hands the user back here', () => {
    onHost('purchase.aicountly.com')
    assert.equal(buildSwitchAccountUrl(88), 'https://my.aicountly.com/login/switch_account/purchases?uuid=88')
    assert.equal(buildAddAccountUrl(), 'https://my.aicountly.com/login/add_account/purchases')
    assert.equal(buildChooseAccountUrl(), 'https://my.aicountly.com/login/select_account/purchases')
  })

  it('uses the sandbox portal on a sandbox host', () => {
    onHost('purchases.gh.aicountly.com')
    assert.equal(
      buildSwitchAccountUrl(88),
      'https://sandbox.aicountly.com/login/switch_account/purchases?uuid=88',
    )
  })
})
