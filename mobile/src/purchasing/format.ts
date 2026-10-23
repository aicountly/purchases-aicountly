/**
 * Display formatting. Pure, and independent of the device's Intl support (Hermes builds differ).
 * Money stays the decimal string the API sent; it is only grouped and rounded for display.
 */

const SYMBOLS: Record<string, string> = { INR: '₹', USD: '$', EUR: '€', GBP: '£', AED: 'AED ' };

/** Indian digit grouping: 12,34,567. */
function groupIndian(int: string): string {
  if (int.length <= 3) return int;
  const last3 = int.slice(-3);
  const rest = int.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
  return `${rest},${last3}`;
}

function groupWestern(int: string): string {
  return int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/** "999" → "1000", on the digits, so no precision is lost on a long number. */
function increment(digits: string): string {
  const out = digits.split('');
  for (let i = out.length - 1; i >= 0; i -= 1) {
    if (out[i] !== '9') {
      out[i] = String(Number(out[i]) + 1);
      return out.join('');
    }
    out[i] = '0';
  }
  return `1${out.join('')}`;
}

/** "₹1,20,000.50" from "120000.5000". Null or unparsable → "—". */
export function money(value: string | number | null | undefined, currency = 'INR'): string {
  if (value === null || value === undefined || value === '') return '—';
  const raw = typeof value === 'number' ? value.toFixed(4) : String(value).trim();
  const m = /^(-)?(\d+)(?:\.(\d+))?$/.exec(raw);
  if (!m) return '—';
  const [, sign, intPart, frac = ''] = m;
  let paise = Math.round(Number(`0.${frac || '0'}`) * 100);
  let int = intPart.replace(/^0+(?=\d)/, '');
  if (paise === 100) {
    paise = 0;
    int = increment(int);
  }
  const grouped = currency === 'INR' ? groupIndian(int) : groupWestern(int);
  const symbol = SYMBOLS[currency] ?? `${currency} `;
  return `${sign ? '−' : ''}${symbol}${grouped}.${String(paise).padStart(2, '0')}`;
}

/** Quantities: up to 3 decimals, trailing zeros dropped. "12", "2.5", "0.125". */
export function qty(value: string | number | null | undefined): string {
  const n = typeof value === 'number' ? value : Number(value ?? 0);
  if (!Number.isFinite(n)) return '0';
  return String(Math.round(n * 1000) / 1000);
}

export function toNumber(value: string | number | null | undefined): number {
  const n = typeof value === 'number' ? value : Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "23 Oct 2026" from "2026-10-23" or an ISO timestamp. */
export function day(value: string | null | undefined): string {
  if (!value) return '—';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (!m) return '—';
  return `${Number(m[3])} ${MONTHS[Number(m[2]) - 1] ?? ''} ${m[1]}`;
}

/** Whole days from `from` to `to` (YYYY-MM-DD), by calendar date. */
export function daysBetween(from: string, to: string): number {
  const a = Date.UTC(Number(from.slice(0, 4)), Number(from.slice(5, 7)) - 1, Number(from.slice(8, 10)));
  const b = Date.UTC(Number(to.slice(0, 4)), Number(to.slice(5, 7)) - 1, Number(to.slice(8, 10)));
  return Math.round((b - a) / 86_400_000);
}

/** "today", "3 days ago", "in 2 days" — for promised dates and waiting times. */
export function relativeDay(value: string | null | undefined, today: string): string {
  if (!value) return '';
  const d = daysBetween(today, value.slice(0, 10));
  if (d === 0) return 'today';
  if (d === 1) return 'tomorrow';
  if (d === -1) return 'yesterday';
  return d > 0 ? `in ${d} days` : `${-d} days ago`;
}
