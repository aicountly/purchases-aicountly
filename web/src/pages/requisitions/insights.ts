/**
 * The insight banner's sentences, built from counts the server did.
 *
 * WHY THERE IS NO MODEL BEHIND THIS. An insight on a procurement screen is
 * acted on — somebody chases an approver because of it — so it has to be true,
 * repeatable and explainable. Every line below is a count this API returned
 * (`RequisitionService::signals`), turned into a sentence and a filter that
 * shows the exact rows it is talking about. Nothing is generated, nothing is
 * guessed, and an insight with no rows behind it is never shown.
 *
 * This product does have an AI assistant (`v1/insights/ask`), and the banner's
 * secondary action opens it. That is the right division: deterministic counts
 * here, open questions there.
 */

import type { RequisitionSignals } from '../../services/types'
import type { RequisitionQuery } from '../../services/requisitions'

export type InsightSeverity = 'warning' | 'info' | 'positive'

export interface Insight {
  id: string
  severity: InsightSeverity
  title: string
  description: string
  /** What the list should show when the reader asks to see it. */
  filter: Partial<RequisitionQuery>
  actionLabel: string
}

const plural = (count: number, one: string, many: string) => (count === 1 ? one : many)

/**
 * In the order a procurement lead would want to hear them.
 *
 * Something blocked comes before something merely worth knowing, and money
 * waiting comes before counts waiting.
 */
export function buildInsights(signals: RequisitionSignals | null, today: string): Insight[] {
  if (!signals) return []

  const insights: Insight[] = []

  if (signals.overdue > 0) {
    insights.push({
      id: 'overdue',
      severity: 'warning',
      title: `${signals.overdue} ${plural(signals.overdue, 'requisition has', 'requisitions have')} passed the date ${plural(signals.overdue, 'it was', 'they were')} needed by.`,
      description: 'Still unapproved or unsourced, so whatever they were for is already late.',
      filter: { status: 'pending', requiredByBefore: today },
      actionLabel: 'Show these',
    })
  }

  if (signals.aged_pending > 0) {
    insights.push({
      id: 'aged-pending',
      severity: 'warning',
      title: `${signals.aged_pending} pending ${plural(signals.aged_pending, 'requisition is', 'requisitions are')} aged more than ${signals.aged_pending_days} days.`,
      description:
        signals.oldest_pending_days > 0
          ? `The oldest has been waiting ${signals.oldest_pending_days} days. Consider following up to avoid delays in procurement.`
          : 'Consider following up to avoid delays in procurement.',
      filter: { status: 'pending' },
      actionLabel: 'Show these',
    })
  }

  if (signals.high_value_pending > 0 && signals.high_value_threshold > 0) {
    insights.push({
      id: 'high-value',
      severity: 'warning',
      title: `${signals.high_value_pending} high-value ${plural(signals.high_value_pending, 'requisition is', 'requisitions are')} waiting for approval.`,
      description: 'Each is above this company’s approval threshold, so nothing moves until somebody signs.',
      filter: { status: 'pending', minValue: String(signals.high_value_threshold) },
      actionLabel: 'Show these',
    })
  }

  if (signals.due_soon > 0) {
    insights.push({
      id: 'due-soon',
      severity: 'info',
      title: `${signals.due_soon} ${plural(signals.due_soon, 'requisition is', 'requisitions are')} needed within ${signals.due_soon_days} days.`,
      description: 'Approve or start sourcing now if the delivery lead time is longer than that.',
      filter: { status: 'pending' },
      actionLabel: 'Show these',
    })
  }

  if (signals.busiest_department && signals.busiest_department.pending > 1) {
    insights.push({
      id: 'busiest-department',
      severity: 'info',
      title: `${signals.busiest_department.department} has ${signals.busiest_department.pending} requisitions waiting.`,
      description: 'The most of any department right now — worth one conversation rather than several.',
      filter: { status: 'pending', department: signals.busiest_department.department },
      actionLabel: 'Show that department',
    })
  }

  if (signals.stalled_drafts > 0) {
    insights.push({
      id: 'stalled-drafts',
      severity: 'info',
      title: `${signals.stalled_drafts} ${plural(signals.stalled_drafts, 'draft has', 'drafts have')} sat unsubmitted for over ${signals.stalled_draft_days} days.`,
      description: 'A draft is not in anybody’s queue. Submit it or delete it.',
      filter: { status: 'draft' },
      actionLabel: 'Show drafts',
    })
  }

  if (signals.rejected_30d > 0) {
    insights.push({
      id: 'rejected',
      severity: 'info',
      title: `${signals.rejected_30d} ${plural(signals.rejected_30d, 'requisition was', 'requisitions were')} rejected in the last 30 days.`,
      description: 'Read the rejection notes — the same reason twice is usually a form, not a person.',
      filter: { status: 'rejected' },
      actionLabel: 'Show rejected',
    })
  }

  if (signals.missing_department > 0) {
    insights.push({
      id: 'missing-department',
      severity: 'info',
      title: `${signals.missing_department} ${plural(signals.missing_department, 'draft has', 'drafts have')} no department set.`,
      description: 'Approval routing and spend reporting both read the department, so both are guessing.',
      filter: { status: 'draft' },
      actionLabel: 'Show drafts',
    })
  }

  return insights
}

/**
 * What the banner says when there is nothing to report.
 *
 * Not the same as an empty screen: a company with requisitions and no problems
 * has earned being told so. A company with no requisitions at all gets no
 * banner, because there is genuinely nothing to say.
 */
export function allClearInsight(pending: number): Insight {
  return {
    id: 'all-clear',
    severity: 'positive',
    title: pending > 0 ? 'Nothing is stuck.' : 'Nothing is waiting for approval.',
    description:
      pending > 0
        ? `${pending} requisition${pending === 1 ? ' is' : 's are'} in approval and none of them has been waiting long.`
        : 'Every requisition raised so far has been dealt with.',
    filter: {},
    actionLabel: 'Ask about spend',
  }
}
