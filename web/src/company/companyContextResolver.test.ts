import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import {
  getCompanyDefault,
  NO_BRANCH_ERROR,
  NO_FY_ERROR,
  pickHeadOfficeBranch,
  pickLatestFy,
  resolveCompanyContext,
  resolveCompanyScope,
  saveCompanyDefault,
} from './companyContextResolver.ts'
import type { CompanyInfo } from './manageShapes.ts'

// Node has no browser storage; the resolver reads the global one, as the app does.
const store = new Map<string, string>()
Object.defineProperty(globalThis, 'localStorage', {
  configurable: true,
  value: {
    getItem: (k: string) => store.get(k) ?? null,
    setItem: (k: string, v: string) => void store.set(k, v),
    removeItem: (k: string) => void store.delete(k),
    clear: () => store.clear(),
  },
})

const fy = (fyId: number, start: string, end: string) => ({ fyId, start, end, label: `FY ${fyId}`, defaultValuationMethod: null })
const bo = (boId: number, name: string, isHeadOffice = false) => ({ boId, name, isHeadOffice })

// Manage's order is not trusted: the latest year is found by its dates.
const INFO: CompanyInfo = {
  cmpId: 88,
  name: 'Deccan Traders',
  fyList: [fy(30, '2024-04-01', '2025-03-31'), fy(31, '2025-04-01', '2026-03-31'), fy(29, '2023-04-01', '2024-03-31')],
  branches: [bo(7, 'Pune'), bo(8, 'Head office')],
  hoId: 8,
  addressLines: [],
  gstin: '',
}

beforeEach(() => store.clear())

describe('opening a company — one click, no year/branch step', () => {
  it('opens on the latest financial year and the head office', async () => {
    const asked: number[] = []
    const result = await resolveCompanyScope(88, async (cmpId) => {
      asked.push(cmpId)
      return INFO
    })
    assert.deepEqual(asked, [88])
    assert.deepEqual(result, { scope: { cmp_id: 88, fy_id: 31, bo_id: 8 }, error: null })
  })

  it('opens on the saved default for that company', async () => {
    saveCompanyDefault(88, 30, 7)
    const result = await resolveCompanyScope(88, async () => INFO)
    assert.deepEqual(result.scope, { cmp_id: 88, fy_id: 30, bo_id: 7 })
  })

  it('honours a saved "All branches" default, which Purchases supports', async () => {
    saveCompanyDefault(88, 31, 0)
    const result = await resolveCompanyScope(88, async () => INFO)
    assert.deepEqual(result.scope, { cmp_id: 88, fy_id: 31, bo_id: 0 })
  })

  it('ignores a saved default whose year or branch no longer exists', async () => {
    saveCompanyDefault(88, 99, 7)
    assert.deepEqual((await resolveCompanyScope(88, async () => INFO)).scope, { cmp_id: 88, fy_id: 31, bo_id: 8 })
    saveCompanyDefault(88, 30, 99)
    assert.deepEqual((await resolveCompanyScope(88, async () => INFO)).scope, { cmp_id: 88, fy_id: 31, bo_id: 8 })
  })

  it('explains a company with no financial year instead of opening it', async () => {
    const result = await resolveCompanyScope(88, async () => ({ ...INFO, fyList: [] }))
    assert.deepEqual(result, { scope: null, error: NO_FY_ERROR })
  })

  it('explains a company with no branch instead of opening it', async () => {
    const result = await resolveCompanyScope(88, async () => ({ ...INFO, branches: [] }))
    assert.deepEqual(result, { scope: null, error: NO_BRANCH_ERROR })
  })

  it('passes a failed Manage request through, so a refusal can be told from an outage', async () => {
    await assert.rejects(resolveCompanyScope(88, async () => { throw new Error('down') }), /down/)
  })
})

describe('the resolution rule', () => {
  it('latest year by end date, then start date', () => {
    assert.equal(pickLatestFy([fy(1, '2025-01-01', '2025-12-31'), fy(2, '2025-04-01', '2025-12-31')]), 2)
    assert.equal(pickLatestFy([]), null)
  })

  it('head office: ho_id when listed, else the flagged branch, else the first', () => {
    assert.equal(pickHeadOfficeBranch([bo(7, 'A'), bo(8, 'B')], 8), 8)
    assert.equal(pickHeadOfficeBranch([bo(7, 'A'), bo(8, 'B', true)], 99), 8)
    assert.equal(pickHeadOfficeBranch([bo(7, 'A'), bo(8, 'B')], null), 7)
    assert.equal(pickHeadOfficeBranch([], 8), null)
  })

  it('says where the answer came from', () => {
    const base = { fyList: INFO.fyList, branches: INFO.branches, hoId: 8 }
    assert.equal(resolveCompanyContext({ ...base, saved: null }).source, 'latest')
    assert.equal(resolveCompanyContext({ ...base, saved: { fy_id: 30, bo_id: 7 } }).source, 'saved')
  })

  it('stores the default per company and survives a broken entry', () => {
    assert.equal(saveCompanyDefault(88, 30, 7), true)
    assert.deepEqual(getCompanyDefault(88), { fy_id: 30, bo_id: 7 })
    assert.equal(store.get('aic_purchases_company_default:88'), '{"fy_id":"30","bo_id":"7"}')
    assert.equal(getCompanyDefault(89), null)
    store.set('aic_purchases_company_default:90', '{not json')
    assert.equal(getCompanyDefault(90), null)
  })
})
