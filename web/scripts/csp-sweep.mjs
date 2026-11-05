#!/usr/bin/env node
/**
 * CSP sweep: proves the Content-Security-Policy in `public/.htaccess` against the built app.
 *
 * A policy nobody walks under is a policy that quietly stops matching the app. This serves
 * `web/dist` with exactly the headers Apache will send — parsed out of `public/.htaccess`, never
 * retyped here, so the proof cannot drift from the deployed policy — and then does two things:
 *
 *  1. **Boots the app under the policy** and records every `securitypolicyviolation`. That covers
 *     what every visitor's first second does: the bundles, the stylesheets, the inline pre-paint
 *     script (where there is one), the web fonts, the app launcher's icons, gtag and the Connect
 *     loader.
 *
 *  2. **Probes every capability the policy declares**, and some it must refuse. Purchases signs in
 *     through the portal, so a sweep cannot reach the screens behind it; instead each thing the
 *     app does on those screens is exercised directly here — a script from the Connect origin, a
 *     fetch to Manage, a blob and a data: image, an inline style — and asserted ALLOWED, while a
 *     script and a fetch from an origin the policy does not name are asserted BLOCKED. A policy
 *     that permits everything passes an app-boot sweep; it fails this one.
 *
 * Nothing leaves the machine: every request is answered by a local route handler. A request the
 * CSP blocks never reaches that handler — the browser refuses it first — which is exactly the
 * signal being measured.
 *
 * Usage (from web/):   npm run csp-sweep
 *
 * Exit code: 0 when the policy is sound, 1 when it is not, 2 on a setup failure.
 */
import { createServer } from 'node:http'
import { createRequire } from 'node:module'
import { existsSync, readFileSync, statSync } from 'node:fs'
import { dirname, extname, join, normalize, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import process from 'node:process'

const WEB = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const HTACCESS = resolve(WEB, 'public/.htaccess')
const DIST = resolve(WEB, 'dist')
const PORT = Number(process.env.CSP_PORT || 5193)

/** An origin the policy must NOT name — the control that proves it is enforcing at all. */
const FOREIGN = 'https://csp-sweep-not-allowed.example'

const CONFIG = {
  "app": "Purchases",
  "routes": [
    "/"
  ],
  "probes": [
    {
      "kind": "script",
      "url": "https://connect.aicountly.com/embed/v1/loader.js",
      "expect": "allowed",
      "why": "the Connect embed loader"
    },
    {
      "kind": "script",
      "url": "https://www.googletagmanager.com/gtag/js?id=G-TEST",
      "expect": "allowed",
      "why": "gtag, which analytics loads once consent is given"
    },
    {
      "kind": "connect",
      "url": "https://connect.aicountly.com/api/ping",
      "expect": "allowed",
      "why": "Connect's API — conversations, calls and the pinned AI Pulse thread"
    },
    {
      "kind": "connect",
      "url": "https://manage.aicountly.com/api/v1/companyinfo",
      "expect": "allowed",
      "why": "Manage's company registry"
    },
    {
      "kind": "connect",
      "url": "https://my.aicountly.com/api/seskey",
      "expect": "allowed",
      "why": "the portal, which mints and refreshes the ses_key"
    },
    {
      "kind": "connect",
      "url": "/api/health",
      "expect": "allowed",
      "why": "this app's own API, same-origin under 'self'"
    },
    {
      "kind": "img",
      "url": "https://console.aicountly.org/icons/app.png",
      "expect": "allowed",
      "why": "the app launcher's product icons"
    },
    {
      "kind": "img",
      "url": "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7",
      "expect": "allowed",
      "why": "a data: image"
    },
    {
      "kind": "style",
      "url": "https://connect.aicountly.com/embed/v1/widget.css",
      "expect": "allowed",
      "why": "the Connect widget's stylesheet, linked inside its shadow root"
    },
    {
      "kind": "inline-style",
      "url": "inline",
      "expect": "allowed",
      "why": "an inline style, which React components set throughout"
    }
  ]
}

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.webmanifest': 'application/manifest+json',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
}

/** Every `Header [always] set <Name> "<value>"` in public/.htaccess — the deployed headers, verbatim. */
export function headersFromHtaccess(text) {
  const out = {}
  const re = /^\s*Header\s+(?:always\s+)?set\s+([A-Za-z-]+)\s+"((?:[^"\\]|\\.)*)"/gm
  let m
  while ((m = re.exec(text)) !== null) {
    // The first Content-Security-Policy wins, as Apache's first matching Header set does for a
    // document response; the per-location blocks above it are for the API and error pages.
    if (out[m[1]] === undefined) out[m[1]] = m[2].replace(/\\"/g, '"')
  }
  return out
}

