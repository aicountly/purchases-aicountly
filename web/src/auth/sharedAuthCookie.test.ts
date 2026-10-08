import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { clearSharedAuthToken, purgeLegacySharedAuthToken, readSharedAuthToken } from './sharedAuthCookie.ts'

// The retired shared `.aicountly.com` `auth_token` cookie: never read, and purged only on
// .aicountly.com — an `auth_token` cookie on localhost or a custom domain belongs to something else.

const g = globalThis as unknown as { window?: unknown; document?: unknown }

/** Pretend the page is on `hostname` with a legacy cookie present, and record every `document.cookie` write. */
function onHost(hostname: string): string[] {
  const writes: string[] = []
  g.window = { location: { hostname, protocol: 'https:' } }
  g.document = {
    get cookie() {
      return 'auth_token=legacy'
    },
    set cookie(value: string) {
      writes.push(value)
    },
  }
  return writes
}

afterEach(() => {
  delete g.window
  delete g.document
})

describe('the retired .aicountly.com auth_token cookie', () => {
  it('is never read, even when one is present', () => {
    onHost('books.aicountly.com')
    assert.equal(readSharedAuthToken(), null)
  })

  it('leaves document.cookie untouched off .aicountly.com (localhost)', () => {
    const writes = onHost('localhost')
    purgeLegacySharedAuthToken()
    clearSharedAuthToken()
    assert.deepEqual(writes, [])
  })

  it('leaves document.cookie untouched on a custom domain', () => {
    const writes = onHost('pay.example.com')
    purgeLegacySharedAuthToken()
    clearSharedAuthToken()
    assert.deepEqual(writes, [])
  })

  it('expires only the .aicountly.com cookie on an AICOUNTLY host', () => {
    const writes = onHost('books.aicountly.com')
    purgeLegacySharedAuthToken()
    assert.equal(writes.length, 1)
    assert.match(writes[0], /^auth_token=;/)
    assert.ok(writes[0].includes('domain=.aicountly.com'))
    assert.ok(writes[0].includes('max-age=0'))
  })
})
