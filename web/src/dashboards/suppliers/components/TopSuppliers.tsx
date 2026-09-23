/**
 * The ranking.
 *
 * Every rate on this card carries the number of observations behind it. A
 * supplier rated 0% on-time from a single delivery is a rating that will be
 * ignored the first time somebody checks it, and then so will the next one.
 *
 * The card shows the top five. The rest are one click away rather than one
 * scroll away: a dashboard panel that is secretly a hundred-row table is a
 * table nobody reads and a page nobody can scan.
 *
 * VIEW ALL IS ALSO VIEW MORE. Opening it adds the four columns the summary
 * leaves out — lead time, the six-month delivery shape, what is still open and
 * what to do next. They are not dropped, they are just not what somebody
 * glancing at a dashboard is asking, and putting all nine columns in a 600px
 * panel means nine columns nobody can read.
 */

import { ChevronRight, ShieldCheck } from 'lucide-react'
import { Badge, DashboardPanel, EmptyState, PanelUnavailable } from '../../shell'
import { Sparkline } from '../../charts'
import type { SupplierRow } from '../../types'
import type { MatrixPanel, ScoreModel } from '../types'
import { riskLevel, scoreBand, supplierStanding, type Thresholds } from '../signals'

const SHOWN = 5

export function TopSuppliers({
  matrix,
  scoreModel,
  limits,
  expanded,
  onToggleExpanded,
  onOpenSupplier,
}: {
  matrix: MatrixPanel
  scoreModel: ScoreModel
  limits: Thresholds
  expanded: boolean
  onToggleExpanded: () => void
  onOpenSupplier: (row: SupplierRow) => void
}) {
  const rows = matrix.available ? matrix.rows : []
  const shown = expanded ? rows : rows.slice(0, SHOWN)

  return (
    <DashboardPanel
      title="Top suppliers performance"
      description="Ranked by overall performance score"
      flush
      action={
        rows.length > SHOWN ? (
          <button type="button" className="purchase-button purchase-button--quiet" onClick={onToggleExpanded}>
            {expanded ? 'Show the top 5' : 'View all'}
          </button>
        ) : undefined
      }
    >
      {!matrix.available ? (
        <div style={{ padding: '0 17px 17px' }}>
          <PanelUnavailable reason={matrix.reason} kind={matrix.kind} />
        </div>
      ) : rows.length === 0 ? (
        <EmptyState title="No supplier has an order in this period.">
          Widen the period, or clear a filter, and this ranking fills itself.
        </EmptyState>
      ) : (
        <>
          <div className="purchase-table-scroll">
            <table className="purchase-table">
              <caption className="purchase-sr-only">{matrix.basis}</caption>
              <thead>
                <tr>
                  <th scope="col">#</th>
                  <th scope="col">Supplier</th>
                  <th scope="col" className="is-numeric">Score</th>
                  <th scope="col" className="is-numeric">Ordered</th>
                  <th scope="col" className="is-numeric">Accepted</th>
                  <th scope="col" className="is-numeric">On time</th>
                  {expanded && <th scope="col" className="is-numeric">Lead time</th>}
                  {expanded && <th scope="col">Trend</th>}
                  {expanded && <th scope="col" className="is-numeric">Open exposure</th>}
                  <th scope="col">Risk</th>
                  <th scope="col">Status</th>
                  {expanded && <th scope="col">Next action</th>}
                  <th scope="col"><span className="purchase-sr-only">Open</span></th>
                </tr>
              </thead>
              <tbody>
                {shown.map((row, index) => {
                  const risk = riskLevel(row, limits)
                  const standing = supplierStanding(row)

                  return (
                    <tr key={row.supplier_account_id}>
                      <td className="purchase-rank">{index + 1}</td>

                      <td className="is-wide">
                        <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                          <strong style={{ fontWeight: 650, color: 'var(--purchase-ink)' }}>
                            {row.supplier_name ?? `Account ${row.supplier_account_id}`}
                          </strong>
                          {row.is_preferred && (
                            <ShieldCheck size={12} aria-label="Preferred supplier" style={{ color: 'var(--purchase-brand-strong)', flexShrink: 0 }} />
                          )}
                        </span>
                        <span className="purchase-table__sub">
                          {row.po_count} order{row.po_count === 1 ? '' : 's'}
                        </span>
                      </td>

                      <td className="is-numeric">
                        {row.score === null ? (
                          <span
                            className="purchase-score purchase-score--none"
                            title={`Not enough data: ${row.score_missing.join(', ')}`}
                          >
                            —
                          </span>
                        ) : (
                          <span
                            className={`purchase-score purchase-score--${scoreBand(row.score, limits)}`}
                            title={row.score_components
                              .map((component) =>
                                `${component.label}: ${component.counted ? `${component.value}% (weight ${component.weight})` : 'not counted'}`,
                              )
                              .join('\n')}
                          >
                            {row.score}
                          </span>
                        )}
                      </td>

                      <td className="is-numeric">
                        {row.ordered_formatted ?? <span className="purchase-muted">Hidden</span>}
                      </td>

                      <td className="is-numeric">
                        <RateCell value={row.acceptance_pc} label={row.acceptance_label} sample={row.acceptance_sample} />
                      </td>

                      <td className="is-numeric">
                        <RateCell value={row.on_time_pc} label={row.on_time_label} sample={row.on_time_sample} />
                      </td>

                      {expanded && (
                        <td className="is-numeric">
                          {row.avg_lead_days === null ? <span className="purchase-muted">—</span> : `${row.avg_lead_days}d`}
                        </td>
                      )}

                      {/* The rate says where a supplier is; this says which way
                          they are going. 86% improving and 86% collapsing read
                          identically as a number. */}
                      {expanded && (
                        <td>
                          <Sparkline
                            label={`On-time delivery for ${row.supplier_name ?? `account ${row.supplier_account_id}`}`}
                            points={(row.trend_points ?? []).map((point) => ({
                              period: point.period,
                              value: point.on_time_pc,
                              sample: point.sample,
                            }))}
                          />
                        </td>
                      )}

                      {expanded && (
                        <td className="is-numeric">
                          {row.open_exposure_formatted ?? <span className="purchase-muted">—</span>}
                          {row.overdue_lines > 0 && (
                            <span className="purchase-table__sub" style={{ color: 'var(--purchase-bad)' }}>
                              {row.overdue_lines} overdue line{row.overdue_lines === 1 ? '' : 's'}
                            </span>
                          )}
                        </td>
                      )}

                      {/* The word carries the meaning; the colour only repeats it,
                          so nothing on this row rests on colour alone. */}
                      <td><Badge tone={risk.tone}>{risk.label}</Badge></td>
                      <td><Badge tone={standing.tone}>{standing.label}</Badge></td>
                      {expanded && <td className="is-wide">{row.next_action}</td>}

                      <td>
                        <button
                          type="button"
                          className="purchase-button purchase-button--quiet"
                          onClick={() => onOpenSupplier(row)}
                          aria-label={`Open ${row.supplier_name ?? `account ${row.supplier_account_id}`}`}
                        >
                          <ChevronRight size={14} aria-hidden />
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <p className="purchase-panel__foot">
            Showing {shown.length} of {rows.length} supplier{rows.length === 1 ? '' : 's'}. {scoreModel.description}
          </p>
        </>
      )}
    </DashboardPanel>
  )
}

/** A rate, with the sample it came from, never one without the other. */
function RateCell({ value, label, sample }: { value: string | null; label: string; sample: number }) {
  if (value === null) {
    return (
      <span className="purchase-muted" title={label}>
        {sample === 0 ? '—' : `${sample} obs`}
        <span className="purchase-table__sub">too few to rate</span>
      </span>
    )
  }

  const numeric = Number.parseFloat(value)

  return (
    <>
      <strong
        style={{
          fontWeight: 650,
          color:
            numeric >= 95
              ? 'var(--purchase-good)'
              : numeric >= 80
                ? 'var(--purchase-ink)'
                : 'var(--purchase-bad)',
        }}
      >
        {value}%
      </strong>
      <span className="purchase-table__sub">of {sample}</span>
    </>
  )
}
