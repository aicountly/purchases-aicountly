/**
 * Browser checks for the five purchase dashboards.
 *
 *   PURCHASE_APP_URL=http://127.0.0.1:5174 npm run test:ui
 *
 * These cover the things a unit test cannot: that the URL carries the state, that
 * Back undoes one change rather than leaving the product, that a drawer traps
 * focus and gives it back, that a drill-down lands on records that are actually
 * filtered, and that an unavailable figure is never drawn as a zero.
 *
 * It needs the app and its API running — see docs/DEVELOPMENT.md. It is not part
 * of `npm run build`, because a browser test that fails when nothing is serving
 * teaches everyone to ignore it.
 */

import { chromium } from 'playwright'

const BASE = process.env.PURCHASE_APP_URL ?? 'http://127.0.0.1:5173'
const results = []
const check = async (name, fn) => {
  try { await fn(); results.push(`  ok    ${name}`) }
  catch (e) { results.push(`  FAIL  ${name}\n        ${e.message}`) }
}
const eq = (a, b, what) => { if (a !== b) throw new Error(`${what}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`) }
const ok = (c, what) => { if (!c) throw new Error(what) }

/**
 * Everything these checks look at, created here.
 *
 * The approval drawer needs an order waiting for approval. Taking that from
 * whatever the database happened to hold made the check pass only after
 * somebody had clicked around by hand, and fail on a clean database — which is
 * the one state CI is always in. It is seeded through the real API, as a user.
 */
import { execFileSync } from 'node:child_process'

const API = process.env.PURCHASE_API_URL ?? 'http://127.0.0.1:8791'
const SES = process.env.PURCHASE_SES_KEY ?? 'preview-ses-key'
const SCOPE = 'cmp_id=88&fy_id=6&bo_id=0'

