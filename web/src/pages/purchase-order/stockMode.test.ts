import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { stockModeChangedText } from './stockMode.ts'

describe('receipt stock mode, who last changed it (E8-INV A11)', () => {
  it('says the company still runs on its starting setting when nobody changed it', () => {
    assert.match(stockModeChangedText(undefined), /started with/)
    assert.match(stockModeChangedText({ by: null, at: null }), /started with/)
  })

  it('names who changed it and when', () => {
    const text = stockModeChangedText({ by: 'Asha Rao', at: '2026-10-11T09:00:00Z' })
    assert.match(text, /by Asha Rao/)
    assert.match(text, / on /)
  })
})
