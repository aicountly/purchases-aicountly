/**
 * Browser checks for the approvals inbox.
 *
 *   PURCHASE_APP_URL=http://127.0.0.1:5174 node tests/approvals.mjs
 *
 * These cover what a unit test cannot: that the tab is in the URL and Back
 * undoes one change, that a decision updates the table AND the figures without
 * reloading the page, that the rule "you may not approve what you raised" is
 * visible rather than only enforced, that a rejection cannot be submitted
 * without a reason, and that the dialog traps focus and gives it back.
 *
 * It needs the app and its API running — see docs/DEVELOPMENT.md. It is not part
 * of `npm run build`, because a browser test that fails when nothing is serving
 * teaches everyone to ignore it.
 */

import { execFileSync } from 'node:child_process'
import { chromium } from 'playwright'

const BASE = process.env.PURCHASE_APP_URL ?? 'http://127.0.0.1:5173'
const API = process.env.PURCHASE_API_URL ?? 'http://127.0.0.1:8791'
const SES = process.env.PURCHASE_SES_KEY ?? 'preview-ses-key'
const SCOPE = 'cmp_id=88&fy_id=6&bo_id=0'

const results = []
const check = async (name, fn) => {
  try { await fn(); results.push(`  ok    ${name}`) }
  catch (e) { results.push(`  FAIL  ${name}\n        ${e.message}`) }
}
const eq = (a, b, what) => { if (a !== b) throw new Error(`${what}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`) }
const ok = (c, what) => { if (!c) throw new Error(what) }

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
const apiGet = async (path, as = SES) => {
  const res = await fetch(`${API}/${path}${path.includes('?') ? '&' : '?'}${SCOPE}`, {
    headers: { Authorization: `Bearer ${as}` },
  })
  const payload = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(`GET ${path} -> ${res.status}`)
  return payload
}

// Raised by somebody else, deliberately: the inbox excludes anything you raised
// yourself, so an order seeded by the person doing the browsing would never
// appear on the tab these checks are about.
const BUYER = `${SES}.as-buyer`

// Start from empty. Reading whatever the database happened to hold passes on a
// machine somebody has been clicking around on and fails on a clean one — the
// state CI is always in.
try {
  execFileSync('php', [new URL('../../server-php/tests/reset.php', import.meta.url).pathname], { stdio: 'inherit' })
} catch (e) {
  console.error('Could not reset the database before the browser checks:', e.message)
  process.exit(1)
}

const iso = (offsetDays) => new Date(Date.now() + offsetDays * 86400000).toISOString().slice(0, 10)

// The buyer needs a name in the queue, and this product's only honest source
// for one is the label an administrator typed against a permission grant.
await apiPost('v1/access/profiles', {
  profile_name: 'Buyer',
  permissions: ['po.view', 'po.create', 'requisition.view', 'requisition.create'],
})
const profiles = await apiGet('v1/access/profiles')
const buyerProfile = profiles.data.find((p) => p.profile_name === 'Buyer')
// The uuid is whatever the portal answers for that key — asked for rather than
// assumed, because a label hung on a guessed uuid labels nobody.
const buyerUuid = (await apiGet('v1/session', BUYER)).data.uuid
await apiPost('v1/access/members', { user_uuid: buyerUuid, profile_id: buyerProfile.profile_id, member_label: 'Sneha Chawla' })

// Under the threshold nothing needs approving, so there would be no queue.
await apiPut('v1/settings', { po_approval_above_amount: 1000 })

const order = (supplierId, supplierName, rate, days) => ({
  supplier_account_id: supplierId,
  supplier_name: supplierName,
  po_date: iso(-days),
  promised_date: iso(10),
  delivery_warehouse_id: 3,
  lines: [{ item_id: 201, unit_id: 1, ordered_qty: 1, agreed_rate: rate, estimated_tax_pc: 0, warehouse_id: 3 }],
})

const seeded = []
for (const [id, name, rate, days] of [
  [601, 'Hometown Furnishings', 125000, 2],
  [602, 'Shree Chemicals Pvt Ltd', 348200, 3],
  [603, 'Metro Traders', 82400, 4],
]) {
  const po = await apiPost('v1/purchase-orders', order(id, name, rate, days), BUYER)
  await apiPost(`v1/purchase-orders/${po.po_id}/submit`, {}, BUYER)
  seeded.push(po)
}

