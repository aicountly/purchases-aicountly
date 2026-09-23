/**
 * The panels beside the queue.
 *
 * Each one answers a question somebody asks WHILE looking at the queue: how
 * fast are we deciding, what is the money waiting on, what is wrong right now,
 * and what is worth reading before approving anything. None of them is a
 * decoration and none of them draws a figure this product cannot produce — a
 * panel with nothing behind it says so and keeps its footprint.
 */

import { useNavigate } from 'react-router-dom'
import {
  ChevronRight,
  CircleAlert,
  CircleCheckBig,
  Info,
  Lightbulb,
  TrendingUp,
  TriangleAlert,
} from 'lucide-react'
import { DonutChart } from '../dashboards/charts'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../dashboards/shell'
import type { ApprovalInsight, ApprovalRisk, ApprovalSummary, ExceptionRow, TrendPoint } from './types'

// ---------------------------------------------------------------------------
// Pending value by document type
// ---------------------------------------------------------------------------

export function PendingValuePanel({ panel }: { panel: ApprovalSummary['by_type'] }) {
  return (
    <DashboardPanel
      title="Pending value by document"
      description={panel.available ? panel.basis : undefined}
      className="purchase-approvals-mix"
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.rows.length === 0 ? (
        <EmptyState title="Nothing is waiting for approval.">
          There is no value to divide up while the queue is empty.
        </EmptyState>
      ) : (
        <>
          <DonutChart
            title="Pending approval value by document type"
            summary={`Value awaiting approval, ${panel.total_exact} in total, split by document type.`}
            segments={panel.rows.map((row) => ({
              id: row.id,
              label: row.label,
              share: row.share_pc,
              formatted: row.formatted,
            }))}
            centreLabel="Pending"
            centreValue={panel.total_formatted}
          />
          <dl className="purchase-dl purchase-approvals-mix__list">
            {panel.rows.map((row) => (
              <div key={row.id}>
                <dt>
                  {row.label}
                  <span className="purchase-table__sub">
                    {row.documents} {row.documents === 1 ? 'document' : 'documents'}
                  </span>
                </dt>
                <dd>{row.formatted}</dd>
              </div>
            ))}
          </dl>
        </>
      )}
    </DashboardPanel>
  )
}

// ---------------------------------------------------------------------------
// Decision trend
// ---------------------------------------------------------------------------

export function ApprovalTrendPanel({ panel }: { panel: ApprovalSummary['trend'] }) {
  if (!panel.available) {
    return (
      <DashboardPanel title="Decision trend" className="purchase-approvals-trend">
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      </DashboardPanel>
    )
  }

  const busiest = panel.points.reduce((most, point) => Math.max(most, point.approved + point.rejected), 0)

  return (
    <DashboardPanel
      title="Decision trend"
      description={panel.basis}
      className="purchase-approvals-trend"
      action={<span className="purchase-panel__tag">Last 6 months</span>}
    >
      {panel.points.length === 0 || busiest === 0 ? (
        <EmptyState title="No decisions in the last six months.">
          {panel.note ?? 'The trend appears once approvals start being decided.'}
        </EmptyState>
      ) : (
        <>
          <div className="purchase-bars" role="img" aria-label={trendSummary(panel.points)}>
            {panel.points.map((point) => {
              const decided = point.approved + point.rejected
              return (
                <div key={point.period} className="purchase-bar-row">
                  <span className="purchase-bar-row__label">{point.label}</span>
                  <span className="purchase-bar-row__track">
                    <span
                      className="purchase-bar-row__fill"
                      style={{ width: `${busiest === 0 ? 0 : (point.approved / busiest) * 100}%` }}
                    />
                    <span
                      className="purchase-bar-row__fill purchase-bar-row__fill--rejected"
                      style={{ width: `${busiest === 0 ? 0 : (point.rejected / busiest) * 100}%` }}
                    />
                  </span>
                  <span className="purchase-bar-row__value" title={`${point.approved} approved, ${point.rejected} rejected`}>
                    {decided === 0 ? '—' : decided}
                  </span>
                </div>
              )
            })}
          </div>

          <p className="purchase-legend">
            <span>
              <i style={{ background: 'var(--purchase-brand-strong)' }} /> Approved
            </span>
            <span>
              <i style={{ background: 'var(--purchase-bad)' }} /> Rejected
            </span>
          </p>

          {/* The average is a different unit from the counts, so it is stated
              rather than drawn onto the same track. */}
          <dl className="purchase-dl purchase-approvals-trend__times">
            {panel.points
              .filter((point) => point.avg_days !== null)
              .slice(-3)
              .map((point) => (
                <div key={point.period}>
                  <dt>{point.label} decision time</dt>
                  <dd>{point.avg_days} days</dd>
                </div>
              ))}
          </dl>
        </>
      )}
    </DashboardPanel>
  )
}

