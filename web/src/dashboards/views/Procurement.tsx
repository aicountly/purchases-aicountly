/**
 * Dashboard 2 — Procurement.
 *
 * The buyer's screen. Denser than the Overview and every row ends in an action,
 * because a list that says what is wrong and not what to do about it has just
 * moved the thinking somewhere else.
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { CalendarClock, Package, ThumbsDown, ThumbsUp } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { Badge, DashboardPanel, DataTable, EmptyState, PanelUnavailable } from '../shell'
import { Drawer } from '../Drawer'
import type { DashboardFilters } from '../filters'
import type {
  ApprovalRow,
  DashboardResponse,
  Panel,
  ReorderRow,
  TimelineGroup,
  WorkbenchRow,
} from '../types'

type WorkbenchPanel = Panel<{
  view: string
  views: { id: string; label: string }[]
  rows: WorkbenchRow[]
  total: number
  limit: number
  offset: number
  values_visible: boolean
  basis: string
}>
type TimelinePanel = Panel<{ horizon_days: number; groups: TimelineGroup[]; basis: string }>
type ReorderPanel = Panel<{ rows: ReorderRow[]; as_of: string | null; basis: string }>
type ApprovalPanel = Panel<{ rows: ApprovalRow[]; total: number; basis: string }>
type QuotePanel = Panel<Record<string, unknown>>

export function ProcurementDashboard({
  data,
  filters,
  onChanged,
}: {
  data: DashboardResponse
  filters: DashboardFilters
  onChanged: () => void
}) {
  const navigate = useNavigate()
  const [decision, setDecision] = useState<ApprovalRow | null>(null)

  const workbench = data.panels.workbench as WorkbenchPanel
  const timeline = data.panels.delivery_timeline as TimelinePanel
  const reorder = data.panels.reorder as ReorderPanel
  const approvals = data.panels.approval_inbox as ApprovalPanel
  const quotes = data.panels.quote_comparison as QuotePanel

  const page = (offset: number) => filters.set({ offset: String(Math.max(0, offset)) })

  return (
    <>
      <DashboardPanel
        title="Procurement workbench"
        description={workbench.available ? workbench.basis : undefined}
        className="purchase-span-all"
        action={
          workbench.available && (
            <div className="purchase-chips">
              {workbench.views.map((view) => (
                <button
                  key={view.id}
                  type="button"
                  className={workbench.view === view.id ? 'purchase-chip is-active' : 'purchase-chip'}
                  aria-pressed={workbench.view === view.id}
                  onClick={() => filters.set({ view: view.id, offset: null })}
                >
                  {view.label}
                </button>
              ))}
            </div>
          )
        }
      >
        {!workbench.available ? (
          <PanelUnavailable reason={workbench.reason} kind={workbench.kind} />
        ) : (
          <>
            <DataTable
              caption={workbench.basis}
              rows={workbench.rows}
              rowKey={(row) => row.po_id}
              onRowOpen={(row) => navigate(row.route)}
              empty={<EmptyState title="Nothing in this view.">Try another view, or widen the date range.</EmptyState>}
              columns={[
                {
                  key: 'po',
                  header: 'Order',
                  render: (row) => (
                    <>
                      <button type="button" className="purchase-table__link" onClick={() => navigate(row.route)}>
                        {row.po_no}
                      </button>
                      <span className="purchase-table__sub">{row.po_date_label}</span>
                    </>
                  ),
                },
                {
                  key: 'supplier',
                  header: 'Supplier',
                  render: (row) => (
                    <>
                      {row.supplier_name ?? `Account ${row.supplier_account_id}`}
                      <span className="purchase-table__sub">
                        {row.line_count} line{row.line_count === 1 ? '' : 's'}, {row.open_lines} open
                      </span>
                    </>
                  ),
                },
                {
                  key: 'promised',
                  header: 'Promised',
                  render: (row) => (
                    <>
                      {row.promised_label ?? '—'}
                      {row.days_late !== null && (
                        <span className="purchase-table__sub" style={{ color: 'var(--purchase-danger)' }}>
                          {row.days_late} days late
                        </span>
                      )}
                    </>
                  ),
                },
                {
                  key: 'value',
                  header: 'Order value',
                  numeric: true,
                  render: (row) =>
                    row.value_formatted === null ? (
                      <span className="purchase-muted">Hidden</span>
                    ) : (
                      <>
                        {row.value_formatted}
                        <span className="purchase-table__sub">{row.remaining_formatted} to arrive</span>
                      </>
                    ),
                },
                {
                  key: 'status',
                  header: 'Status',
                  render: (row) => (
                    <Badge
                      tone={
                        row.days_late !== null
                          ? 'danger'
                          : row.status === 'APPROVAL_PENDING'
                            ? 'warning'
                            : row.status === 'PARTIALLY_RECEIVED'
                              ? 'info'
                              : 'neutral'
                      }
                    >
                      {row.status.replace(/_/g, ' ')}
                    </Badge>
                  ),
                },
                { key: 'action', header: 'Next action', render: (row) => row.next_action },
              ]}
            />

            {workbench.total > workbench.limit && (
              <div
                style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginTop: 14 }}
              >
                <span className="purchase-muted" style={{ fontSize: 12 }}>
                  Showing {workbench.offset + 1}–{Math.min(workbench.offset + workbench.limit, workbench.total)} of{' '}
                  {workbench.total}
                </span>
                <div style={{ display: 'flex', gap: 8 }}>
                  <button
                    type="button"
                    className="purchase-button purchase-button--secondary"
                    disabled={workbench.offset === 0}
                    onClick={() => page(workbench.offset - workbench.limit)}
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    className="purchase-button purchase-button--secondary"
                    disabled={workbench.offset + workbench.limit >= workbench.total}
                    onClick={() => page(workbench.offset + workbench.limit)}
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </DashboardPanel>

      <div className="purchase-dashboard-grid">
        <DashboardPanel
          title="Reorder review"
          description={reorder.available ? reorder.basis : undefined}
          action={
            reorder.available && reorder.as_of ? (
              <span className="purchase-muted" style={{ fontSize: 11 }}>
                Inventory as of {reorder.as_of}
              </span>
            ) : undefined
          }
        >
          {!reorder.available ? (
            <PanelUnavailable reason={reorder.reason} kind={reorder.kind} />
          ) : reorder.rows.length === 0 ? (
            <EmptyState title="Nothing is below its reorder level." />
          ) : (
            <DataTable
              caption={reorder.basis}
              rows={reorder.rows}
              rowKey={(row) => row.item_id}
              empty={<EmptyState title="Nothing short." />}
              columns={[
                {
                  key: 'item',
                  header: 'Material',
                  render: (row) => (
                    <span
                      style={{ display: 'flex', alignItems: 'center', gap: 6 }}
                      title={row.basis}
                    >
                      <Package size={13} aria-hidden style={{ color: 'var(--purchase-muted)', flexShrink: 0 }} />
                      {row.item_label}
                    </span>
                  ),
                },
                {
                  key: 'stock',
                  header: 'Available',
                  numeric: true,
                  render: (row) => (
                    <>
                      {row.available_label}
                      {row.stale && <span className="purchase-table__sub">Figure may be stale</span>}
                    </>
                  ),
                },
                {
                  key: 'on_order',
                  header: 'On order',
                  numeric: true,
                  render: (row) => (
                    <>
                      {row.on_order_label}
                      {row.next_arrival_label && <span className="purchase-table__sub">{row.next_arrival_label}</span>}
                    </>
                  ),
                },
                {
                  key: 'lead',
                  header: 'Lead time',
                  numeric: true,
                  render: (row) => (row.lead_days === null ? <span className="purchase-muted">Not reported</span> : `${row.lead_days}d`),
                },
                {
                  key: 'suggested',
                  header: 'Draft order',
                  numeric: true,
                  render: (row) => <strong>{row.suggested_label}</strong>,
                },
              ]}
              onRowOpen={(row) => navigate(`${row.route}?${new URLSearchParams(row.filters).toString()}`)}
            />
          )}
          {reorder.available && reorder.rows.length > 0 && (
            <p className="purchase-muted" style={{ fontSize: 12, marginTop: 14 }}>
              Every draft quantity is Inventory's suggestion less what is already on an unreceived order here. Hover a
              material to see the arithmetic for that row.
            </p>
          )}
        </DashboardPanel>

        <DashboardPanel
          title="Incoming deliveries"
          description={timeline.available ? timeline.basis : undefined}
          action={
            timeline.available && (
              <label>
                Horizon
                <select
                  value={filters.get('horizon') ?? '7'}
                  onChange={(event) => filters.set({ horizon: event.target.value })}
                >
                  <option value="7">Next 7 days</option>
                  <option value="14">Next 14 days</option>
                  <option value="30">Next 30 days</option>
                  <option value="90">Next 90 days</option>
                </select>
              </label>
            )
          }
        >
          {!timeline.available ? (
            <PanelUnavailable reason={timeline.reason} kind={timeline.kind} />
          ) : timeline.groups.length === 0 ? (
            <EmptyState title="Nothing due in this window.">
              Widen the horizon to see deliveries further out.
            </EmptyState>
          ) : (
            <div className="purchase-timeline">
              {timeline.groups.map((group) => (
                <details
                  key={group.date ?? 'undated'}
                  className={group.is_overdue ? 'purchase-timeline__group is-overdue' : 'purchase-timeline__group'}
                  open={group.is_overdue}
                >
                  <summary className="purchase-timeline__summary">
                    <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <CalendarClock size={15} aria-hidden />
                      {group.date_label}
                    </span>
                    <span className="purchase-muted" style={{ fontSize: 12, fontWeight: 500 }}>
                      {group.lines.length} line{group.lines.length === 1 ? '' : 's'}
                      {group.is_overdue && ' · overdue'}
                    </span>
                  </summary>
                  <table className="purchase-table">
                    <caption className="purchase-sr-only">
                      Order lines expected on {group.date_label}. Quantities are shown per line with their unit.
                    </caption>
                    <thead>
                      <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Item</th>
                        <th scope="col" className="is-numeric">Still to arrive</th>
                      </tr>
                    </thead>
                    <tbody>
                      {group.lines.map((line) => (
                        <tr key={line.line_id}>
                          <td className="is-compact">
                            <button type="button" className="purchase-table__link" onClick={() => navigate(line.route)}>
                              {line.po_no}
                            </button>
                            <span className="purchase-table__sub">{line.supplier_name}</span>
                          </td>
                          <td className="is-compact">
                            {line.item_label}
                            {line.revised && (
                              <span className="purchase-table__sub" style={{ color: 'var(--purchase-warning)' }}>
                                Date revised from {line.revised_from}
                              </span>
                            )}
                          </td>
                          {/* The unit travels with the quantity, always. */}
                          <td className="is-compact is-numeric">{line.remaining_label}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </details>
              ))}
            </div>
          )}
        </DashboardPanel>

        <DashboardPanel
          title="Approval inbox"
          description={approvals.available ? approvals.basis : undefined}
          className="purchase-span-all"
        >
          {!approvals.available ? (
            <PanelUnavailable reason={approvals.reason} kind={approvals.kind} />
          ) : approvals.rows.length === 0 ? (
            <EmptyState title="Nothing is waiting on you." />
          ) : (
            <DataTable
              caption={approvals.basis}
              rows={approvals.rows}
              rowKey={(row) => row.approval_id}
              empty={<EmptyState title="Nothing is waiting on you." />}
              columns={[
                {
                  key: 'reference',
                  header: 'Document',
                  render: (row) => (
                    <>
                      <button type="button" className="purchase-table__link" onClick={() => navigate(row.route)}>
                        {row.reference}
                      </button>
                      <span className="purchase-table__sub">
                        {row.entity_type.replace(/_/g, ' ')} · {row.supplier_name ?? '—'}
                      </span>
                    </>
                  ),
                },
                { key: 'reason', header: 'Why it needs approval', render: (row) => row.reason ?? '—' },
                {
                  key: 'value',
                  header: 'Value',
                  numeric: true,
                  render: (row) => (
                    <>
                      {row.value_formatted ?? '—'}
                      {row.threshold_formatted && (
                        <span className="purchase-table__sub">threshold {row.threshold_formatted}</span>
                      )}
                    </>
                  ),
                },
                { key: 'age', header: 'Waiting', numeric: true, render: (row) => `${row.age_days}d` },
                {
                  key: 'decide',
                  header: 'Decision',
                  render: (row) => (
                    <button type="button" className="purchase-button purchase-button--secondary" onClick={() => setDecision(row)}>
                      Approve or reject
                    </button>
                  ),
                },
              ]}
            />
          )}
        </DashboardPanel>
      </div>

      <QuoteComparison panel={quotes} filters={filters} />

      <ApprovalDrawer
        approval={decision}
        onClose={() => setDecision(null)}
        onDecided={() => {
          setDecision(null)
          onChanged()
        }}
      />
    </>
  )
}

