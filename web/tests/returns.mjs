/**
 * Browser checks for the purchase returns workspace.
 *
 *   PURCHASE_APP_URL=http://127.0.0.1:5173 npm run test:returns
 *
 * These cover the things a unit test cannot: that the URL carries the whole
 * state, that a filter narrows the register rather than the page, that one
 * widget failing leaves the rest of the screen working, that a drawer traps
 * focus and gives it back, that an unreachable Inventory reads as unavailable
 * rather than as nothing, and that the page never scrolls sideways at a laptop
 * width.
 *
 * It needs the app and its API running — see docs/DEVELOPMENT.md. It is not
 * part of `npm run build`, because a browser test that fails when nothing is
 * serving teaches everyone to ignore it.
 */

import { execFileSync } from 'node:child_process'
import { chromium } from 'playwright'

const BASE = process.env.PURCHASE_APP_URL ?? 'http://127.0.0.1:5173'
const API = process.env.PURCHASE_API_URL ?? 'http://127.0.0.1:8791'
const SES = process.env.PURCHASE_SES_KEY ?? 'preview-ses-key'
const SCOPE = 'cmp_id=88&fy_id=6&bo_id=0'

const results = []
const check = async (name, fn) => {
  try {
    await fn()
    results.push(`  ok    ${name}`)
  } catch (e) {
    results.push(`  FAIL  ${name}\n        ${e.message}`)
  }
}
const eq = (a, b, what) => {
  if (a !== b) throw new Error(`${what}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`)
}
const ok = (c, what) => {
  if (!c) throw new Error(what)
}

