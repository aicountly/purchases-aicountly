/**
 * Browser checks for the screens the 2026-10 remediation changed: receiving a partial delivery
 * as its own GRN, the order document and its sent/acknowledged trail, PO / direct / service
 * bills, short-close, returns, claims, approvals, the supplier's Contacts card and the
 * segregation-of-duties settings. Every page must render without a console error.
 *
 *   PURCHASE_APP_URL=http://127.0.0.1:5173 npm run test:remediation
 *
 * Needs the app, its API and the stub running — see docs/DEVELOPMENT.md. Screenshots go to
 * PURCHASE_SHOTS (default: the system temp directory).
 */
import { chromium } from 'playwright'
import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const BASE = process.env.PURCHASE_APP_URL ?? 'http://127.0.0.1:5173'
const API = process.env.PURCHASE_API_URL ?? 'http://127.0.0.1:8791'
const SCOPE = 'cmp_id=88&fy_id=6&bo_id=0'
const OUT = process.env.PURCHASE_SHOTS ?? join(tmpdir(), 'purchase-remediation')
mkdirSync(OUT, { recursive: true })

const call = async (method, path, body) => {
  const res = await fetch(`${API}/${path}${path.includes('?') ? '&' : '?'}${SCOPE}`, {
    method, headers: { Authorization: 'Bearer preview-ses-key', 'Content-Type': 'application/json' },
    ...(method === 'GET' ? {} : { body: JSON.stringify(body ?? {}) }),
  })
  const json = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${JSON.stringify(json).slice(0, 300)}`)
  return json.data
}

execFileSync('php', [new URL('../../server-php/tests/reset.php', import.meta.url).pathname], { stdio: 'inherit' })
await call('PUT', 'v1/settings', { po_approval_above_amount: 0 })
const po = await call('POST', 'v1/purchase-orders', {
  supplier_account_id: 501, supplier_name: 'Northern Distributors', po_date: '2026-09-20', promised_date: '2026-10-05', delivery_warehouse_id: 3,
  lines: [
    { item_id: 201, unit_id: 1, ordered_qty: 40, agreed_rate: 250, estimated_tax_pc: 18, warehouse_id: 3 },
    { item_id: 202, unit_id: 1, ordered_qty: 10, agreed_rate: 900, estimated_tax_pc: 18, warehouse_id: 3 },
  ],
})
await call('POST', `v1/purchase-orders/${po.po_id}/submit`)
await call('POST', `v1/purchase-orders/${po.po_id}/issue`)

const results = []
const errors = []
const check = async (name, fn) => {
  try { await fn(); results.push(`  ok    ${name}`) } catch (e) { results.push(`  FAIL  ${name}\n        ${e.message.split('\n')[0]}`) }
}
const ok = (c, what) => { if (!c) throw new Error(what) }

const executablePath = process.env.PURCHASE_CHROMIUM_PATH || undefined
const browser = await chromium.launch(executablePath ? { executablePath } : {})
const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 }, acceptDownloads: true })
await ctx.addInitScript(() => {
  localStorage.setItem('auth_token', 'preview-auth-token')
  localStorage.setItem('purchases:scope', JSON.stringify({ cmp_id: 88, fy_id: 6, bo_id: 0 }))
})
const page = await ctx.newPage()
page.on('pageerror', (e) => errors.push(`pageerror ${page.url()}: ${e.message}`))
page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(`console ${page.url()}: ${m.text()}`) })
const shot = (n) => page.screenshot({ path: `${OUT}/${n}.png`, fullPage: true })
const settle = () => page.waitForTimeout(700)
const button = (name) => page.getByRole('button', { name, exact: true })

await check('an issued order offers Receive goods and records a partial delivery as its own GRN', async () => {
  await page.goto(`${BASE}/purchase-orders/${po.po_id}`, { waitUntil: 'networkidle' })
  await settle()
  await button('Receive goods').click()
  await settle()
  const accepted = page.getByLabel('Accepted', { exact: true })
  ok((await accepted.count()) === 2, `two lines to receive, got ${await accepted.count()}`)
  await accepted.nth(0).fill('15')
  await accepted.nth(1).fill('0')
  await page.getByLabel('Supplier delivery challan no.').fill('DC-771')
  await shot('01-receive-panel')
  await button('Record this delivery').click()
  await settle()
  await page.waitForTimeout(800)
  await shot('02-after-receipt')
  const text = await page.locator('main').innerText()
  ok(/PARTIAL/i.test(text) || /partially/i.test(text), 'order shows a partial receipt status')
  const receipts = await call('GET', `v1/purchase-orders/${po.po_id}`)
  ok(receipts.lines[0].received_qty && Number(receipts.lines[0].received_qty) === 15, `line 1 received 15, got ${receipts.lines[0].received_qty}`)
})

await check('the order document downloads as a PDF and sending is recorded with its evidence', async () => {
  await page.goto(`${BASE}/purchase-orders/${po.po_id}`, { waitUntil: 'networkidle' })
  await settle()
  const [download] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.getByRole('button', { name: 'Purchase order PDF' }).click()])
  const path = `${OUT}/po.pdf`
  await download.saveAs(path)
  const head = execFileSync('head', ['-c', '5', path]).toString()
  ok(head === '%PDF-', `a real PDF, got ${JSON.stringify(head)}`)
  await button('Record how it was sent').click()
  await page.getByLabel(/^To whom/).fill('orders@northern.example')
  await button('Record as sent').click()
  await settle()
  await button("Record the supplier's acknowledgement").click()
  await page.getByLabel(/^Evidence/).fill('Reply from Anil, 21 Sep 10:14, ref NDL/SO/88')
  await button('Record acknowledgement').click()
  await settle()
  await shot('03-communications')
  const fresh = await call('GET', `v1/purchase-orders/${po.po_id}`)
  ok(fresh.sent_at && fresh.acknowledged_at, 'sent and acknowledged are both recorded')
})

await check('the bill for received goods opens from the order and saves for matching', async () => {
  await page.goto(`${BASE}/bills/new?po_id=${po.po_id}`, { waitUntil: 'networkidle' })
  await settle()
  await page.getByLabel(/^Supplier invoice number/).fill('NDL/INV/4410')
  await page.getByLabel(/^Invoice date/).fill('2026-09-22')
  await shot('04-bill-editor')
  await button('Save and match').click()
  await page.waitForURL(/\/bills\/\d+/, { timeout: 15000 })
  await settle()
  await shot('05-bill-detail')
  ok(await page.getByText('Post to Smart Books').count() > 0 || await page.getByText(/exception/i).count() > 0, 'the bill offers posting or shows its match')
})

await check('a bill without an order offers a service line booked to a Books ledger', async () => {
  await page.goto(`${BASE}/bills/new`, { waitUntil: 'networkidle' })
  await settle()
  await shot('06-direct-bill')
  const text = await page.locator('main').innerText()
  ok(/service line/i.test(text), 'service lines are offered')
})

await check('short-closing asks for a reason', async () => {
  await page.goto(`${BASE}/purchase-orders/${po.po_id}`, { waitUntil: 'networkidle' })
  await settle()
  await button('Short-close').first().click()
  await settle()
  await shot('07-short-close')
  ok(await page.getByText('Short-close what will not be delivered').count() === 1 && await button('Short-close').last().isDisabled(), 'a reason is required before it can short-close')
})

for (const [name, path] of [['returns-new', `/returns/new?po_id=${po.po_id}`], ['returns', '/returns'], ['claims', '/claims'], ['approvals', '/approvals'], ['settings', '/settings'], ['bills', '/bills'], ['orders', '/purchase-orders']]) {
  await check(`${path} renders without errors`, async () => {
    const before = errors.length
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' })
    await settle()
    await shot(`10-${name}`)
    ok(errors.length === before, `errors: ${errors.slice(before).join(' | ')}`)
    ok(!/That page does not exist/.test(await page.locator('main').innerText()), 'route exists')
  })
}

await check('a supplier row opens the Contacts card, read live', async () => {
  await call('POST', 'v1/suppliers', { supplier_account_id: 501 })
  await page.goto(`${BASE}/suppliers`, { waitUntil: 'networkidle' })
  await settle()
  await page.getByText('#501').first().click()
  await settle()
  await page.waitForTimeout(800)
  await shot('11-supplier-contact')
  ok(await page.getByText('Contact at this supplier').count() === 1, 'the contact card is shown')
})

await check('the approval controls carry the segregation-of-duties policy and receipt tolerance', async () => {
  await page.goto(`${BASE}/settings/new-profile`, { waitUntil: 'networkidle' })
  await settle()
  await page.waitForTimeout(800)
  const text = await page.locator('body').innerText()
  await shot('12-settings')
  ok(/Who may decide on a document they raised/.test(text) && /Over-receipt tolerance/.test(text), 'SoD policy and tolerance visible')
})

await browser.close()
console.log(results.join('\n'))
console.log(`\n${results.filter((r) => r.includes(' ok ')).length} ok, ${results.filter((r) => r.includes('FAIL')).length} failed`)
if (errors.length) console.log('Console/page errors:\n  ' + [...new Set(errors)].join('\n  '))
process.exit(results.some((r) => r.includes('FAIL')) ? 1 : 0)
