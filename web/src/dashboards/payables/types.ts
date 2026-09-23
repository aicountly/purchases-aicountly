/**
 * The payables workspace contract, as the API actually answers it.
 *
 * Money is a STRING everywhere, exactly as in the dashboard types next door.
 * It leaves PostgreSQL as an exact decimal and is added up on the server;
 * parsing it into a JavaScript number to render it would undo that at the last
 * possible step. `formatMoney` below takes the string and formats it — it
 * never becomes the basis of another figure.
 */

import type { Panel } from '../types'

/** One bill in the workspace, as `v1/bills/payables` returns it. */
export interface PayableRow {
  request_id: number
  invoice_no: string | null
  invoice_date: string | null
  supplier_account_id: number
  supplier_name: string | null
  po_id: number | null
  po_no: string | null
  payment_terms: string | null
  status: string
  line_count: number
  currency: string
  /** Exclusive of tax. Books computes the tax when it posts the voucher. */
  subtotal: string
  tax_basis: string
  open_exceptions: number
  duplicate_of: number | null
  duplicate_of_no: string | null
  books_voucher_no: string | null
  last_error: string | null
  entered_by: string | null
  entered_at: string | null
  route: string
  /**
   * A convenience for the interface, never the control. Every one of these
   * actions asserts its permission again on the server, so a flag flipped in
   * a debugger buys nothing.
   */
  can_edit: boolean
  can_rematch: boolean
  can_resolve: boolean
  can_post: boolean
}

export interface PayableTabCounts {
  all: number
  awaiting_review: number
  exceptions: number
  ready_to_post: number
  posted: number
  failed: number
  duplicates: number
}

export type PayableTabId = keyof PayableTabCounts

export const PAYABLE_TABS: { id: PayableTabId; label: string }[] = [
  { id: 'all', label: 'All bills' },
  { id: 'awaiting_review', label: 'Awaiting review' },
  { id: 'exceptions', label: 'Exceptions' },
  { id: 'ready_to_post', label: 'Ready to post' },
  { id: 'posted', label: 'Posted' },
  { id: 'failed', label: 'Needs attention' },
  { id: 'duplicates', label: 'Possible duplicates' },
]

/**
 * What Smart Books knows about one bill.
 *
 * Built on the client from the open items the payment planning panel already
 * read, keyed by supplier and bill reference. A bill outside that set has NO
 * entry, and the table says so rather than leaving the column blank — an empty
 * due date reads as "no due date", which is a different and wrong claim.
 */
export interface BooksOpenItem {
  due_date: string | null
  due_label: string
  pending: string
  pending_formatted: string
  part_paid: boolean
  days_overdue: number | null
  held: boolean
  held_reason: string | null
}

export type TrendPanel = Panel<{
  points: {
    period: string
    label: string
    booked: string
    formatted: string
    posted: string
    posted_formatted: string
    bill_count: number
    posted_count: number
  }[]
  currency: string
  months: number
  series: { id: string; label: string; tone: string }[]
  basis: string
}>

export type SupplierExposurePanel = Panel<{
  rows: {
    supplier_account_id: number
    supplier_name: string | null
    amount: string
    formatted: string
    share_pc: string | null
    bill_count: number
    overdue_count: number
    held_count: number
    route: string
    filters: Record<string, string>
  }[]
  currency: string
  total: string
  total_formatted: string
  covered_suppliers: number
  basis: string
}>

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/**
 * Indian grouping, from the exact decimal string.
 *
 * `en-IN` is what makes ₹48,05,000 come out as lakhs rather than as 4,805,000.
 * A value that is not a number at all renders as an em dash rather than NaN.
 */
export function formatMoney(value: string | null | undefined, currency = 'INR'): string {
  if (value === null || value === undefined || value === '') return '—'
  const amount = Number.parseFloat(value)
  if (!Number.isFinite(amount)) return '—'

  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(amount)
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }).format(parsed)
}

/** Two letters for an avatar, from whatever the name turns out to be. */
export function initials(name: string | null, fallback = '#'): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return fallback
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return ((parts[0][0] ?? '') + (parts[parts.length - 1][0] ?? '')).toUpperCase()
}

/** Whole days from today to `date`; negative once it is in the past. */
export function daysUntil(date: string | null): number | null {
  if (!date) return null
  const target = new Date(`${date}T00:00:00`)
  if (Number.isNaN(target.getTime())) return null

  const today = new Date()
  today.setHours(0, 0, 0, 0)

  return Math.round((target.getTime() - today.getTime()) / 86_400_000)
}

/**
 * How a bill's workflow state should read, and in what tone.
 *
 * The label is always rendered next to the colour, never instead of it: a
 * reader who cannot separate the red pill from the amber one still gets
 * "Exception" and "Awaiting review" in words.
 */
export const STATUS_FACE: Record<string, { label: string; tone: 'warning' | 'danger' | 'success' | 'primary' | 'neutral' }> = {
  DRAFT:     { label: 'Draft', tone: 'neutral' },
  MATCHING:  { label: 'Matching', tone: 'warning' },
  MATCHED:   { label: 'Ready to post', tone: 'primary' },
  EXCEPTION: { label: 'Exception', tone: 'danger' },
  POSTED:    { label: 'Posted', tone: 'success' },
  FAILED:    { label: 'Posting failed', tone: 'danger' },
  CANCELLED: { label: 'Cancelled', tone: 'neutral' },
}