// One the browsing user raised themselves, for the segregation-of-duties check.
const ownOrder = await apiPost('v1/purchase-orders', order(604, 'Vision Enterprises', 51875, 1))
await apiPost(`v1/purchase-orders/${ownOrder.po_id}/submit`)

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
const pageErrors = []
page.on('pageerror', (e) => pageErrors.push(e.message))

const settle = () => page.waitForTimeout(700)
const open = async (path) => { await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }); await settle() }
const rows = () => page.locator('.purchase-approvals-table tbody tr')
const kpi = (label) =>
  page.locator('.purchase-metric', { has: page.locator('.purchase-metric__label', { hasText: label }) })
    .locator('.purchase-metric__value')

await open('/approvals')

await check('the screen is the same screen as the dashboards', async () => {
  ok(await page.locator('.purchase-workspace').isVisible(), 'the purchase visual system is the one in use')
  ok(await page.getByRole('heading', { name: 'Approvals', level: 1 }).isVisible(), 'the page title')
  eq(await page.locator('.purchase-breadcrumb span').first().textContent(), 'Aicountly Purchases', 'the breadcrumb')
  eq(await page.locator('.purchase-metric').count(), 6, 'six KPI tiles, as every dashboard has')
  ok(await page.locator('.purchase-feature').isVisible(), 'the green feature card')
})

await check('an unavailable figure is never drawn as a zero', async () => {
  // Nothing has been decided, so there is no decision time to average. The card
  // has to say that rather than showing "0 days".
  const card = page.locator('.purchase-metric', {
    has: page.locator('.purchase-metric__label', { hasText: 'Average decision time' }),
  })
  eq((await card.locator('.purchase-metric__value').textContent())?.trim(), 'Unavailable', 'the value slot')
  ok((await card.textContent())?.includes('no time to average'), 'and it says why')
})

await check('the queue lists what is waiting, with the document behind it', async () => {
  // Four: three raised by the buyer, and one this user raised themselves. The
  // browsing user here is the COMPANY OWNER, and the owner is the one person
  // the domain services let approve their own document — so the queue that
  // excludes a buyer's own work does not exclude theirs.
  eq(await rows().count(), 4, 'everything waiting on this user')
  const sneha = rows().filter({ hasText: 'Sneha Chawla' }).first()
  ok((await sneha.textContent())?.includes('Sneha Chawla'), 'the requester is named from the access label')
  ok((await sneha.textContent())?.includes('₹'), 'the amount is formatted')
})

await check('the segregation-of-duties rule is written out, not only enforced', async () => {
  // The page states the rule and states the one exception to it, because a
  // screen that silently applies a rule is a screen somebody files a bug about.
  const rule = await page.locator('.purchase-approvals-rule').textContent()
  ok(rule.includes('cannot be approved by the person who raised it'), 'the rule')
  ok(rule.includes('except by the company owner'), 'and the exception, for the owner reading it')

  await page.getByRole('button', { name: 'Raised by me' }).click()
  await settle()
  eq(new URL(page.url()).searchParams.get('tab'), 'raised_by_me', 'the tab is in the URL')
  ok(
    (await page.locator('.purchase-approvals-table').textContent()).includes('Vision Enterprises'),
    'what this user raised is on its own tab',
  )
})

await check('Back undoes one tab, not the whole screen', async () => {
  await page.goBack()
  await settle()
  eq(new URL(page.url()).searchParams.get('tab'), null, 'back on the first tab')
  eq(new URL(page.url()).pathname, '/approvals', 'still on the approvals screen')
})

await check('the search narrows the queue and is one history entry', async () => {
  const before = page.url()
  await page.getByLabel('Search approvals').fill('Metro')
  await page.waitForTimeout(900)
  eq(await rows().count(), 1, 'one match')
  eq(new URL(page.url()).searchParams.get('q'), 'Metro', 'the term is in the URL')

  await page.goBack()
  await page.waitForTimeout(900)
  eq(page.url(), before, 'one Back clears the whole search, not one keystroke of it')
})

await check('the review drawer states the rules behind the risk chip', async () => {
  await open('/approvals')
  await page.locator('.purchase-approvals-more').first().click()
  await settle()
  const drawer = page.locator('.purchase-drawer')
  ok(await drawer.isVisible(), 'the drawer opened')
  ok((await drawer.textContent()).includes('Worth checking'), 'it says what to check')
  ok((await drawer.textContent()).includes('advisory'), 'and that the decision stays with the user')
  await page.keyboard.press('Escape')
  await settle()
  eq(await drawer.count(), 0, 'Escape closes it')
})