/** The directives a sweep is pointless without — a policy missing these is not a policy. */
export function missingCoreDirectives(csp) {
  const required = ['default-src', 'script-src', 'connect-src', 'object-src', 'base-uri', 'frame-ancestors']
  const present = new Set(
    String(csp || '')
      .split(';')
      .map((d) => d.trim().split(/\s+/)[0])
      .filter(Boolean),
  )
  return required.filter((d) => !present.has(d))
}

/** A configured API on another origin would need naming in connect-src; the default is same-origin. */
export function apiOriginProblem(csp, configured) {
  const value = String(configured || '').trim()
  if (!value) return null
  let origin
  try {
    origin = new URL(value).origin
  } catch {
    return null
  }
  const connectSrc = String(csp || '')
    .split(';')
    .map((d) => d.trim())
    .find((d) => d.startsWith('connect-src '))
  return connectSrc && connectSrc.includes(origin) ? null : origin
}

function loadChromium() {
  const require = createRequire(import.meta.url)
  for (const candidate of [process.env.PLAYWRIGHT_CORE, 'playwright', 'playwright-core', '/opt/node-tools/node_modules/playwright']) {
    if (!candidate) continue
    try {
      return require(candidate).chromium
    } catch {
      /* try the next one */
    }
  }
  return null
}

/**
 * Ask the browser whether the policy permits one thing. Returns 'allowed' or 'blocked'.
 *
 * Every allowed request is answered locally, so 'allowed' means the policy let it through AND the
 * resource loaded; 'blocked' means the browser refused before any request was made.
 */
const PROBE = async ({ kind, url: requested }) => {
  let url = requested
  const seen = () => (window.__cspViolations || []).some((v) => url.startsWith(v.blocked) || (v.blocked && url.includes(v.blocked)))
  const settle = (el, done) =>
    new Promise((r) => {
      let answered = false
      const finish = (value) => {
        if (answered) return
        answered = true
        el?.remove?.()
        r(value)
      }
      if (el) {
        el.onload = () => finish('allowed')
        el.onerror = () => finish(seen() ? 'blocked' : 'error')
      }
      done?.(finish)
      setTimeout(() => finish(seen() ? 'blocked' : 'timeout'), 4000)
    })

  if (kind === 'script') {
    const el = document.createElement('script')
    el.src = url
    document.head.appendChild(el)
    return settle(el)
  }
  if (kind === 'style') {
    const el = document.createElement('link')
    el.rel = 'stylesheet'
    el.href = url
    document.head.appendChild(el)
    return settle(el)
  }
  if (kind === 'img') {
    const el = document.createElement('img')
    el.src = url
    document.body.appendChild(el)
    return settle(el)
  }
  if (kind === 'frame') {
    // A blob: iframe is how a PDF is printed without a pop-up; the URL has to be minted here.
    if (url === 'BLOB_IFRAME') url = URL.createObjectURL(new Blob(['<p>sweep</p>'], { type: 'text/html' }))
    const el = document.createElement('iframe')
    el.style.cssText = 'position:fixed;width:1px;height:1px;opacity:0'
    el.src = url
    document.body.appendChild(el)
    return settle(el)
  }
  if (kind === 'connect') {
    try {
      await fetch(url, { mode: 'no-cors' })
      return 'allowed'
    } catch {
      // The rejection arrives before the securitypolicyviolation event does.
      await new Promise((r) => setTimeout(r, 60))
      return seen() ? 'blocked' : 'error'
    }
  }
  if (kind === 'font') {
    try {
      const face = new FontFace('CspSweepProbe', `url(${url})`)
      await face.load()
      return 'allowed'
    } catch {
      await new Promise((r) => setTimeout(r, 60))
      return seen() ? 'blocked' : 'error'
    }
  }
  if (kind === 'inline-style') {
    const el = document.createElement('style')
    el.textContent = '.csp-sweep-probe{color:rgb(1,2,3)}'
    document.head.appendChild(el)
    const t = document.createElement('div')
    t.className = 'csp-sweep-probe'
    document.body.appendChild(t)
    const applied = getComputedStyle(t).color === 'rgb(1, 2, 3)'
    el.remove()
    t.remove()
    return applied ? 'allowed' : 'blocked'
  }
  if (kind === 'inline-script') {
    delete window.__cspInlineRan
    const el = document.createElement('script')
    el.textContent = 'window.__cspInlineRan = true'
    document.head.appendChild(el)
    const ran = window.__cspInlineRan === true
    el.remove()
    return ran ? 'allowed' : 'blocked'
  }
  return 'unknown-probe'
}

