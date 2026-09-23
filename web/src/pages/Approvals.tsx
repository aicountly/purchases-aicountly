/**
 * My approvals.
 *
 * WHAT THIS SCREEN IS FOR. One person, one question: what is waiting on me, and
 * what do I need to know before I decide it. Everything else on the page exists
 * to answer that — the figures say whether the queue is healthy, the two lists
 * say what is wrong with it, and the table is where the decision is made.
 *
 * IT IS THE SAME SCREEN AS THE DASHBOARDS. Same header, same green card, same
 * filter row, same KPI tiles, same panels, same table — literally the same
 * components, not a copy of their appearance. An approvals inbox that invented
 * its own version of a metric card would be a second design system to keep in
 * step, and the first thing to drift would be what an unavailable figure looks
 * like.
 *
 * THREE HONESTY RULES IT KEEPS.
 *
 *  - The period narrows DECIDED documents. A pending approval is pending
 *    whatever month it was raised in; hiding an aged one behind a date filter
 *    defeats the point of the screen, so the pending tabs ignore the period and
 *    say so.
 *  - The risk chip is four facts this product already records, not a score. The
 *    facts travel with it and are printed in full in the review drawer.
 *  - The backend is the authority. `may_approve` greys a button; the domain
 *    services are what refuse the request, including the rule that the person
 *    who raised a document may not approve it.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  CheckCheck,
  ClipboardList,
  Download,
  FileWarning,
  Inbox,
  Search,
  Sparkles,
  UserRound,
  X,
} from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { PurchaseDashboardShell } from '../dashboards/shell'
import type { Drilldown } from '../dashboards/types'
import { ApprovalQueue } from '../approvals/ApprovalQueue'
import { DecisionDialog } from '../approvals/DecisionDialog'
import {
  ApprovalInsightsPanel,
  ApprovalRisksPanel,
  ApprovalTrendPanel,
  MatchExceptionsPanel,
  PendingValuePanel,
} from '../approvals/panels'
import { ReviewDrawer } from '../approvals/ReviewDrawer'
import { APPROVAL_PERIODS, useApprovalFilters, useDebounced } from '../approvals/state'
import {
  APPROVAL_TABS,
  DOCUMENT_TYPES,
  QUEUE_TABS,
  type ApprovalQueueMeta,
  type ApprovalQueueRow,
  type ApprovalRequester,
  type ExceptionRow,
  type ApprovalSummary,
  type ApprovalTabId,
} from '../approvals/types'
import '../dashboards/purchase.css'

const TAB_ICON: Record<ApprovalTabId, typeof Inbox> = {
  mine: Inbox,
  raised_by_me: UserRound,
  all_pending: ClipboardList,
  actioned: CheckCheck,
  exceptions: FileWarning,
  insights: Sparkles,
}

interface Decision {
  row: ApprovalQueueRow
  action: 'approve' | 'reject'
}

export default function Approvals() {
  const navigate = useNavigate()
  const { scope, can, session } = usePurchases()
  const filters = useApprovalFilters()

  // The URL holds the settled term; the box holds what is being typed. A
  // history entry per keystroke is a Back button nobody can use.
  const [typed, setTyped] = useState(filters.q)
  const settled = useDebounced(typed)

  useEffect(() => setTyped(filters.q), [filters.q])

  useEffect(() => {
    if (settled !== filters.q) filters.set({ q: settled })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settled])

  useEffect(() => {
    document.title = 'Approvals · Aicountly Purchases'
  }, [])

  const [reloadToken, setReloadToken] = useState(0)
  const [decision, setDecision] = useState<Decision | null>(null)
  const [reviewing, setReviewing] = useState<ApprovalQueueRow | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [decisionError, setDecisionError] = useState<string | null>(null)
  const [outcome, setOutcome] = useState<string | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const ready = Boolean(scope)
  const onQueueTab = QUEUE_TABS.includes(filters.tab)

  const summary = useApi<ApprovalSummary>(
    (signal) => api.get<{ data: ApprovalSummary }>('v1/approvals/summary', filters.summaryParams, signal).then((r) => r.data),
    [scope?.cmp_id, scope?.fy_id, filters.preset, reloadToken],
    ready,
  )

  const queue = useApi(
    (signal) => api.list<ApprovalQueueRow>('v1/approvals/queue', filters.queueParams, signal),
    [scope?.cmp_id, scope?.fy_id, JSON.stringify(filters.queueParams), reloadToken],
    ready && onQueueTab,
  )

  // Only on the tab that lists them: the summary already carries the counts
  // every other tab needs, and a request nobody will read is a request not
  // worth making.
  const exceptions = useApi(
    (signal) => api.list<ExceptionRow>('v1/match-exceptions', { limit: 50 }, signal),
    [scope?.cmp_id, scope?.fy_id, reloadToken],
    ready && filters.tab === 'exceptions' && can('match.view'),
  )

  const requesters = useApi<ApprovalRequester[]>(
    (signal) => api.get<{ data: ApprovalRequester[] }>('v1/approvals/requesters', undefined, signal).then((r) => r.data),
    [scope?.cmp_id, scope?.fy_id, reloadToken],
    ready,
  )

  /** Everything the decision touched, refetched together. Never a page reload. */
  const reloadEverything = useCallback(() => setReloadToken((token) => token + 1), [])

  const decide = async (note: string) => {
    if (decision === null) return
    const { row, action } = decision

    const base = row.entity_type === 'requisition' ? 'v1/requisitions' : 'v1/purchase-orders'
    setBusyId(row.approval_id)
    setDecisionError(null)
    try {
      await api.post(`${base}/${row.entity_id}/${action}`, note === '' ? {} : { note })
      setDecision(null)
      setReviewing(null)
      setOutcome(
        `${row.document_label} ${action === 'approve' ? 'approved' : 'rejected'}. The queue and the figures have been refreshed.`,
      )
      reloadEverything()
    } catch (error) {
      setDecisionError(error instanceof ApiError ? error.message : String(error))
    } finally {
      setBusyId(null)
    }
  }

  /**
   * The filtered queue, as a CSV.
   *
   * There is no approvals export endpoint, so rather than leaving a dead button
   * this takes the rows the API would return for the filters on screen — up to
   * the one page the API will serve — and writes them out with the same
   * formatted figures the table shows. The row count is in the filename, so
   * nobody reconciles against a file that was silently truncated.
   */
  const exportQueue = async () => {
    setExporting(true)
    setExportError(null)
    try {
      const page = await api.list<ApprovalQueueRow>('v1/approvals/queue', { ...filters.queueParams, limit: 200, offset: 0 })
      const header = [
        'Document', 'Type', 'Supplier', 'Amount', 'Raised by', 'Department',
        'Raised on', 'Waiting (days)', 'Risk', 'Why', 'Status',
      ]
      const lines = [header, ...page.data.map((row) => [
        row.document_label,
        row.type_label,
        row.supplier_name ?? '',
        row.amount_formatted ?? '',
        row.requester_label,
        row.requester_department ?? '',
        row.requested_at_label ?? '',
        String(row.age_days),
        row.risk_label,
        row.reason_detail ?? row.reason_kind,
        row.status_label,
      ])]

      const csv = lines.map((line) => line.map(csvCell).join(',')).join('\r\n')
      const url = URL.createObjectURL(new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8' }))
      const link = document.createElement('a')
      link.href = url
      link.download = `approvals-${filters.tab}-${page.data.length}-rows.csv`
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch (error) {
      setExportError(error instanceof ApiError ? error.message : 'That export could not be produced.')
    } finally {
      setExporting(false)
    }
  }

  const meta = (queue.data?.meta ?? null) as ApprovalQueueMeta | null

  const tabs = useMemo(
    () => (
      <nav className="purchase-switcher purchase-switcher--six" aria-label="Approvals">
        {APPROVAL_TABS.map((tab) => {
          const Icon = TAB_ICON[tab.id]
          const count = tabCount(tab.id, summary.data)
          return (
            <button
              key={tab.id}
              type="button"
              className={filters.tab === tab.id ? 'purchase-switcher__button is-active' : 'purchase-switcher__button'}
              aria-current={filters.tab === tab.id ? 'page' : undefined}
              onClick={() => filters.set({ tab: tab.id, scope: null, status: null, page: null })}
            >
              <Icon size={16} aria-hidden />
              {tab.label}
              {count !== null && count > 0 && <span className="purchase-switcher__count">{count}</span>}
            </button>
          )
        })}
      </nav>
    ),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [filters.tab, summary.data],
  )

  const failed = summary.error !== null && summary.data === null

  return (
    <>
      <PurchaseDashboardShell
        title="Approvals"
        subtitle="Review purchase requests, orders and exceptions that need your decision."
        breadcrumb="My approvals"
        monitorNoun="Approval queue"
        sources={summary.data?.sources ?? []}
        metrics={summary.data?.metrics ?? []}
        loading={summary.loading && summary.data === null}
        refreshing={summary.loading || queue.loading}
        fetchedAt={summary.data ? new Date(summary.data.generated_at) : null}
        onRefresh={reloadEverything}
        feature={{
          title: 'Faster approvals',
          description: 'Keep purchasing moving without losing control.',
          actionLabel: 'Show everything still waiting',
          onAction: () => filters.set({ tab: 'all_pending', scope: null, status: null }),
        }}
        actions={
          <>
            <button
              type="button"
              className="purchase-button purchase-button--primary"
              onClick={() => void exportQueue()}
              disabled={exporting || !onQueueTab}
              title={onQueueTab ? 'Take the filtered queue as a CSV' : 'Open a queue tab to export it'}
            >
              <Download size={15} aria-hidden /> {exporting ? 'Exporting…' : 'Export'}
            </button>
          </>
        }
        filters={
          <>
            <label className="purchase-field">
              <span>Period</span>
              <select value={filters.preset} onChange={(event) => filters.set({ preset: event.target.value })}>
                {APPROVAL_PERIODS.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="purchase-field">
              <span>Document type</span>
              <select value={filters.type} onChange={(event) => filters.set({ type: event.target.value })}>
                <option value="">All documents</option>
                {DOCUMENT_TYPES.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="purchase-field">
              <span>Requested by</span>
              <select value={filters.requester} onChange={(event) => filters.set({ requester: event.target.value })}>
                <option value="">All requesters</option>
                {(requesters.data ?? []).map((person) => (
                  <option key={person.user_uuid} value={person.user_uuid}>
                    {person.label}
                    {person.pending > 0 ? ` (${person.pending})` : ''}
                  </option>
                ))}
              </select>
            </label>

            <label className="purchase-field purchase-field--search">
              <span>Search</span>
              <span className="purchase-searchbox">
                <Search size={15} aria-hidden />
                <input
                  type="search"
                  value={typed}
                  onChange={(event) => setTyped(event.target.value)}
                  placeholder="Document, supplier or requester…"
                  aria-label="Search approvals"
                />
              </span>
            </label>

            {filters.tab === 'actioned' && (
              <label className="purchase-field">
                <span>Outcome</span>
                <select value={filters.status} onChange={(event) => filters.set({ status: event.target.value })}>
                  <option value="">Approved and rejected</option>
                  <option value="APPROVED">Approved</option>
                  <option value="REJECTED">Rejected</option>
                </select>
              </label>
            )}

            {filters.isNarrowed && (
              <button type="button" className="purchase-button purchase-field__clear" onClick={filters.clear}>
                <X size={14} aria-hidden /> Clear
              </button>
            )}
          </>
        }
        tabs={tabs}
      >
        {outcome && (
          <div className="purchase-notice purchase-notice--success purchase-approvals-outcome" role="status">
            <span>{outcome}</span>
            <button type="button" onClick={() => setOutcome(null)} aria-label="Dismiss">
              <X size={14} aria-hidden />
            </button>
          </div>
        )}

        {exportError && (
          <div className="purchase-notice purchase-notice--danger purchase-approvals-outcome" role="alert">
            <span>{exportError}</span>
            <button type="button" onClick={() => setExportError(null)} aria-label="Dismiss">
              <X size={14} aria-hidden />
            </button>
          </div>
        )}

        {/* The same line every dashboard prints under its figures: what scope
            they were computed over, and what they are being compared against. */}
        {summary.data && (
          <p className="purchase-scope-line">
            {summary.data.period.label} · {summary.data.scope.branch_label}
            {summary.data.scope.reporting_currency === null && ' · mixed currencies'}
            {summary.data.period.comparison_mode === 'previous_period' && ` · ${summary.data.period.comparison_label}`}
          </p>
        )}

        {/* The period only narrows decided documents, so the pending tabs say
            so rather than leaving a reader to wonder why an old approval is
            still listed under "This month". */}
        {onQueueTab && filters.tab !== 'actioned' && (
          <p className="purchase-approvals-scope-note">
            Everything still pending is shown, whenever it was raised. The period narrows decided documents and the
            figures above.
          </p>
        )}

        {failed ? (
          <ErrorState message={summary.error ?? ''} onRetry={reloadEverything} />
        ) : (
          <>
            {/* Two zones, not three. The queue carries nine columns and two
                buttons per row, and squeezed into a third of the page it was a
                table nobody could read — so the analytics move under it rather
                than take width off the thing this screen exists for. */}
            <div className="purchase-approvals-grid">
              <MainColumn
                tab={filters.tab}
                summary={summary.data}
                exceptions={(exceptions.data?.data ?? []) as ExceptionRow[]}
                exceptionsLoading={exceptions.loading && exceptions.data === null}
                rows={(queue.data?.data ?? []) as ApprovalQueueRow[]}
                meta={meta}
                loading={queue.loading && queue.data === null}
                narrowed={filters.isNarrowed}
                busyId={busyId}
                onApprove={(row) => setDecision({ row, action: 'approve' })}
                onReject={(row) => setDecision({ row, action: 'reject' })}
                onOpenReview={setReviewing}
                onPage={(page) => filters.set({ page })}
                onPageSize={(size) => filters.set({ size, page: null })}
                onClearFilters={filters.clear}
                onAsk={() => navigate('/dashboard/ai-insights?ask=1')}
              />

              <div className="purchase-intel-column">
                {summary.data ? (
                  <>
                    <ApprovalRisksPanel panel={summary.data.risks} />
                    <ApprovalInsightsPanel
                      panel={summary.data.insights}
                      onAsk={() => navigate('/dashboard/ai-insights?ask=1')}
                    />
                  </>
                ) : (
                  <PanelSkeleton count={2} />
                )}
              </div>
            </div>

            <div className="purchase-approvals-analytics">
              {summary.data ? (
                <>
                  <ApprovalTrendPanel panel={summary.data.trend} />
                  <PendingValuePanel panel={summary.data.by_type} />
                </>
              ) : (
                <PanelSkeleton count={2} />
              )}
            </div>
          </>
        )}

        {/* The one rule this screen exists to enforce, said once, in words. */}
        <p className="purchase-approvals-rule">
          A document cannot be approved by the person who raised it
          {session?.is_owner ? ', except by the company owner.' : '.'}
        </p>

        {/* `can` is read so the screen degrades with the session rather than
            assuming everybody may decide everything. The buttons themselves
            take their answer from the row, which the server computed. */}
        {!can('po.approve') && !can('requisition.approve') && (
          <p className="purchase-sr-only">You may view approvals but not decide them.</p>
        )}

        {/* INSIDE the shell, not beside it. Both are position: fixed and cover
            the viewport either way, but the whole visual system is declared on
            `.purchase-workspace` — rendered as a sibling they inherited none of
            it and drew as unstyled text over the page. */}
        <ReviewDrawer
          row={reviewing}
          onClose={() => setReviewing(null)}
          onApprove={(row) => setDecision({ row, action: 'approve' })}
          onReject={(row) => setDecision({ row, action: 'reject' })}
        />

        {decision && (
          <DecisionDialog
            row={decision.row}
            action={decision.action}
            busy={busyId !== null}
            error={decisionError}
            onCancel={() => {
              setDecision(null)
              setDecisionError(null)
            }}
            onConfirm={(note) => void decide(note)}
          />
        )}
      </PurchaseDashboardShell>
    </>
  )
}

// ---------------------------------------------------------------------------

function MainColumn({
  tab,
  summary,
  exceptions,
  exceptionsLoading,
  rows,
  meta,
  loading,
  narrowed,
  busyId,
  onApprove,
  onReject,
  onOpenReview,
  onPage,
  onPageSize,
  onClearFilters,
  onAsk,
}: {
  tab: ApprovalTabId
  summary: ApprovalSummary | null
  exceptions: ExceptionRow[]
  exceptionsLoading: boolean
  rows: ApprovalQueueRow[]
  meta: ApprovalQueueMeta | null
  loading: boolean
  narrowed: boolean
  busyId: number | null
  onApprove: (row: ApprovalQueueRow) => void
  onReject: (row: ApprovalQueueRow) => void
  onOpenReview: (row: ApprovalQueueRow) => void
  onPage: (page: number) => void
  onPageSize: (size: number) => void
  onClearFilters: () => void
  onAsk: () => void
}) {
  const navigate = useNavigate()

  if (tab === 'exceptions') {
    return summary ? (
      <MatchExceptionsPanel
        panel={summary.exceptions}
        rows={exceptions}
        loading={exceptionsLoading}
        onViewAll={() => navigate('/bills')}
      />
    ) : (
      <PanelSkeleton count={1} />
    )
  }

  if (tab === 'insights') {
    return summary ? <ApprovalInsightsPanel panel={summary.insights} onAsk={onAsk} /> : <PanelSkeleton count={1} />
  }

  return (
    <ApprovalQueue
      tab={tab}
      rows={rows}
      meta={meta}
      loading={loading}
      narrowed={narrowed}
      busyId={busyId}
      onApprove={onApprove}
      onReject={onReject}
      onOpenReview={onOpenReview}
      onPage={onPage}
      onPageSize={onPageSize}
      onClearFilters={onClearFilters}
    />
  )
}

/**
 * The shell stays; the content says what went wrong.
 *
 * Never the raw message from the database — the API has already turned that
 * into a sentence, and anything it did not is logged rather than printed.
 */
function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="purchase-panel purchase-approvals-error">
      <div className="purchase-panel__body">
        <h2>We couldn't load approvals.</h2>
        <p className="purchase-muted">{message || 'Please try again.'}</p>
        <button type="button" className="purchase-button purchase-button--primary" onClick={onRetry}>
          Retry
        </button>
      </div>
    </div>
  )
}

function PanelSkeleton({ count }: { count: number }) {
  return (
    <>
      {Array.from({ length: count }, (_, index) => (
        <div key={index} className="purchase-panel purchase-skeleton purchase-approvals-skeleton__panel" aria-hidden />
      ))}
    </>
  )
}

function tabCount(tab: ApprovalTabId, summary: ApprovalSummary | null): number | null {
  if (summary === null) return null

  return tab === 'mine'
    ? summary.counts.mine
    : tab === 'all_pending'
      ? summary.counts.pending
      : tab === 'raised_by_me'
        ? summary.counts.raised_by_me
        : tab === 'exceptions'
          ? summary.counts.exceptions
          : null
}

/** RFC 4180: quote anything holding a comma, a quote or a newline. */
function csvCell(value: string): string {
  return /[",\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

// Kept so a drill-down target stays a typed thing rather than a string literal
// scattered through the file.
export type { Drilldown }
