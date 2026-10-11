/**
 * Today on this computer's calendar — not the UTC day `toISOString()` gives, which is still
 * yesterday before 05:30 in India (E8-PUR F388: a receipt keyed early on 1 April landed in the
 * previous financial year).
 */
export function localToday(now: Date = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}
