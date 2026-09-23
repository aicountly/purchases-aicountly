/**
 * Dashboard 5 — Purchase intelligence.
 *
 * Three kinds of statement, never mixed: obligations that already exist,
 * arithmetic over history, and — only where a model is configured — commentary.
 * Each is labelled on the screen, because a reader who cannot tell them apart
 * will treat the weakest as if it were the strongest.
 *
 * The layout is the one the rest of the product uses: the opportunities on the
 * left because they are what somebody acts on, the analytics in the middle
 * because they are what somebody checks the opportunities against, and the two
 * intelligence lists on the right because they are what somebody scans.
 */

import { useNavigate } from 'react-router-dom'
import { Badge, DashboardPanel, DataTable, EmptyState, PanelUnavailable } from '../shell'
import { TrendChart } from '../charts'
import { OpportunityTable } from '../intelligence/OpportunityTable'
import { SpendTrend } from '../intelligence/SpendTrend'
import { CategoryConcentration } from '../intelligence/CategoryConcentration'
import { RisksPanel } from '../intelligence/RisksPanel'
import { InsightsPanel } from '../intelligence/InsightsPanel'
import { AskDrawer, type AskPanel } from '../intelligence/AskDrawer'
import type { DashboardFilters } from '../filters'
import type {
  AnomalyRow,
  CategoryPanel,
  DashboardResponse,
  InsightPanel,
  OpportunityPanel,
  Panel,
  RiskPanel,
  SpendTrendPanel,
} from '../types'

type AnomalyPanel = Panel<{ method_label: string; rows: AnomalyRow[]; disclaimer: string; basis: string }>
type ForecastPanel = Panel<{
  currency: string
  obligations: { kind: string; label: string; months: { month: string; amount: string; formatted: string; orders: number }[]; basis: string }
  spend_forecast:
    | { kind: string; available: false; reason: string; months_available: number }
    | {
        kind: string
        available: true
        method_label: string
        observation_period: string
        months_observed: number
        projection: string
        projection_formatted: string
        range: { low: string; high: string }
        range_formatted: { low: string; high: string }
        range_label: string
        basis: string
        caveat: string
        history: { month: string; amount: string; formatted: string; orders: number }[]
      }
  commentary: { available: boolean; note: string }
  payables_note: string
}>
type ActionsPanel = Panel<{ actions: { id: string; label: string; route: string; filters: Record<string, string>; effect: string }[]; notice: string }>

