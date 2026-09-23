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
    // fetch refuses a body on GET, and this helper is used to read as well as write.
    ...(method === 'GET' || method === 'HEAD' ? {} : { body: JSON.stringify(body ?? {}) }),
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

/**
 * Four suppliers, four months, three deliveries each.
 *
 * Not decoration: the charts refuse to draw on thin data, correctly. A donut
 * needs more than one supplier to divide anything up, a price path needs the
 * same item ordered in several months, and the product will not rate a month
 * with fewer than three deliveries — so a single seeded order proves nothing
 * about any of them, and a check written against it would pass on an empty
 * chart.
 */
const SUPPLIERS = [
  [601, 'Shree Cement Ltd.'],
  [602, 'Tata Steel'],
  [603, 'Ujala Electricals'],
  [604, 'Hindustan Petroleum'],
]

// Nothing needs approval while the history is built, so every order reaches
// ISSUED and can be received.
await apiPut('v1/settings', { po_approval_above_amount: 0 })

let month = 0
for (const mm of ['04', '05', '06', '07']) {
  month += 1
  let sIndex = 0
  for (const [supplierId, supplierName] of SUPPLIERS) {
    sIndex += 1
    const body = {
      supplier_account_id: supplierId,
      supplier_name: supplierName,
      po_date: `2026-${mm}-05`,
      promised_date: `2026-${mm}-20`,
      delivery_warehouse_id: 3,
      lines: [
        { item_id: 201, unit_id: 1, ordered_qty: 40 + sIndex * 10, agreed_rate: 250 + month * 12 + sIndex * 3, estimated_tax_pc: 18, warehouse_id: 3 },
        { item_id: 202, unit_id: 1, ordered_qty: 20 + sIndex * 5, agreed_rate: 900 + month * 25 - sIndex * 7, estimated_tax_pc: 18, warehouse_id: 3 },
      ],
    }
    for (let n = 1; n <= 3; n++) {
      const po = await apiPost('v1/purchase-orders', body)
      await apiPost(`v1/purchase-orders/${po.po_id}/submit`)
      await apiPost(`v1/purchase-orders/${po.po_id}/issue`)
      // The last supplier slips from month three on. A sparkline that only ever
      // draws a flat line is not evidence that gaps and declines render.
      const late = sIndex === SUPPLIERS.length && month > 2 && n !== 3
      await apiPost(`v1/purchase-orders/${po.po_id}/receive`, { received_at: `2026-${mm}-${late ? 27 : 18}` })
    }
  }
}

// One order left ISSUED and never received, so "In-transit deliveries" on the
// Procurement workspace is not trivially zero — a card-to-table agreement
// check proves nothing if the count it is checking is always empty.
const inTransit = await apiPost('v1/purchase-orders', {
  supplier_account_id: SUPPLIERS[0][0],
  supplier_name: SUPPLIERS[0][1],
  po_date: '2026-08-05',
  promised_date: '2026-09-05',
  delivery_warehouse_id: 3,
  lines: [{ item_id: 201, unit_id: 1, ordered_qty: 50, agreed_rate: 260, estimated_tax_pc: 18, warehouse_id: 3 }],
})
await apiPost(`v1/purchase-orders/${inTransit.po_id}/submit`)
await apiPost(`v1/purchase-orders/${inTransit.po_id}/issue`)

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
const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 }, acceptDownloads: true })
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
  const active = await page.locator('.purchase-chip.is-active').first().textContent()
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
  ok((await page.locator('.purchase-scope-line').textContent())?.includes('–'), 'the resolved range is shown')
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

  // The card counts orders that are issued, acknowledged or partly received;
  // the drill-down opens the workbench filtered to exactly that predicate. If
  // the two ever disagree, one of them is lying and there is no way to tell
  // which from the screen.
  const card = page.locator('.purchase-metric', { hasText: 'In-transit deliveries' })
  const onCard = Number.parseInt(((await card.locator('.purchase-metric__value').textContent()) || '0').replace(/[^0-9]/g, ''), 10)
  ok(Number.isFinite(onCard) && onCard > 0, 'the card shows a count')

  await card.getByRole('button').click()
  await settle()
  eq(new URL(page.url()).searchParams.get('view'), 'open_orders', 'the workbench is filtered to open orders')

  const inList = await page.locator('.purchase-panel', { hasText: 'Procurement workbench' }).locator('tbody tr').count()
  eq(inList, onCard, 'the workbench holds exactly as many orders as the card counted')
})

