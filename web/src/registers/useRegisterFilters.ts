/**
 * The register filters of a list, read from and written to the URL (see useUrlFilter for why a
 * filter lives there). Each change is its own history entry, so Back undoes one filter.
 */

import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  type GstKey,
  type RegisterFilterState,
  readRegisterFilters,
  registerQuery,
  toggleGstKey,
  withAdvanced,
  withGst,
} from './registerFilters'

/** @param withSupplier false for a list with no supplier (requisitions): `supplier_id` is then ignored. */
export function useRegisterFilters(withSupplier = true) {
  const [params, setParams] = useSearchParams()
  const state = useMemo(() => {
    const read = readRegisterFilters(params)
    return withSupplier ? read : { ...read, supplierId: '' }
  }, [params, withSupplier])
  // One string to put in an effect's or a fetch's dependencies.
  const key = `${state.gst.join(',')}|${state.amountMin}|${state.amountMax}|${state.supplierId}`

  const update = useCallback((next: URLSearchParams) => setParams(next, { replace: false }), [setParams])

  return {
    ...state,
    key,
    /** Any filter in force — for the empty state ("No bills match these filters."). */
    active: state.gst.length > 0 || state.amountMin !== '' || state.amountMax !== '' || state.supplierId !== '',
    query: registerQuery(state),
    toggleGst: (gstKey: GstKey) => update(withGst(params, toggleGstKey(state.gst, gstKey))),
    /** Drop these keys (all of them when none are named). */
    clearGst: (keys?: readonly string[]) => update(withGst(params, keys ? state.gst.filter((k) => !keys.includes(k)) : [])),
    applyAdvanced: (next: Pick<RegisterFilterState, 'amountMin' | 'amountMax' | 'supplierId'>) => update(withAdvanced(params, next)),
    clearAdvanced: () => update(withAdvanced(params, { amountMin: '', amountMax: '', supplierId: '' })),
  }
}
