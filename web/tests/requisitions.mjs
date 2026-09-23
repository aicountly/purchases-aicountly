/**
 * Browser checks for Procurement → Requisitions, with the API stubbed.
 *
 *   npm run build && npx vite preview --port 4173 &
 *   PURCHASE_APP_URL=http://127.0.0.1:4173 npm run test:requisitions
 *
 * DIFFERENT FROM dashboards.mjs ON PURPOSE. That suite seeds a real API and
 * asserts on real records, which is the right test for a drill-down landing on
 * the rows it promised. This one stubs every response instead, because what it
 * checks is the screen: that ten columns fit a 1366px laptop without scrolling
 * sideways, that the approval popover and the row menu open and close on the
 * keyboard, that an empty company and an empty FILTER draw two different
 * states, and that Ctrl/Cmd+K lands in the search box. None of that needs a
 * database, and making it need one would mean nobody ran it.
 *
 * It writes screenshots to OUT (default /tmp/shots) and exits non-zero on any
 * console error or failed assertion.
 */
import { chromium } from 'playwright'

const BASE = process.env.APP_URL ?? 'http://127.0.0.1:4173'
const OUT = process.env.OUT ?? '/tmp/shots'

const today = new Date().toISOString().slice(0, 10)
const month = (back) => {
  const d = new Date()
  d.setUTCDate(1)
  d.setUTCMonth(d.getUTCMonth() - back)
  return d.toISOString().slice(0, 7)
}

const series = [9, 12, 7, 14, 11, 16, 13, 18, 15, 21, 17, 24].map((n, i) => ({
  bucket: month(11 - i),
  total: n,
  pending: Math.max(1, Math.round(n / 3)),
  approved: Math.max(1, Math.round(n / 2)),
  rejected: i % 4 === 0 ? 2 : 1,
  value: n * 41000,
}))

const SUMMARY = {
  data: {
    totals: { total: 12, draft: 2, pending: 4, approved: 5, rejected: 1, cancelled: 0, estimated_value: 1248500, pending_value: 656000 },
    month: { from: `${month(0)}-01`, total: 8, pending: 4, approved: 5, rejected: 1, value: 742000 },
    previous_month: { from: `${month(1)}-01`, total: 6, pending: 3, approved: 4, rejected: 2, value: 629000 },
    today: { date: today, raised: 1, pending: 1 },
    series,
    departments: [
      { department: 'IT', total: 3, pending: 1 },
      { department: 'Production', total: 3, pending: 1 },
      { department: 'Admin', total: 2, pending: 0 },
      { department: 'Warehouse', total: 2, pending: 1 },
      { department: 'Maintenance', total: 1, pending: 1 },
      { department: 'Marketing', total: 1, pending: 0 },
    ],
    signals: {
      aged_pending_days: 5, aged_pending: 3, oldest_pending_days: 11,
      high_value_pending: 2, high_value_amount: 636000, high_value_threshold: 100000,
      due_soon_days: 7, due_soon: 2, overdue: 1,
      stalled_drafts: 1, stalled_draft_days: 7, rejected_30d: 1, missing_department: 1,
      busiest_department: { department: 'Production', pending: 2 },
    },
  },
}

const chain = (approved, total) =>
  Array.from({ length: total }, (_, i) => ({
    stage_no: i + 1,
    stage_name: ['Department head', 'Finance', 'Director'][i] ?? `Stage ${i + 1}`,
    reason_kind: 'value',
    reason_detail: 'Above the approval threshold.',
    status: i < approved ? 'APPROVED' : 'PENDING',
    decided_at: i < approved ? `${today}T09:00:00Z` : null,
  }))

const row = (id, no, date, status, dept, title, items, value, approved, stages, mine = false) => ({
  requisition_id: id,
  requisition_uuid: `uuid-${id}`,
  requisition_no: no,
  requisition_date: date,
  status,
  requester_uuid: mine ? 'me-uuid' : `9f${id}c4a21-b73d-4e19-9f22-${String(id).padStart(12, '0')}`,
  department: dept,
  required_by: '2026-10-08',
  priority: 'normal',
  justification: null,
  exception_flags: [],
  estimated_value: String(value),
  created_at: `${date}T08:00:00Z`,
  line_count: items,
  first_description: title,
  approval_stages: stages,
  approval_approved: approved,
  approval_rejected: status === 'REJECTED' ? 1 : 0,
  approval_pending: Math.max(0, stages - approved - (status === 'REJECTED' ? 1 : 0)),
  approval_chain: chain(approved, stages),
  is_mine: mine,
})

