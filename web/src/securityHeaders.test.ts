import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * The headers Apache sends for this SPA.
 *
 * Until this audit the Content-Security-Policy was `frame-ancestors` alone: no default-src and no
 * script-src, so it said who may frame the app and nothing at all about what the app may load.
 * Injected script ran unopposed. The policy now restricts loading, and this pins the properties
 * that make it worth having rather than its exact text — a new origin may be added, but not
 * 'unsafe-inline' on script-src, and not an http:// origin.
 *
 * The policy was checked against the built app in headless Chromium (shell, documents and settings, with a real
 * Connect widget build), which reported no violation.
 */
const htaccess = readFileSync(join(process.cwd(), 'public', '.htaccess'), 'utf8')

function headers(text: string): Record<string, string> {
  const out: Record<string, string> = {}
  for (const m of text.matchAll(/^\s*Header (?:always )?set ([A-Za-z-]+) "((?:[^"\\]|\\.)*)"/gm)) {
    out[m[1]] = m[2]
  }
  return out
}

function directives(policy: string): Record<string, string[]> {
  return Object.fromEntries(
    policy.split(';').map((p) => p.trim()).filter(Boolean).map((p) => {
      const [name, ...sources] = p.split(/\s+/)
      return [name, sources]
    }),
  )
}

const sent = headers(htaccess)
const csp = directives(sent['Content-Security-Policy'] ?? '')
const permissions = Object.fromEntries(
  (sent['Permissions-Policy'] ?? '').split(',').map((p) => p.trim()).filter(Boolean).map((p) => {
    const [name, ...rest] = p.split('=')
    return [name.trim(), rest.join('=').trim()]
  }),
)

describe('security headers in public/.htaccess', () => {
  it('sends a Content-Security-Policy at all', () => {
    assert.ok(sent['Content-Security-Policy'])
  })

  it('falls back to this origin, so a directive left out is not a hole', () => {
    assert.deepEqual(csp['default-src'], ["'self'"])
  })

  // The reason the policy exists. An inline <script> an attacker injects must not run.
  it('does not let injected script run', () => {
    assert.ok(csp['script-src'])
    assert.ok(!csp['script-src'].includes("'unsafe-inline'"))
    assert.ok(!csp['script-src'].includes("'unsafe-eval'"))
    assert.ok(csp['script-src'].includes("'self'"))
  })

  it('admits the Connect widget, which the app embeds', () => {
    for (const directive of ['script-src', 'style-src', 'connect-src']) {
      for (const origin of ['https://connect.aicountly.com', 'https://connect.gh.aicountly.com']) {
        assert.ok(csp[directive].includes(origin), directive + ' is missing ' + origin)
      }
    }
  })

  it('admits the portal and the company registry it reads', () => {
    for (const origin of ['https://my.aicountly.com', 'https://sandbox.aicountly.com',
      'https://manage.aicountly.com', 'https://manage.gh.aicountly.com']) {
      assert.ok(csp['connect-src'].includes(origin), 'connect-src is missing ' + origin)
    }
  })

  it('keeps the framing rule the app already had', () => {
    assert.deepEqual(csp['frame-ancestors'], ["'self'", 'https://*.aicountly.com'])
  })

  it('shuts the usual holes', () => {
    assert.deepEqual(csp['object-src'], ["'none'"])
    assert.deepEqual(csp['base-uri'], ["'self'"])
    assert.deepEqual(csp['form-action'], ["'self'"])
  })

  it('names no origin that is not https', () => {
    const origins = Object.values(csp).flat().filter((s) => s.includes('://'))
    assert.ok(origins.length > 0)
    for (const origin of origins) assert.match(origin, /^https:\/\//)
  })

  // The embedded Connect widget places calls from inside this page.
  it('keeps camera and microphone for this origin', () => {
    assert.equal(permissions.camera, '(self)')
    assert.equal(permissions.microphone, '(self)')
  })

  it('denies the powerful features this app does not use', () => {
    for (const feature of ['geolocation', 'payment', 'usb', 'serial', 'bluetooth', 'midi']) {
      assert.equal(permissions[feature], '()', feature)
    }
  })
})
