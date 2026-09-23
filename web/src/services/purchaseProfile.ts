/**
 * Every call the purchase-profile workspace makes, in one place.
 *
 * The screen is a dozen components; if each one reached for `api` directly,
 * the contract with the server would be spread across a dozen files and the
 * next person changing an endpoint would have to find all of them. The cards
 * take data and callbacks. This module is the only thing that knows a URL.
 *
 * Nothing here caches. The profile is read on the request that draws it, which
 * is the same rule the rest of this product follows.
 */

import { api, type CompanyScope, type ItemResponse } from './api'
import type { MatchPolicy, ProfileSavePayload, PurchaseSettings, PurchaseSettingsMeta } from './types'

/** `GET v1/settings` answers `{data, meta}` — the row, and what the screen needs to render it. */
export interface ProfileResponse {
  data: PurchaseSettings
  meta: PurchaseSettingsMeta
}

export interface MatchPolicyPayload {
  /** Present means edit that policy, including renaming it. Absent means create. */
  policy_id?: number
  policy_name: string
  qty_tolerance_pc: number
  rate_tolerance_pc: number
  value_tolerance_amt: number
  freight_tolerance_amt: number
  auto_match_below_amt: number
  is_default: boolean
}

export function fetchProfile(signal?: AbortSignal): Promise<ProfileResponse> {
  return api.get<ProfileResponse>('v1/settings', undefined, signal)
}

/**
 * The purchase profile of another company this person can open.
 *
 * Used by "Copy from existing profile", and deliberately the SAME endpoint as
 * the one above with a different scope on it rather than a new cross-company
 * route. Every scoped request is checked against Manage before it reads a row,
 * so borrowing that path means the copy is authorised exactly as strictly as
 * opening that company would be — and there is no second place to get wrong.
 */
export function fetchProfileForScope(scope: CompanyScope, signal?: AbortSignal): Promise<ProfileResponse> {
  return api.get<ProfileResponse>('v1/settings', { ...scope }, signal)
}

/**
 * Saving answers with the saved row. The server also re-sends `meta`, but the
 * screen already has it, so this is typed as the row alone rather than
 * pretending a PUT is the place to learn the profile-type catalogue.
 */
export function saveProfile(payload: ProfileSavePayload): Promise<ItemResponse<PurchaseSettings>> {
  return api.put<PurchaseSettings>('v1/settings', payload)
}

export function fetchMatchPolicies(signal?: AbortSignal): Promise<ItemResponse<MatchPolicy[]>> {
  return api.get<ItemResponse<MatchPolicy[]>>('v1/settings/match-policies', undefined, signal)
}

/** Both writes answer with the whole list, so the caller never has to re-fetch. */
export function saveMatchPolicy(payload: MatchPolicyPayload): Promise<ItemResponse<MatchPolicy[]>> {
  return api.post<MatchPolicy[]>('v1/settings/match-policies', payload)
}

export function deleteMatchPolicy(policyId: number): Promise<ItemResponse<MatchPolicy[]>> {
  return api.del<MatchPolicy[]>(`v1/settings/match-policies/${policyId}`)
}
