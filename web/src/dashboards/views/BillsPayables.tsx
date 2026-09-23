/**
 * Bills & Payables — the matching workbench, the payment planner and intake.
 *
 * What is held up and why, and what falls due next. The division of labour is
 * on the screen as well as in the code: the exceptions are ours, the money is
 * Smart Books', and neither is ever derived from the other.
 *
 * The ageing panel that used to live here has moved to the payables command
 * centre's own card, which draws Books' buckets as a donut beside the figures
 * they belong with. It is the same panel from the same API — it is not drawn
 * twice on one screen.
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertOctagon, Ban, Check, X } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { Badge, DashboardPanel, DataTable, EmptyState, PanelUnavailable } from '../shell'
import { SegmentedBar } from '../charts'
import { Drawer } from '../Drawer'
import type { DashboardFilters } from '../filters'
import type { DashboardResponse, MatchRow, Panel, PaymentRow } from '../types'

type MatchingPanel = Panel<{
  category: string | null
  categories: { id: string; label: string }[]
  counts: Record<string, number>
  rows: MatchRow[]
  tolerances: {
    policy_name: string
    qty_pc: string
    rate_pc: string
    value_amount: string
    freight_amount: string
    auto_match_below: string
    route: string
  } | null
  can_resolve: boolean
  basis: string
}>
type PlanningPanel = Panel<{
  rows: PaymentRow[]
  currency: string
  windows: Record<string, { amount: string; formatted: string; label: string }>
  covered_suppliers: number
  requested_suppliers: number
  as_of: string
  scope_note: string
  pay_note: string
  basis: string
}>
type IntakePanel = Panel<{
  stages: { id: string; label: string; count: number; route: string; filters: Record<string, string> }[]
  stuck_commands: { command_id: number; command_type: string; entity_id: number; status: string; attempts: number; last_error: string | null; retryable: boolean; route: string }[]
  capabilities: { id: string; label: string; available: boolean; reason?: string }[]
  basis: string
}>

/**
 * The three outcomes behind the match bar.
 *
 * `matched` is not a category the API returns — it is everything the period
 * produced minus what raised an exception, so it is computed here from the
 * counts rather than invented. When the API has no total to subtract from, the
 * bar simply shows the exception categories: a "matched" count guessed at would
 * be the one number on this screen nobody could trace back to a record.
 *
 * @param counts every exception category and its count
 * @param categories the categories the API defined, in its own order
 */
function matchOutcome(
  counts: Record<string, number>,
  categories: { id: string; label: string }[],
): { id: string; label: string; count: number; tone: 'good' | 'warn' | 'bad' }[] {
  const matched = counts.matched ?? 0
  const exceptions = categories
    .filter((category) => category.id !== 'matched')
    .map((category) => ({
      id: category.id,
      label: category.label,
      count: counts[category.id] ?? 0,
      // Missing paperwork is a harder stop than a tolerance breach: one is a
      // decision waiting to be made, the other is a document that does not
      // exist yet.
      tone: category.id.includes('missing') || category.id.includes('receipt') ? ('bad' as const) : ('warn' as const),
    }))

  return matched > 0
    ? [{ id: 'matched', label: 'Matched', count: matched, tone: 'good' as const }, ...exceptions]
    : exceptions
}

/**
 * Which of Books' ageing buckets one open item falls in.
 *
 * Derived from the SAME `days_overdue` Books returned on that item, so
 * selecting a bucket on the ageing donut and reading the bills behind it here
 * cannot disagree — both sides of that click are Books' own arithmetic, not
 * this screen re-ageing anything against its own clock.
 */
function bucketOf(daysOverdue: number | null): string {
  if (daysOverdue === null || daysOverdue <= 0) return 'not_due'
  if (daysOverdue <= 30) return 'b_0_30'
  if (daysOverdue <= 60) return 'b_31_60'
  if (daysOverdue <= 90) return 'b_61_90'
  return 'b_90_plus'
}

const BUCKET_LABELS: Record<string, string> = {
  not_due: 'Not due',
  b_0_30: '1 – 30 days overdue',
  b_31_60: '31 – 60 days',
  b_61_90: '61 – 90 days',
  b_90_plus: 'Over 90 days',
}