async function main() {
  if (!existsSync(join(DIST, 'index.html'))) {
    console.error('web/dist is not built — run `npm run build` first.')
    process.exit(2)
  }
  const headers = headersFromHtaccess(readFileSync(HTACCESS, 'utf8'))
  const csp = headers['Content-Security-Policy']
  if (!csp) {
    console.error('No Content-Security-Policy found in public/.htaccess')
    process.exit(2)
  }
  const missing = missingCoreDirectives(csp)
  if (missing.length) {
    console.error(`Content-Security-Policy is missing core directives: ${missing.join(', ')}`)
    process.exit(1)
  }
  const strayApi = apiOriginProblem(csp, process.env.VITE_API_BASE_URL)
  if (strayApi) {
    console.error(`VITE_API_BASE_URL points at ${strayApi}, which connect-src does not name.`)
    process.exit(1)
  }

  const chromium = loadChromium()
  if (!chromium) {
    console.error('No playwright available; install playwright or set PLAYWRIGHT_CORE.')
    process.exit(2)
  }

  // Apache in front of the built app: the same headers, the same SPA fallback.
  const server = createServer((req, res) => {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1')
    // A bare page on the app's OWN origin, carrying the app's OWN headers, for the capability
    // probes. The app itself cannot host them: signed out it leaves for the portal within a
    // second, and probes that followed it would be measuring the portal's headers.
    if (url.pathname === '/__csp-probe') {
      for (const [name, value] of Object.entries(headers)) res.setHeader(name, value)
      res.setHeader('Content-Type', 'text/html; charset=utf-8')
      res.writeHead(200)
      return res.end('<!doctype html><html><head><title>CSP probe</title></head><body></body></html>')
    }
    let file = normalize(join(DIST, decodeURIComponent(url.pathname)))
    if (!file.startsWith(DIST + sep) && file !== DIST) file = join(DIST, 'index.html')
    if (!existsSync(file) || statSync(file).isDirectory()) file = join(DIST, 'index.html')
    for (const [name, value] of Object.entries(headers)) res.setHeader(name, value)
    res.setHeader('Content-Type', TYPES[extname(file)] ?? 'application/octet-stream')
    res.writeHead(200)
    res.end(readFileSync(file))
  })
  await new Promise((r) => server.listen(PORT, '127.0.0.1', r))

  const browser = await chromium.launch({ args: ['--no-sandbox'] })
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } })

  // Nothing leaves the machine. A request the CSP blocks never arrives here.
  const ONE_PX = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')
  /**
   * Every URL that reached the network.
   *
   * This is the ground truth for a probe: the CSP refuses in the renderer, BEFORE a request is
   * made, so anything this handler is asked for is something the policy allowed. It is how a
   * probe tells "the policy refused it" from "the stub served something the browser could not
   * use" — an empty font file is a parse failure, not a refusal, and reading it as one would have
   * reported a sound policy as broken.
   */
  const served = new Set()
  await ctx.route('**/*', async (route) => {
    const url = route.request().url()
    served.add(url)
    if (url.startsWith(`http://127.0.0.1:${PORT}`)) return route.continue()
    // Signed out, the app sends the browser to the portal. Letting that navigation happen put the
    // page on another origin — one with no policy of its own — where every probe below passed
    // whatever it was asked, and the sweep reported a sound policy for a page it was not
    // measuring. A cross-origin NAVIGATION is refused so the page stays on the app's own document,
    // under the app's own headers; sub-resources are still answered.
    if (route.request().isNavigationRequest()) return route.abort()
    const ext = extname(new URL(url).pathname)
    if (ext === '.css') return route.fulfill({ status: 200, contentType: 'text/css', body: '/* sweep */' })
    if (['.png', '.gif', '.jpg', '.jpeg', '.webp', '.ico'].includes(ext)) {
      return route.fulfill({ status: 200, contentType: 'image/gif', body: ONE_PX })
    }
    if (['.woff', '.woff2', '.ttf', '.otf'].includes(ext)) {
      return route.fulfill({ status: 200, contentType: 'font/woff2', body: Buffer.alloc(0) })
    }
    if (ext === '.js' || url.includes('/gtag/js') || url.includes('loader.js')) {
      return route.fulfill({ status: 200, contentType: 'text/javascript', body: '/* sweep */' })
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' })
  })

  const record = (page, bucket, isCollecting) => {
    page.on('console', (m) => {
      const t = m.text()
      // Only while the APP is driving. The probes refuse things on purpose, and their refusals
      // are the result, not a fault.
      if (isCollecting() && /Content Security Policy|Refused to/i.test(t)) bucket.push({ source: 'console', detail: t.slice(0, 240) })
    })
  }

  const violations = []
  const page = await ctx.newPage()
  // Reported as they happen rather than read back afterwards: the app navigates away mid-sweep,
  // and anything held in the page's own memory goes with the document that held it.
  let collecting = true
  await page.exposeFunction('__cspReport', (v) => {
    if (collecting) violations.push(v)
  })
  await page.addInitScript(() => {
    window.__cspViolations = []
    document.addEventListener('securitypolicyviolation', (e) => {
      const v = {
        directive: e.effectiveDirective || e.violatedDirective,
        blocked: e.blockedURI,
        sample: (e.sample || '').slice(0, 80),
      }
      window.__cspViolations.push(v)
      try {
        window.__cspReport(v)
      } catch {
        /* the binding is gone with the document */
      }
    })
  })
  record(page, violations, () => collecting)

  // 1. Boot the app under the policy.
  for (const route of CONFIG.routes) {
    try {
      await page.goto(`http://127.0.0.1:${PORT}${route}`, { waitUntil: 'networkidle', timeout: 45000 })
      await page.waitForTimeout(900)
    } catch (err) {
      violations.push({ route, source: 'load', detail: String(err).slice(0, 200) })
    }
  }
  // The app's own boot is measured; from here the probes drive the page deliberately, and a
  // violation they cause on purpose is not a fault in the policy.
  collecting = false

  // 2. Probe every capability the policy declares, and two it must refuse.
  const probes = [...CONFIG.probes, { kind: 'script', url: `${FOREIGN}/x.js`, expect: 'blocked', why: 'a script from an origin the policy does not name' }, { kind: 'connect', url: `${FOREIGN}/x.json`, expect: 'blocked', why: 'a fetch to an origin the policy does not name' }, { kind: 'inline-script', url: 'inline', expect: 'blocked', why: "an inline script (script-src must carry no 'unsafe-inline')" }]
  await page.goto(`http://127.0.0.1:${PORT}/__csp-probe`, { waitUntil: 'domcontentloaded', timeout: 30000 })

  // The probes are only worth anything on a document the policy covers.
  const where = page.url()
  if (!where.startsWith(`http://127.0.0.1:${PORT}`)) {
    console.error(`The page left the app (now at ${where}); the policy was not what the probes measured.`)
    await browser.close()
    server.close()
    process.exit(2)
  }

  const probeResults = []
  for (const p of probes) {
    await page.evaluate(() => {
      window.__cspViolations = []
    })
    let got
    try {
      got = await page.evaluate(PROBE, { kind: p.kind, url: p.url })
    } catch (err) {
      got = `error: ${String(err).slice(0, 80)}`
    }
    // A probe whose resource reached the network was allowed, whatever the stub then gave it.
    if ((got === 'error' || got === 'timeout') && p.url.startsWith('http') && served.has(p.url)) got = 'allowed'
    probeResults.push({ ...p, got, ok: got === p.expect })
  }

  await browser.close()
  server.close()

  console.log(`\nCSP sweep — ${CONFIG.app}`)
  console.log(`Policy: ${csp.slice(0, 110)}…\n`)
  console.log(`Booted ${CONFIG.routes.length} route(s) under the policy.`)
  console.log('\nCapability probes:')
  for (const r of probeResults) {
    console.log(`  ${r.ok ? 'ok   ' : 'FAIL '} ${r.expect.padEnd(7)} ${r.kind.padEnd(13)} ${r.why}${r.ok ? '' : `  — got ${r.got}`}`)
  }

  const failed = probeResults.filter((r) => !r.ok)
  if (!violations.length && !failed.length) {
    console.log('\nNo violation on boot, and every capability behaves as the policy says.')
    process.exit(0)
  }
  if (violations.length) {
    console.error(`\n${violations.length} violation(s) while booting:`)
    for (const v of violations) console.error(`  [${v.route ?? '-'}] ${v.directive || v.source}: ${v.blocked || v.detail || ''} ${v.sample || ''}`)
  }
  if (failed.length) console.error(`\n${failed.length} probe(s) did not behave as the policy says.`)
  process.exit(1)
}

if (process.argv[1] && process.argv[1].endsWith('csp-sweep.mjs')) {
  await main()
}
