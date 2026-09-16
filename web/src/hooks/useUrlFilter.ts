/**
 * A list filter that lives in the URL.
 *
 * Two things stop working when a filter lives only in component state: a
 * drill-down from a dashboard arrives and is ignored, and a user who has
 * narrowed a list cannot send anyone the link. Both are the same bug, and this
 * is the fix for both.
 */

import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'

export function useUrlFilter(name: string, fallback = ''): [string, (value: string) => void] {
  const [params, setParams] = useSearchParams()
  const value = params.get(name) ?? fallback

  const set = useCallback(
    (next: string) => {
      const updated = new URLSearchParams(params)
      if (next === '' || next === fallback) {
        updated.delete(name)
      } else {
        updated.set(name, next)
      }
      // Each change is its own history entry, so Back undoes one filter rather
      // than leaving the page entirely.
      setParams(updated, { replace: false })
    },
    [params, setParams, name, fallback],
  )

  return [value, set]
}

/** The same, for a flag that is present or absent rather than a value. */
export function useUrlFlag(name: string): [boolean, (value: boolean) => void] {
  const [value, set] = useUrlFilter(name)

  return [value === '1', (next: boolean) => set(next ? '1' : '')]
}

/**
 * A numeric id from the URL, or undefined.
 *
 * The app uses `supplier_id` in links throughout; the API calls the same thing
 * `supplier_account_id`. Mapping it in one place is what stops half the links
 * using one spelling and half the other.
 */
export function useUrlId(name: string): number | undefined {
  const [params] = useSearchParams()
  const raw = params.get(name)
  if (raw === null || raw === '') return undefined
  const parsed = Number.parseInt(raw, 10)

  return Number.isFinite(parsed) && parsed > 0 ? parsed : undefined
}
