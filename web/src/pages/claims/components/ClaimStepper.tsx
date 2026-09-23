/**
 * Where you are, where you have been, and what you may open next.
 *
 * BACKWARDS IS ALWAYS ALLOWED. Forwards is allowed as far as the furthest step
 * reached, and one beyond it only when the current step is complete — which is
 * the same rule the Next button follows, so the two can never disagree about
 * whether you may move on.
 *
 * On a phone five steps do not fit and shrinking them to fit makes five
 * unreadable ones, so that width gets a single line and a progress bar instead.
 */

import { Check } from 'lucide-react'
import { CLAIM_STEPS, stepIndex } from '../model'
import type { ClaimStepId } from '../types'

export function ClaimStepper({
  current,
  furthest,
  completed,
  onSelect,
}: {
  current: ClaimStepId
  furthest: ClaimStepId
  completed: (step: ClaimStepId) => boolean
  onSelect: (step: ClaimStepId) => void
}) {
  const currentIndex = stepIndex(current)
  const furthestIndex = stepIndex(furthest)
  const step = CLAIM_STEPS[currentIndex]

  return (
    <>
      <nav className="claim-stepper" aria-label="Claim progress">
        {CLAIM_STEPS.map((definition, index) => {
          const isActive = definition.id === current
          const isComplete = !isActive && completed(definition.id)
          const reachable = index <= furthestIndex

          return (
            <button
              key={definition.id}
              type="button"
              className={`claim-step${isActive ? ' is-active' : ''}${isComplete ? ' is-complete' : ''}`}
              aria-current={isActive ? 'step' : undefined}
              disabled={!reachable}
              onClick={() => onSelect(definition.id)}
            >
              <span className="claim-step__number" aria-hidden>
                {isComplete ? <Check size={15} /> : index + 1}
              </span>
              <span className="claim-step__label">
                <strong>{definition.title}</strong>
                <small>{definition.caption}</small>
              </span>
            </button>
          )
        })}
      </nav>

      <div className="claim-stepper-compact">
        <div className="claim-stepper-compact__head">
          <strong>{step.title}</strong>
          <span>
            Step {currentIndex + 1} of {CLAIM_STEPS.length}
          </span>
        </div>
        <div className="claim-stepper-compact__track">
          <div
            className="claim-stepper-compact__fill"
            style={{ width: `${((currentIndex + 1) / CLAIM_STEPS.length) * 100}%` }}
          />
        </div>
      </div>
    </>
  )
}