const apiCall = async (method, path, body, as = SES) => {
  const res = await fetch(`${API}/${path}${path.includes('?') ? '&' : '?'}${SCOPE}`, {
    method,
    headers: { Authorization: `Bearer ${as}`, 'Content-Type': 'application/json' },
    body: JSON.stringify(body ?? {}),
  })
  const payload = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${JSON.stringify(payload).slice(0, 200)}`)
  return payload.data
}
const apiPost = (path, body, as) => apiCall('POST', path, body, as)
const apiPut = (path, body) => apiCall('PUT', path, body)

// Raised by somebody else, deliberately. The approval inbox excludes anything
// you raised yourself — that is the segregation of duties this product enforces,
// so an order seeded by the person doing the browsing would never appear.
const BUYER = `${SES}.as-buyer`

// Start from empty. These checks used to read whatever the database happened to
// hold, so they passed on a machine somebody had been clicking around on and
// failed on a clean one — the state CI is always in.
try {
  execFileSync('php', [new URL('../../server-php/tests/reset.php', import.meta.url).pathname], { stdio: 'inherit' })
} catch (e) {
  console.error('Could not reset the database before the browser checks:', e.message)
  process.exit(1)
}

const today = new Date()
const iso = (offsetDays) => new Date(today.getTime() + offsetDays * 86400000).toISOString().slice(0, 10)

// An approval threshold below the order below, or submitting it approves it on
// the spot and there is no approval to open a drawer on. This is the product's
// own rule, not a test switch: an order under the threshold does not need one.
await apiPut('v1/settings', { po_approval_above_amount: 1000 })

// An order waiting for approval — what the drawer opens on.
const seeded = await apiPost('v1/purchase-orders', {
  supplier_account_id: 601,
  supplier_name: 'Deccan Steel Traders',
  po_date: iso(-3),
  promised_date: iso(7),
  delivery_warehouse_id: 3,
  lines: [
    { item_id: 201, unit_id: 1, ordered_qty: 100, agreed_rate: 250, estimated_tax_pc: 18, warehouse_id: 3 },
    { item_id: 202, unit_id: 1, ordered_qty: 40, agreed_rate: 900, estimated_tax_pc: 18, warehouse_id: 3 },
  ],
}, BUYER)
await apiPost(`v1/purchase-orders/${seeded.po_id}/submit`, {}, BUYER)

// Honour a preinstalled browser where the environment provides one, and fall
// back to whatever `npx playwright install chromium` put in place.
const executablePath = process.env.PURCHASE_CHROMIUM_PATH || undefined
const browser = await chromium.launch(executablePath ? { executablePath } : {})
const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 } })
await ctx.addInitScript(() => {
  try {
    localStorage.setItem('auth_token', 'preview-auth-token')
    localStorage.setItem('purchases:scope', JSON.stringify({ cmp_id: 88, fy_id: 6, bo_id: 0 }))
  } catch {}
})
const page = await ctx.newPage()
const settle = () => page.waitForTimeout(600)

await page.goto(`${BASE}/dashboard/overview`, { waitUntil: 'networkidle' })
await settle()

await check('the switcher puts the view in the URL', async () => {
  await page.locator('.purchase-switcher').getByRole('button', { name: 'Procurement', exact: true }).click()
  await settle()
  eq(new URL(page.url()).pathname, '/dashboard/procurement', 'pathname')
  ok(await page.getByRole('heading', { name: 'Procurement workspace' }).isVisible(), 'heading changed')
})

await check('Back returns to the previous dashboard', async () => {
  await page.goBack()
  await settle()
  eq(new URL(page.url()).pathname, '/dashboard/overview', 'pathname after Back')
  ok(await page.getByRole('heading', { name: 'Purchase overview' }).isVisible(), 'overview is back')
})

await check('a filter is a query parameter and Back undoes exactly one', async () => {
  await page.goto(`${BASE}/dashboard/procurement`, { waitUntil: 'networkidle' })
  await settle()
  await page.getByRole('button', { name: /^Delayed$/ }).click()
  await settle()
  eq(new URL(page.url()).searchParams.get('view'), 'delayed', 'view in the URL')

  await page.getByRole('button', { name: /^Open orders$/ }).click()
  await settle()
  eq(new URL(page.url()).searchParams.get('view'), 'open_orders', 'second filter')

  await page.goBack()
  await settle()
  eq(new URL(page.url()).searchParams.get('view'), 'delayed', 'Back undid one filter, not the page')
})

await check('a shared link reproduces the same filtered screen', async () => {
  await page.goto(`${BASE}/dashboard/procurement?view=delayed&preset=this_year`, { waitUntil: 'networkidle' })
  await settle()
  const active = await page.locator('.purchase-segment.is-active').first().textContent()
  eq((active || '').trim(), 'Delayed', 'the saved view is applied from the URL')
  eq(await page.locator('select').first().inputValue(), 'this_year', 'the period is applied from the URL')
})

await check('the period control changes the figures and the URL together', async () => {
  await page.goto(`${BASE}/dashboard/overview?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()
  await page.locator('select').first().selectOption('last_7_days')
  await settle()
  eq(new URL(page.url()).searchParams.get('preset'), 'last_7_days', 'preset in the URL')
  // The server resolves the preset into dates and echoes them back, so the
  // screen can never disagree with the range its figures were computed for.
  ok((await page.locator('.purchase-context').textContent())?.includes('–'), 'the resolved range is shown')
})

await check('an unknown view redirects rather than erroring', async () => {
  await page.goto(`${BASE}/dashboard/not-a-view`, { waitUntil: 'networkidle' })
  await settle()
  eq(new URL(page.url()).pathname, '/dashboard/overview', 'redirected to overview')
})