export function AiInsightsDashboard({
  data,
  filters,
}: {
  data: DashboardResponse
  filters: DashboardFilters
  onRefresh: () => void
}) {
  const navigate = useNavigate()
  const open = (route: string, params: Record<string, string> = {}) => {
    const query = new URLSearchParams(params).toString()
    navigate(query === '' ? route : `${route}?${query}`)
  }

  const ask = data.panels.ask as AskPanel
  const opportunities = data.panels.opportunities as OpportunityPanel
  const spendTrend = data.panels.spend_trend as SpendTrendPanel
  const categories = data.panels.categories as CategoryPanel
  const risks = data.panels.risks as RiskPanel
  const insights = data.panels.insights as InsightPanel
  const anomalies = data.panels.anomalies as AnomalyPanel
  const forecast = data.panels.forecast as ForecastPanel
  const actions = data.panels.actions as ActionsPanel

  // The drawer's open state lives in the URL, so the green card at the top of
  // the page, a link somebody sent and the Back button all agree about it.
  const askOpen = filters.get('ask') === '1'

  return (
    <>
      <div className="purchase-intel-grid">
        <OpportunityTable panel={opportunities} onViewAll={() => open('/purchase-orders')} />

        <div className="purchase-intel-column">
          <SpendTrend panel={spendTrend} />
          <CategoryConcentration panel={categories} />
        </div>

        <div className="purchase-intel-column">
          <RisksPanel panel={risks} onViewAll={() => open('/dashboard/procurement')} />
          <InsightsPanel panel={insights} onAsk={() => filters.set({ ask: '1' })} />
        </div>
      </div>

      {/* Below the fold: the detail behind the four panels above. Nothing here
          is new information, and nothing above depends on scrolling to it. */}
      <div className="purchase-dashboard-grid">
        <DashboardPanel
          title="Anomalies to review"
          description={anomalies.available ? anomalies.method_label : undefined}
          className="purchase-span-all"
        >
          {!anomalies.available ? (
            <PanelUnavailable reason={anomalies.reason} kind={anomalies.kind} />
          ) : (
            <>
              <div className="purchase-notice purchase-notice--info" style={{ marginBottom: 14 }}>
                <div>
                  <strong>These are review candidates</strong>
                  <p>{anomalies.disclaimer}</p>
                </div>
              </div>
              <DataTable
                caption={anomalies.basis}
                rows={anomalies.rows}
                rowKey={(row) => row.id}
                onRowOpen={(row) => open(row.route)}
                empty={<EmptyState title="No rule matched in this period." />}
                columns={[
                  {
                    key: 'title',
                    header: 'What was noticed',
                    render: (row) => (
                      <>
                        {row.title}
                        <span className="purchase-table__sub">{row.detail}</span>
                      </>
                    ),
                  },
                  { key: 'kind', header: 'Rule', render: (row) => <Badge tone="warning">{row.kind}</Badge> },
                  {
                    key: 'amount',
                    header: 'Amount',
                    numeric: true,
                    render: (row) => row.amount_formatted ?? <span className="purchase-muted">—</span>,
                  },
                ]}
              />
            </>
          )}
        </DashboardPanel>

        <DashboardPanel title="What is committed, and what the arithmetic suggests" className="purchase-span-all">
          {!forecast.available ? (
            <PanelUnavailable reason={forecast.reason} kind={forecast.kind} />
          ) : (
            <div className="purchase-dashboard-grid" style={{ gap: 24 }}>
              <div>
                <h3 style={{ margin: '0 0 4px', fontSize: 14 }}>
                  {forecast.obligations.label} <Badge tone="success">Contractual</Badge>
                </h3>
                <p className="purchase-muted" style={{ fontSize: 12, marginTop: 0 }}>{forecast.obligations.basis}</p>
                {forecast.obligations.months.length === 0 ? (
                  <EmptyState title="No dated commitments outstanding." />
                ) : (
                  <DataTable
                    caption={forecast.obligations.basis}
                    rows={forecast.obligations.months}
                    rowKey={(row) => row.month}
                    empty={<EmptyState title="Nothing committed." />}
                    columns={[
                      { key: 'month', header: 'Expected in', render: (row) => row.month },
                      { key: 'orders', header: 'Orders', numeric: true, render: (row) => row.orders },
                      { key: 'amount', header: 'Value', numeric: true, render: (row) => row.formatted },
                    ]}
                  />
                )}
              </div>

              <div>
                <h3 style={{ margin: '0 0 4px', fontSize: 14 }}>
                  Projected ordering <Badge tone="info">Statistical</Badge>
                </h3>
                {!forecast.spend_forecast.available ? (
                  <div className="purchase-notice purchase-notice--warning">
                    <div>
                      <strong>No projection</strong>
                      <p>{forecast.spend_forecast.reason}</p>
                    </div>
                  </div>
                ) : (
                  <>
                    <p className="purchase-muted" style={{ fontSize: 12, marginTop: 0 }}>
                      {forecast.spend_forecast.method_label} Observed {forecast.spend_forecast.observation_period}.
                    </p>
                    <TrendChart
                      title="Ordered value per month, with the projection"
                      unitLabel={`Amount in ${forecast.currency}`}
                      points={[
                        ...forecast.spend_forecast.history.map((point) => ({
                          label: point.month,
                          value: point.amount,
                          formatted: point.formatted,
                        })),
                        {
                          label: 'Next',
                          value: forecast.spend_forecast.projection,
                          formatted: forecast.spend_forecast.projection_formatted,
                          projected: true,
                        },
                      ]}
                    />
                    <dl className="purchase-dl" style={{ marginTop: 14 }}>
                      <div>
                        <dt>Projection</dt>
                        <dd>{forecast.spend_forecast.projection_formatted}</dd>
                      </div>
                      <div>
                        <dt>Observed range</dt>
                        <dd>
                          {forecast.spend_forecast.range_formatted.low} – {forecast.spend_forecast.range_formatted.high}
                        </dd>
                      </div>
                    </dl>
                    <p className="purchase-muted" style={{ fontSize: 12 }}>
                      {forecast.spend_forecast.range_label} {forecast.spend_forecast.caveat}
                    </p>
                  </>
                )}

                <div
                  className={forecast.commentary.available ? 'purchase-notice purchase-notice--success' : 'purchase-notice purchase-notice--warning'}
                  style={{ marginTop: 16 }}
                >
                  <div>
                    <strong>{forecast.commentary.available ? 'AI commentary' : 'AI insights are currently unavailable'}</strong>
                    <p>{forecast.commentary.note}</p>
                    <p>{forecast.payables_note}</p>
                  </div>
                </div>
              </div>
            </div>
          )}
        </DashboardPanel>

        <DashboardPanel title="Proposed actions" className="purchase-span-all">
          {!actions.available ? (
            <PanelUnavailable reason={actions.reason} kind={actions.kind} />
          ) : (
            <>
              <div className="purchase-pipeline">
                {actions.actions.map((action) => (
                  <button
                    key={action.id}
                    type="button"
                    className="purchase-pipeline__stage"
                    onClick={() => open(action.route, action.filters)}
                  >
                    <span style={{ fontWeight: 650 }}>{action.label}</span>
                    <span className="purchase-pipeline__label">{action.effect}</span>
                  </button>
                ))}
              </div>
              <p className="purchase-muted" style={{ fontSize: 12, marginTop: 14 }}>{actions.notice}</p>
            </>
          )}
        </DashboardPanel>
      </div>

      <AskDrawer open={askOpen} panel={ask} onClose={() => filters.set({ ask: null })} />
    </>
  )
}
