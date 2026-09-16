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
  await small.locator('.app-shell__close').click()
  await small.waitForTimeout(300)
  ok((await small.locator('.app-shell__sidebar.is-open').count()) === 0, 'and slid out again')
  await mobile.close()
})

await browser.close()
console.log(results.join('\n'))
const failed = results.filter((r) => r.startsWith('  FAIL')).length
console.log(`\n${results.length - failed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