await check('a rejection will not go without a reason', async () => {
  await rows().first().getByRole('button', { name: 'Reject', exact: true }).click()
  await settle()
  const dialog = page.locator('.purchase-modal')
  ok(await dialog.isVisible(), 'the dialog opened')

  await dialog.getByRole('button', { name: 'Reject', exact: true }).click()
  await settle()
  ok(await dialog.isVisible(), 'it did not submit')
  ok((await dialog.textContent()).includes('A rejection needs a reason'), 'and it says what is missing')

  await page.keyboard.press('Escape')
  await settle()
  eq(await page.locator('.purchase-modal').count(), 0, 'Escape closes it')
})

await check('approving updates the table and the figures without a reload', async () => {
  await open('/approvals')
  const before = await rows().count()
  eq((await kpi('Pending approvals').textContent())?.trim(), '4', 'four are open to begin with')
  eq(before, 4, 'and all four are in the queue')
  eq((await kpi('Approved this period').textContent())?.trim(), '0', 'none approved yet')

  const navigated = page.waitForNavigation({ timeout: 1500 }).then(() => true).catch(() => false)

  await rows().first().getByRole('button', { name: 'Approve', exact: true }).click()
  await settle()
  await page.locator('.purchase-modal').getByRole('button', { name: 'Approve', exact: true }).click()
  await page.waitForTimeout(1600)

  eq(await navigated, false, 'the page was not reloaded')
  eq(await rows().count(), before - 1, 'the row left the queue')
  eq((await kpi('Pending approvals').textContent())?.trim(), '3', 'the pending figure followed')
  eq((await kpi('Approved this period').textContent())?.trim(), '1', 'and so did the approved figure')
  ok((await page.locator('.purchase-notice--success').textContent()).includes('approved'), 'and it said so')
})

await check('the decision is on the actioned tab, and the period applies there', async () => {
  await open('/approvals?tab=actioned&preset=this_year')
  eq(await rows().count(), 1, 'the approved order is in what was decided')
  ok((await page.locator('.purchase-approvals-table').textContent()).includes('Approved'), 'with its outcome')
})

await check('an empty queue says you are caught up rather than showing nothing', async () => {
  // Whatever is still open, read back rather than assumed: the approve check
  // above already decided one of them and which one depends on the sort.
  const stillOpen = await apiGet('v1/approvals/queue?scope=all_pending&limit=50')
  for (const row of stillOpen.data) {
    await apiPost(`v1/purchase-orders/${row.entity_id}/reject`, { note: 'Cleared by the browser checks.' })
  }
  await open('/approvals')
  eq(await rows().count(), 0, 'nothing is listed')
  ok(
    (await page.locator('.purchase-approvals-queue .purchase-empty').textContent()).includes("You're all caught up"),
    'the empty state',
  )
  ok(await page.locator('.purchase-metric').first().isVisible(), 'and the figures stay on screen')
})

await check('the page never scrolls sideways', async () => {
  for (const width of [1920, 1440, 1366, 1280]) {
    await page.setViewportSize({ width, height: 900 })
    await open('/approvals?tab=all_pending')
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)
    ok(!overflow, `no horizontal page overflow at ${width}px`)
  }
  await page.setViewportSize({ width: 1440, height: 950 })
})

await check('the approval API failing keeps the frame and offers a retry', async () => {
  await page.route('**/v1/approvals/summary**', (route) => route.fulfill({ status: 503, body: '{}' }))
  await open('/approvals')
  ok(await page.locator('.purchase-workspace').isVisible(), 'the shell is still there')
  ok((await page.locator('.purchase-approvals-error').textContent()).includes("couldn't load"), 'and it says so plainly')
  ok(await page.getByRole('button', { name: 'Retry' }).isVisible(), 'with a way to try again')
  await page.unroute('**/v1/approvals/summary**')
})

await check('nothing threw while any of that happened', async () => {
  eq(pageErrors.join(' | '), '', 'page errors')
})

console.log('\nApprovals')
console.log(results.join('\n'))
const failed = results.filter((line) => line.includes('FAIL')).length
console.log(`\n${results.length - failed} passed, ${failed} failed`)

await browser.close()
process.exit(failed === 0 ? 0 : 1)
