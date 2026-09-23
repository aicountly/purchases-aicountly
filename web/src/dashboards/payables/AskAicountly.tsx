/**
 * Ask Aicountly, on the payables screen.
 *
 * This talks to the SAME engine the AI Insights dashboard uses — there is one
 * `v1/insights/ask`, it answers within the caller's own permissions and scope,
 * and it cites the records it used. Nothing here simulates an answer: if the
 * engine does not recognise a question it says so and offers the questions it
 * does recognise, which is what the suggestions below are drawn from.
 *
 * ADVISORY, ALWAYS. The panel can take a reader to a filtered list. It cannot
 * approve, post, schedule, pay or delete anything, and no answer it returns is
 * wired to an action that would. Those all stay explicit, on the row, behind
 * their own permission and their own confirmation.
 */

import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowRight, Send, ShieldCheck, Sparkles, X } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import type { AskAnswer } from '../types'

/**
 * The questions offered before anything is typed.
 *
 * Every one of these maps to an intent the engine actually implements, so a
 * click is guaranteed an answer. A question the engine cannot answer would be
 * a worse suggestion than no suggestion at all.
 */
const SUGGESTIONS = [
  'Which bills need review before payment?',
  'What is waiting for approval?',
  'What have we spent, and with whom?',
  'What purchases are concentrated with one supplier?',
  'Which suppliers increased prices for the same items?',
]

export function AskAicountlyPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate()
  const { scope } = usePurchases()
  const [question, setQuestion] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [answer, setAnswer] = useState<AskAnswer | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const closeRef = useRef<HTMLButtonElement>(null)

  // Opening a drawer moves the caret into it; Escape closes it. Without both,
  // a keyboard user tabs through the whole page behind the drawer to reach it.
  useEffect(() => {
    if (!open) return
    inputRef.current?.focus()

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  const ask = async (text: string) => {
    const trimmed = text.trim()
    if (trimmed === '') return

    setBusy(true)
    setError(null)
    try {
      const response = await api.post<AskAnswer>('v1/insights/ask', { question: trimmed, ...(scope ?? {}) })
      setAnswer(response.data)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'That question could not be answered just now.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <div className="aic-drawer-backdrop" onClick={onClose} aria-hidden />
      <aside className="aic-drawer" role="dialog" aria-modal="true" aria-label="Ask Aicountly about payables">
        <header className="aic-drawer__head">
          <div>
            <h2>
              <Sparkles size={15} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6, color: '#6d28d9' }} />
              Ask Aicountly
            </h2>
            <p>Answers are drawn from your own records, within your permissions.</p>
          </div>
          <button type="button" className="aic-icon-btn" onClick={onClose} aria-label="Close" ref={closeRef}>
            <X size={16} aria-hidden />
          </button>
        </header>

        <div className="aic-drawer__body">
          <form
            className="aic-ask-form"
            onSubmit={(event) => {
              event.preventDefault()
              void ask(question)
            }}
          >
            <label className="aic-sr-only" htmlFor="aic-ask-input">
              Ask a question about your payables
            </label>
            <input
              id="aic-ask-input"
              ref={inputRef}
              value={question}
              onChange={(event) => setQuestion(event.target.value)}
              placeholder="Ask about bills, suppliers or payments…"
              autoComplete="off"
            />
            <button type="submit" className="aic-btn aic-btn--primary" disabled={busy || question.trim() === ''}>
              <Send size={14} aria-hidden /> {busy ? 'Asking…' : 'Ask'}
            </button>
          </form>

          {error && (
            <div className="aic-notice aic-notice--danger" role="alert">
              <div>
                <strong>Not answered</strong>
                <p>{error}</p>
              </div>
            </div>
          )}

          {answer && <Answer answer={answer} onOpen={(route) => { onClose(); navigate(route) }} />}

          <div>
            <p className="aic-menu__heading" style={{ padding: '0 0 6px' }}>
              {answer ? 'Ask something else' : 'Suggested questions'}
            </p>
            <div className="aic-ask-suggestions">
              {(answer?.suggestions?.map((s) => s.question) ?? SUGGESTIONS).map((suggestion) => (
                <button
                  key={suggestion}
                  type="button"
                  onClick={() => {
                    setQuestion(suggestion)
                    void ask(suggestion)
                  }}
                  disabled={busy}
                >
                  <Sparkles size={13} aria-hidden style={{ color: '#6d28d9', flexShrink: 0 }} />
                  {suggestion}
                </button>
              ))}
            </div>
          </div>

          <p className="aic-advisory">
            <ShieldCheck size={14} aria-hidden style={{ flexShrink: 0, marginTop: 1 }} />
            <span>
              Aicountly explains and points you at records. It never approves a bill, posts to Smart Books,
              schedules a payment or pays anybody — every one of those stays an explicit action on the bill itself.
            </span>
          </p>
        </div>
      </aside>
    </>
  )
}

function Answer({ answer, onOpen }: { answer: AskAnswer; onOpen: (route: string) => void }) {
  return (
    <div className="aic-ask-answer">
      <h3>{answer.question}</h3>
      <p>{answer.answer}</p>

      {answer.calculation && (
        <dl>
          <dt>How</dt>
          <dd>{answer.calculation}</dd>
        </dl>
      )}

      {answer.uncertainty && (
        <p style={{ color: 'var(--aic-text-muted)', fontSize: 11.5 }}>{answer.uncertainty}</p>
      )}

      {answer.sources.length > 0 && (
        <dl>
          <dt>Sources</dt>
          <dd>{answer.sources.map((source) => source.label).join(', ')}</dd>
          <dt>Method</dt>
          <dd>{answer.method_label}</dd>
        </dl>
      )}

      {answer.next_action?.route && (
        <button
          type="button"
          className="aic-btn aic-btn--secondary"
          style={{ marginTop: 10 }}
          onClick={() => {
            const query = new URLSearchParams(answer.next_action?.filters ?? {}).toString()
            onOpen(query === '' ? (answer.next_action?.route as string) : `${answer.next_action?.route}?${query}`)
          }}
        >
          {answer.next_action.label} <ArrowRight size={14} aria-hidden />
        </button>
      )}
    </div>
  )
}
