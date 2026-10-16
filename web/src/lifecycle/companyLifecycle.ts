/**
 * Company lifecycle — view/export-only after a lapsed subscription (server-php docs/COMPANY_LIFECYCLE.md).
 *
 * Two sources, both from Manage through this product's API:
 *   - a write refused with HTTP 423 `{code: "COMPANY_RESTRICTED", message, restricted_until}` (the API's
 *     receiver-kit write gate) — the message is ready to show as it is;
 *   - companyinfo's `lifecycle` (`active | restricted | purge_pending`) and `restricted_until`, read when
 *     the company is opened, so the banner is up before the first refused write.
 *
 * Framework-free store + React hooks; CompanyReadOnlyBanner renders the banner and the 423 toast.
 */

import { useSyncExternalStore } from 'react'

export const COMPANY_RESTRICTED = 'COMPANY_RESTRICTED'

export interface CompanyRestriction {
  /** The company it applies to; null when the refusal did not say (shown for whichever is open). */
  cmpId: number | null
  /** restricted | purge_pending | purged */
  lifecycle: string
  message: string
  restrictedUntil: string | null
}

export interface RestrictionToast {
  id: number
  message: string
}

let restriction: CompanyRestriction | null = null
let toast: RestrictionToast | null = null
let toastSeq = 0
const listeners = new Set<() => void>()

function emit(): void {
  for (const listener of listeners) listener()
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function record(value: unknown): Record<string, unknown> | null {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : null
}

function text(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null
}

function formatDate(iso: string | null): string | null {
  if (!iso) return null
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return null
  return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
}

/** The sentence the API's 423 carries, for a state learnt from companyinfo. */
export function restrictionMessage(lifecycle: string, restrictedUntil: string | null): string {
  if (lifecycle === 'purged') {
    return "This company's data in this application was deleted after its subscription ended. Renew the subscription to start again."
  }
  if (lifecycle === 'purge_pending') {
    return "This company is read-only because its subscription ended, and its data in this application is being deleted. Export what you need now."
  }
  const date = formatDate(restrictedUntil)
  return date
    ? `This company is read-only until ${date} because its subscription ended. Renew to make changes, or export your data.`
    : 'This company is read-only because its subscription ended. Renew to make changes, or export your data.'
}

/** Is this an API answer refusing a write because the company is read-only? */
export function isCompanyRestricted(status: number, body: unknown): boolean {
  if (status !== 423) return false
  const b = record(body)
  const error = record(b?.error)
  return b?.code === COMPANY_RESTRICTED || error?.code === COMPANY_RESTRICTED
}

/**
 * Call with every failed API answer. A 423 COMPANY_RESTRICTED puts the banner up and shows its message
 * as a toast; returns true when it was one.
 */
export function noteApiRefusal(status: number, body: unknown, cmpId: number | null = null): boolean {
  if (!isCompanyRestricted(status, body)) return false
  const b = record(body) ?? {}
  const error = record(b.error) ?? {}
  const until = text(b.restricted_until) ?? text(error.restricted_until) ?? text(record(error.details)?.restricted_until)
  const lifecycle = text(b.lifecycle) ?? text(record(error.details)?.lifecycle) ?? 'restricted'
  const message = text(b.message) ?? text(error.message) ?? restrictionMessage(lifecycle, until)
  restriction = { cmpId: cmpId && cmpId > 0 ? cmpId : null, lifecycle, message, restrictedUntil: until }
  toast = { id: ++toastSeq, message }
  emit()
  return true
}

/**
 * Call with Manage's companyinfo answer for the company being opened (the whole body or its `data`).
 * `restricted` / `purge_pending` put the banner up; `active` takes it down. An answer without
 * `lifecycle` (an older Manage) changes nothing.
 */
export function noteCompanyInfo(cmpId: number, body: unknown): void {
  const outer = record(body)
  const data = record(outer?.data) ?? outer
  const lifecycle = text(data?.lifecycle)?.toLowerCase()
  if (!lifecycle) return
  if (lifecycle === 'restricted' || lifecycle === 'purge_pending') {
    const until = text(data?.restricted_until)
    restriction = { cmpId, lifecycle, message: restrictionMessage(lifecycle, until), restrictedUntil: until }
    emit()
  } else if (lifecycle === 'active' && restriction && (restriction.cmpId === cmpId || restriction.cmpId === null)) {
    restriction = null
    emit()
  }
}

export function clearCompanyRestriction(): void {
  if (restriction === null && toast === null) return
  restriction = null
  toast = null
  emit()
}

export function dismissRestrictionToast(id: number): void {
  if (toast?.id !== id) return
  toast = null
  emit()
}

export function getCompanyRestriction(): CompanyRestriction | null {
  return restriction
}

export function getRestrictionToast(): RestrictionToast | null {
  return toast
}

/** The restriction for the open company (null when it is not read-only, or another company is). */
export function useCompanyRestriction(cmpId: number | null | undefined): CompanyRestriction | null {
  const current = useSyncExternalStore(subscribe, getCompanyRestriction, getCompanyRestriction)
  if (!current) return null
  if (current.cmpId !== null && cmpId && current.cmpId !== cmpId) return null
  return current
}

export function useRestrictionToast(): RestrictionToast | null {
  return useSyncExternalStore(subscribe, getRestrictionToast, getRestrictionToast)
}