function trendSummary(points: TrendPoint[]): string {
  return points.map((point) => `${point.label}: ${point.approved} approved, ${point.rejected} rejected`).join('. ')
}

// ---------------------------------------------------------------------------
// Match exceptions
// ---------------------------------------------------------------------------

export function MatchExceptionsPanel({
  panel,
  rows,
  loading,
  onViewAll,
}: {
  panel: ApprovalSummary['exceptions']
  /** The exceptions themselves, from `v1/match-exceptions`. */
  rows: ExceptionRow[]
  loading: boolean
  onViewAll: () => void
}) {
  const navigate = useNavigate()

  return (
    <DashboardPanel
      title="Match exceptions"
      description={panel.available ? panel.basis : undefined}
      className="purchase-approvals-exceptions"
      action={
        panel.available && panel.rows.length > 0 ? (
          <button type="button" className="purchase-panel__action" onClick={onViewAll}>
            Open the bills
          </button>
        ) : undefined
      }
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.rows.length === 0 ? (
        <EmptyState title="No open match exceptions">
          Every bill currently agrees with its order and its receipt.
        </EmptyState>
      ) : (
        <>
          {/* The summary first — what kind of disagreement, and how much of it
              — then the documents themselves, because the summary is what a
              reader scans and the rows are what they act on. */}
          <ul className="purchase-intel-list">
            {panel.rows.map((row) => (
              <li key={row.id}>
                <button type="button" onClick={onViewAll} title={panel.basis}>
                  <span className="purchase-intel-list__icon is-warning" aria-hidden>
                    <TriangleAlert size={15} />
                  </span>
                  <span className="purchase-intel-list__copy">
                    <strong>
                      {row.documents} {row.documents === 1 ? 'bill' : 'bills'} · {row.label}
                    </strong>
                    <span>
                      {row.variance_formatted === null
                        ? 'Blocking the bill from being posted.'
                        : `${row.variance_formatted} of variance in total.`}
                    </span>
                  </span>
                  <ChevronRight size={15} aria-hidden className="purchase-intel-list__chevron" />
                </button>
              </li>
            ))}
          </ul>

          {loading ? (
            <p className="purchase-panel__note">Loading the documents behind these…</p>
          ) : rows.length === 0 ? null : (
            <div className="purchase-table-scroll purchase-approvals-exception-rows">
              <table className="purchase-table">
                <caption className="purchase-sr-only">Open three-way match exceptions</caption>
                <thead>
                  <tr>
                    <th scope="col">Supplier invoice</th>
                    <th scope="col">Purchase order</th>
                    <th scope="col">What did not agree</th>
                    <th scope="col" className="is-numeric">Variance</th>
                    <th scope="col">Found</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.exception_id}>
                      <td>
                        {row.bill_request_id === null ? (
                          <span>{row.supplier_invoice_no ?? '—'}</span>
                        ) : (
                          <button
                            type="button"
                            className="purchase-table__link"
                            onClick={() => navigate(`/bills/${row.bill_request_id}`)}
                          >
                            {row.supplier_invoice_no ?? `Bill #${row.bill_request_id}`}
                          </button>
                        )}
                      </td>
                      <td>{row.po_no ?? '—'}</td>
                      <td>
                        {row.exception_kind.replace(/_/g, ' ')}
                        {row.detail && <span className="purchase-table__sub">{row.detail}</span>}
                      </td>
                      <td className="is-numeric">{row.variance_value ?? '—'}</td>
                      <td>{(row.created_at ?? '').slice(0, 10)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </DashboardPanel>
  )
}

// ---------------------------------------------------------------------------
// Risks
// ---------------------------------------------------------------------------

const RISK_FACE = {
  critical: { icon: CircleAlert, tone: 'danger' },
  warning: { icon: TriangleAlert, tone: 'warning' },
  info: { icon: Info, tone: 'info' },
} as const

export function ApprovalRisksPanel({ panel }: { panel: ApprovalSummary['risks'] }) {
  const navigate = useNavigate()

  const open = (risk: ApprovalRisk) => {
    const query = new URLSearchParams(risk.filters).toString()
    navigate(query === '' ? risk.route : `${risk.route}?${query}`)
  }

  return (
    <DashboardPanel title="Approval risks & issues" className="purchase-intel-risks">
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.rows.length === 0 ? (
        <EmptyState title="Nothing about this queue needs attention.">
          The rules behind this list still ran; none of them matched.
        </EmptyState>
      ) : (
        <ul className="purchase-intel-list">
          {panel.rows.map((risk) => {
            const face = RISK_FACE[risk.severity]
            const Icon = face.icon
            return (
              <li key={risk.id}>
                <button type="button" onClick={() => open(risk)} title={risk.basis}>
                  <span className={`purchase-intel-list__icon is-${face.tone}`} aria-hidden>
                    <Icon size={15} />
                  </span>
                  <span className="purchase-intel-list__copy">
                    <strong>{risk.title}</strong>
                    <span>{risk.detail}</span>
                  </span>
                  <ChevronRight size={15} aria-hidden className="purchase-intel-list__chevron" />
                </button>
              </li>
            )
          })}
        </ul>
      )}
    </DashboardPanel>
  )
}

// ---------------------------------------------------------------------------
// Insights
// ---------------------------------------------------------------------------

const INSIGHT_FACE = {
  success: { icon: CircleCheckBig, tone: 'success' },
  warning: { icon: TriangleAlert, tone: 'warning' },
  danger: { icon: CircleAlert, tone: 'danger' },
  info: { icon: TrendingUp, tone: 'info' },
} as const

export function ApprovalInsightsPanel({
  panel,
  onAsk,
}: {
  panel: ApprovalSummary['insights']
  onAsk: () => void
}) {
  const navigate = useNavigate()

  const open = (insight: ApprovalInsight) => {
    const query = new URLSearchParams(insight.filters).toString()
    navigate(query === '' ? insight.route : `${insight.route}?${query}`)
  }

  return (
    <DashboardPanel
      title="Approval insights"
      className="purchase-intel-insights"
      description={<span className="purchase-panel__tag purchase-panel__tag--quiet">Rules only</span>}
      action={
        <button type="button" className="purchase-panel__action" onClick={onAsk}>
          Ask AI
        </button>
      }
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.rows.length === 0 ? (
        <EmptyState title="Nothing stands out in this queue.">
          <Lightbulb size={13} aria-hidden /> The rules behind this list still ran; none of them found anything to say.
        </EmptyState>
      ) : (
        <>
          <ul className="purchase-intel-list">
            {panel.rows.map((insight) => {
              const face = INSIGHT_FACE[insight.tone]
              const Icon = face.icon
              return (
                <li key={insight.id}>
                  <button type="button" onClick={() => open(insight)} title={insight.basis}>
                    <span className={`purchase-intel-list__icon is-${face.tone}`} aria-hidden>
                      <Icon size={15} />
                    </span>
                    <span className="purchase-intel-list__copy">
                      <strong>
                        {insight.title}
                        <em className={`purchase-kind purchase-kind--${insight.kind}`}>{insight.kind_label}</em>
                      </strong>
                      <span>{insight.detail}</span>
                    </span>
                    <ChevronRight size={15} aria-hidden className="purchase-intel-list__chevron" />
                  </button>
                </li>
              )
            })}
          </ul>
          <p className="purchase-panel__note">{panel.method_label}</p>
        </>
      )}
    </DashboardPanel>
  )
}
