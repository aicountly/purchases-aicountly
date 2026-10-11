import { test } from 'node:test'
import assert from 'node:assert/strict'
import { localToday } from './localDate.ts'

test('localToday uses the local calendar date, not the UTC one', () => {
  const early = new Date(2027, 3, 1, 0, 15) // 1 April 2027, 00:15 local
  assert.equal(localToday(early), '2027-04-01')
})

test('localToday pads month and day', () => {
  assert.equal(localToday(new Date(2026, 8, 5, 12)), '2026-09-05')
})