// ---------------------------------------------------------------------------

function ApprovalDrawer({
  approval,
  onClose,
  onDecided,
}: {
  approval: ApprovalRow | null
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
      // The server decides whether this user may approve; a refusal here is the
      // real answer, not a UI bug to hide.
      setError(err instanceof ApiError ? err.message : 'That decision could not be recorded.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Drawer
      open={approval !== null}
      title={approval ? `Approve ${approval.reference}` : 'Approve'}
      subtitle={approval?.reason ?? undefined}
      onClose={onClose}
    >
      {approval && (
        <div style={{ display: 'grid', gap: 18 }}>
          <dl className="purchase-dl">
            <dt>Supplier</dt>
            <dd>{approval.supplier_name ?? '—'}</dd>
            <dt>Value</dt>
            <dd>{approval.value_formatted ?? '—'}</dd>
            <dt>Threshold</dt>
            <dd>{approval.threshold_formatted ?? '—'}</dd>
            <dt>Stage</dt>
            <dd>{approval.stage}</dd>
            <dt>Raised</dt>
            <dd>{approval.raised_label ?? '—'}</dd>
            <dt>Waiting</dt>
            <dd>{approval.age_days} days</dd>
          </dl>

          <label style={{ display: 'grid', gap: 6 }}>
            <span style={{ fontSize: 12, color: 'var(--purchase-muted)', fontWeight: 650 }}>
              Note (recorded against the decision)
            </span>
            <textarea
              value={note}
              onChange={(event) => setNote(event.target.value)}
              rows={3}
              style={{
                padding: 10,
                border: '1px solid var(--purchase-border)',
                borderRadius: 10,
                font: 'inherit',
                resize: 'vertical',
              }}
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
              disabled={busy}
              onClick={() => decide(approval.approve_endpoint)}
            >
              <ThumbsUp size={15} aria-hidden /> {busy ? 'Recording…' : 'Approve'}
            </button>
            <button
              type="button"
              className="purchase-button purchase-button--secondary"
              disabled={busy}
              onClick={() => decide(approval.reject_endpoint)}
            >
              <ThumbsDown size={15} aria-hidden /> Reject
            </button>
          </div>

          <p className="purchase-muted" style={{ fontSize: 12, margin: 0 }}>
            The permission behind this decision is checked again on the server. Nobody approves a document they raised,
            whatever permissions they hold.
          </p>
        </div>
      )}
    </Drawer>
  )
}

// ---------------------------------------------------------------------------

interface QuoteOffer {
  quote_id: number
  quoted_rate: string
  lead_days: number | null
  comparable: boolean
  not_comparable_reason: string | null
}

interface QuoteLine {
  rfq_line_id: number
  line_no: number
  item_label: string
  unit: string | null
  required_label: string
  specification: string | null
  offers: QuoteOffer[]
  best_quote_id: number | null
}

interface QuoteRow {
  quote_id: number
  supplier_account_id: number
  quote_ref: string | null
  currency: string
  payment_terms: string | null
  delivery_days: number | null
  freight_amount: string
  other_charges: string
  qualification_status: string | null
  is_preferred: boolean
}

function QuoteComparison({ panel, filters }: { panel: QuotePanel; filters: DashboardFilters }) {
  const navigate = useNavigate()

  if (!panel.available) {
    return (
      <DashboardPanel title="Supplier quotation comparison" className="purchase-span-all">
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      </DashboardPanel>
    )
  }

  const basis = panel.basis as string

  if (panel.mode === 'list') {
    const rfqs = panel.rfqs as {
      rfq_id: number
      rfq_no: string
      title: string | null
      status: string
      deadline_label: string | null
      quote_count: number
      invited_count: number
      route: string
    }[]

    return (
      <DashboardPanel title="Supplier quotation comparison" description={basis} className="purchase-span-all">
        <DataTable
          caption={basis}
          rows={rfqs}
          rowKey={(row) => row.rfq_id}
          empty={<EmptyState title="No RFQ is awaiting evaluation." />}
          columns={[
            {
              key: 'rfq',
              header: 'RFQ',
              render: (row) => (
                <>
                  <button type="button" className="purchase-table__link" onClick={() => navigate(row.route)}>
                    {row.rfq_no}
                  </button>
                  <span className="purchase-table__sub">{row.title ?? '—'}</span>
                </>
              ),
            },
            { key: 'status', header: 'Status', render: (row) => <Badge tone="info">{row.status.replace(/_/g, ' ')}</Badge> },
            { key: 'deadline', header: 'Responses close', render: (row) => row.deadline_label ?? '—' },
            {
              key: 'quotes',
              header: 'Quotes',
              numeric: true,
              render: (row) => `${row.quote_count} of ${row.invited_count}`,
            },
            {
              key: 'compare',
              header: 'Compare',
              render: (row) => (
                <button
                  type="button"
                  className="purchase-button purchase-button--quiet"
                  onClick={() => filters.set({ rfq_id: String(row.rfq_id) })}
                >
                  Compare quotes
                </button>
              ),
            },
          ]}
        />
      </DashboardPanel>
    )
  }

  const rfq = panel.rfq as { rfq_no: string; title: string | null; route: string }
  const quotes = panel.quotes as QuoteRow[]
  const lines = panel.lines as QuoteLine[]
  const caveat = panel.caveat as string | null

  return (
    <DashboardPanel
      title={`Quotation comparison — ${rfq.rfq_no}`}
      description={basis}
      className="purchase-span-all"
      action={
        <button type="button" className="purchase-button purchase-button--secondary" onClick={() => filters.set({ rfq_id: null })}>
          Back to open RFQs
        </button>
      }
    >
      {caveat && (
        <div className="purchase-notice purchase-notice--warning" style={{ marginBottom: 16 }}>
          <div>
            <strong>Not directly comparable</strong>
            <p>{caveat}</p>
          </div>
        </div>
      )}

      <div className="purchase-table-scroll">
        <table className="purchase-table">
          <caption className="purchase-sr-only">{basis}</caption>
          <thead>
            <tr>
              <th scope="col">Line</th>
              <th scope="col" className="is-numeric">Required</th>
              {quotes.map((quote) => (
                <th key={quote.quote_id} scope="col" className="is-numeric">
                  {quote.quote_ref ?? `Account ${quote.supplier_account_id}`}
                  <span className="purchase-table__sub">
                    {quote.currency}
                    {quote.is_preferred ? ' · preferred' : ''}
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {lines.map((line) => (
              <tr key={line.rfq_line_id}>
                <th scope="row" style={{ fontWeight: 500 }}>
                  {line.item_label}
                  {line.specification && <span className="purchase-table__sub">{line.specification}</span>}
                </th>
                <td className="is-numeric">{line.required_label}</td>
                {quotes.map((quote) => {
                  const offer = line.offers.find((candidate) => candidate.quote_id === quote.quote_id)
                  if (!offer) {
                    return (
                      <td key={quote.quote_id} className="is-numeric purchase-muted">
                        Not quoted
                      </td>
                    )
                  }
                  const best = line.best_quote_id === quote.quote_id
                  return (
                    <td
                      key={quote.quote_id}
                      className="is-numeric"
                      style={best ? { background: 'var(--purchase-soft)', fontWeight: 700 } : undefined}
                    >
                      {offer.quoted_rate}
                      {!offer.comparable && (
                        <span className="purchase-table__sub" style={{ color: 'var(--purchase-warning)' }}>
                          {offer.not_comparable_reason}
                        </span>
                      )}
                      {offer.lead_days !== null && <span className="purchase-table__sub">{offer.lead_days}d lead</span>}
                    </td>
                  )
                })}
              </tr>
            ))}
            <tr>
              <th scope="row" style={{ fontWeight: 500 }}>Freight and other charges</th>
              <td className="is-numeric">—</td>
              {quotes.map((quote) => (
                <td key={quote.quote_id} className="is-numeric">
                  {quote.freight_amount} + {quote.other_charges}
                </td>
              ))}
            </tr>
            <tr>
              <th scope="row" style={{ fontWeight: 500 }}>Payment terms</th>
              <td className="is-numeric">—</td>
              {quotes.map((quote) => (
                <td key={quote.quote_id} className="is-numeric">
                  {quote.payment_terms ?? '—'}
                </td>
              ))}
            </tr>
          </tbody>
        </table>
      </div>

      <p className="purchase-muted" style={{ fontSize: 12, marginTop: 14 }}>
        Charges stay at quote level rather than being spread across lines — spreading them invents a per-unit cost
        nobody quoted. <button type="button" className="purchase-table__link" onClick={() => navigate(rfq.route)}>Open the RFQ</button> to award it.
      </p>
    </DashboardPanel>
  )
}
