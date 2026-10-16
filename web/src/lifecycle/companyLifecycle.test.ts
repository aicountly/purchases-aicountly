import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import {
  clearCompanyRestriction,
  getCompanyRestriction,
  getRestrictionToast,
  isCompanyRestricted,
  noteApiRefusal,
  noteCompanyInfo,
  restrictionMessage,
} from './companyLifecycle.ts'

// The API's 423 body, as the receiver kit's ReadOnlyGuard sends it.
const refusal = {
  code: 'COMPANY_RESTRICTED',
  message: 'This company is read-only until 28 Nov 2026 because its subscription ended. Renew to make changes, or export your data.',
  restricted_until: '2026-11-28T00:00:00Z',
  lifecycle: 'restricted',
  error: { code: 'COMPANY_RESTRICTED', message: 'This company is read-only until 28 Nov 2026 because its subscription ended. Renew to make changes, or export your data.', restricted_until: '2026-11-28T00:00:00Z' },
}

describe('company lifecycle (423 COMPANY_RESTRICTED and companyinfo)', () => {
  beforeEach(() => clearCompanyRestriction())

  it('a 423 COMPANY_RESTRICTED puts up the banner and a toast with the API message as it is', () => {
    assert.equal(noteApiRefusal(423, refusal, 42), true)
    assert.equal(getCompanyRestriction()?.message, refusal.message)
    assert.equal(getCompanyRestriction()?.restrictedUntil, '2026-11-28T00:00:00Z')
    assert.equal(getCompanyRestriction()?.cmpId, 42)
    assert.equal(getRestrictionToast()?.message, refusal.message)
  })

  it('other refusals are not a restriction', () => {
    assert.equal(isCompanyRestricted(403, refusal), false)
    assert.equal(isCompanyRestricted(423, { error: { code: 'locked' } }), false)
    assert.equal(noteApiRefusal(409, { error: { code: 'conflict', message: 'x' } }, 42), false)
    assert.equal(getCompanyRestriction(), null)
  })

  it('companyinfo restricted / purge_pending raise it, active lowers it, no lifecycle changes nothing', () => {
    noteCompanyInfo(7, { data: { cmp_id: 7, lifecycle: 'restricted', restricted_until: '2026-11-28T00:00:00Z' } })
    assert.equal(getCompanyRestriction()?.message, restrictionMessage('restricted', '2026-11-28T00:00:00Z'))
    assert.match(getCompanyRestriction()?.message ?? '', /read-only until 28 Nov 2026/)
    noteCompanyInfo(7, { data: { cmp_id: 7 } })
    assert.equal(getCompanyRestriction()?.lifecycle, 'restricted')
    noteCompanyInfo(7, { data: { cmp_id: 7, lifecycle: 'purge_pending' } })
    assert.match(getCompanyRestriction()?.message ?? '', /being deleted/)
    noteCompanyInfo(7, { data: { cmp_id: 7, lifecycle: 'active' } })
    assert.equal(getCompanyRestriction(), null)
  })
})