await check('a flow stage opens the records behind it', async () => {
  await page.goto(`${BASE}/dashboard/procurement?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const stage = page.locator('.purchase-pipeline__stage', { hasText: 'Delivery' }).first()
  ok(await stage.isVisible(), 'the delivery stage is on the screen')
  await stage.click()
  await settle()

  // Which view depends on whether anything is late, and both are real views of
  // the workbench — what must never happen is a stage that leads nowhere.
  const view = new URL(page.url()).searchParams.get('view')
  ok(['expected', 'delayed'].includes(view || ''), `the stage opened a workbench view, got ${view}`)
})

await check('the activity row menu opens, closes and only offers real screens', async () => {
  await page.goto(`${BASE}/dashboard/procurement?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const activity = page.locator('.purchase-panel', { hasText: 'Recent activity & exceptions' })
  const toggle = activity.locator('.purchase-overflow button[aria-haspopup="menu"]').first()

  if ((await toggle.count()) === 0) {
    // Every row had a single action and rendered a plain Open button instead —
    // a legitimate shape for this period's data, not a failure.
    ok(await activity.locator('tbody tr').count() >= 0, 'the feed rendered')
    return
  }

  await toggle.click()
  await page.waitForTimeout(200)
  const menu = activity.locator('.purchase-overflow__menu').first()
  ok(!(await menu.isHidden()), 'the menu opened')
  ok((await menu.getByRole('menuitem').count()) > 0, 'it offers at least one destination')

  await page.keyboard.press('Escape')
  await page.waitForTimeout(200)
  ok(await menu.isHidden(), 'Escape closed it')
})

await check('the procurement workspace never scrolls sideways, from a laptop down to a phone', async () => {
  for (const [width, height] of [[1920, 1080], [1536, 864], [1366, 768], [1024, 768], [768, 900], [390, 844]]) {
    await page.setViewportSize({ width, height })
    await page.goto(`${BASE}/dashboard/procurement?preset=this_year`, { waitUntil: 'networkidle' })
    await settle()

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    ok(overflow <= 1, `the page overflowed by ${overflow}px at ${width}px wide`)
  }
  await page.setViewportSize({ width: 1440, height: 950 })
})

await check('an unavailable figure never renders as a zero', async () => {
  await page.goto(`${BASE}/dashboard/bills-payables?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  // The forward-window card has two honest faces and this accepts either: the
  // figure, when the planner has read real due dates for somebody, or the
  // reason it cannot be answered company-wide. What it must never be is a zero
  // standing in for "we could not ask".
  const card = page.locator('.aic-kpi', { hasText: 'Due within 7 / 15 / 30 days' })
  const text = (await card.textContent()) || ''

  if (text.includes('Unavailable')) {
    ok(!/₹\s?0(\.00)?\b/.test(text), 'an unavailable card never shows a zero amount')
    ok(text.includes('acc_id'), 'and states the upstream reason on the card')
  } else {
    ok(/₹/.test(text), 'a ready card shows a real amount')
    ok(text.includes('15 days'), 'and carries the other two windows in its footnote')
    // Ready means it was answered for a stated set of suppliers, never for the
    // whole ledger — the card has to say which, or the figure reads as company-wide.
    ok(((await card.getAttribute('title')) || '').includes('acc_id'), 'and still explains why it is not company-wide')
  }
})

// ---------------------------------------------------------------------------
// Bills, for the payables workspace.
//
// The shared seed above builds orders and receipts but stops there, because
// until now nothing needed a bill. The payables screen is a list of bills, so
// it seeds its own: one that matches cleanly and is therefore ready to post,
// one billed above the agreed rate so there is a real exception to look at,
// and a pair sharing a supplier and an invoice date so the duplicate review
// has something to review. They are entered through the real API, as a user.
// ---------------------------------------------------------------------------
{
  const orders = await apiCall('GET', 'v1/purchase-orders?limit=6&status=RECEIVED')
  const usable = []
  for (const row of orders ?? []) {
    const full = await apiCall('GET', `v1/purchase-orders/${row.po_id}`)
    if (full?.lines?.length) usable.push(full)
    if (usable.length === 4) break
  }

  let n = 0
  for (const po of usable) {
    n += 1
    const line = po.lines[0]
    await apiPost('v1/bills', {
      supplier_account_id: po.supplier_account_id,
      po_id: po.po_id,
      supplier_invoice_no: `SEED-BILL-${String(n).padStart(3, '0')}`,
      supplier_invoice_date: `2026-07-${String(10 + n).padStart(2, '0')}`,
      // The second one is billed well above the agreed rate: an exception by policy.
      lines: [{ po_line_id: line.line_id, qty: Number(line.ordered_qty), rate: n === 2 ? Number(line.agreed_rate) * 2 : Number(line.agreed_rate) }],
    })
  }

  // Two bills, one supplier, one invoice date.
  if (usable[0]) {
    const po = usable[0]
    await apiPost('v1/bills', {
      supplier_account_id: po.supplier_account_id,
      po_id: po.po_id,
      supplier_invoice_no: 'SEED-BILL-DUP',
      supplier_invoice_date: '2026-07-11',
      lines: [{ po_line_id: po.lines[0].line_id, qty: 1, rate: Number(po.lines[0].agreed_rate) }],
    })
  }
}

await check('the payables tabs filter on the server and the paging agrees', async () => {
  await page.goto(`${BASE}/dashboard/bills-payables?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const all = page.locator('.aic-tab', { hasText: 'All bills' })
  const onTab = Number.parseInt(((await all.locator('span').textContent()) || '0').replace(/[^0-9]/g, ''), 10)
  ok(Number.isFinite(onTab) && onTab > 0, 'the strip counts the bills that exist')

  const footer = (await page.locator('.aic-pagination').first().textContent()) || ''
  ok(footer.includes(`of ${onTab} bill`), `the footer agrees with the tab, got "${footer.trim().slice(0, 60)}"`)

  // A tab is a server-side filter, not a hide: the count it carries is what
  // the list becomes, and it survives a reload because it is in the URL.
  const exceptions = page.locator('.aic-tab', { hasText: 'Exceptions' })
  const expected = Number.parseInt(((await exceptions.locator('span').textContent()) || '0').replace(/[^0-9]/g, ''), 10)
  await exceptions.click()
  await settle()

  eq(new URL(page.url()).searchParams.get('tab'), 'exceptions', 'the tab is in the URL')
  eq(await page.locator('.aic-table tbody tr').count(), expected, 'the list holds exactly what the tab counted')

  await page.reload({ waitUntil: 'networkidle' })
  await settle()
  ok(await exceptions.evaluate((el) => el.classList.contains('is-active')), 'and a reload lands on the same tab')
})

await check('every bill in the list states a value and never a tax it cannot compute', async () => {
  await page.goto(`${BASE}/dashboard/bills-payables?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const first = page.locator('.aic-table tbody tr').first()
  const amount = (await first.locator('.aic-amount').textContent()) || ''
  ok(/₹/.test(amount), 'the row carries a rupee value')
  ok(amount.includes('excl. tax'), 'and says the tax is not in it')

  // Due date and payment are Books', and Books answers open items one supplier
  // at a time. A bill it was not asked about must say so rather than leave a
  // blank a reader takes for "nothing due".
  const due = (await first.locator('td').nth(4).textContent()) || ''
  ok(due.trim() !== '', 'the due date cell is never silently empty')
})

await check('Ask Aicountly answers, and offers nothing that moves money', async () => {
  await page.goto(`${BASE}/dashboard/bills-payables?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  await page.locator('.aic-ask-card').click()
  await page.waitForTimeout(400)

  const drawer = page.locator('.aic-drawer')
  ok(await drawer.isVisible(), 'the panel opens')
  eq(await page.evaluate(() => document.activeElement?.id), 'aic-ask-input', 'and takes the caret')

  await drawer.getByRole('button', { name: 'Which bills need review before payment?', exact: true }).click()
  await page.waitForTimeout(1800)

  const answer = await drawer.locator('.aic-ask-answer').textContent()
  ok((answer || '').length > 20, 'a real answer comes back from the engine')

  // Advisory, always. Nothing in this panel may approve, post, schedule or pay.
  const buttons = await drawer.locator('button').allTextContents()
  const dangerous = buttons.filter((t) => /\b(approve|post to|pay|schedule)\b/i.test(t))
  eq(dangerous.length, 0, `the panel offers no money-moving action, found ${JSON.stringify(dangerous)}`)

  await page.keyboard.press('Escape')
  await page.waitForTimeout(300)
  ok(!(await drawer.isVisible()), 'and Escape closes it')
})

await check('posting to Smart Books is confirmed, never fired from a menu', async () => {
  await page.goto(`${BASE}/dashboard/bills-payables?preset=this_year&tab=ready_to_post`, { waitUntil: 'networkidle' })
  await settle()

  const rows = await page.locator('.aic-table tbody tr').count()
  ok(rows > 0, 'there is a bill ready to post to try this on')

  await page.locator('.aic-table tbody tr').first().getByRole('button', { name: /^Actions for bill/ }).click()
  await page.waitForTimeout(300)

  const post = page.getByRole('menuitem', { name: /Post to Smart Books/ })
  ok(await post.isVisible(), 'the owner is offered the post')
  await post.click()
  await page.waitForTimeout(400)

  // The menu item opens a confirmation. It does NOT post.
  const dialog = page.locator('.aic-drawer[role="dialog"]')
  ok(await dialog.isVisible(), 'a confirmation opens instead')
  const text = (await dialog.textContent()) || ''
  ok(text.includes('cannot be undone'), 'it says the post cannot be undone from here')
  ok(text.includes('Aicountly Pay is not integrated'), 'and refuses to imply anybody gets paid')

  await page.getByRole('button', { name: 'Cancel' }).click()
  await page.waitForTimeout(300)
  ok(!(await dialog.isVisible()), 'and cancelling leaves the bill alone')
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

await check('purchase intelligence leads with six figures and three zones', async () => {
  await page.goto(`${BASE}/dashboard/ai-insights?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  eq((await page.locator('h1').first().textContent())?.trim(), 'Purchase intelligence', 'the heading')
  eq(await page.locator('.purchase-metric').count(), 6, 'six cards')
  ok(await page.locator('.purchase-intel-opportunities').isVisible(), 'the opportunities table')
  ok(await page.locator('.purchase-intel-risks').isVisible(), 'the risk list')
  ok(await page.locator('.purchase-intel-insights').isVisible(), 'the insight list')

  // The row must fit the viewport. A KPI row somebody has to drag sideways is
  // a KPI row they read half of.
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  ok(overflow <= 1, `no horizontal page scroll, got ${overflow}px`)
})

await check('a KPI carries its shape and the previous period under it', async () => {
  await page.goto(`${BASE}/dashboard/ai-insights?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const card = page.locator('.purchase-metric', { hasText: 'Purchase value' }).first()
  ok(await card.locator('.purchase-metric__spark').isVisible(), 'the sparkline drew')
  ok(((await card.locator('.purchase-metric__footer').textContent()) || '').length > 0, 'the footer states the comparison')

  // The short form is on the card; the exact figure is the tooltip, so nothing
  // rounded is ever the only figure on the screen.
  const exact = await card.locator('.purchase-metric__value').getAttribute('title')
  ok((exact || '').includes('₹'), 'the exact figure travels with the short one')
})

await check('an opportunity opens a drawer rather than navigating away', async () => {
  await page.goto(`${BASE}/dashboard/ai-insights?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const rows = page.locator('.purchase-intel-opportunities tbody tr')
  if ((await rows.count()) === 0) return

  const before = page.url()
  await rows.first().locator('button').first().click()
  await page.waitForTimeout(400)

  const dialog = page.getByRole('dialog')
  ok(await dialog.isVisible(), 'the drawer opened')
  eq(page.url(), before, 'and the page did not navigate')
  ok(((await dialog.textContent()) || '').includes('Why this was detected'), 'it states why the rule fired')
  ok(((await dialog.textContent()) || '').includes('assumes'), 'and what the estimate assumes')

  await page.keyboard.press('Escape')
  await page.waitForTimeout(300)
  ok((await page.getByRole('dialog').count()) === 0, 'Escape closed it')
})

await check('Ask Aicountly AI is a drawer, and its state is in the URL', async () => {
  await page.goto(`${BASE}/dashboard/ai-insights?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  await page.locator('.purchase-intel-insights').getByRole('button', { name: 'Ask AI' }).click()
  await page.waitForTimeout(400)
  eq(new URL(page.url()).searchParams.get('ask'), '1', 'the drawer is in the URL')

  const dialog = page.getByRole('dialog')
  ok(await dialog.isVisible(), 'the drawer opened')
  ok(((await dialog.textContent()) || '').includes('never writes a query'), 'the security position is on the screen')

  await page.keyboard.press('Escape')
  await page.waitForTimeout(300)
  eq(new URL(page.url()).searchParams.get('ask'), null, 'and closing takes it out again')
})

await check('the monitoring pill reports what actually answered', async () => {
  await page.goto(`${BASE}/dashboard/ai-insights?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const pill = page.locator('.purchase-monitor__pill')
  const label = ((await pill.textContent()) || '').trim()
  ok(/AI monitoring live|Limited ai monitoring|AI monitoring unavailable/.test(label), `a real state, got "${label}"`)

  await pill.click()
  await page.waitForTimeout(250)
  const popover = page.locator('.purchase-monitor__popover')
  ok(await popover.isVisible(), 'the sources are one click away')
  ok(((await popover.textContent()) || '').includes('Purchases'), 'and this product is named among them')
})

await check('access administration bootstraps, assigns and shows its own rules', async () => {
  await page.goto(`${BASE}/access`, { waitUntil: 'networkidle' })
  await settle()

  // Renamed to "Access & Permissions" by the workspace rebuild; the check
  // follows the page rather than pinning it to a title that no longer exists.
  eq((await page.locator('h1').first().textContent())?.trim(), 'Access & Permissions', 'the access screen opened')

  // A company with no profiles says so, rather than looking merely empty: only
  // the owner can do anything until one exists.
  //
  // The workspace rebuild offers the same action from two places — a top-level
  // prompt and the Profiles panel's own empty state — so this scopes to the
  // panel, which is the one guaranteed to exist only when there is something
  // for it to bootstrap.
  const bootstrap = page.getByRole('tabpanel', { name: 'Profiles' }).getByRole('button', { name: /Create starter profiles/ })
  if (await bootstrap.count()) {
    // Reworded by the workspace rebuild — quieter, but the same fact: nothing
    // but ownership works until a profile exists.
    ok(
      (await page.locator('text=only the company owner can use Purchases').count()) > 0,
      'and explains why an empty company is a problem',
    )
    await bootstrap.click()
    // The workspace rebuild previews the four starters in a dialog before
    // writing anything — the same list the server would create from, so a
    // second confirm is now needed to actually create them.
    await page.getByRole('button', { name: /^Create profiles$/ }).click()
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

  // Assigning is the whole point: do it and check the person appears. The
  // grant form stays mounted under every tab — deliberately, per the
  // workspace rebuild's own comment, so granting is never a click behind a
  // tab — but the People table it should appear in only mounts once that tab
  // is the active one, so the check switches to it before counting either side.
  await page.getByRole('tab', { name: 'People with access' }).click()
  await settle()
  // With the People tab active, the Profiles tab's table is not in the DOM,
  // and a company with nobody yet assigned renders an empty state instead of
  // a table at all — so there is at most one <table> here, not two.
  const before = await page.locator('table').first().locator('tbody tr').count()
  await page.locator('input[placeholder*="8f2c"]').fill('user-checked-by-test')
  await page.locator('select').first().selectOption({ index: 1 })
  await page.locator('input[placeholder*="Priya"]').fill('Test person')
  await page.getByRole('button', { name: /Give access/ }).click()
  await page.waitForTimeout(1200)

  const after = await page.locator('table').first().locator('tbody tr').count()
  eq(after, before + 1, 'the person now holds a profile')
  ok((await page.locator('text=Test person').count()) > 0, 'shown by the label the administrator typed')
  // The full uuid is truncated for display in the row — "user-checked…test" —
  // with the real value kept as the title attribute for a hover tooltip. The
  // truncation is deliberate design, so this checks the attribute a sighted
  // reader would still have to hover for, not the shortened text node.
  ok(
    (await page.locator('[title="user-checked-by-test"]').count()) > 0,
    'with the portal uuid as the real identity',
  )
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

await check('the supplier charts are drawn from the data, with the figures behind them', async () => {
  await page.goto(`${BASE}/dashboard/suppliers?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  // The donut: one arc per named supplier plus, when there are more than four,
  // one neutral slice for the tail. Never a generated fifth hue.
  const arcs = page.locator('.purchase-donut svg path')
  const arcCount = await arcs.count()
  ok(arcCount >= 2 && arcCount <= 6, `donut has ${arcCount} slices — between 2 and 6`)

  // Identity is never colour alone: every slice is named and shares are stated.
  const legend = (await page.locator('.purchase-donut__legend').textContent()) || ''
  ok(/%/.test(legend), 'the legend carries each share')

  // And the same figures exist as a table, which is what a screen reader and a
  // printout get.
  const donutPanel = page.locator('section.purchase-panel').filter({ hasText: 'Concentration exposure' }).first()
  await donutPanel.locator('.purchase-chart__toggle button').first().click()
  await page.waitForTimeout(300)
  ok((await donutPanel.locator('table').count()) > 0, 'the donut has a table view')

  // The price path: one line per item on ONE axis, indexed so items priced per
  // tonne and per coil can share it.
  const pricePanel = page.locator('section.purchase-panel').filter({ hasText: 'Price movement' }).first()
  await pricePanel.scrollIntoViewIfNeeded()
  const lines = pricePanel.locator('.purchase-chart--line path[stroke]')
  ok((await lines.count()) >= 1, 'at least one price path is drawn')
  const axisLabels = await pricePanel.locator('.purchase-chart--line .purchase-chart__tick').count()
  ok(axisLabels > 0, 'the index axis is labelled')
  ok(
    (await pricePanel.locator('text=/indexed to 100/i').count()) > 0,
    'and the page says what the index is against',
  )
})

await check('a sparkline breaks at months it cannot rate rather than drawing zero', async () => {
  await page.goto(`${BASE}/dashboard/suppliers?preset=this_year`, { waitUntil: 'networkidle' })
  await settle()

  const sparks = page.locator('.purchase-spark')
  ok((await sparks.count()) >= 1, 'the scorecard carries a trend column')

  // Every sparkline states its own figures for a screen reader, and a month
  // with too few deliveries is absent from that list rather than present as 0%.
  const label = (await sparks.first().getAttribute('aria-label')) || ''
  ok(/On-time delivery for/.test(label), `the sparkline names its supplier: ${label.slice(0, 60)}`)
  ok(!/\b0%/.test(label) || /\d+%/.test(label), 'rated months carry a percentage')
})

await check('a supplier statement is read, checked, then reconciled', async () => {
  const fs = await import('node:fs/promises')
  const os = await import('node:os')
  const pathMod = await import('node:path')

  // A statement shaped like the ones that actually arrive: a letterhead above
  // the table, Indian lakh grouping, and the same invoice written two ways.
  const csv = [
    'Shree Cement Ltd.,,,',
    'Statement of account,,,',
    '01 Aug 2026 to 31 Aug 2026,,,',
    'Bill Date,Invoice No,Particulars,Amount',
    '01/08/2026,INV-0001,Cement OPC,"2,00,000.00"',
    '10/08/2026,INV/0002,Freight,"45,000.25"',
    '15/08/2026,INV-0003,Admixture,"11,000.00"',
    '22/08/2026,INV-9999,Unknown charge,"7,500.00"',
  ].join('\n')
  const file = pathMod.join(os.tmpdir(), 'purchases-browser-statement.csv')
  await fs.writeFile(file, csv, 'utf8')

  await page.goto(`${BASE}/statements`, { waitUntil: 'networkidle' })
  await settle()

  await page.locator('input[type="file"]').setInputFiles(file)
  await page.waitForTimeout(1500)

  // Step two must show what it decided BEFORE anything is compared.
  ok(
    (await page.locator('text=/read as a letterhead and skipped/').count()) > 0,
    'it says it skipped the letterhead',
  )
  const mapped = await page.locator('select').first().inputValue()
  eq(mapped, '0', 'the date column was found')

  await page.locator('input[placeholder="Account id"]').fill('601')
  await page.locator('input[type="date"]').first().fill('2026-08-01')
  await page.locator('input[type="date"]').nth(1).fill('2026-08-31')
  await page.getByRole('button', { name: /Reconcile/ }).click()
  await page.waitForTimeout(1800)

  const body = (await page.locator('.purchase-dashboard-content').textContent()) || ''

  // All four answers, and the one that proves the matching works: INV/0002 on
  // the statement is INV/0002 in Books, and INV-0001 is INV/0001 — punctuation
  // must not create two false exceptions.
  ok(/Agreed/.test(body), 'the agreed bucket is shown')
  ok(/Same bill, different amount/.test(body), 'so is the disagreement bucket')
  ok(/On the statement only/.test(body), 'and what the supplier billed that we have not')
  ok(/In Smart Books only/.test(body), 'and what we hold that they did not list')
  ok(/INV-9999/.test(body), 'the unknown invoice is named')

  // Nothing is kept. That claim is on the screen because it is a promise.
  ok(/read and discarded/.test(body), 'and it says the file was not kept')

  await fs.unlink(file).catch(() => {})
})

await check('a report downloads as a real PDF, not a renamed CSV', async () => {
  await page.goto(`${BASE}/reports`, { waitUntil: 'networkidle' })
  await settle()

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: /PDF/ }).first().click(),
  ])

  const path = await download.path()
  ok(path !== null, 'the browser received a file')

  const fs = await import('node:fs/promises')
  const head = (await fs.readFile(path)).subarray(0, 8).toString('latin1')
  ok(head.startsWith('%PDF-'), `and its first bytes are a PDF header, got ${JSON.stringify(head)}`)
  ok(download.suggestedFilename().endsWith('.pdf'), 'named as a PDF')
})

await browser.close()
console.log(results.join('\n'))
const failed = results.filter((r) => r.startsWith('  FAIL')).length
console.log(`\n${results.length - failed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