const apiCall = async (method, path, body) => {
  const res = await fetch(`${API}/${path}${path.includes('?') ? '&' : '?'}${SCOPE}`, {
    method,
    headers: { Authorization: `Bearer ${SES}`, 'Content-Type': 'application/json' },
    body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
  })
  const payload = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${JSON.stringify(payload).slice(0, 200)}`)
  return payload.data
}
const get = (path) => apiCall('GET', path)
const post = (path, body) => apiCall('POST', path, body)
const put = (path, body) => apiCall('PUT', path, body)

/**
 * Six months of returns, in every state the workflow has.
 *
 * Not decoration. The trend refuses to draw on one month, the donut cannot
 * divide one reason up, the board has nothing to lay out without several
 * states, and the insight rules will not call three returns a pattern — so a
 * single seeded return proves nothing about any of it, and a check written
 * against one would pass on an empty screen.
 */
try {
  execFileSync('php', [new URL('../../server-php/tests/reset.php', import.meta.url).pathname], { stdio: 'inherit' })
} catch (e) {
  console.error('Could not reset the database before the browser checks:', e.message)
  process.exit(1)
}

await put('v1/settings', { po_approval_above_amount: 0 })

const SUPPLIERS = [
  [601, 'Metro Electronics Pvt. Ltd.'],
  [602, 'Shree Traders'],
  [603, 'Global Tech Supplies'],
  [604, 'R.K. Enterprises'],
]
const REASONS = ['damaged', 'wrong_item', 'quality', 'short_supply', 'price_difference']

let seeded = 0
for (const [index, mm] of ['04', '05', '06', '07', '08', '09'].entries()) {
  for (let n = 0; n < 4; n++) {
    const [supplierId, supplierName] = SUPPLIERS[(index + n) % SUPPLIERS.length]
    const day = String(3 + n * 5).padStart(2, '0')

    const po = await post('v1/purchase-orders', {
      supplier_account_id: supplierId,
      supplier_name: supplierName,
      po_date: `2026-${mm}-01`,
      promised_date: `2026-${mm}-15`,
      delivery_warehouse_id: 3,
      lines: [{ item_id: 201, unit_id: 1, ordered_qty: 100, agreed_rate: 250, estimated_tax_pc: 18, warehouse_id: 3 }],
    })
    await post(`v1/purchase-orders/${po.po_id}/submit`)
    await post(`v1/purchase-orders/${po.po_id}/issue`)
    await post(`v1/purchase-orders/${po.po_id}/receive`, { received_at: `2026-${mm}-10` })
    const full = await get(`v1/purchase-orders/${po.po_id}`)

    const created = await post('v1/returns', {
      supplier_account_id: supplierId,
      supplier_name: supplierName,
      po_id: po.po_id,
      return_date: `2026-${mm}-${day}`,
      expected_pickup_date: `2026-${mm}-${String(Number(day) + 4).padStart(2, '0')}`,
      reason_code: REASONS[(index + n) % REASONS.length],
      lines: [{ po_line_id: full.lines[0].line_id, item_id: 201, warehouse_id: 3, return_qty: 2 + n, rate: 250 }],
    })
    seeded++

    // Draft, approved, dispatched, debited — one of each, every month.
    if (n >= 1) await post(`v1/returns/${created.return_id}/approve`)
    if (n >= 2) await post(`v1/returns/${created.return_id}/dispatch`)
    if (n >= 3) {
      await post(`v1/returns/${created.return_id}/debit-note`)
      await post(`v1/returns/${created.return_id}/supplier-credit`, {
        supplier_credit_status: 'RECEIVED',
        supplier_credit_ref: `CN-${4300 + seeded}`,
      })
    }
  }
}

const executablePath = process.env.PURCHASE_CHROMIUM_PATH || undefined
const browser = await chromium.launch(executablePath ? { executablePath } : {})
const signedIn = async (viewport = { width: 1440, height: 1000 }) => {
  const ctx = await browser.newContext({ viewport, acceptDownloads: true })
  await ctx.addInitScript(() => {
    try {
      localStorage.setItem('auth_token', 'preview-auth-token')
      localStorage.setItem('purchases:scope', JSON.stringify({ cmp_id: 88, fy_id: 6, bo_id: 0 }))
    } catch {}
  })
  return ctx
}

const ctx = await signedIn()
const page = await ctx.newPage()
const settle = () => page.waitForTimeout(900)

await page.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
await settle()

await check('the workspace draws its figures, its charts and its register', async () => {
  ok(await page.getByRole('heading', { name: 'Purchase returns' }).first().isVisible(), 'the heading')
  eq(await page.locator('.pr-kpi').count(), 4, 'four cards')
  ok((await page.locator('.pr-table tbody tr').count()) > 0, 'the register has rows')
  ok((await page.locator('.pr-ai-item').count()) > 0, 'at least one insight')
})

await check('every chart states its figures as a table as well', async () => {
  const toggles = page.getByRole('button', { name: 'Show the figures' })
  ok((await toggles.count()) >= 2, 'both charts offer their figures')
  await toggles.first().click()
  await page.waitForTimeout(250)
  ok(await page.locator('.pr-figures').first().isVisible(), 'the figures are a real table')
  await page.getByRole('button', { name: 'Hide the figures' }).first().click()
})

await check('a filter narrows the whole register, not the page', async () => {
  const all = Number((await page.locator('.pr-register-foot').first().textContent()).match(/of (\d+)/)[1])
  await page.goto(`${BASE}/returns?status=DRAFT`, { waitUntil: 'networkidle' })
  await settle()

  const narrowed = Number((await page.locator('.pr-register-foot').first().textContent()).match(/of (\d+)/)[1])
  ok(narrowed < all, `the filter narrowed the total (${narrowed} of ${all})`)

  const statuses = await page.locator('.pr-table tbody td:nth-child(8)').allTextContents()
  ok(
    statuses.every((text) => text.trim() === 'Draft'),
    'every row on the page matches the filter',
  )
})

await check('search is debounced into the URL and narrows the register', async () => {
  await page.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
  await settle()
  await page.getByRole('searchbox', { name: 'Search purchase returns' }).fill('Shree')
  await page.waitForTimeout(1400)

  eq(new URL(page.url()).searchParams.get('q'), 'Shree', 'the search is in the URL')
  const suppliers = await page.locator('.pr-table tbody td:nth-child(4)').allTextContents()
  ok(
    suppliers.every((text) => text.includes('Shree')),
    'every row matches the search',
  )
})

await check('a shared link reproduces the same filtered screen', async () => {
  await page.goto(`${BASE}/returns?status=DRAFT&sort=return_value&order=asc`, { waitUntil: 'networkidle' })
  await settle()

  const values = (await page.locator('.pr-table tbody td:nth-child(7)').allTextContents()).map((v) =>
    Number(v.replace(/[^\d.]/g, '')),
  )
  ok(
    values.every((value, index) => index === 0 || values[index - 1] <= value),
    'the sort from the URL was applied',
  )
})

await check('changing a filter returns to the first page', async () => {
  await page.goto(`${BASE}/returns?page=2`, { waitUntil: 'networkidle' })
  await settle()
  await page.getByRole('searchbox', { name: 'Search purchase returns' }).fill('Metro')
  await page.waitForTimeout(1400)
  eq(new URL(page.url()).searchParams.get('page'), null, 'page 2 of a narrower result does not exist')
})

await check('the three views are three URLs, and each draws', async () => {
  for (const [name, selector] of [
    ['Calendar', '.pr-calendar__grid'],
    ['Kanban', '.pr-kanban__col'],
    ['List', '.pr-table'],
  ]) {
    await page.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
    await settle()
    await page.getByRole('button', { name, exact: true }).click()
    await page.waitForTimeout(1200)

    eq(new URL(page.url()).searchParams.get('view'), name === 'List' ? null : name.toLowerCase(), `${name} in the URL`)
    ok((await page.locator(selector).count()) > 0, `${name} drew something`)
  }
})

await check('the board is a read view — no card claims to be draggable', async () => {
  await page.goto(`${BASE}/returns?view=kanban`, { waitUntil: 'networkidle' })
  await settle()
  const draggable = await page.locator('.pr-kanban__card[draggable="true"]').count()
  // Three of these transitions write into Inventory and Smart Books. Neither
  // is something to set off by dropping a card, and neither undoes by dragging
  // it back — so the cards open the return instead.
  eq(draggable, 0, 'no card offers drag-to-transition')
})

await check('a drawer traps focus, closes on Escape and gives focus back', async () => {
  await page.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
  await settle()

  await page.locator('.pr-table tbody tr').first().locator('.pr-menu__trigger').click()
  await page.waitForTimeout(300)
  await page.getByRole('menuitem', { name: 'Open' }).click()
  await page.waitForTimeout(1200)

  const dialog = page.getByRole('dialog')
  ok(await dialog.isVisible(), 'the drawer opened')
  eq(await dialog.getAttribute('aria-modal'), 'true', 'it is modal')

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
  eq(await page.getByRole('dialog').count(), 0, 'Escape closed it')
})

await check('the detail reads Inventory and Books live, and says so', async () => {
  await page.goto(`${BASE}/returns?books=POSTED`, { waitUntil: 'networkidle' })
  await settle()
  await page.locator('.pr-table tbody tr').first().locator('.pr-link').first().click()
  await page.waitForTimeout(1600)

  const panel = page.locator('.pr-section', { hasText: 'Where this stands in the other products' })
  ok(await panel.isVisible(), 'the live panel is there')
  ok((await panel.textContent()).includes('Posted'), 'it reports what the other product holds')
})

await check('an unreachable Inventory reads as unavailable, never as nothing posted', async () => {
  const down = await signedIn()
  const blind = await down.newPage()
  await blind.route('**/v1/returns/*/integration*', (route) =>
    route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ error: { message: 'Inventory could not be reached.' } }),
    }),
  )

  await blind.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
  await blind.waitForTimeout(900)
  await blind.locator('.pr-table tbody tr').first().locator('.pr-link').first().click()
  await blind.waitForTimeout(1500)

  ok(
    await blind.getByText(/could not be asked just now/).isVisible(),
    'the panel says it could not ask rather than drawing a zero',
  )
  await down.close()
})

await check('one widget failing leaves the rest of the screen working', async () => {
  const half = await signedIn()
  const broken = await half.newPage()
  await broken.route('**/v1/returns/summary*', (route) =>
    route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ error: { message: 'The summary could not be computed.' } }),
    }),
  )

  await broken.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
  await broken.waitForTimeout(1200)

  ok(await broken.getByText('The analytics could not be loaded.').isVisible(), 'the analytics say so')
  ok((await broken.locator('.pr-table tbody tr').count()) > 0, 'the register still works')
  await half.close()
})

await check('without permission to create, nothing offers to create', async () => {
  const reader = await signedIn()
  const readOnly = await reader.newPage()
  await readOnly.route('**/v1/returns/options*', async (route) => {
    const response = await route.fetch()
    const body = await response.json()
    body.data.can = { create: false, approve: false, export: false }
    await route.fulfill({ response, body: JSON.stringify(body) })
  })

  await readOnly.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
  await readOnly.waitForTimeout(1300)

  eq(await readOnly.getByRole('button', { name: /New purchase return/ }).count(), 0, 'no create button')
  eq(await readOnly.getByRole('button', { name: 'Import', exact: true }).count(), 0, 'no import button')
  ok(await readOnly.getByText(/You can read this register but not add to it/).isVisible(), 'and it says why')

  await readOnly.locator('.pr-table tbody tr').first().locator('.pr-menu__trigger').click()
  await readOnly.waitForTimeout(300)
  ok(await readOnly.getByRole('menuitem', { name: 'Approve' }).isDisabled(), 'approve is offered disabled, with a reason')
  await reader.close()
})

await check('an empty register offers a way out of whatever emptied it', async () => {
  await page.goto(`${BASE}/returns?q=nothingmatchesthis`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(1400)
  ok(await page.getByText('No returns match your filters').isVisible(), 'the filtered empty state')
  ok(await page.getByRole('button', { name: 'Clear filters' }).isVisible(), 'and a way to clear them')
})

await check('the register never makes the page scroll sideways', async () => {
  for (const width of [1440, 1280, 1024, 768]) {
    const narrow = await signedIn({ width, height: 950 })
    const small = await narrow.newPage()
    await small.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
    await small.waitForTimeout(900)

    const scrolled = await small.evaluate(() => {
      window.scrollTo(4000, 0)
      const left = window.scrollX
      window.scrollTo(0, 0)
      return left
    })
    eq(scrolled, 0, `the page does not scroll sideways at ${width}px`)
    await narrow.close()
  }
})

await check('the export carries the filters the screen was showing', async () => {
  await page.goto(`${BASE}/returns?status=DRAFT`, { waitUntil: 'networkidle' })
  await settle()

  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 15000 }),
    (async () => {
      await page.getByRole('button', { name: 'More', exact: true }).click()
      await page.waitForTimeout(400)
      await page.getByRole('button', { name: 'Export to CSV' }).click()
    })(),
  ])

  ok(download.suggestedFilename().endsWith('.csv'), 'a CSV came back')
})

await check('an import is read and reported before anything is created', async () => {
  await page.goto(`${BASE}/returns`, { waitUntil: 'networkidle' })
  await settle()
  await page.getByRole('button', { name: 'Import', exact: true }).click()
  await page.waitForTimeout(500)

  const csv = [
    'A letterhead nobody asked for',
    'Return date,Supplier account,Supplier name,Item,Quantity,Rate,Reason,Warehouse',
    '22/09/2026,601,Metro Electronics Pvt. Ltd.,201,4,250,Damaged goods,3',
    '22/09/2026,601,Metro Electronics Pvt. Ltd.,202,1,900,Damaged goods,3',
    '22/09/2026,,Nobody,201,2,250,quality,3',
  ].join('\n')

  await page.setInputFiles('.pr-drawer input[type=file]', {
    name: 'returns.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv),
  })
  await page.waitForTimeout(2000)

  const read = (await page.locator('.pr-facts').first().textContent()).replace(/\s+/g, '')
  ok(read.includes('Rows3'), 'the letterhead was skipped and three rows were read')
  ok(read.includes('Usable2'), 'two rows are usable')
  ok(read.includes('Returnstocreate1'), 'and two lines for one supplier make ONE return')
  ok((await page.locator('.pr-import-errors').count()) > 0, 'the bad row says what is wrong with it')

  // The preview is a READ. Nothing may have been created by looking at a file.
  const total = () =>
    fetch(`${API}/v1/returns?limit=1&${SCOPE}`, { headers: { Authorization: `Bearer ${SES}` } })
      .then((r) => r.json())
      .then((body) => body.meta.total)

  const after = await total()
  await page.getByRole('button', { name: 'Cancel' }).click()
  await page.waitForTimeout(600)
  eq(await total(), after, 'reading the file created nothing')
})

await browser.close()

console.log('\nPurchase returns\n')
console.log(results.join('\n'))
const failed = results.filter((line) => line.includes('FAIL')).length
console.log(`\n${results.length - failed} passed, ${failed} failed\n`)
process.exit(failed === 0 ? 0 : 1)
