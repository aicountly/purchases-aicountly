/** The first-run checklist (launch-readiness P1-P8): what a new company still needs, in order. */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { countOf, firstRunSteps, needsFirstRun } from './firstRun.ts'

describe('firstRun', () => {
  it('lists supplier, item, order — each done once one exists', () => {
    const steps = firstRunSteps({ suppliers: 0, items: 3, orders: 0 }, { addSupplier: true, createOrder: true })
    assert.deepEqual(steps.map((s) => [s.key, s.done]), [['supplier', false], ['item', true], ['order', false]])
    assert.equal(steps[0].to, '/purchase-orders/new?new_supplier=1', 'a supplier is added from the order editor')
    assert.equal(steps[1].external, 'inventory', 'items are added in Inventory')
    assert.equal(steps[2].to, '/purchase-orders/new')
  })

  it('offers no link the reader may not use', () => {
    const steps = firstRunSteps({ suppliers: 0, items: 0, orders: 0 }, { addSupplier: false, createOrder: false })
    assert.equal(steps[0].to, null)
    assert.equal(steps[2].to, null)
  })

  it('is shown only while something is known to be missing; an unreadable count is not "to do"', () => {
    assert.equal(needsFirstRun({ suppliers: 1, items: 1, orders: 1 }), false)
    assert.equal(needsFirstRun({ suppliers: 0, items: 1, orders: 1 }), true)
    assert.equal(needsFirstRun({ suppliers: null, items: null, orders: null }), false, 'Books or Inventory down: no false alarm')
  })

  it('counts a list answer by its data array', () => {
    assert.equal(countOf({ data: [{}, {}] }), 2)
    assert.equal(countOf({ data: [] }), 0)
    assert.equal(countOf({ error: 'x' }), null)
    assert.equal(countOf(null), null)
  })
})
