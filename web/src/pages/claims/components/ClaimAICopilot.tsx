/**
 * The assistant, offered rather than imposed.
 *
 * FIVE NAMED JOBS, and each one sends the draft on screen and nothing else.
 * There is no model key in this bundle and there must never be one: the browser
 * asks this product's own API for a named job, and the server decides what to
 * ask a model — see `server-php/src/Ai/ClaimAssistant.php`.
 *
 * Every answer lands in a panel with Use this and Discard on it. Nothing the
 * model writes reaches the form on its own.
 */

import { FileSearch, Lightbulb, ListChecks, MessageSquare, ScrollText, Sparkles } from 'lucide-react'
import type { AssistIntent } from '../types'
import { AiSuggestion } from './AiSuggestion'
import type { AssistState } from '../useClaimAssist'

const ACTIONS: Array<{ intent: AssistIntent; label: string; icon: typeof Sparkles }> = [
  { intent: 'draft_description', label: 'Draft claim description', icon: ScrollText },
  { intent: 'suggest_type', label: 'Suggest claim type', icon: Lightbulb },
  { intent: 'suggest_documents', label: 'Identify missing documents', icon: FileSearch },
  { intent: 'summarise', label: 'Summarise claim', icon: ListChecks },
]

export function ClaimAICopilot({
  assist,
  onRun,
  onAccept,
  onDismiss,
  disabled,
  disabledReason,
}: {
  assist: AssistState
  onRun: (intent: AssistIntent) => void
  onAccept: () => void
  onDismiss: () => void
  disabled: boolean
  disabledReason: string
}) {
  // Only the two that write into a field can be applied. A summary is something
  // to read, and applying it over the description would replace the account of
  // what happened with a précis of it.
  const appliable = assist.intent === 'draft_description' || assist.intent === 'improve_description' || assist.intent === 'suggest_type'

  const showPanel =
    assist.intent !== null &&
    assist.intent !== 'improve_description' &&
    (assist.busy || assist.result !== null || assist.error !== null)

  return (
    <section className="claim-card claim-ai-card">
      <div className="claim-card-header">
        <h2>
          <Sparkles size={15} aria-hidden />
          AI Copilot
        </h2>
        <span className="sc-beta-badge">Beta</span>
      </div>

      <p className="sc-ai-copy">Need help with your claim?</p>
      <p className="sc-ai-copy is-muted">
        I can draft the description, suggest the likely claim type, point out the documents worth attaching, or
        summarise what you have so far.
      </p>

      {ACTIONS.map((action) => {
        const Icon = action.icon

        return (
          <button
            key={action.intent}
            type="button"
            className="sc-ai-action"
            disabled={disabled || assist.busy}
            title={disabled ? disabledReason : undefined}
            onClick={() => onRun(action.intent)}
          >
            <Icon size={14} aria-hidden />
            {action.label}
          </button>
        )
      })}

      <button
        type="button"
        className="sc-btn sc-btn--ai"
        disabled={disabled || assist.busy}
        title={disabled ? disabledReason : undefined}
        onClick={() => onRun('summarise')}
      >
        <MessageSquare size={14} aria-hidden />
        Chat with AI
      </button>

      {disabled && <p className="sc-field__hint" style={{ marginTop: 10 }}>{disabledReason}</p>}

      {showPanel && (
        <AiSuggestion
          result={assist.result}
          busy={assist.busy}
          error={assist.error}
          applyLabel={assist.intent === 'suggest_type' ? 'Use this type' : 'Use this description'}
          onApply={appliable ? onAccept : undefined}
          onDismiss={onDismiss}
        />
      )}
    </section>
  )
}
