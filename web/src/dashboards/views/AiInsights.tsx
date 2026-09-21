/**
 * Dashboard 5 — AI Insights.
 *
 * Three kinds of statement, never mixed: obligations that already exist,
 * arithmetic over history, and — only where a model is configured — commentary.
 * Each is labelled on the screen, because a reader who cannot tell them apart
 * will treat the weakest as if it were the strongest.
 */

import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Search, Sparkles } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import { Badge, DashboardPanel, DataTable, EmptyState, PanelUnavailable } from '../shell'
import { TrendChart } from '../charts'
import type { AnomalyRow, AskAnswer, DashboardResponse, OpportunityCard, Panel } from '../types'

type AskPanel = Panel<{
  ai: { available: boolean; reason: string | null; model: string | null; admin_hint: string | null }
  questions: { id: string; question: string; description: string; permission: string | null }[]
  withheld_count: number
  notice: string
  security: string
}>
type OpportunityPanel = Panel<{ method_label: string; cards: OpportunityCard[]; basis: string }>
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
        range: { low: string; high: string }
        range_label: string
        basis: string
        caveat: string
        history: { month: string; amount: string; orders: number }[]
      }
  commentary: { available: boolean; note: string }
  payables_note: string
}>
type ActionsPanel = Panel<{ actions: { id: string; label: string; route: string; filters: Record<string, string>; effect: string }[]; notice: string }>

export function AiInsightsDashboard({ data }: { data: DashboardResponse }) {
  const navigate = useNavigate()
  const open = (route: string, filters: Record<string, string> = {}) => {
    const query = new URLSearchParams(filters).toString()
    navigate(query === '' ? route : `${route}?${query}`)
  }

  const ask = data.panels.ask as AskPanel
  const opportunities = data.panels.opportunities as OpportunityPanel
  const anomalies = data.panels.anomalies as AnomalyPanel
  const forecast = data.panels.forecast as ForecastPanel
  const actions = data.panels.actions as ActionsPanel

  return (
    <>
      <AskPurchases panel={ask} onOpen={open} />

      <div className="purchase-dashboard-grid">
        <DashboardPanel
          title="Opportunities"
          description={opportunities.available ? opportunities.method_label : undefined}
        >
          {!opportunities.available ? (
            <PanelUnavailable reason={opportunities.reason} kind={opportunities.kind} />
          ) : opportunities.cards.length === 0 ? (
            <EmptyState title="Nothing stands out in this period.">
              These rules look for fragmented buying, rate rises and repeated small orders.
            </EmptyState>
          ) : (
            <div style={{ display: 'grid', gap: 12 }}>
              {opportunities.cards.map((card) => (
                <article key={card.id} className="purchase-priority">
                  <div className="purchase-priority__top">
                    <Badge tone="info">{card.kind.replace(/_/g, ' ')}</Badge>
                    {card.estimate_formatted && (
                      <strong style={{ fontVariantNumeric: 'tabular-nums' }}>{card.estimate_formatted}</strong>
                    )}
                  </div>
                  <h3>{card.title}</h3>
                  <p>{card.detail}</p>
                  <dl className="purchase-dl" style={{ marginBottom: 14 }}>
                    <dt>Baseline</dt>
                    <dd>{card.baseline_formatted}</dd>
                    <dt>Estimate</dt>
                    <dd>{card.estimate_formatted ?? 'Not quantified'}</dd>
                  </dl>
                  <p className="purchase-muted" style={{ fontSize: 12, margin: '0 0 12px' }}>
                    <strong>Assumption:</strong> {card.assumption}
                  </p>
                  <div className="purchase-priority__footer">
                    <span />
                    <button
                      type="button"
                      className="purchase-button purchase-button--secondary"
                      onClick={() => open(card.route, card.filters)}
                    >
                      See the evidence
                    </button>
                  </div>
                </article>
              ))}
            </div>
          )}
        </DashboardPanel>

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
                          formatted: point.amount,
                        })),
                        {
                          label: 'Next',
                          value: forecast.spend_forecast.projection,
                          formatted: forecast.spend_forecast.projection,
                          projected: true,
                        },
                      ]}
                    />
                    <dl className="purchase-dl" style={{ marginTop: 14 }}>
                      <dt>Projection</dt>
                      <dd>{forecast.spend_forecast.projection}</dd>
                      <dt>Observed range</dt>
                      <dd>
                        {forecast.spend_forecast.range.low} – {forecast.spend_forecast.range.high}
                      </dd>
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
    </>
  )
}

// ---------------------------------------------------------------------------

