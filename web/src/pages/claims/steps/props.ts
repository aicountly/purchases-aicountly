/**
 * What every step is given.
 *
 * The steps hold no state of their own. They read the draft, they call `patch`,
 * and the page above owns the whole thing — which is what makes the summary
 * rail, the stepper and the footer agree with the form without any of them
 * talking to each other.
 */

import type { FieldErrors } from '../model'
import type { AssistIntent, ClaimDraft, ClaimMeta, ClaimStepId } from '../types'
import type { AssistState } from '../useClaimAssist'

export interface StepProps {
  draft: ClaimDraft
  meta: ClaimMeta | null
  errors: FieldErrors
  patch: (patch: Partial<ClaimDraft>) => void
  goTo: (step: ClaimStepId) => void

  assist: AssistState
  /** Ask the assistant. The draft it is given is assembled by the page. */
  runAssist: (intent: AssistIntent) => void
  acceptAssist: () => void
  dismissAssist: () => void
  assistIsFor: (intent: AssistIntent) => boolean
}
