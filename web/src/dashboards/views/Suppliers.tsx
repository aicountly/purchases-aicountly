/**
 * Dashboard 3 — Suppliers.
 *
 * Every rate on this screen carries the number of observations behind it. A
 * supplier rated 0% on-time from a single delivery is a rating that will be
 * ignored the first time somebody checks it, and then so will the next one.
 */

import { useNavigate } from 'react-router-dom'
import { ArrowDownRight, ArrowRight, ArrowUpRight, ShieldCheck } from 'lucide-react'
import { Badge, DashboardPanel, DataTable, EmptyState, PanelUnavailable } from '../shell'
import { BarChart, DonutChart, IndexLineChart, Sparkline } from '../charts'
import { Drawer } from '../Drawer'
import type { DashboardFilters } from '../filters'
import type { DashboardResponse, Panel, PriceMovementRow, SupplierRow } from '../types'

type MatrixPanel = Panel<{ rows: SupplierRow[]; currency: string; values_visible: boolean; basis: string }>
type PricePanel = Panel<{
  rows: PriceMovementRow[]
  observation_period: string
  min_sample: number
  index_note?: string
  basis: string
}>
type TrendPanel = Panel<{
  points: { period: string; sample: number; on_time: number; on_time_pc: string | null; rated: boolean }[]
  min_sample: number
  still_waiting: { lines: number; orders: number; note: string }
  basis: string
}>
type ConcentrationPanel = Panel<{
  currency: string
  total_formatted: string
  suppliers: { supplier_account_id: number; supplier_name: string | null; formatted: string; share_pc: string | null; qualification_status: string }[]
  sole_source: { item_id: number; item_label: string; supplier_name: string | null; formatted: string; line_count: number }[]
  basis: string
}>
type DetailPanel = Panel<Record<string, unknown>>

/**
 * Top four suppliers by share, and everything else as one neutral slice.
 *
 * A ninth hue is never generated: the tail folds. "Others" is grey rather than
 * a fifth colour because it is not an identity — it is the absence of one.
 */
function donutSegments(
  suppliers: { supplier_account_id: number; supplier_name: string | null; formatted: string; share_pc: string | null }[],
) {
  const head = suppliers.slice(0, 4).map((row) => ({
    id: String(row.supplier_account_id),
    label: row.supplier_name ?? `Account ${row.supplier_account_id}`,
    share: row.share_pc,
    formatted: row.formatted,
  }))

  const tail = suppliers.slice(4)
  if (tail.length === 0) return head

  // Shares are exact decimal strings from the server. Summing them here is
  // geometry for one slice, not a figure anybody reads as money — the count is
  // what the label states.
  const rest = tail.reduce((sum, row) => sum + Number.parseFloat(row.share_pc ?? '0'), 0)

  return [
    ...head,
    {
      id: 'others',
      label: `${tail.length} other supplier${tail.length === 1 ? '' : 's'}`,
      share: rest.toFixed(1),
      formatted: `${tail.length} supplier${tail.length === 1 ? '' : 's'}`,
    },
  ]
}