await check('a drawer traps focus, closes on Escape and returns focus', async () => {
  await page.goto(`${BASE}/dashboard/procurement?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const opener = page.getByRole('button', { name: 'Approve or reject' }).first()
  await opener.click()
  await page.waitForTimeout(400)

  const dialog = page.getByRole('dialog')
  ok(await dialog.isVisible(), 'the drawer opened')
  ok((await dialog.getAttribute('aria-modal')) === 'true', 'it is modal')
  ok(await page.getByRole('button', { name: /^Close / }).isVisible(), 'the close button is labelled')

  // Tab right around the drawer; focus must never leave it.
  for (let i = 0; i < 14; i++) {
    await page.keyboard.press('Tab')
    const inside = await page.evaluate(() => {
      const d = document.querySelector('[role="dialog"]')
      return d ? d.contains(document.activeElement) : false
    })
    ok(inside, `focus escaped the drawer on tab ${i + 1}`)
  }

  await page.keyboard.press('Escape')
  await page.waitForTimeout(400)
  ok((await page.getByRole('dialog').count()) === 0, 'Escape closed it')

  const returned = await page.evaluate(() => document.activeElement?.textContent?.trim())
  ok((returned || '').includes('Approve or reject'), `focus returned to the opener, got "${returned}"`)
})

await check('switching views never renders one view against another view payload', async () => {
  const errors = []
  const onError = (e) => errors.push(String(e))
  page.on('pageerror', onError)

  for (const view of ['procurement', 'suppliers', 'bills-payables', 'ai-insights', 'overview']) {
    const label = { procurement: 'Procurement', suppliers: 'Suppliers', 'bills-payables': 'Bills & Payables', 'ai-insights': 'AI Insights', overview: 'Overview' }[view]
    await page.locator('.purchase-switcher').getByRole('button', { name: label, exact: true }).click()
    await settle()
    eq(new URL(page.url()).pathname, `/dashboard/${view}`, `pathname for ${view}`)
    ok((await page.locator('h1').count()) === 1, `${view} still rendered a heading`)
  }

  page.off('pageerror', onError)
  ok(errors.length === 0, `no runtime errors while switching: ${errors.join(' | ')}`)
})

await check('a drilldown carries its filters to the records', async () => {
  await page.goto(`${BASE}/dashboard/overview?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()
  await page.getByRole('button', { name: /^View the records behind Orders with delayed quantities/ }).click()
  await settle()
  const url = new URL(page.url())
  eq(url.pathname, '/purchase-orders', 'opened the order list')
  eq(url.searchParams.get('overdue'), '1', 'with a filter the list honours')
  ok(await page.getByRole('checkbox', { name: /Overdue only/ }).isChecked(), 'and the list shows it applied')
})

await check('a KPI and the records behind it agree', async () => {
  await page.goto(`${BASE}/dashboard/procurement?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  // The card counts orders with an overdue quantity; the drill-down opens the
  // order list filtered to exactly that. If the two ever disagree, one of them
  // is lying and there is no way to tell which from the screen.
  const card = page.locator('.purchase-metric', { hasText: 'Orders with overdue quantities' })
  const onCard = Number.parseInt(((await card.locator('.purchase-metric__value').textContent()) || '0').replace(/[^0-9]/g, ''), 10)
  ok(Number.isFinite(onCard), 'the card shows a count')

  await card.getByRole('button').click()
  await settle()
  eq(new URL(page.url()).searchParams.get('view'), 'delayed', 'the workbench is filtered to delayed orders')

  const inList = await page.locator('.purchase-panel', { hasText: 'Procurement workbench' }).locator('tbody tr').count()
  eq(inList, onCard, 'the workbench holds exactly as many orders as the card counted')
})

await check('an unavailable figure never renders as a zero', async () => {
  await page.goto(`${BASE}/dashboard/bills-payables?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()
  const card = page.locator('.purchase-metric', { hasText: 'Due within 7 / 15 / 30 days' })
  const text = (await card.textContent()) || ''
  ok(text.includes('Unavailable'), 'the card says Unavailable')
  ok(!/₹\s?0\.00/.test(text), 'and never shows a zero amount')
  ok(text.includes('acc_id'), 'and states the upstream reason')
})

await check('a chart offers its figures as a table', async () => {
  await page.goto(`${BASE}/dashboard/bills-payables?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  // Located by its place in the chart, not by its label: the label is the very
  // thing that changes when it is pressed.
  const toggle = page.locator('.purchase-chart__toggle button').first()
  ok(await toggle.isVisible(), 'the chart offers a way to see the figures')
  eq(await toggle.getAttribute('aria-expanded'), 'false', 'collapsed by default')

  const tableId = await toggle.getAttribute('aria-controls')
  ok(!!tableId, 'the toggle names the region it controls')
  ok(await page.locator(`#${tableId}`).isHidden(), 'the figures start hidden')

  await toggle.click()
  await page.waitForTimeout(250)
  eq(await toggle.getAttribute('aria-expanded'), 'true', 'expanded on click')
  ok(await page.locator(`#${tableId} table`).isVisible(), 'and the figures are a real table')
  ok((await toggle.textContent())?.includes('Hide'), 'and the control now offers to hide them')
})

await check('every dashboard is reachable by keyboard alone', async () => {
  await page.goto(`${BASE}/dashboard/overview`, { waitUntil: 'networkidle' })
  await settle()
  await page.locator('.purchase-switcher').getByRole('button', { name: 'Suppliers', exact: true }).focus()
  await page.keyboard.press('Enter')
  await settle()
  eq(new URL(page.url()).pathname, '/dashboard/suppliers', 'switched by keyboard')
})

await check('the mobile menu opens and closes without trapping the page', async () => {
  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 } })
  await mobile.addInitScript(() => {
    try {
      localStorage.setItem('auth_token', 'preview-auth-token')
      localStorage.setItem('purchases:scope', JSON.stringify({ cmp_id: 88, fy_id: 6, bo_id: 0 }))
    } catch {}
  })
  const small = await mobile.newPage()
  await small.goto(`${BASE}/dashboard/overview`, { waitUntil: 'networkidle' })
  await small.waitForTimeout(600)

  const menu = small.getByRole('button', { name: 'Open the menu' })
  ok(await menu.isVisible(), 'the menu button is shown on a phone')
  await menu.click()
  await small.waitForTimeout(300)
  ok(await small.locator('.app-shell__sidebar.is-open').isVisible(), 'the sidebar slid in')
  // The same button closes it — it swaps its icon and its label rather than
  // hiding a second control inside the panel.
  await small.getByRole('button', { name: 'Close the menu' }).click()
  await small.waitForTimeout(300)
  ok((await small.locator('.app-shell__sidebar.is-open').count()) === 0, 'and slid out again')
  await mobile.close()
})