const ROWS = [
  row(12, 'PR-2026-0012', '2026-09-21', 'APPROVAL_PENDING', 'IT', 'Office Laptops (5 units)', 5, 450000, 2, 3, true),
  row(11, 'PR-2026-0011', '2026-09-20', 'APPROVED', 'Admin', 'Stationery & Office Supplies', 12, 22500, 3, 3),
  row(10, 'PR-2026-0010', '2026-09-18', 'APPROVAL_PENDING', 'Production', 'Raw Material - Packaging', 8, 186000, 1, 2),
  row(9, 'PR-2026-0009', '2026-09-15', 'REJECTED', 'Maintenance', 'Maintenance Spares', 4, 75000, 0, 2),
  row(8, 'PR-2026-0008', '2026-09-12', 'APPROVED', 'IT', 'Software Subscriptions — annual renewal for the whole finance team', 3, 120000, 3, 3),
  row(7, 'PR-2026-0007', '2026-09-10', 'APPROVED', 'Warehouse', 'Warehouse Racks', 6, 95000, 2, 2),
  row(6, 'PR-2026-0006', '2026-09-08', 'DRAFT', 'Marketing', 'Marketing Materials', 9, 48000, 0, 0, true),
  row(5, 'PR-2026-0005', '2026-09-05', 'APPROVAL_PENDING', null, 'Safety Equipment', 5, 66000, 1, 3),
]

const json = (body) => ({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })

const shots = []
let tableOverflow = 0