function AskPurchases({
  panel,
  onOpen,
}: {
  panel: AskPanel
  onOpen: (route: string, filters?: Record<string, string>) => void
}) {
  const { scope } = usePurchases()
  // A question typed into the header search arrives as `?ask=`. It seeds the
  // box but is NOT submitted: a page that fires a query the moment it opens is
  // a page you cannot reload without asking again.
  const [searchParams] = useSearchParams()
  const asked = searchParams.get('ask')
  const [question, setQuestion] = useState(asked ?? '')

  // An effect, not just an initial value: searching again from the header
  // while this screen is already open changes the parameter without
  // remounting, and a box that ignored that would swallow the question.
  useEffect(() => {
    if (asked !== null && asked !== '') setQuestion(asked)
  }, [asked])
  const [answer, setAnswer] = useState<AskAnswer | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!panel.available) {
    return (
      <DashboardPanel title="Ask Purchases" className="purchase-span-all">
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      </DashboardPanel>
    )
  }

  const submit = async (text: string, intent?: string) => {
    if (text.trim() === '' && intent === undefined) return
    setBusy(true)
    setError(null)
    try {
      const response = await api.post<AskAnswer>('v1/insights/ask', { question: text, intent, ...(scope ?? {}) })
      setAnswer(response.data)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'That question could not be answered.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <DashboardPanel
      title="Ask Purchases"
      description={panel.notice}
      className="purchase-span-all"
      action={
        <Badge tone={panel.ai.available ? 'success' : 'neutral'}>
          {panel.ai.available ? 'Model configured' : 'Rules only'}
        </Badge>
      }
    >
      <form
        className="purchase-ask"
        onSubmit={(event) => {
          event.preventDefault()
          void submit(question)
        }}
      >
        <Search size={17} aria-hidden style={{ color: 'var(--purchase-muted)', flexShrink: 0 }} />
        <input
          type="search"
          value={question}
          onChange={(event) => setQuestion(event.target.value)}
          placeholder="Which orders are delayed this week?"
          aria-label="Ask a question about your purchases"
        />
        <button type="submit" className="purchase-button purchase-button--primary" disabled={busy}>
          {busy ? 'Asking…' : 'Ask'}
        </button>
      </form>

      <div className="purchase-chips">
        {panel.questions.map((item) => (
          <button
            key={item.id}
            type="button"
            className="purchase-segment"
            title={item.description}
            onClick={() => {
              setQuestion(item.question)
              void submit(item.question, item.id)
            }}
          >
            <Sparkles size={12} aria-hidden style={{ marginRight: 4 }} />
            {item.question}
          </button>
        ))}
      </div>

      {panel.ai.admin_hint && (
        <p className="purchase-muted" style={{ fontSize: 12, marginTop: 10 }}>
          <strong>For an administrator:</strong> {panel.ai.admin_hint}
        </p>
      )}

      {panel.withheld_count > 0 && (
        <p className="purchase-muted" style={{ fontSize: 12, marginTop: 10 }}>
          {panel.withheld_count} further question{panel.withheld_count === 1 ? ' is' : 's are'} not shown because your
          permissions do not cover the data behind {panel.withheld_count === 1 ? 'it' : 'them'}.
        </p>
      )}

      {error && (
        <div className="purchase-notice purchase-notice--danger" style={{ marginTop: 16 }}>
          <div>
            <strong>Could not answer</strong>
            <p>{error}</p>
          </div>
        </div>
      )}

      {answer && <Answer answer={answer} onOpen={onOpen} />}

      <p className="purchase-muted" style={{ fontSize: 12, marginTop: 16 }}>
        <strong>How this works.</strong> {panel.security}
      </p>
    </DashboardPanel>
  )
}

function Answer({
  answer,
  onOpen,
}: {
  answer: AskAnswer
  onOpen: (route: string, filters?: Record<string, string>) => void
}) {
  const columns =
    answer.records.length === 0
      ? []
      : Object.keys(answer.records[0])
          .filter((key) => key !== 'route')
          .map((key) => ({
            key,
            header: key.replace(/_/g, ' ').replace(/^./, (character) => character.toUpperCase()),
            numeric: ['value', 'amount', 'remaining', 'change_pc', 'share_pc', 'days_late', 'waiting_days', 'lead_days', 'observations'].includes(key),
            render: (row: Record<string, unknown>) => {
              const value = row[key]
              if (value === null || value === undefined) return <span className="purchase-muted">—</span>
              if (typeof value === 'boolean') return value ? 'Yes' : 'No'
              return String(value)
            },
          }))

  return (
    <div style={{ marginTop: 18, display: 'grid', gap: 14 }}>
      <div className="purchase-notice purchase-notice--success">
        <div style={{ minWidth: 0 }}>
          <strong>{answer.answer}</strong>
          <p className="purchase-muted" style={{ fontSize: 12 }}>{answer.method_label}</p>
        </div>
      </div>

      {answer.suggestions && (
        <div className="purchase-chips">
          {answer.suggestions.map((suggestion) => (
            <span key={suggestion.id} className="purchase-segment" style={{ cursor: 'default' }}>
              {suggestion.question}
            </span>
          ))}
        </div>
      )}

      {columns.length > 0 && (
        <DataTable
          caption={answer.calculation ?? 'Supporting records'}
          rows={answer.records}
          rowKey={(row) => JSON.stringify(row).slice(0, 80)}
          empty={<EmptyState title="No supporting records." />}
          columns={columns}
          onRowOpen={
            answer.records[0]?.route === undefined ? undefined : (row) => onOpen(String(row.route))
          }
        />
      )}

      <dl className="purchase-dl">
        <dt>Scope applied</dt>
        <dd>
          Company {String(answer.scope.company_id)} · FY {String(answer.scope.financial_year_id)} ·{' '}
          {String(answer.scope.branch_label)} · {String(answer.scope.period_label)}
        </dd>
        <dt>Sources</dt>
        <dd>
          {answer.sources.length === 0
            ? '—'
            : answer.sources
                .map((source) => `${source.label}${source.status === 'unavailable' ? ' (unavailable)' : ''}`)
                .join(', ')}
        </dd>
      </dl>

      {answer.calculation && (
        <p className="purchase-muted" style={{ fontSize: 12, margin: 0 }}>
          <strong>How it was calculated.</strong> {answer.calculation}
        </p>
      )}

      {answer.uncertainty && (
        <p className="purchase-muted" style={{ fontSize: 12, margin: 0 }}>
          <strong>What might be missing.</strong> {answer.uncertainty}
        </p>
      )}

      {answer.next_action?.route && (
        <div>
          <button
            type="button"
            className="purchase-button purchase-button--secondary"
            onClick={() => onOpen(answer.next_action!.route as string, answer.next_action?.filters)}
          >
            {answer.next_action.label}
          </button>
        </div>
      )}
    </div>
  )
}