await check('access administration bootstraps, assigns and shows its own rules', async () => {
  await page.goto(`${BASE}/access`, { waitUntil: 'networkidle' })
  await settle()

  eq((await page.locator('h1').first().textContent())?.trim(), 'Access', 'the access screen opened')

  // A company with no profiles says so, rather than looking merely empty: only
  // the owner can do anything until one exists.
  const bootstrap = page.getByRole('button', { name: /Create starter profiles/ })
  if (await bootstrap.count()) {
    ok(
      (await page.locator('text=Nobody but the owner can do anything yet').count()) > 0,
      'and explains why an empty company is a problem',
    )
    await bootstrap.click()
    await page.waitForTimeout(1200)
  }

  const profiles = page.locator('table').first().locator('tbody tr')
  ok((await profiles.count()) >= 4, 'the starter profiles exist')

  // The starters separate the jobs this product separates.
  const text = (await page.locator('table').first().textContent()) || ''
  ok(text.includes('Buyer') && text.includes('Purchase approver'), 'buyer and approver are distinct profiles')
  ok(text.includes('Cannot approve their own'), 'and the separation is stated on the screen')

  // Candidates come from this product's own audit trail, not a user directory.
  ok(
    (await page.locator("text=from this product's own audit trail, not a user directory").count()) > 0,
    'the candidate list says where it comes from',
  )

  // Assigning is the whole point: do it and check the person appears.
  const before = await page.locator('table').nth(1).locator('tbody tr').count()
  await page.locator('input[placeholder*="8f2c"]').fill('user-checked-by-test')
  await page.locator('select').first().selectOption({ index: 1 })
  await page.locator('input[placeholder*="Priya"]').fill('Test person')
  await page.getByRole('button', { name: /Give access/ }).click()
  await page.waitForTimeout(1200)

  const after = await page.locator('table').nth(1).locator('tbody tr').count()
  eq(after, before + 1, 'the person now holds a profile')
  ok((await page.locator('text=Test person').count()) > 0, 'shown by the label the administrator typed')
  ok((await page.locator('text=user-checked-by-test').count()) > 0, 'with the portal uuid as the real identity')
})

// ---------------------------------------------------------------------------
// What the app looks like to somebody who has not been granted anything.
//
// It used to look like this to EVERYBODY, company owners included, because the
// owner check read a field the portal does not send. The sidebar collapsed to
// the two or three entries that need no permission and every Books-backed
// figure read "Unavailable", which is indistinguishable from a product that was
// never finished. These two checks are the difference.
// ---------------------------------------------------------------------------

await check('the owner gets the whole product', async () => {
  await page.goto(`${BASE}/dashboard/overview`, { waitUntil: 'networkidle' })
  await settle()

  // The navigation is flat now, so what a permission decides is whether an
  // ENTRY appears. Most of them are gated, and they are exactly what vanished
  // in the screenshot this bug was reported with.
  const navText = (await page.locator('nav[aria-label="Purchases"]').textContent()) || ''
  for (const entry of ['Requisitions', 'Purchase orders', 'Purchase bills', 'Suppliers', 'Reports', 'Access']) {
    ok(navText.includes(entry), `${entry} is in the navigation`)
  }
  ok(navText.includes('Dashboard') && navText.includes('Settings'), 'and the ungated ones too')

  // No banner: there is nothing to explain.
  eq(await page.locator('text=You have no permissions in Aicountly Purchases yet').count(), 0, 'no notice for the owner')

  // And Smart Books is asked, rather than skipped for want of a permission.
  const sources = (await page.locator('.purchase-source-list').first().textContent()) || ''
  ok(sources.includes('Smart Books'), 'Smart Books is listed as a source')
  ok(!sources.includes('Not requested'), `Books is asked rather than skipped, got: ${sources.slice(0, 160)}`)
})

