/**
 * A 402 PLAN_LIMIT_REACHED from the Purchases API is one sentence naming the limit and who can
 * change it (CONTRACTS §3.6). api.ts turns every such refusal into this sentence.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { PLAN_LIMIT_CODE, planLimitFromError, planLimitMessage } from './planLimit.ts'

describe('planLimit', () => {
  it('reads the refusal by its code, or a bare 402, and nothing else', () => {
    assert.deepEqual(planLimitFromError(402, PLAN_LIMIT_CODE, { code: 'purchases.max_purchase_orders_per_month', limit: 5, used: 5 }), {
      code: 'purchases.max_purchase_orders_per_month',
      limit: 5,
      used: 5,
    })
    assert.deepEqual(planLimitFromError(402, undefined, {}), { code: '', limit: 0, used: null })
    assert.equal(planLimitFromError(403, 'forbidden', { code: 'purchases.max_purchase_orders_per_month' }), null)
    assert.equal(planLimitFromError(402, 'something_else', {}), null)
  })

  it('names the limit in words, and who can change it', () => {
    assert.equal(
      planLimitMessage({ code: 'purchases.max_purchase_orders_per_month', limit: 40, used: 40 }),
      'Your plan allows 40 purchase orders a month — ask the account owner to upgrade in Manage.',
    )
    assert.equal(
      planLimitMessage({ code: 'purchases.max_purchase_orders_per_month', limit: 1, used: 1 }),
      'Your plan allows 1 purchase order a month — ask the account owner to upgrade in Manage.',
    )
    assert.match(planLimitMessage(null), /ask the account owner to upgrade in Manage/)
  })
})
