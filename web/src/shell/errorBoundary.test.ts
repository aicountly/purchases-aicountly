/**
 * The app-level error boundary's words (launch-readiness P1-P6): a quotable reference, a recovery,
 * and never the error's message or stack.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { errorView, newErrorId } from './errorBoundary.ts'

describe('errorBoundary', () => {
  it('makes a short, quotable reference', () => {
    const id = newErrorId(Date.UTC(2026, 9, 23), () => 0.5)
    assert.match(id, /^PUR-[0-9A-Z]+-[0-9A-Z]{4}$/)
    assert.equal(id, newErrorId(Date.UTC(2026, 9, 23), () => 0.5), 'deterministic for the same inputs')
    assert.notEqual(newErrorId(1), newErrorId(2), 'differs in time')
  })

  it('never shows the error message or stack', () => {
    const error = new TypeError("Cannot read properties of undefined (reading 'lines') at PurchaseOrderDetail (index-abc.js:1:2)")
    const view = errorView(error, 'PUR-X-ABCD')
    const shown = `${view.title} ${view.body} ${view.reference}`
    assert.ok(!shown.includes('Cannot read'), 'no message')
    assert.ok(!shown.includes('index-abc.js'), 'no stack')
    assert.equal(view.reference, 'PUR-X-ABCD')
    assert.equal(view.title, 'This screen could not be shown')
    assert.match(view.body, /Nothing was saved/)
  })

  it('says a stale build is fixed by reloading', () => {
    const chunk = new Error('Failed to fetch dynamically imported module: https://purchase.aicountly.com/assets/access-1.js')
    assert.equal(errorView(chunk, 'PUR-1').title, 'Purchases was updated')
    const named = Object.assign(new Error('x'), { name: 'ChunkLoadError' })
    assert.equal(errorView(named, 'PUR-2').title, 'Purchases was updated')
    assert.equal(errorView('not an error', 'PUR-3').title, 'This screen could not be shown')
  })
})
