import { test } from 'node:test'
import assert from 'node:assert/strict'
import { format, grossAmount, lineAmount, percentOf, sum, units } from './decimal.ts'

test('reads what people type and what the server sends', () => {
  assert.equal(format(units('12.5')), '12.5000')
  assert.equal(format(units(' 1,200.00 ')), '1200.0000')
  assert.equal(format(units('')), '0.0000')
  assert.equal(format(units('abc')), '0.0000')
  assert.equal(format(units(0.1)), '0.1000')
  assert.equal(format(units('-3.25')), '-3.2500')
  assert.equal(format(units('0.00005')), '0.0001', 'half away from zero at the fourth place')
})

test('a discounted line is the server\'s net amount (PurchaseOrderService::normaliseLines)', () => {
  // 10 x 200 less 10% = 1800, the A31 order line.
  assert.equal(lineAmount('10', '200', '10'), '1800.0000')
  // Floats would give 0.30000000000000004 for 0.1 x 3; the server stores 0.3000.
  assert.equal(lineAmount('3', '0.1'), '0.3000')
  assert.equal(lineAmount('1.3333', '3.3333', '0'), '4.4443')
  assert.equal(lineAmount('7', '19.99', '12.5'), '122.4387')
})

test('subtotal and GST of a PO add up to the paisa the server posts', () => {
  const lines = [
    { qty: '3', rate: '0.1', tax: '18' },
    { qty: '1', rate: '1999.99', tax: '28' },
    { qty: '12.5', rate: '40.04', tax: '5' },
  ]
  const amounts = lines.map((l) => grossAmount(l.qty, l.rate))
  assert.equal(sum(amounts), '2500.7900')
  const tax = sum(lines.map((l, i) => percentOf(amounts[i], l.tax)))
  // 0.054 + 559.9972 + 25.025 = 585.0762
  assert.equal(tax, '585.0762')
  // What the screen used to do: 0.1 + 0.2 in floats is not 0.3.
  const floatTotal = [0.1, 0.2].reduce((s, v) => s + v, 0)
  assert.notEqual(floatTotal, 0.3)
  assert.equal(sum(['0.1', '0.2']), '0.3000')
})
