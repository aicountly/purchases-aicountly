import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import {
  BILL_GST_KEYS,
  GST_KEYS,
  advancedActiveCount,
  describeAmountRange,
  gstUnavailableMessage,
  normalizeAmountBound,
  normalizeSupplierId,
  orderedAmountRange,
  parseGstKeys,
  quickFilterGroups,
  readFilterMeta,
  readRegisterFilters,
  registerQuery,
  toggleGstKey,
  withAdvanced,
  withGst,
} from './registerFilters.ts'

describe('register GST quick filters', () => {
  it("uses Books' keys, in Books' order", () => {
    assert.deepEqual(GST_KEYS, ['local', 'interstate', 'overseas', 'registered', 'unregistered', 'composition', 'sez', 'exempt', 'rcm'])
  })

  it('offers on the bills list only the chips its own rows answer', () => {
    const groups = quickFilterGroups(BILL_GST_KEYS)
    assert.deepEqual(groups.map((g) => g.key), ['tax'])
    assert.deepEqual(groups[0].options.map((o) => [o.value, o.label]), [['rcm', 'Reverse charge']])
    assert.deepEqual(quickFilterGroups([]), [])
  })

  it('reads ?gst= as known keys, in display order, each once; typos dropped', () => {
    assert.deepEqual(parseGstKeys('rcm, LOCAL,rcm,nonsense,,'), ['local', 'rcm'])
    assert.deepEqual(parseGstKeys(null), [])
    assert.deepEqual(parseGstKeys('rcm,local', BILL_GST_KEYS), ['rcm'])
  })

  it('toggles a chip on and off, keeping display order', () => {
    assert.deepEqual(toggleGstKey(['rcm'], 'local'), ['local', 'rcm'])
    assert.deepEqual(toggleGstKey(['local', 'rcm'], 'rcm'), ['local'])
  })

  it('writes the chips to the URL and keeps every other parameter', () => {
    const params = new URLSearchParams('status=POSTED&supplier_id=7')
    assert.equal(withGst(params, ['rcm']).toString(), 'status=POSTED&supplier_id=7&gst=rcm')
    assert.equal(withGst(new URLSearchParams('gst=rcm&status=POSTED'), []).toString(), 'status=POSTED')
    // The input is not mutated: React Router's params object stays as it was.
    assert.equal(params.toString(), 'status=POSTED&supplier_id=7')
  })

  it('reads back what the server applied and what it could not', () => {
    assert.deepEqual(readFilterMeta({ total: 3, filters: { gst: ['rcm'], gst_unavailable: ['local', 7] } }), { gst: ['rcm'], gstUnavailable: ['local'] })
    assert.deepEqual(readFilterMeta(undefined), { gst: [], gstUnavailable: [] })
    assert.deepEqual(readFilterMeta({ filters: 'x' }), { gst: [], gstUnavailable: [] })
  })

  it('says why a key was not applied, and says nothing when every key was', () => {
    assert.equal(gstUnavailableMessage([]), '')
    const message = gstUnavailableMessage(['local', 'registered'])
    assert.match(message, /^Local, Registered cannot be filtered here, so they were not applied\./)
    assert.match(gstUnavailableMessage(['exempt']), /^Exempt \/ Nil \/ Non-GST cannot be filtered here, so it was not applied\./)
  })
})

describe('register advanced filters', () => {
  it('accepts non-negative decimals with commas and spaces; anything else is no bound', () => {
    assert.equal(normalizeAmountBound('1,50,000'), '150000')
    assert.equal(normalizeAmountBound(' 2 500.50 '), '2500.50')
    assert.equal(normalizeAmountBound('0'), '0')
    for (const garbage of ['-5', 'abc', '5e3', '1.', '.5', '1.2.3', '', null, undefined]) {
      assert.equal(normalizeAmountBound(garbage), '', String(garbage))
    }
  })

  it('swaps bounds entered the wrong way round', () => {
    assert.deepEqual(orderedAmountRange('50,000', '10000'), ['10000', '50000'])
    assert.deepEqual(orderedAmountRange('100', ''), ['100', ''])
    assert.deepEqual(orderedAmountRange('x', '9'), ['', '9'])
  })

  it('takes only a positive whole-number supplier id', () => {
    assert.equal(normalizeSupplierId('601'), '601')
    assert.equal(normalizeSupplierId('0'), '')
    assert.equal(normalizeSupplierId('12abc'), '')
    assert.equal(normalizeSupplierId(null), '')
  })

  it('reads the URL into the state the list asks the server for', () => {
    const state = readRegisterFilters(new URLSearchParams('gst=rcm,local&amount_min=90,000&amount_max=10000&supplier_id=601&status=POSTED'))
    assert.deepEqual(state, { gst: ['local', 'rcm'], amountMin: '10000', amountMax: '90000', supplierId: '601' })
    // Every known key goes to the server, so it can say which it could not apply.
    assert.deepEqual(registerQuery(state), { gst: 'local,rcm', amount_min: '10000', amount_max: '90000', supplier_account_id: '601' })
    assert.deepEqual(registerQuery(readRegisterFilters(new URLSearchParams('amount_min=abc'))), {
      gst: undefined,
      amount_min: undefined,
      amount_max: undefined,
      supplier_account_id: undefined,
    })
  })

  it('applies the panel to the URL: normalised, swapped, empty ones removed', () => {
    const before = new URLSearchParams('status=MATCHED&amount_min=5&gst=rcm')
    const after = withAdvanced(before, { amountMin: '2,00,000', amountMax: '1,000', supplierId: '42' })
    assert.equal(after.get('amount_min'), '1000')
    assert.equal(after.get('amount_max'), '200000')
    assert.equal(after.get('supplier_id'), '42')
    assert.equal(after.get('status'), 'MATCHED')
    assert.equal(after.get('gst'), 'rcm')
    const cleared = withAdvanced(after, { amountMin: '', amountMax: 'junk', supplierId: '' })
    assert.equal(cleared.toString(), 'status=MATCHED&gst=rcm')
  })

  it('counts the amount range once and the supplier once on the badge', () => {
    assert.equal(advancedActiveCount({ amountMin: '', amountMax: '', supplierId: '' }), 0)
    assert.equal(advancedActiveCount({ amountMin: '1', amountMax: '9', supplierId: '' }), 1)
    assert.equal(advancedActiveCount({ amountMin: '', amountMax: '9', supplierId: '3' }), 2)
  })

  it('describes the range in Indian grouping', () => {
    assert.equal(describeAmountRange('150000', ''), 'Amount ≥ 1,50,000')
    assert.equal(describeAmountRange('', '2500.5'), 'Amount ≤ 2,500.5')
    assert.equal(describeAmountRange('10', '20'), 'Amount 10 – 20')
    assert.equal(describeAmountRange('', ''), '')
  })
})
