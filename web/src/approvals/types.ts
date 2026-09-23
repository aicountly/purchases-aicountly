/**
 * The approvals contract, as `v1/approvals/queue` and `v1/approvals/summary`
 * actually return it.
 *
 * Every money value is a STRING and arrives with its formatted form beside it.
 * The server does the grouping, the symbol and the short form, so the card, the
 * table and anything exported from them cannot disagree at the last paisa.
 */

import type { DashboardMetric, DashboardPeriod, SourceStatus } from '../dashboards/types'

/** The faces of the screen. Not dashboards — filters over one queue. */
export const APPROVAL_TABS = [
  { id: 'mine', label: 'Pending for me' },
  { id: 'raised_by_me', label: 'Raised by me' },
  { id: 'all_pending', label: 'All pending' },
  { id: 'actioned', label: 'Recently actioned' },
  { id: 'exceptions', label: 'Exceptions' },
  { id: 'insights', label: 'Insights' },
] as const

export type ApprovalTabId = (typeof APPROVAL_TABS)[number]['id']

/** Tabs that are a view of the queue. The other two are their own panels. */
export const QUEUE_TABS: ApprovalTabId[] = ['mine', 'raised_by_me', 'all_pending', 'actioned']

export function isApprovalTab(value: string | null | undefined): value is ApprovalTabId {
  return APPROVAL_TABS.some((tab) => tab.id === value)
}

export const DOCUMENT_TYPES = [
  { id: 'purchase_order', label: 'Purchase orders' },
  { id: 'requisition', label: 'Requisitions' },
] as const

export type ApprovalRiskLevel = 'high' | 'medium' | 'low' | 'not_assessed'
export type ApprovalAgeBand = 'fresh' | 'waiting' | 'late'

export interface ApprovalQueueRow {
  approval_id: number
  entity_type: string
  entity_id: number
  type_label: string

  document_no: string | null
  document_label: string
  /** What the document says about itself. Never the supplier — that has its own column. */
  document_title: string | null
  document_date: string | null
  document_date_label: string | null
  /** Where the document itself lives, or null for a kind with no screen yet. */
  route: string | null

  supplier_name: string | null
  supplier_account_id: number | null
  /** What Purchases knows about the supplier. Identity belongs to Contacts. */
  supplier_note: string | null

  amount: string | null
  amount_formatted: string | null
  currency: string | null

  raised_by: string
  requester_label: string
  requester_initials: string
  requester_department: string | null

  requested_at: string
  requested_at_label: string | null
  age_days: number
  age_label: string
  age_band: ApprovalAgeBand
  age_note: string

  risk: ApprovalRiskLevel
  risk_label: string
  /** The facts the verdict was built from. Empty when nothing could be judged. */
  risk_reasons: string[]

  status: string
  status_label: string

  reason_kind: string
  reason_detail: string | null
  stage_name: string | null
  threshold_formatted: string | null

  is_self_raised: boolean
  may_approve: boolean
  /** Why the buttons are off. Shown on the control, never only in a hover. */
  block_reason: string | null

  decided_by: string | null
  decided_at: string | null
  decided_at_label: string | null
  decision_note: string | null
}

export interface ApprovalQueueMeta {
  total: number
  limit: number
  offset: number
  scope: string
  sort: string
  order: string
  period_applies_to: 'nothing' | 'decision_date'
  can_approve: boolean
  decidable_types: string[]
}

export interface ApprovalRequester {
  user_uuid: string
  label: string
  is_you: boolean
  pending: number
  total: number
}

export type ApprovalPanel<T> = ({ available: true } & T) | { available: false; reason: string; kind: 'permission' | 'source' }

export interface TypeMixRow {
  id: string
  label: string
  documents: number
  value: string
  formatted: string
  share_pc: string | null
}

export interface TrendPoint {
  period: string
  label: string
  approved: number
  rejected: number
  avg_days: string | null
}

export interface ApprovalRisk {
  id: string
  severity: 'critical' | 'warning' | 'info'
  title: string
  detail: string
  basis: string
  route: string
  filters: Record<string, string>
}

export interface ApprovalInsight {
  id: string
  tone: 'success' | 'warning' | 'danger' | 'info'
  kind: 'observation' | 'estimate' | 'projection'
  kind_label: string
  title: string
  detail: string
  basis: string
  route: string
  filters: Record<string, string>
}

/**
 * One open exception, as `v1/match-exceptions` has always returned it.
 *
 * The original approvals screen listed these and the new one keeps doing it:
 * the grouped counts say what kind of disagreement there is, and this is the
 * document somebody opens to fix it.
 */
export interface ExceptionRow {
  exception_id: number
  exception_kind: string
  detail: string | null
  variance_value: string | null
  created_at: string
  bill_request_id: number | null
  supplier_invoice_no: string | null
  po_no: string | null
}

export interface ExceptionKindRow {
  id: string
  label: string
  documents: number
  variance: string
  variance_formatted: string | null
}

export interface ApprovalSummary {
  scope: {
    company_id: number
    financial_year_id: number
    branch_id: number
    branch_label: string
    reporting_currency: string | null
  }
  period: DashboardPeriod
  generated_at: string
  sources: SourceStatus[]
  metrics: DashboardMetric[]
  counts: {
    /** Pending, of a kind you may decide, and not raised by you. */
    mine: number
    pending: number
    raised_today: number
    raised_by_me: number
    waiting_long: number
    exceptions: number | null
  }
  by_type: ApprovalPanel<{ currency: string; total: string; total_formatted: string; total_exact: string; rows: TypeMixRow[]; basis: string }>
  trend: ApprovalPanel<{ points: TrendPoint[]; basis: string; note: string | null }>
  exceptions: ApprovalPanel<{ total: number; rows: ExceptionKindRow[]; basis: string }>
  risks: ApprovalPanel<{ rows: ApprovalRisk[] }>
  insights: ApprovalPanel<{ rows: ApprovalInsight[]; method_label: string }>
}