export function BillsPayablesWorkbench({
  data,
  filters,
  onChanged,
}: {
  data: DashboardResponse
  filters: DashboardFilters
  onChanged: () => void
}) {
  const navigate = useNavigate()
  const [resolving, setResolving] = useState<MatchRow | null>(null)

  const matching = data.panels.matching as MatchingPanel
  const planning = data.panels.payment_planning as PlanningPanel
  const intake = data.panels.intake as IntakePanel

  // The ageing card is a filter as well as a picture: picking a bucket there
  // narrows the bills listed here to the ones Books put in it.
  const bucket = filters.get('bucket')
  const planningRows =
    planning.available && bucket !== null
      ? planning.rows.filter((row) => bucketOf(row.days_overdue) === bucket)
      : planning.available
        ? planning.rows
        : []

  return (
    <>
      <DashboardPanel
        title="PO – receipt – invoice matching"
        description={matching.available ? matching.basis : undefined}
        className="purchase-span-all"
        action={
          matching.available &&
          matching.tolerances && (
            <button type="button" className="purchase-button purchase-button--secondary" onClick={() => navigate(matching.tolerances!.route)}>
              Tolerances: {matching.tolerances.policy_name}
            </button>
          )
        }
      >
        {!matching.available ? (
          <PanelUnavailable reason={matching.reason} kind={matching.kind} />
        ) : (
          <>
            <div className="purchase-chips" style={{ marginBottom: 16 }}>
              <button
                type="button"
                className={matching.category === null ? 'purchase-chip is-active' : 'purchase-chip'}
                onClick={() => filters.set({ category: null })}
              >
                All exceptions
              </button>
              {matching.categories.map((category) => (
                <button
                  key={category.id}
                  type="button"
                  className={matching.category === category.id ? 'purchase-chip is-active' : 'purchase-chip'}
                  aria-pressed={matching.category === category.id}
                  onClick={() => filters.set({ category: category.id })}
                >
                  {category.label}
                  <span className="purchase-segment__count">{matching.counts[category.id] ?? 0}</span>
                </button>
              ))}
            </div>

            {/* How the period came out, before the list of what went wrong.
                These are OUTCOMES, not identities, so they take the status
                palette and each carries its own label and count — the colour
                never does the work on its own. */}
            <SegmentedBar
              segments={matchOutcome(matching.counts, matching.categories)}
              onOpen={(id) => filters.set({ category: id === 'matched' ? null : id })}
            />

            <DataTable
              caption={matching.basis}
              rows={matching.rows}
              rowKey={(row) => `${row.kind}-${row.exception_id ?? row.bill_request_id}`}
              onRowOpen={(row) => navigate(row.route)}
              empty={<EmptyState title="Nothing is held up here.">Every bill in this category matched.</EmptyState>}
              columns={[
                {
                  key: 'bill',
                  header: 'Bill',
                  render: (row) => (
                    <>
                      <button type="button" className="purchase-table__link" onClick={() => navigate(row.route)}>
                        {row.supplier_invoice_no ?? `#${row.bill_request_id}`}
                      </button>
                      <span className="purchase-table__sub">{row.supplier_name ?? '—'}</span>
                    </>
                  ),
                },
                {
                  key: 'rule',
                  header: 'What failed',
                  render: (row) => (
                    <>
                      {row.rule}
                      {row.po_no && <span className="purchase-table__sub">against {row.po_no}</span>}
                      {row.compared_reference && (
                        <span className="purchase-table__sub">compared with {row.compared_reference}</span>
                      )}
                    </>
                  ),
                },
                {
                  key: 'variance',
                  header: 'Variance',
                  numeric: true,
                  render: (row) => row.variance_formatted ?? <span className="purchase-muted">—</span>,
                },
                { key: 'age', header: 'Open for', numeric: true, render: (row) => `${row.age_days}d` },
                {
                  key: 'resolve',
                  header: 'Decision',
                  render: (row) =>
                    row.kind === 'exception' && matching.can_resolve ? (
                      <button type="button" className="purchase-button purchase-button--secondary" onClick={() => setResolving(row)}>
                        Decide
                      </button>
                    ) : row.kind === 'exception' ? (
                      <span className="purchase-muted" title="Needs the match.resolve permission">Not yours to decide</span>
                    ) : (
                      <button type="button" className="purchase-button purchase-button--quiet" onClick={() => navigate(row.route)}>
                        Compare
                      </button>
                    ),
                },
              ]}
            />
          </>
        )}
      </DashboardPanel>

      <div className="purchase-dashboard-grid">
        <DashboardPanel title="Bill intake" description={intake.available ? intake.basis : undefined}>
          {!intake.available ? (
            <PanelUnavailable reason={intake.reason} kind={intake.kind} />
          ) : (
            <>
              <div className="purchase-pipeline">
                {intake.stages.map((stage) => (
                  <button
                    key={stage.id}
                    type="button"
                    className="purchase-pipeline__stage"
                    onClick={() => navigate(`${stage.route}?${new URLSearchParams(stage.filters).toString()}`)}
                  >
                    <span className="purchase-pipeline__count">{stage.count}</span>
                    <span className="purchase-pipeline__label">{stage.label}</span>
                  </button>
                ))}
              </div>

              {intake.stuck_commands.length > 0 && (
                <div className="purchase-notice purchase-notice--danger" style={{ marginTop: 16 }}>
                  <AlertOctagon size={18} aria-hidden style={{ flexShrink: 0, marginTop: 2 }} />
                  <div>
                    <strong>
                      {intake.stuck_commands.length} posting request did not finish
                    </strong>
                    <p>
                      Nothing is retried behind your back. Open the bill to see the error and retry on the same
                      idempotency key, so Smart Books cannot end up with two vouchers.
                    </p>
                    <ul style={{ margin: '10px 0 0', paddingLeft: 18, fontSize: 13 }}>
                      {intake.stuck_commands.slice(0, 4).map((command) => (
                        <li key={command.command_id}>
                          <button type="button" className="purchase-table__link" onClick={() => navigate(command.route)}>
                            {command.command_type} #{command.entity_id}
                          </button>{' '}
                          — {command.last_error ?? command.status} ({command.attempts} attempts)
                        </li>
                      ))}
                    </ul>
                  </div>
                </div>
              )}

              <h3 style={{ margin: '18px 0 8px', fontSize: 13 }}>What this intake can and cannot do</h3>
              <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 8 }}>
                {intake.capabilities.map((capability) => (
                  <li key={capability.id} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 13 }}>
                    {capability.available ? (
                      <Check size={15} aria-hidden style={{ color: 'var(--purchase-action)', flexShrink: 0, marginTop: 2 }} />
                    ) : (
                      <Ban size={15} aria-hidden style={{ color: 'var(--purchase-muted)', flexShrink: 0, marginTop: 2 }} />
                    )}
                    <span style={{ color: capability.available ? 'inherit' : 'var(--purchase-muted)' }}>
                      {capability.label}
                      {capability.reason && <span className="purchase-table__sub">{capability.reason}</span>}
                    </span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </DashboardPanel>

        <DashboardPanel
          title="Payment planning"
          description={planning.available ? planning.basis : undefined}
          className="purchase-span-all"
        >
          {!planning.available ? (
            <PanelUnavailable reason={planning.reason} kind={planning.kind} />
          ) : (
            <>
              <div className="purchase-pipeline" style={{ marginBottom: 16 }}>
                {Object.entries(planning.windows).map(([id, window]) => (
                  <div key={id} className="purchase-pipeline__stage" style={{ cursor: 'default' }}>
                    <span className="purchase-pipeline__count" style={{ fontSize: 18 }}>
                      {window.formatted}
                    </span>
                    <span className="purchase-pipeline__label">{window.label}</span>
                  </div>
                ))}
              </div>

              {bucket !== null && (
                <div className="purchase-chips" style={{ marginBottom: 12 }}>
                  <button type="button" className="purchase-chip is-active" onClick={() => filters.set({ bucket: null })}>
                    Ageing: {BUCKET_LABELS[bucket] ?? bucket}
                    <X size={12} aria-hidden style={{ marginLeft: 4 }} />
                  </button>
                </div>
              )}

              <DataTable
                caption={planning.basis}
                rows={planningRows}
                rowKey={(row) => `${row.supplier_account_id}-${row.bill_ref}`}
                empty={<EmptyState title="Nothing is open for these suppliers." />}
                columns={[
                  {
                    key: 'supplier',
                    header: 'Supplier',
                    render: (row) => (
                      <>
                        {row.supplier_name ?? `Account ${row.supplier_account_id}`}
                        <span className="purchase-table__sub">{row.bill_ref ?? '—'}</span>
                      </>
                    ),
                  },
                  {
                    key: 'due',
                    header: 'Due',
                    render: (row) => (
                      <>
                        {row.due_label}
                        {row.days_overdue !== null && (
                          <span className="purchase-table__sub" style={{ color: 'var(--purchase-danger)' }}>
                            {row.days_overdue} days overdue
                          </span>
                        )}
                      </>
                    ),
                  },
                  {
                    key: 'amount',
                    header: 'Outstanding',
                    numeric: true,
                    render: (row) => (
                      <>
                        {row.pending_formatted}
                        {row.part_paid && <span className="purchase-table__sub">part paid</span>}
                      </>
                    ),
                  },
                  {
                    key: 'state',
                    header: 'State',
                    render: (row) =>
                      row.held ? (
                        <span title={row.held_reason ?? undefined}>
                          <Badge tone="danger">Held</Badge>
                        </span>
                      ) : (
                        <Badge tone="neutral">Not proposed</Badge>
                      ),
                  },
                ]}
              />

              <div className="purchase-notice purchase-notice--info" style={{ marginTop: 16 }}>
                <div>
                  <strong>What this panel covers, and what a proposal does</strong>
                  <p>{planning.scope_note}</p>
                  <p>{planning.pay_note}</p>
                </div>
              </div>
            </>
          )}
        </DashboardPanel>
      </div>

      <ResolveDrawer
        row={resolving}
        onClose={() => setResolving(null)}
        onDecided={() => {
          setResolving(null)
          onChanged()
        }}
      />
    </>
  )
}

// ---------------------------------------------------------------------------

function ResolveDrawer({
  row,
  onClose,
  onDecided,
}: {
  row: MatchRow | null
  onClose: () => void
  onDecided: () => void
}) {
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const decide = async (endpoint: string) => {
    setBusy(true)
    setError(null)
    try {
      await api.post(endpoint, { note })
      setNote('')
      onDecided()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'That decision could not be recorded.')
    } finally {
      setBusy(false)
    }
  }

  // Accepting a variance costs the company money, so the reason is required
  // here as well as on the server.
  const canAccept = note.trim().length >= 4

  return (
    <Drawer
      open={row !== null}
      title={row ? `Match exception on ${row.supplier_invoice_no ?? `bill #${row.bill_request_id}`}` : 'Match exception'}
      subtitle={row?.rule}
      onClose={onClose}
    >
      {row && (
        <div style={{ display: 'grid', gap: 18 }}>
          <dl className="purchase-dl">
            <dt>Supplier</dt>
            <dd>{row.supplier_name ?? '—'}</dd>
            <dt>Purchase order</dt>
            <dd>{row.po_no ?? 'None'}</dd>
            <dt>Variance</dt>
            <dd>{row.variance_formatted ?? '—'}</dd>
            <dt>Open for</dt>
            <dd>{row.age_days} days</dd>
          </dl>

          <label style={{ display: 'grid', gap: 6 }}>
            <span style={{ fontSize: 12, color: 'var(--purchase-muted)', fontWeight: 650 }}>
              Reason (required to accept a variance)
            </span>
            <textarea
              value={note}
              onChange={(event) => setNote(event.target.value)}
              rows={3}
              placeholder="Why is this difference acceptable?"
              style={{ padding: 10, border: '1px solid var(--purchase-border)', borderRadius: 10, font: 'inherit', resize: 'vertical' }}
            />
          </label>

          {error && (
            <div className="purchase-notice purchase-notice--danger">
              <div>
                <strong>Not recorded</strong>
                <p>{error}</p>
              </div>
            </div>
          )}

          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <button
              type="button"
              className="purchase-button purchase-button--primary"
              disabled={busy || !canAccept}
              title={canAccept ? undefined : 'A written reason is required before a variance can be accepted.'}
              onClick={() => row.accept_endpoint && decide(row.accept_endpoint)}
            >
              <Check size={15} aria-hidden /> {busy ? 'Recording…' : 'Accept the variance'}
            </button>
            <button
              type="button"
              className="purchase-button purchase-button--secondary"
              disabled={busy}
              onClick={() => row.reject_endpoint && decide(row.reject_endpoint)}
            >
              <X size={15} aria-hidden /> Reject and query the supplier
            </button>
          </div>

          <p className="purchase-muted" style={{ fontSize: 12, margin: 0 }}>
            Accepting records the decision against the bill and lets it post. It does not change the order, the receipt
            or anything in Smart Books until the bill is posted.
          </p>
        </div>
      )}
    </Drawer>
  )
}