export function SuppliersDashboard({ data, filters }: { data: DashboardResponse; filters: DashboardFilters }) {
  const navigate = useNavigate()

  const matrix = data.panels.matrix as MatrixPanel
  const price = data.panels.price_movement as PricePanel
  const trend = data.panels.delivery_trend as TrendPanel
  const concentration = data.panels.concentration as ConcentrationPanel
  const detail = data.panels.detail as DetailPanel
  const scoreModel = data.score_model as { weights: Record<string, number>; min_sample: number; description: string }

  return (
    <>
      <DashboardPanel
        title="Supplier performance"
        description={matrix.available ? matrix.basis : undefined}
        className="purchase-span-all"
      >
        {!matrix.available ? (
          <PanelUnavailable reason={matrix.reason} kind={matrix.kind} />
        ) : (
          <>
            <DataTable
              caption={matrix.basis}
              rows={matrix.rows}
              rowKey={(row) => row.supplier_account_id}
              onRowOpen={(row) => filters.set({ supplier_id: String(row.supplier_account_id) })}
              empty={<EmptyState title="No supplier has an order in this period." />}
              columns={[
                {
                  key: 'supplier',
                  header: 'Supplier',
                  render: (row) => (
                    <>
                      <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        {row.supplier_name ?? `Account ${row.supplier_account_id}`}
                        {row.is_preferred && <ShieldCheck size={13} aria-label="Preferred supplier" style={{ color: 'var(--purchase-action)' }} />}
                      </span>
                      <span className="purchase-table__sub">
                        {row.qualification_status === 'none' ? 'No procurement profile' : row.qualification_status.replace(/_/g, ' ')}
                      </span>
                    </>
                  ),
                },
                {
                  key: 'spend',
                  header: 'Ordered',
                  numeric: true,
                  render: (row) => (
                    <>
                      {row.ordered_formatted ?? <span className="purchase-muted">Hidden</span>}
                      <span className="purchase-table__sub">
                        {row.po_count} order{row.po_count === 1 ? '' : 's'}
                      </span>
                    </>
                  ),
                },
                {
                  key: 'delivery',
                  header: 'On time',
                  numeric: true,
                  render: (row) => <RateCell value={row.on_time_pc} label={row.on_time_label} sample={row.on_time_sample} />,
                },
                {
                  key: 'acceptance',
                  header: 'Accepted',
                  numeric: true,
                  render: (row) => <RateCell value={row.acceptance_pc} label={row.acceptance_label} sample={row.acceptance_sample} />,
                },
                {
                  key: 'lead',
                  header: 'Lead time',
                  numeric: true,
                  render: (row) =>
                    row.avg_lead_days === null ? <span className="purchase-muted">—</span> : `${row.avg_lead_days}d`,
                },
                {
                  // The rate says where a supplier is; this says which way they
                  // are going. 86% improving and 86% collapsing read identically
                  // as a number and need different conversations.
                  key: 'trend',
                  header: 'Trend',
                  render: (row) => (
                    <Sparkline
                      label={`On-time delivery for ${row.supplier_name ?? `account ${row.supplier_account_id}`}`}
                      points={(row.trend_points ?? []).map((point) => ({
                        period: point.period,
                        value: point.on_time_pc,
                        sample: point.sample,
                      }))}
                    />
                  ),
                },
                {
                  key: 'exposure',
                  header: 'Open exposure',
                  numeric: true,
                  render: (row) => (
                    <>
                      {row.open_exposure_formatted ?? <span className="purchase-muted">—</span>}
                      {row.overdue_lines > 0 && (
                        <span className="purchase-table__sub" style={{ color: 'var(--purchase-danger)' }}>
                          {row.overdue_lines} overdue lines
                        </span>
                      )}
                    </>
                  ),
                },
                {
                  key: 'score',
                  header: 'Score',
                  numeric: true,
                  render: (row) =>
                    row.score === null ? (
                      <span className="purchase-muted" title={`Not enough data: ${row.score_missing.join(', ')}`}>
                        Not scored
                      </span>
                    ) : (
                      <span
                        title={row.score_components
                          .map((c) => `${c.label}: ${c.counted ? `${c.value}% (weight ${c.weight})` : 'not counted'}`)
                          .join('\n')}
                      >
                        <strong>{row.score}</strong>
                        {row.score_missing.length > 0 && (
                          <span className="purchase-table__sub">missing {row.score_missing.length}</span>
                        )}
                      </span>
                    ),
                },
                { key: 'action', header: 'Next action', render: (row) => row.next_action },
              ]}
            />
            <p className="purchase-muted" style={{ fontSize: 12, marginTop: 14 }}>
              <strong>How the score is built.</strong> {scoreModel.description} Weights:{' '}
              {Object.entries(scoreModel.weights)
                .map(([key, weight]) => `${key.replace(/_/g, ' ')} ${weight}`)
                .join(', ')}
              .
            </p>
          </>
        )}
      </DashboardPanel>

      <div className="purchase-dashboard-grid">
        <DashboardPanel title="Price movement" description={price.available ? price.basis : undefined}>
          {!price.available ? (
            <PanelUnavailable reason={price.reason} kind={price.kind} />
          ) : price.rows.length === 0 ? (
            <EmptyState title="No item has enough repeat orders to show a movement.">
              At least {price.min_sample} orders of the same item, unit and currency are needed.
            </EmptyState>
          ) : (
            <>
              {/* The table already states a first and a last rate. Two numbers
                  cannot tell a steady climb from a spike that came back down,
                  and those are different negotiations. */}
              <IndexLineChart
                title="Price path by item"
                summary={
                  'Agreed rate per month for the items that moved most, indexed to 100 at the first month each was ordered. ' +
                  price.rows
                    .slice(0, 4)
                    .map((row) => `${row.item_label} ended at ${row.last_formatted}`)
                    .join('. ')
                }
                baseLabel={price.index_note ?? 'Indexed to 100 at each item’s first month in this period.'}
                series={price.rows.slice(0, 4).map((row) => ({
                  id: `${row.item_id}-${row.currency}`,
                  label: row.item_label,
                  points: (row.points ?? []).map((point) => ({
                    period: point.period,
                    index: point.index,
                    formatted: point.formatted,
                  })),
                }))}
              />

              <DataTable
              caption={price.basis}
              rows={price.rows}
              rowKey={(row) => `${row.item_id}-${row.currency}`}
              empty={<EmptyState title="No comparable price history." />}
              columns={[
                {
                  key: 'item',
                  header: 'Item',
                  render: (row) => (
                    <>
                      {row.item_label}
                      <span className="purchase-table__sub">
                        {row.unit ? `per ${row.unit} · ` : ''}
                        {row.observations} order{row.observations === 1 ? '' : 's'}, {row.supplier_count} supplier
                        {row.supplier_count === 1 ? '' : 's'}
                      </span>
                    </>
                  ),
                },
                {
                  key: 'first',
                  header: 'First',
                  numeric: true,
                  render: (row) => (
                    <>
                      {row.first_formatted}
                      <span className="purchase-table__sub">{row.first_date}</span>
                    </>
                  ),
                },
                {
                  key: 'last',
                  header: 'Latest',
                  numeric: true,
                  render: (row) => (
                    <>
                      {row.last_formatted}
                      <span className="purchase-table__sub">{row.last_date}</span>
                    </>
                  ),
                },
                {
                  key: 'change',
                  header: 'Change',
                  numeric: true,
                  render: (row) => (
                    <span
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 4,
                        color:
                          row.direction === 'up'
                            ? 'var(--purchase-danger)'
                            : row.direction === 'down'
                              ? 'var(--purchase-action)'
                              : 'var(--purchase-muted)',
                        fontWeight: 650,
                      }}
                    >
                      {row.direction === 'up' ? <ArrowUpRight size={13} aria-hidden /> : row.direction === 'down' ? <ArrowDownRight size={13} aria-hidden /> : <ArrowRight size={13} aria-hidden />}
                      {row.change_pc === null ? '—' : `${row.change_pc}%`}
                    </span>
                  ),
                },
              ]}
              />
            </>
          )}
        </DashboardPanel>

        <DashboardPanel title="Delivery performance" description={trend.available ? trend.basis : undefined}>
          {!trend.available ? (
            <PanelUnavailable reason={trend.reason} kind={trend.kind} />
          ) : (
            <>
              {trend.points.length === 0 ? (
                <EmptyState title="No accepted receipts in the last twelve months." />
              ) : (
                <BarChart
                  title="On-time delivery by month"
                  unitLabel="On time"
                  data={trend.points.map((point) => ({
                    id: point.period,
                    label: `${point.period} (${point.sample})`,
                    value: point.on_time_pc ?? '0',
                    formatted: point.rated ? `${point.on_time_pc}% of ${point.sample}` : `${point.sample} receipts — too few to rate`,
                    tone: point.rated ? 'brand' : 'muted',
                  }))}
                />
              )}

              {trend.still_waiting.lines > 0 && (
                <div className="purchase-notice purchase-notice--warning" style={{ marginTop: 16 }}>
                  <div>
                    <strong>
                      {trend.still_waiting.lines} overdue line{trend.still_waiting.lines === 1 ? '' : 's'} with nothing
                      received, across {trend.still_waiting.orders} order{trend.still_waiting.orders === 1 ? '' : 's'}
                    </strong>
                    <p>{trend.still_waiting.note}</p>
                  </div>
                </div>
              )}
            </>
          )}
        </DashboardPanel>

        <DashboardPanel
          title="Concentration exposure"
          description={concentration.available ? concentration.basis : undefined}
          className="purchase-span-all"
        >
          {!concentration.available ? (
            <PanelUnavailable reason={concentration.reason} kind={concentration.kind} />
          ) : (
            <div className="purchase-dashboard-grid" style={{ gap: 24 }}>
              {/* Part-to-whole at a glance, which is the one thing a donut is
                  good at. Four named suppliers and the rest folded into one
                  neutral slice: past six, adjacent slices blur and the table
                  below the toggle is the better answer. */}
              <DonutChart
                title="Ordered value by supplier"
                summary={
                  `Ordered value of ${concentration.total_formatted} across ${concentration.suppliers.length} suppliers. ` +
                  concentration.suppliers
                    .slice(0, 4)
                    .map((row) => `${row.supplier_name ?? `Account ${row.supplier_account_id}`} ${row.share_pc ?? '—'}%`)
                    .join(', ') +
                  (concentration.suppliers.length > 4 ? `, and ${concentration.suppliers.length - 4} others.` : '.')
                }
                centreLabel="Total ordered"
                centreValue={concentration.total_formatted}
                segments={donutSegments(concentration.suppliers)}
              />

              <div>
                <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Bought from one supplier only</h3>
                <DataTable
                  caption="Items ordered two or more times in this period, always from the same supplier"
                  rows={concentration.sole_source}
                  rowKey={(row) => row.item_id}
                  empty={<EmptyState title="No item depends on a single supplier." />}
                  columns={[
                    { key: 'item', header: 'Item', render: (row) => row.item_label },
                    { key: 'supplier', header: 'Supplier', render: (row) => row.supplier_name ?? '—' },
                    { key: 'value', header: 'Value', numeric: true, render: (row) => row.formatted },
                    { key: 'orders', header: 'Lines', numeric: true, render: (row) => row.line_count },
                  ]}
                />
                <p className="purchase-muted" style={{ fontSize: 12, marginTop: 10 }}>
                  Evidence of dependence, not proof that no alternative exists.
                </p>
              </div>
            </div>
          )}
        </DashboardPanel>
      </div>

      <SupplierDrawer panel={detail} onClose={() => filters.set({ supplier_id: null })} onOpen={navigate} />
    </>
  )
}

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
      <strong style={{ color: numeric >= 95 ? 'var(--purchase-action)' : numeric >= 80 ? 'inherit' : 'var(--purchase-danger)' }}>
        {value}%
      </strong>
      {/* The sample size is never hidden behind a tooltip. */}
      <span className="purchase-table__sub">of {sample}</span>
    </>
  )
}

