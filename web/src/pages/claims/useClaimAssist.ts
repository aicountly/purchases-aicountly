/**
 * The assistant's state, for the whole screen.
 *
 * ONE SUGGESTION AT A TIME, and it belongs to the field it was asked about.
 * Asking for a claim type while a description suggestion is on screen replaces
 * it, because two suggestions competing for one "Use this" is a way to apply
 * the wrong one.
 *
 * Nothing here writes to the draft. `accept` hands the text back to the page,
 * which decides which field it belongs in — this hook never reaches into a form.
 */

import { useCallback, useState } from 'react'
import { ApiError } from '../../services/api'
import { requestClaimAssist } from './service'
import type { AssistIntent, AssistResult } from './types'

export interface AssistState {
  intent: AssistIntent | null
  busy: boolean
  result: AssistResult | null
  error: string | null
}

const IDLE: AssistState = { intent: null, busy: false, result: null, error: null }

export function useClaimAssist() {
  const [state, setState] = useState<AssistState>(IDLE)

  const run = useCallback(async (intent: AssistIntent, draft: Record<string, unknown>) => {
    setState({ intent, busy: true, result: null, error: null })

    try {
      const result = await requestClaimAssist(intent, draft)
      setState({ intent, busy: false, result, error: null })
    } catch (error) {
      setState({
        intent,
        busy: false,
        result: null,
        error: error instanceof ApiError ? error.message : 'The assistant could not be reached.',
      })
    }
  }, [])

  const dismiss = useCallback(() => setState(IDLE), [])

  /** True when the panel on screen belongs to this intent. */
  const isFor = useCallback(
    (intent: AssistIntent) => state.intent === intent && (state.busy || state.result !== null || state.error !== null),
    [state],
  )

  return { state, run, dismiss, isFor }
}
