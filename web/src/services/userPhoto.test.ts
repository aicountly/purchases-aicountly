/**
 * The account menu's profile photo (services/userPhoto.ts).
 *
 * It is read through this product's own API — `/api/global/user/logo`, never my.aicountly.com,
 * which does not name product domains in its CORS allowlist. Every failure is "no photo", so the
 * menu falls back to initials and nothing is raised at the person
 * (AICOUNTLY_ACCOUNT_MENU.md §6).
 *
 * Loaded through Vite rather than imported directly, as `accounts.test.ts` is: `config.ts` reads
 * `import.meta.env`, which does not exist under `node --test --experimental-strip-types`.
 */

import { after, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

const ORIGIN = 'https://purchase.aicountly.com'

const g = globalThis as unknown as Record<string, unknown>
let calls: Array<{ url: string; init: RequestInit }> = []
let answer: () => Response = () => new Response('', { status: 404 })
const revoked: string[] = []
let created = 0

g.window = { location: { origin: ORIGIN, hostname: 'purchase.aicountly.com' } }
g.document = { cookie: '' }
g.localStorage = {
  getItem: () => null,
  setItem: () => {},
  removeItem: () => {},
}
g.sessionStorage = g.localStorage

const realFetch = globalThis.fetch
globalThis.fetch = (async (input: unknown, init: RequestInit = {}) => {
  calls.push({ url: String(input), init })
  return answer()
}) as typeof fetch

const realCreate = URL.createObjectURL
const realRevoke = URL.revokeObjectURL
URL.createObjectURL = (() => `blob:photo-${++created}`) as typeof URL.createObjectURL
URL.revokeObjectURL = ((url: string) => void revoked.push(url)) as typeof URL.revokeObjectURL

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..')
const server = await createServer({
  root: ROOT,
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})

// A session in hand, so ensureSesKey() answers from memory and the photo call is the only fetch.
const tokens = await server.ssrLoadModule('/src/auth/tokens.ts')
tokens.saveSession('ses-test', 900)

const { fetchUserPhotoUrl, USER_PHOTO_PATH } = await server.ssrLoadModule('/src/services/userPhoto.ts')

after(async () => {
  globalThis.fetch = realFetch
  URL.createObjectURL = realCreate
  URL.revokeObjectURL = realRevoke
  await server.close()
})

beforeEach(() => {
  calls = []
  created = 0
})

const image = (body = 'PNGDATA', type = 'image/png', status = 200) =>
  new Response(body, { status, headers: { 'Content-Type': type } })

describe('the account menu photo', () => {
  it('is read through this product’s own relay, with the session as a bearer token', async () => {
    answer = () => image()

    const url = await fetchUserPhotoUrl()

    assert.equal(USER_PHOTO_PATH, 'global/user/logo')
    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, `${ORIGIN}/api/global/user/logo`)
    assert.equal((calls[0].init.headers as Record<string, string>).Authorization, 'Bearer ses-test')
    assert.equal(url, 'blob:photo-1')
  })

  it('falls back to initials when the portal has no photo', async () => {
    answer = () => new Response('', { status: 404 })
    assert.equal(await fetchUserPhotoUrl(), null)

    answer = () => new Response('', { status: 204, headers: { 'Content-Type': 'image/png' } })
    assert.equal(await fetchUserPhotoUrl(), null)
  })

  it('falls back to initials when the relay is down', async () => {
    answer = () => new Response('{"message":"Auth service unavailable"}', {
      status: 503,
      headers: { 'Content-Type': 'application/json' },
    })
    assert.equal(await fetchUserPhotoUrl(), null)
  })

  it('falls back to initials when the body is not an image', async () => {
    // A JSON error, or the hosting's challenge page: never handed to an <img>.
    answer = () => new Response('{"error":{"code":"not_relayed"}}', {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
    assert.equal(await fetchUserPhotoUrl(), null)

    answer = () => image('', 'image/png')
    assert.equal(await fetchUserPhotoUrl(), null, 'an empty image body is no photo either')
  })

  it('falls back to initials when the request itself throws, and never rejects', async () => {
    answer = () => {
      throw new Error('network down')
    }
    assert.equal(await fetchUserPhotoUrl(), null)
  })
})
