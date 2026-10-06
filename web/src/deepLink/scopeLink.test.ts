import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { judgeLinkScope, readLinkScope, rememberDestination, safeDestination, takeDestination, withoutScopeParams } from './scopeLink.ts'

function memoryStorage() {
  const m = new Map<string, string>()
  return { getItem: (k: string) => m.get(k) ?? null, setItem: (k: string, v: string) => void m.set(k, v), removeItem: (k: string) => void m.delete(k) }
}

describe('deep-link scope (MNY-15)', () => {
  it('reads the scope a link asks for, and refuses one that is not whole numbers', () => {
    assert.equal(readLinkScope('?route=x'), null)
    assert.deepEqual(readLinkScope('?cmp_id=9101&fy_id=92&bo_id=11'), { cmp_id: 9101, fy_id: 92, bo_id: 11 })
    assert.deepEqual(readLinkScope('?cmp_id=9101&bo_id=0'), { cmp_id: 9101, fy_id: null, bo_id: 0 })
    assert.equal(readLinkScope('?cmp_id=abc'), 'malformed')
    assert.equal(readLinkScope('?fy_id=92'), 'malformed')
  })

  it('applies a scope Manage confirms for this person', () => {
    const facts = { fyIds: [92, 91], branchIds: [11, 12] }
    assert.deepEqual(judgeLinkScope({ cmp_id: 9101, fy_id: 91, bo_id: 12 }, facts), { kind: 'apply', cmp_id: 9101, fy_id: 91, bo_id: 12 })
    assert.deepEqual(judgeLinkScope({ cmp_id: 9101, fy_id: null, bo_id: null }, facts), { kind: 'apply', cmp_id: 9101, fy_id: 92, bo_id: 0 })
  })

  it('refuses — never swaps — a company, year or branch the person cannot open', () => {
    const facts = { fyIds: [92], branchIds: [11] }
    assert.deepEqual([(judgeLinkScope({ cmp_id: 77, fy_id: null, bo_id: null }, null) as { kind: string; reason?: string }).kind, (judgeLinkScope({ cmp_id: 77, fy_id: null, bo_id: null }, null) as { reason?: string }).reason], ['refuse', 'company'])
    assert.deepEqual([(judgeLinkScope({ cmp_id: 9101, fy_id: null, bo_id: 99 }, facts) as { kind: string; reason?: string }).kind, (judgeLinkScope({ cmp_id: 9101, fy_id: null, bo_id: 99 }, facts) as { reason?: string }).reason], ['refuse', 'branch'])
    assert.deepEqual([(judgeLinkScope({ cmp_id: 9101, fy_id: 88, bo_id: null }, facts) as { kind: string }).kind, (judgeLinkScope({ cmp_id: 9101, fy_id: 88, bo_id: null }, facts) as { reason?: string }).reason], ['refuse', 'year'])
    assert.deepEqual([(judgeLinkScope('malformed', null) as { kind: string; reason?: string }).kind, (judgeLinkScope('malformed', null) as { reason?: string }).reason], ['refuse', 'malformed'])
  })

  it('keeps the destination and drops only the scope parameters', () => {
    assert.equal(withoutScopeParams('/orders/5', '?cmp_id=1&fy_id=2&bo_id=0&tab=lines', '#top'), '/orders/5?tab=lines#top')
    assert.equal(withoutScopeParams('/reports', '?cmp_id=1', ''), '/reports')
  })

  it('carries the destination through sign-in, same origin only', () => {
    const s = memoryStorage()
    rememberDestination(s, '/dashboard/collections', '?cmp_id=9101&fy_id=92&auth_token=leak', '', '/auth/callback')
    assert.equal(takeDestination(s), '/dashboard/collections?cmp_id=9101&fy_id=92')
    assert.equal(takeDestination(s), null)
    rememberDestination(s, '/auth/callback', '?auth_token=x', '', '/auth/callback')
    assert.equal(takeDestination(s), null)
    assert.equal(safeDestination('//evil.example/x'), null)
    assert.equal(safeDestination('https://evil.example/'), null)
    assert.equal(safeDestination('/ok?x=1'), '/ok?x=1')
  })
})
