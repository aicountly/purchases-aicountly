/**
 * Ask Aicountly AI.
 *
 * A drawer, not a chat window that takes half the screen. The answer is
 * structured rather than a paragraph: a summary sentence, the method that
 * produced it, the records behind it, how it was calculated and what might be
 * missing — in that order, every time.
 *
 * The model, where one is configured, decides which approved question was
 * meant and writes the summary sentence. It never writes a query, never reaches
 * the database and cannot take an action; permissions are applied before a
 * record is fetched, not before it is displayed. That sentence is on the screen
 * as well as in this comment.
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowRight, Send, Sparkles } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import { Badge, DataTable, EmptyState } from '../shell'
import { Drawer } from '../Drawer'
import type { AskAnswer, Panel } from '../types'

export type AskPanel = Panel<{
  ai: { available: boolean; reason: string | null; model: string | null; admin_hint: string | null }
  questions: { id: string; question: string; description: string; permission: string | null }[]
  withheld_count: number
  notice: string
  security: string
}>

const NUMERIC_KEYS = [
  'value', 'amount', 'remaining', 'change_pc', 'share_pc',
  'days_late', 'waiting_days', 'lead_days', 'observations',
]

export function AskDrawer({ open, panel, onClose }: { open: boolean; panel: AskPanel; onClose: () => void }) {
  const navigate = useNavigate()
  const { scope } = usePurchases()
  const [question, setQuestion] = useState('')
  const [answer, setAnswer] = useState<AskAnswer | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const go = (route: string, filters: Record<string, string> = {}) => {
    const query = new URLSearchParams(filters).toString()
    onClose()
    navigate(query === '' ? route : `${route}?${query}`)
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
    <Drawer
      open={open}
      title="Ask Aicountly AI"
      subtitle="Ask about purchases, suppliers, pricing and procurement activity."
      wide
      onClose={onClose}
    >
      {!panel.available ? (
        <EmptyState title="Not available to you">{panel.reason}</EmptyState>
      ) : (
        <div className="purchase-ask-drawer">
          <div className="purchase-ask-drawer__state">
            <Badge tone={panel.ai.available ? 'success' : 'neutral'}>
              {panel.ai.available ? 'Model configured' : 'Rules only'}
            </Badge>
            <p className="purchase-muted">{panel.notice}</p>
          </div>

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

          {panel.withheld_count > 0 && (
            <p className="purchase-muted" style={{ fontSize: 12 }}>
              {panel.withheld_count} further question{panel.withheld_count === 1 ? ' is' : 's are'} not shown because
              your permissions do not cover the data behind {panel.withheld_count === 1 ? 'it' : 'them'}.
            </p>
          )}

          {error && (
            <div className="purchase-notice purchase-notice--danger">
              <div>
                <strong>Could not answer</strong>
                <p>{error}</p>
              </div>
            </div>
          )}

          {/* aria-live so the answer announces itself: a drawer that fills in
              silently is a drawer a screen-reader user never learns answered. */}
          <div aria-live="polite" aria-busy={busy}>
            {busy && (
              <div className="purchase-panel" style={{ padding: 16 }}>
                <div className="purchase-skeleton purchase-skeleton--row" style={{ width: '60%' }} />
                <div className="purchase-skeleton purchase-skeleton--row" />
              </div>
            )}
            {!busy && answer && <Answer answer={answer} onOpen={go} />}
          </div>

          <p className="purchase-muted purchase-ask-drawer__security">
            <strong>How this works.</strong> {panel.security}
          </p>

          {panel.ai.admin_hint && (
            <p className="purchase-muted" style={{ fontSize: 12 }}>
              <strong>For an administrator:</strong> {panel.ai.admin_hint}
            </p>
          )}

          <form
            className="purchase-ask"
            onSubmit={(event) => {
              event.preventDefault()
              void submit(question)
            }}
          >
            <input
              type="search"
              value={question}
              onChange={(event) => setQuestion(event.target.value)}
              placeholder="Ask about suppliers, prices, POs, bills or savings…"
              aria-label="Ask a question about your purchases"
            />
            <button type="submit" className="purchase-button purchase-button--primary" disabled={busy}>
              <Send size={14} aria-hidden /> {busy ? 'Asking…' : 'Ask'}
            </button>
          </form>
        </div>
      )}
    </Drawer>
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
            numeric: NUMERIC_KEYS.includes(key),
            render: (row: Record<string, unknown>) => {
              const value = row[key]
              if (value === null || value === undefined) return <span className="purchase-muted">—</span>
              if (typeof value === 'boolean') return value ? 'Yes' : 'No'
              // Rendered as text, never as markup: nothing a model produced is
              // given to the browser as HTML.
              return String(value)
            },
          }))

  return (
    <div className="purchase-answer">
      <section className="purchase-answer__summary">
        <h3>Summary</h3>
        <p>{answer.answer}</p>
        <p className="purchase-muted">{answer.method_label}</p>
      </section>

      {columns.length > 0 && (
        <section>
          <h3>Evidence</h3>
          <DataTable
            caption={answer.calculation ?? 'Supporting records'}
            rows={answer.records}
            rowKey={(row) => JSON.stringify(row).slice(0, 80)}
            empty={<EmptyState title="No supporting records." />}
            columns={columns}
            onRowOpen={answer.records[0]?.route === undefined ? undefined : (row) => onOpen(String(row.route))}
          />
        </section>
      )}

      {answer.calculation && (
        <section>
          <h3>How it was calculated</h3>
          <p>{answer.calculation}</p>
        </section>
      )}

      {answer.uncertainty && (
        <section>
          <h3>What might be missing</h3>
          <p>{answer.uncertainty}</p>
        </section>
      )}

      <section>
        <h3>Scope applied</h3>
        <dl className="purchase-dl">
          <div>
            <dt>Company and period</dt>
            <dd>
              Company {String(answer.scope.company_id)} · FY {String(answer.scope.financial_year_id)} ·{' '}
              {String(answer.scope.branch_label)} · {String(answer.scope.period_label)}
            </dd>
          </div>
          <div>
            <dt>Sources</dt>
            <dd>
              {answer.sources.length === 0
                ? '—'
                : answer.sources
                    .map((source) => `${source.label}${source.status === 'unavailable' ? ' (unavailable)' : ''}`)
                    .join(', ')}
            </dd>
          </div>
        </dl>
      </section>

      {answer.next_action?.route && (
        <button
          type="button"
          className="purchase-button purchase-button--secondary"
          onClick={() => onOpen(answer.next_action!.route as string, answer.next_action?.filters)}
        >
          {answer.next_action.label} <ArrowRight size={14} aria-hidden />
        </button>
      )}
    </div>
  )
}