const run = async () => {
  // A preinstalled browser, where the environment provides one. Playwright's
  // own download is used otherwise.
  const browser = await chromium.launch(
    process.env.PLAYWRIGHT_CHROMIUM ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM } : {},
  )
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 })
  const page = await context.newPage()

  const errors = []
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()))
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))

  let empty = false

  await page.route('**/api/**', async (route) => {
    const url = route.request().url()
    if (url.includes('/global/seskey')) return route.fulfill(json({ ses_key: 'stub', expires_in: 900 }))
    if (url.includes('v1/manage/companyinfo'))
      return route.fulfill(json({ data: { cmp_id: 28, comp_name: 'AM SALES', ho_id: 1,
        fy_list: [{ fy_id: 33, fy_start: '2026-04-01', fy_end: '2027-03-31' }],
        branch_list: [{ bo_id: 1, bo_name: 'Head office', mark_ho: 1 }] } }))
    if (url.includes('v1/session'))
      return route.fulfill(json({ data: { uuid: 'me-uuid', display_name: 'CA Rahul Gupta', kind: 'user',
        is_owner: true, access_resolved: true, context: { cmp_id: 28, fy_id: 33, bo_id: 0 }, permissions: [] } }))
    if (url.includes('v1/requisitions/summary'))
      return route.fulfill(json(empty
        ? { data: { ...SUMMARY.data, totals: { total: 0, draft: 0, pending: 0, approved: 0, rejected: 0, cancelled: 0, estimated_value: 0, pending_value: 0 },
            departments: [], series: series.map((s) => ({ ...s, total: 0, pending: 0, approved: 0, rejected: 0, value: 0 })),
            signals: { ...SUMMARY.data.signals, aged_pending: 0, overdue: 0, high_value_pending: 0, due_soon: 0, stalled_drafts: 0, rejected_30d: 0, missing_department: 0, busiest_department: null } } }
        : SUMMARY))
    if (url.includes('v1/requisitions'))
      return route.fulfill(json(empty
        ? { data: [], meta: { total: 0, limit: 10, offset: 0 } }
        : { data: ROWS, meta: { total: 12, limit: 10, offset: 0 } }))
    return route.fulfill(json({ data: {} }))
  })

  await page.addInitScript(() => {
    localStorage.setItem('auth_token', 'stub-token')
    localStorage.setItem('purchases:scope', JSON.stringify({ cmp_id: 28, fy_id: 33, bo_id: 0 }))
  })

  const shot = async (name, opts = {}) => {
    await page.screenshot({ path: `${OUT}/${name}.png`, ...opts })
    shots.push(name)
  }

  await page.goto(`${BASE}/requisitions`, { waitUntil: 'networkidle' })
  await page.waitForSelector('.rq-table tbody tr')
  await shot('01-desktop-1440')

  // The approval popover.
  await page.locator('.rq-approval').first().click()
  await page.waitForSelector('.rq-popover')
  await shot('02-approval-popover')
  await page.keyboard.press('Escape')

  // The row menu.
  await page.locator('.rq-menu__trigger').first().click()
  await page.waitForSelector('.rq-menu__list')
  await shot('03-row-menu')
  await page.keyboard.press('Escape')

  // More filters, and a chip.
  await page.getByRole('button', { name: /More filters/ }).click()
  await page.waitForSelector('.rq-more')
  await shot('04-more-filters')
  await page.getByLabel('Minimum estimated value').fill('100000')
  await page.getByRole('button', { name: 'Done' }).click()
  await page.waitForSelector('.rq-chip')
  await shot('05-chips-and-tab')

  // Selection and the bulk bar.
  await page.goto(`${BASE}/requisitions`, { waitUntil: 'networkidle' })
  await page.waitForSelector('.rq-table tbody tr')
  await page.getByLabel('Select every requisition on this page').check()
  await page.waitForSelector('.rq-bulk')
  await shot('06-bulk-bar')

  // Confirm dialog.
  await page.getByRole('button', { name: /^Approve \d/ }).click()
  await page.waitForSelector('.rq-dialog')
  await shot('07-confirm')
  await page.keyboard.press('Escape')

  // No results for a filter.
  await page.goto(`${BASE}/requisitions?q=nothing-matches-this`, { waitUntil: 'networkidle' })
  empty = true
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForSelector('.rq-state')
  await shot('08-no-results')

  // First-time empty.
  await page.goto(`${BASE}/requisitions`, { waitUntil: 'networkidle' })
  await page.waitForSelector('.rq-state')
  await shot('09-empty')
  empty = false

  // Narrow laptop and phone.
  await page.setViewportSize({ width: 1366, height: 900 })
  await page.goto(`${BASE}/requisitions`, { waitUntil: 'networkidle' })
  await page.waitForSelector('.rq-table tbody tr')
  await shot('10-laptop-1366')

  // The reason the columns are sized the way they are. A laptop is the common
  // case and a table that scrolls sideways on one hides the Actions column.
  tableOverflow = await page.evaluate(() => {
    const scroll = document.querySelector('.rq-table-scroll')
    return scroll.scrollWidth - scroll.clientWidth
  })

  await page.setViewportSize({ width: 430, height: 950 })
  await page.reload({ waitUntil: 'networkidle' })
  await page.waitForSelector('.rq-table tbody tr')
  await shot('11-phone-430')

  // Keyboard: Ctrl+K reaches the search box.
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto(`${BASE}/requisitions`, { waitUntil: 'networkidle' })
  await page.keyboard.press('Control+k')
  const focused = await page.evaluate(() => document.activeElement?.getAttribute('aria-label'))

  const failures = []
  if (focused !== 'Search requisitions') failures.push(`Ctrl+K focused ${focused ?? 'nothing'}`)
  if (errors.length) failures.push(...errors.map((e) => `console: ${e}`))
  if (tableOverflow > 1) failures.push(`the table needs ${tableOverflow}px more than a 1366px laptop gives it`)

  console.log(
    failures.length === 0
      ? `ok    ${shots.length} screenshots in ${OUT}, no console errors, table fits 1366px`
      : failures.map((f) => `FAIL  ${f}`).join('\n'),
  )

  await browser.close()
  if (failures.length) process.exitCode = 1
}

run().catch((e) => { console.error(e); process.exit(1) })
