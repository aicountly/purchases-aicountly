/**
 * What the sourcing screen knows about an RFQ, and how it says it.
 *
 * The backend's statuses are the product's statuses — DRAFT, ISSUED,
 * RESPONSES_OPEN, EVALUATING, AWARDED, CANCELLED, CLOSED — and nothing here
 * renames, merges or invents one. What lives in this file is only how each is
 * SPOKEN and COLOURED, kept in one place so a badge, a tab and a filter can
 * never disagree about what "Responses open" looks like.
 */

import type { RfqListRow } from '../../services/types'

export type RfqTone = 'neutral' | 'info' | 'violet' | 'warning' | 'success' | 'muted'

export interface StatusPresentation {
  /** The persisted value. Never altered — it is what the API filters on. */
  value: string
  label: string
  tone: RfqTone
  /** What this stage of the enquiry is waiting for. Shown in the tab tooltip. */
  meaning: string
}

/**
 * The lifecycle, in the order an enquiry moves through it.
 *
 * Cancelled is here rather than hidden: an enquiry nobody can reach from the
 * tabs is an enquiry somebody has to go looking for in a search box.
 */
export const RFQ_STATUSES: StatusPresentation[] = [
  { value: 'DRAFT', label: 'Draft', tone: 'neutral', meaning: 'Not sent to anybody yet.' },
  { value: 'ISSUED', label: 'Issued', tone: 'info', meaning: 'Sent to suppliers, nothing back yet.' },
  { value: 'RESPONSES_OPEN', label: 'Responses open', tone: 'violet', meaning: 'At least one supplier has quoted.' },
  { value: 'EVALUATING', label: 'Evaluating', tone: 'warning', meaning: 'Quotations are being compared.' },
  { value: 'AWARDED', label: 'Awarded', tone: 'success', meaning: 'A supplier has won it.' },
  { value: 'CLOSED', label: 'Closed', tone: 'muted', meaning: 'Finished, with nothing further to do.' },
  { value: 'CANCELLED', label: 'Cancelled', tone: 'muted', meaning: 'Dropped before it was decided.' },
]

const BY_VALUE = new Map(RFQ_STATUSES.map((status) => [status.value, status]))

export function statusOf(value: string): StatusPresentation {
  return (
    BY_VALUE.get(value) ?? {
      value,
      // A status this screen has not been taught is shown as it is stored
      // rather than as "Unknown": the buyer can at least read it and say it out
      // loud to whoever added it.
      label: value.replace(/_/g, ' ').toLowerCase().replace(/^./, (c) => c.toUpperCase()),
      tone: 'neutral' as const,
      meaning: '',
    }
  )
}

// ---------------------------------------------------------------------------
// The row, as the table reads it
// ---------------------------------------------------------------------------

export interface RfqView {
  id: number
  number: string
  title: string
  /** The first few lines, or null when the enquiry has none. */
  items: string | null
  lines: number
  invited: number
  responded: number
  quotes: number
  awarded: boolean
  status: StatusPresentation
  raised: string
  deadline: string | null
  updated: string
  /** Past its response deadline with nobody left to wait for. */
  overdue: boolean
}

/** Postgres sends bigint counts as strings. Reading them as numbers is this function's job. */
const count = (value: number | string | null | undefined): number => {
  const parsed = typeof value === 'string' ? Number.parseInt(value, 10) : (value ?? 0)
  return Number.isFinite(parsed) ? parsed : 0
}

/**
 * The stored row, adapted for the screen.
 *
 * An adapter rather than a rename in the API: the backend's field names are the
 * contract three other screens already read, and changing them to suit a table
 * header is how two products come to disagree about what a column is called.
 */
export function toRfqView(row: RfqListRow): RfqView {
  const status = statusOf(row.status)
  const deadline = row.response_deadline

  return {
    id: row.rfq_id,
    number: row.rfq_no,
    title: row.title?.trim() || 'Untitled enquiry',
    items: row.item_summary?.trim() || null,
    lines: count(row.line_count),
    invited: count(row.invited_count),
    responded: count(row.responded_count),
    quotes: count(row.quote_count),
    awarded: count(row.award_count) > 0,
    status,
    raised: row.rfq_date,
    deadline,
    updated: row.updated_at ?? row.created_at ?? row.rfq_date,
    overdue:
      deadline !== null &&
      ['ISSUED', 'RESPONSES_OPEN'].includes(row.status) &&
      new Date(deadline).getTime() < Date.now(),
  }
}

// ---------------------------------------------------------------------------
// Numbers and dates
// ---------------------------------------------------------------------------

/**
 * ₹4.85 L, ₹2.45 Cr — the way an amount is actually said here.
 *
 * Lakh and crore, not K and M: a KPI is read aloud in meetings, and "four point
 * eight five lakh" is what the person reading it says. The full figure goes in
 * the `title` of whatever renders this, so the exact number is never lost.
 */
export function compactMoney(value: number, currency = 'INR'): string {
  const symbol = currency === 'INR' ? '₹' : ''
  const prefix = symbol === '' ? `${currency} ` : symbol
  const abs = Math.abs(value)

  const scaled = (divisor: number, suffix: string): string => {
    const n = value / divisor
    // Two figures after the point up to 100, one above it: ₹12.5 Cr reads
    // better than ₹12.50 Cr, and ₹4.85 L is worth the extra digit.
    const digits = Math.abs(n) >= 100 ? 0 : Math.abs(n) >= 10 ? 1 : 2
    return `${prefix}${n.toFixed(digits)} ${suffix}`
  }

  if (abs >= 1_00_00_000) return scaled(1_00_00_000, 'Cr')
  if (abs >= 1_00_000) return scaled(1_00_000, 'L')
  if (abs >= 1_000) return `${prefix}${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(value)}`

  return `${prefix}${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(value)}`
}

/** 20 Sep 2026, in the user's own timezone — never a sliced ISO string. */
const DAY = new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
const TIME = new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true })

export function dayOf(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? value : DAY.format(parsed)
}

export function timeOf(value: string | null | undefined): string | null {
  if (!value) return null
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return null
  // A date with no time component carries no time worth showing.
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return null
  return TIME.format(parsed)
}

/** "in 3 days" / "2 days ago", for a deadline that needs reading at a glance. */
export function relativeDay(value: string | null | undefined): string | null {
  if (!value) return null
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return null

  const days = Math.round((parsed.getTime() - Date.now()) / 86_400_000)
  if (days === 0) return 'today'
  if (days === 1) return 'tomorrow'
  if (days === -1) return 'yesterday'
  return days > 0 ? `in ${days} days` : `${Math.abs(days)} days ago`
}

/** A signed percentage, or null. A change against nothing is not a change. */
export function changePc(now: number, before: number): number | null {
  if (before <= 0) return null
  return Math.round(((now - before) / before) * 1000) / 10
}