await check('a delegate is told why the app is empty, not left to guess', async () => {
  const delegateCtx = await browser.newContext({ viewport: { width: 1440, height: 950 } })
  await delegateCtx.addInitScript(() => {
    try {
      localStorage.setItem('auth_token', 'preview-auth-token.role-0')
      localStorage.setItem('purchases:scope', JSON.stringify({ cmp_id: 88, fy_id: 6, bo_id: 0 }))
    } catch {}
  })
  const delegate = await delegateCtx.newPage()
  await delegate.goto(`${BASE}/dashboard/overview`, { waitUntil: 'networkidle' })
  await delegate.waitForTimeout(900)

  ok(
    (await delegate.locator('text=You have no permissions in Aicountly Purchases yet').count()) > 0,
    'the notice says what is wrong',
  )
  ok(
    (await delegate.locator('text=Administration → Access').count()) > 0,
    'and who fixes it, and where',
  )

  const navText = (await delegate.locator('nav[aria-label="Purchases"]').textContent()) || ''
  for (const entry of ['Requisitions', 'Purchase orders', 'Purchase bills', 'Reports', 'Access']) {
    ok(!navText.includes(entry), `${entry} is correctly hidden`)
  }
  ok(navText.includes('Dashboard'), 'but what needs no permission stays')

  await delegateCtx.close()
})

await check('with no company chosen the launcher is what you get, not a broken shell', async () => {
  const fresh = await browser.newContext({ viewport: { width: 1440, height: 950 } })
  await fresh.addInitScript(() => {
    try {
      localStorage.setItem('auth_token', 'preview-auth-token')
      localStorage.removeItem('purchases:scope')
    } catch {}
  })
  const first = await fresh.newPage()
  await first.goto(`${BASE}/`, { waitUntil: 'networkidle' })
  await first.waitForTimeout(900)

  ok(
    await first.getByRole('heading', { name: /Which company are you buying for/ }).isVisible(),
    'the launcher is the page',
  )
  // Not the application frame: a sidebar of links that all refuse to load is
  // exactly what this replaced.
  eq(await first.locator('.app-shell__sidebar').count(), 0, 'no application frame behind it')

  const cards = first.locator('.launcher__card')
  ok((await cards.count()) >= 2, 'the companies Manage listed are offered')

  // Search narrows, and the count beside the heading agrees with what is drawn.
  await first.locator('.launcher__search input').fill('deccan')
  await first.waitForTimeout(350)
  eq(await first.locator('.launcher__card').count(), 1, 'search narrows the list')
  await first.locator('.launcher__search input').fill('')
  await first.waitForTimeout(350)

  // Choosing one asks for the year before opening anything: every document
  // here is filed against a year, so it cannot be skipped.
  await cards.first().locator('.launcher__card-text').click()
  await first.waitForTimeout(800)
  ok(await first.locator('.launcher__confirm').isVisible(), 'the year and branch step appears')
  ok(await first.locator('#launcher-fy').isVisible(), 'with a financial year to pick')

  await first.getByRole('button', { name: /^Open/ }).click()
  await first.waitForTimeout(1200)
  ok(
    await first.getByRole('heading', { name: 'Purchase overview' }).isVisible(),
    'and opening it lands on the dashboard',
  )
  ok((await first.locator('.app-shell__sidebar').count()) === 1, 'now the frame is there')

  await fresh.close()
})

await check('the launcher and the shell hold together on a phone', async () => {
  const phone = await browser.newContext({ viewport: { width: 390, height: 844 } })
  await phone.addInitScript(() => {
    try {
      localStorage.setItem('auth_token', 'preview-auth-token')
      localStorage.removeItem('purchases:scope')
    } catch {}
  })
  const small = await phone.newPage()
  await small.goto(`${BASE}/`, { waitUntil: 'networkidle' })
  await small.waitForTimeout(900)
  const overflow = await small.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
  eq(overflow, 0, 'the launcher does not scroll sideways at 390px')
  await phone.close()
})

await browser.close()
console.log(results.join('\n'))
const failed = results.filter((r) => r.startsWith('  FAIL')).length
console.log(`\n${results.length - failed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
