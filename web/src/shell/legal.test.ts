/** The legal links are the fleet's (Billing / Inventory / Books / Manage): same entries, same order. */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { GRIEVANCE_OFFICER, LEGAL_LINKS } from './legal.ts'

describe('legal links', () => {
  it('carries privacy, terms and grievance, on aicountly.com over HTTPS', () => {
    assert.deepEqual(LEGAL_LINKS.map((l) => l.label), ['Privacy', 'Terms', 'Refund policy', 'Delete account', 'Support', 'Grievance'])
    for (const link of LEGAL_LINKS) assert.match(link.href, /^https:\/\/aicountly\.com\//)
    assert.equal(LEGAL_LINKS.find((l) => l.label === 'Privacy')?.href, 'https://aicountly.com/privacy-policy')
    assert.equal(GRIEVANCE_OFFICER.email, 'grievance@aicountly.com')
  })
})
