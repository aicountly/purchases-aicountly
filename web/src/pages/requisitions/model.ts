/**
 * What the Requisitions list screen knows about a requisition, and nothing else.
 *
 * Presentation decisions live here rather than in the components, so the tab
 * that filters by "pending" and the badge that reads "Pending" can never mean
 * two different sets of statuses.
 */

import type { RequisitionRow } from '../../services/types'

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

export type Bucket = 'all' | 'draft' | 'pending' | 'approved' | 'rejected'

/**
 * Nine stored statuses, four a buyer thinks in.
 *
 * This is the SAME grouping the API applies when it is asked for a bucket —
 * see RequisitionService::BUCKETS. Two copies of one rule is a risk, so the
 * server is the one that filters and this is only what paints a row; if they
 * ever disagree the tab count is what is right, because it is counted there.
 */
export const BUCKET_STATUSES: Record<Exclude<Bucket, 'all'>, string[]> = {
  draft: ['DRAFT'],
  pending: ['SUBMITTED', 'APPROVAL_PENDING'],
  approved: ['APPROVED', 'SOURCING', 'ORDERED', 'CLOSED'],
  rejected: ['REJECTED'],
}

export function bucketOf(status: string): Bucket | 'other' {
  for (const [bucket, statuses] of Object.entries(BUCKET_STATUSES)) {
    if (statuses.includes(status)) return bucket as Bucket
  }
  return 'other'
}

export type StatusTone = 'draft' | 'pending' | 'approved' | 'rejected' | 'neutral'

/**
 * Colour by what the status MEANS, with a neutral fallback.
 *
 * A status this screen has not been taught yet draws grey and its own name
 * rather than nothing, so adding one to the backend cannot produce a blank
 * cell here.
 */
export function statusTone(status: string): StatusTone {
  const bucket = bucketOf(status)
  if (bucket === 'other') return 'neutral'
  if (bucket === 'all') return 'neutral'
  return bucket
}

/** "APPROVAL_PENDING" reads as "Approval pending" in a sentence, not as a constant. */
export function statusLabel(status: string): string {
  const words = status.replace(/_/g, ' ').toLowerCase()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * The same status, narrow enough for a badge in a ten-column table.
 *
 * "Approval pending" is 16 characters and was the widest thing in the row —
 * wide enough to push the Approval column off the right edge on a 1366px
 * laptop. The tab above the table already calls this set "Pending", so the
 * badge says the same word rather than a second name for one thing.
 */
export function statusShortLabel(status: string): string {
  if (status === 'APPROVAL_PENDING') return 'Pending'
  return statusLabel(status)
}

// ---------------------------------------------------------------------------
// Department chips
// ---------------------------------------------------------------------------

export const DEPARTMENT_TONES = 6

/**
 * A department's colour, decided by its name.
 *
 * Deterministic on purpose: `department` is free text on the requisition, so
 * there is no department master to colour from, and a random colour per render
 * would make the same department a different chip on every page of the list.
 */
export function departmentTone(department: string): number {
  let hash = 0
  for (let index = 0; index < department.length; index++) {
    hash = (hash * 31 + department.charCodeAt(index)) >>> 0
  }
  return hash % DEPARTMENT_TONES
}

// ---------------------------------------------------------------------------
// Row presentation
// ---------------------------------------------------------------------------

/**
 * What to call a requisition in the list.
 *
 * There is no title column on a requisition — the record is its lines. The
 * first line's description is the closest thing to a title and is what a buyer
 * would say if asked what the requisition was for; a requisition with no
 * description at all falls back to its number, never to an empty cell.
 *
 * Nothing is written back: this is a label, not a field.
 */
export function requisitionTitle(row: RequisitionRow): string {
  const first = row.first_description?.trim()
  if (first) return first
  if (row.justification?.trim()) return row.justification.trim()
  return row.requisition_no
}

export function itemCountLabel(count: number): string {
  if (count === 0) return 'No lines'
  return `${count} item${count === 1 ? '' : 's'}`
}

/** Initials for the requester avatar, from whatever name we actually have. */
export function initialsOf(label: string): string {
  const words = label.trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '—'
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase()
  return (words[0][0] + words[words.length - 1][0]).toUpperCase()
}

/** A uuid is 36 characters of no interest until you need all of them. */
export function shortUuid(uuid: string): string {
  return uuid.length > 12 ? `${uuid.slice(0, 8)}…` : uuid
}

/**
 * How far through approval a requisition is.
 *
 * Only what the approval rows actually say. A requisition that needed no
 * approval has no stages and gets a dash — not "0/0", and certainly not a full
 * bar implying somebody signed something.
 */
export interface ApprovalProgress {
  /** null when this requisition has no approval chain at all. */
  ratio: number | null
  done: number
  total: number
  tone: StatusTone
}

export function approvalProgress(row: RequisitionRow): ApprovalProgress {
  const total = row.approval_stages
  if (total === 0) return { ratio: null, done: 0, total: 0, tone: 'neutral' }

  const done = row.approval_approved + row.approval_rejected
  const tone: StatusTone = row.approval_rejected > 0 ? 'rejected' : row.approval_pending > 0 ? 'pending' : 'approved'

  return { ratio: Math.min(1, done / total), done, total, tone }
}

// ---------------------------------------------------------------------------
// Trends
// ---------------------------------------------------------------------------

export interface Trend {
  /** null when there is nothing to compare against — never a fabricated zero. */
  direction: 'up' | 'down' | 'flat' | null
  label: string
  comparison: string
  tone: 'positive' | 'negative' | 'quiet'
}

/**
 * This month against last month, or an honest silence.
 *
 * A percentage needs something to be a percentage OF. With no requisitions last
 * month there is no change to state, so the card says what it does know — how
 * many were raised this month — rather than inventing "+100%".
 *
 * `improvementIsUp` is false for rejections, where fewer is better, so the
 * colour follows the meaning rather than the arithmetic.
 */
export function monthTrend(current: number, previous: number, improvementIsUp = true): Trend {
  if (previous === 0) {
    return current === 0
      ? { direction: null, label: 'No change', comparison: 'vs last month', tone: 'quiet' }
      : { direction: null, label: `${current} this month`, comparison: 'no figure for last month', tone: 'quiet' }
  }

  const change = Math.round(((current - previous) / previous) * 100)
  if (change === 0) return { direction: 'flat', label: 'No change', comparison: 'vs last month', tone: 'quiet' }

  const up = change > 0
  const good = improvementIsUp ? up : !up

  return {
    direction: up ? 'up' : 'down',
    label: `${up ? '+' : ''}${change}%`,
    comparison: 'vs last month',
    tone: good ? 'positive' : 'negative',
  }
}

/** The 12 monthly buckets a sparkline is drawn from, or [] when there is no history. */
export function sparklinePoints(values: number[]): number[] {
  const meaningful = values.filter((value) => value > 0)
  // One month of data is a dot, not a trend. Drawing a line through it would
  // be drawing a line through nothing.
  return meaningful.length < 2 ? [] : values
}