// ---------------------------------------------------------------------------

function SupplierDrawer({
  panel,
  onClose,
  onOpen,
}: {
  panel: DetailPanel
  onClose: () => void
  onOpen: (route: string) => void
}) {
  if (!panel.available || panel.loaded !== true) return null

  const profile = panel.profile as Record<string, string | number | boolean | null> | null
  const orders = panel.orders as { po_id: number; po_no: string; po_date_label: string; status: string; open_lines: number; value_formatted: string | null; route: string }[]
  const claims = panel.claims as { claim_id: number; claim_no: string; claim_date: string; claim_kind: string; status: string; claimed: string }[]
  const prices = panel.price_history as { item_id: number; item_label: string; unit: string | null; currency: string; observations: number; min_rate: string; max_rate: string }[]
  const dues = panel.dues as
    | { available: true; as_of: string; total: string; overdue: string; undated_count: number; basis: string; rows: { bill_ref: string | null; due_date: string | null; pending_amount: string; days_overdue: number | null }[] }
    | { available: false; reason: string }

  return (
    <Drawer
      open
      title={`Supplier ${panel.supplier_account_id}`}
      subtitle={panel.identity_note as string}
      onClose={onClose}
    >
      <div style={{ display: 'grid', gap: 22 }}>
        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Procurement profile</h3>
          {profile === null ? (
            <div className="purchase-notice purchase-notice--info">
              <div>
                <strong>No procurement profile yet</strong>
                <p>Orders have been raised against this account, but nobody has qualified them here.</p>
              </div>
            </div>
          ) : (
            <dl className="purchase-dl">
              <dt>Qualification</dt>
              <dd>
                <Badge tone={profile.qualification_status === 'approved' ? 'success' : 'warning'}>
                  {String(profile.qualification_status).replace(/_/g, ' ')}
                </Badge>
              </dd>
              <dt>Preferred</dt>
              <dd>{profile.is_preferred ? 'Yes' : 'No'}</dd>
              <dt>Lead time</dt>
              <dd>{profile.operational_lead_days === null ? '—' : `${profile.operational_lead_days} days`}</dd>
              <dt>Payment terms</dt>
              <dd>{profile.payment_terms ?? '—'}</dd>
              <dt>Incoterm</dt>
              <dd>{profile.incoterm ?? '—'}</dd>
              <dt>Risk flag</dt>
              <dd>{profile.risk_flag ?? 'None'}</dd>
            </dl>
          )}
        </section>

        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Live position in Smart Books</h3>
          {!dues.available ? (
            <PanelUnavailable reason={dues.reason} kind="source" />
          ) : (
            <>
              <dl className="purchase-dl">
                <dt>Outstanding</dt>
                <dd>{dues.total}</dd>
                <dt>Overdue</dt>
                <dd style={{ color: 'var(--purchase-danger)' }}>{dues.overdue}</dd>
                <dt>As at</dt>
                <dd>{dues.as_of}</dd>
                <dt>No due date recorded</dt>
                <dd>{dues.undated_count}</dd>
              </dl>
              <p className="purchase-muted" style={{ fontSize: 12, marginTop: 8 }}>{dues.basis}</p>
            </>
          )}
        </section>

        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Recent orders</h3>
          <DataTable
            caption="Purchase orders raised with this supplier"
            rows={orders}
            rowKey={(row) => row.po_id}
            empty={<EmptyState title="No orders yet." />}
            onRowOpen={(row) => onOpen(row.route)}
            columns={[
              { key: 'po', header: 'Order', render: (row) => <>{row.po_no}<span className="purchase-table__sub">{row.po_date_label}</span></> },
              { key: 'status', header: 'Status', render: (row) => row.status.replace(/_/g, ' ') },
              { key: 'value', header: 'Value', numeric: true, render: (row) => row.value_formatted ?? '—' },
            ]}
          />
        </section>

        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Price history</h3>
          <DataTable
            caption="Agreed rates by item, from this supplier"
            rows={prices}
            rowKey={(row) => `${row.item_id}-${row.currency}`}
            empty={<EmptyState title="No priced order lines yet." />}
            columns={[
              { key: 'item', header: 'Item', render: (row) => <>{row.item_label}<span className="purchase-table__sub">{row.observations} orders{row.unit ? ` · per ${row.unit}` : ''}</span></> },
              { key: 'low', header: 'Lowest', numeric: true, render: (row) => row.min_rate },
              { key: 'high', header: 'Highest', numeric: true, render: (row) => row.max_rate },
            ]}
          />
        </section>

        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Claims and quality</h3>
          <DataTable
            caption="Claims raised against this supplier"
            rows={claims}
            rowKey={(row) => row.claim_id}
            empty={<EmptyState title="No claims raised." />}
            columns={[
              { key: 'claim', header: 'Claim', render: (row) => <>{row.claim_no}<span className="purchase-table__sub">{row.claim_kind.replace(/_/g, ' ')}</span></> },
              { key: 'status', header: 'Status', render: (row) => row.status.replace(/_/g, ' ') },
              { key: 'amount', header: 'Claimed', numeric: true, render: (row) => row.claimed },
            ]}
          />
        </section>
      </div>
    </Drawer>
  )
}
