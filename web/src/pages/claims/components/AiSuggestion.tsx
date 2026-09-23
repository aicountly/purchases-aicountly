/**
 * What the assistant came back with, shown beside the field it is about.
 *
 * IT IS NEVER APPLIED BY ITSELF. The suggestion sits here until somebody
 * presses "Use this", and the field underneath keeps whatever the user wrote
 * until they do. A model that silently rewrote a description somebody had spent
 * five minutes on would be a model nobody used twice.
 *
 * The text is rendered as text. Nothing the model returns is interpreted as
 * markup — it goes into a text node, and `white-space: pre-wrap` keeps its line
 * breaks without giving it a way to bring anything else in.
 */

import { Sparkles } from 'lucide-react'
import type { AssistResult } from '../types'
import { Button, Notice } from './ui'

export function AiSuggestion({
  result,
  busy,
  error,
  applyLabel = 'Use this',
  onApply,
  onDismiss,
  onRetry,
}: {
  result: AssistResult | null
  busy: boolean
  error: string | null
  applyLabel?: string
  /**
   * Absent when the answer is something to READ rather than something to put in
   * a field — a summary, or a list of documents to go and find. The panel then
   * offers Close alone, rather than an apply button with nowhere to apply to.
   */
  onApply?: () => void
  onDismiss: () => void
  onRetry?: () => void
}) {
  if (busy) {
    return (
      <div className="sc-ai-suggestion" aria-live="polite">
        <p className="sc-ai-suggestion__head">
          <Sparkles size={13} aria-hidden />
          Thinking
        </p>
        <p className="sc-ai-suggestion__body">Asking the assistant…</p>
      </div>
    )
  }

  if (error) {
    return (
      <div style={{ marginTop: 10 }}>
        <Notice
          tone="warning"
          title="The assistant could not answer"
          actions={onRetry ? <Button small onClick={onRetry}>Retry</Button> : undefined}
        >
          {error}
        </Notice>
      </div>
    )
  }

  if (!result) return null

  // A deployment with no model configured is a normal state, not a failure, and
  // it is worth saying once rather than showing a broken button.
  if (!result.available) {
    return (
      <div style={{ marginTop: 10 }}>
        <Notice tone="info" title="AI assistance is not available here">
          {result.reason ?? 'No model is configured for this deployment.'}
        </Notice>
      </div>
    )
  }

  return (
    <div className="sc-ai-suggestion" aria-live="polite">
      <p className="sc-ai-suggestion__head">
        <Sparkles size={13} aria-hidden />
        Suggestion
      </p>
      <p className="sc-ai-suggestion__body">{result.text}</p>
      <div className="sc-ai-suggestion__actions">
        {onApply && (
          <Button small tone="primary" onClick={onApply}>
            {applyLabel}
          </Button>
        )}
        <Button small onClick={onDismiss}>
          {onApply ? 'Discard' : 'Close'}
        </Button>
      </div>
    </div>
  )
}
