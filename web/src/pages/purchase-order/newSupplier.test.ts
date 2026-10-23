/** The purchase order editor's "New supplier" form (launch-readiness P1-P8). */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { newIdempotencyKey, newSupplierBody, stateOfGstin } from './newSupplier.ts'

describe('newSupplier', () => {
  it('reads the state from a GSTIN', () => {
    assert.equal(stateOfGstin('27aapfu0939f1zv'), '27')
    assert.equal(stateOfGstin('29AAACS1234A1ZY'), '29')
    assert.equal(stateOfGstin('99AAACS1234A1ZY'), null)
    assert.equal(stateOfGstin('27AAPFU'), null)
  })

  it('sends a tidy body, the GSTIN deciding the state', () => {
    assert.deepEqual(newSupplierBody({ name: '  Konkan   Cement ', gstin: '27aapfu0939f1zv', stateCode: '', city: ' Pune ' }), {
      body: { name: 'Konkan Cement', gstin: '27AAPFU0939F1ZV', state_code: '27', city: 'Pune' },
    })
    assert.deepEqual(newSupplierBody({ name: 'Local Hardware', gstin: '', stateCode: '29', city: '' }), { body: { name: 'Local Hardware', state_code: '29' } })
  })

  it('says what to fix', () => {
    assert.equal('field' in newSupplierBody({ name: ' ', gstin: '', stateCode: '', city: '' }) ? 'name' : '', 'name')
    const bad = newSupplierBody({ name: 'X', gstin: '27AAPFU', stateCode: '', city: '' })
    assert.ok('field' in bad && bad.field === 'gstin')
    const clash = newSupplierBody({ name: 'X', gstin: '27AAPFU0939F1ZV', stateCode: '29', city: '' })
    assert.ok('field' in clash && clash.field === 'stateCode')
  })

  it('makes a key the server accepts (8-64 of letters, digits, - and _)', () => {
    const key = newIdempotencyKey(() => '0b0e8c7e-1111-4a4a-9c9c-000000000001')
    assert.match(key, /^[A-Za-z0-9_-]{8,64}$/)
    assert.notEqual(newIdempotencyKey(), newIdempotencyKey())
  })
})
