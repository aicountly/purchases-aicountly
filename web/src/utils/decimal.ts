/**
 * Money and quantity arithmetic for what a buyer is SHOWN before the server answers — a PO's
 * subtotal and estimated tax, a bill's total before tax — done in fixed-point integers, the way
 * the server stores them (NUMERIC(18,4)), never in binary floating point (F452).
 *
 * The server is authoritative and recomputes everything it stores. This only keeps the figure on
 * the screen equal to the one the server will send back: each step is rounded half away from zero
 * at the same scale the server rounds at (PurchaseOrderService::normaliseLines,
 * BillService::normaliseLines — PHP's round()).
 */

/** Digits kept after the point, as the server keeps them. */
export const SCALE = 4

const TEN = 10n

function pow10(n: number): bigint {
  return TEN ** BigInt(n)
}

/** Divide and round half away from zero (PHP round()). */
function divRound(numerator: bigint, denominator: bigint): bigint {
  const negative = (numerator < 0n) !== (denominator < 0n)
  const n = numerator < 0n ? -numerator : numerator
  const d = denominator < 0n ? -denominator : denominator
  const q = (n * 2n + d) / (d * 2n)
  return negative ? -q : q
}

/**
 * A decimal written by a person or sent by the server ("12.5", 12.5, "1,200.00", "") as an
 * integer count of 10^-scale units. Anything unreadable is 0, as Number('') was.
 */
export function units(value: string | number | null | undefined, scale = SCALE): bigint {
  if (value === null || value === undefined) return 0n
  let text = typeof value === 'number' ? (Number.isFinite(value) ? value.toString() : '0') : value.replace(/,/g, '').trim()
  if (/e/i.test(text)) text = Number(text).toFixed(scale + 2)
  const match = /^([+-])?(\d*)(?:\.(\d*))?$/.exec(text)
  if (!match || (match[2] === '' && (match[3] ?? '') === '')) return 0n
  const sign = match[1] === '-' ? -1n : 1n
  const whole = match[2] === '' ? 0n : BigInt(match[2])
  const fraction = match[3] ?? ''
  const exact = whole * pow10(fraction.length) + (fraction === '' ? 0n : BigInt(fraction))
  return sign * divRound(exact * pow10(scale), pow10(fraction.length))
}

/** Back to a plain decimal string with `scale` digits ("1800.0000"). */
export function format(value: bigint, scale = SCALE): string {
  const negative = value < 0n
  const abs = negative ? -value : value
  const unit = pow10(scale)
  const whole = abs / unit
  const fraction = (abs % unit).toString().padStart(scale, '0')
  return `${negative ? '-' : ''}${whole.toString()}${scale > 0 ? `.${fraction}` : ''}`
}

/** A product of two scaled values, back at the scale, rounded once. */
function times(a: bigint, b: bigint, scale = SCALE): bigint {
  return divRound(a * b, pow10(scale))
}

/**
 * One line's amount after its discount, exactly as the server computes a PO or bill line:
 * gross = round(qty x rate, 4); discount = round(gross x pc / 100, 4); net = gross - discount.
 */
export function lineAmount(qty: string | number, rate: string | number, discountPc: string | number = 0): string {
  const gross = times(units(qty), units(rate))
  const discount = divRound(gross * units(discountPc, 3), 100n * pow10(3))
  return format(gross - discount)
}

/** The gross of a line, before any discount: round(qty x rate, 4). */
export function grossAmount(qty: string | number, rate: string | number): string {
  return format(times(units(qty), units(rate)))
}

/** round(amount x pc / 100, 4) — the estimated tax of a line. */
export function percentOf(amount: string | number, pc: string | number): string {
  return format(divRound(units(amount) * units(pc, 3), 100n * pow10(3)))
}

/** The exact sum of amounts. */
export function sum(values: Array<string | number>): string {
  return format(values.reduce<bigint>((total, v) => total + units(v), 0n))
}

/** For display only: the value as a number once the arithmetic is done. */
export function toNumber(value: string): number {
  return Number(value)
}
