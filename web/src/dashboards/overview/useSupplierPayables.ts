/**
 * The payables column, fetched after the dashboard has drawn.
 *
 * Smart Books answers bill-by-bill for ONE supplier at a time, so filling this
 * column is one upstream call per supplier. Doing that inside the dashboard
 * request would hold the whole screen — every KPI, every chart — behind five
 * round trips for one column. So the table paints with the column pending and
 * this fills it in.
 *
 * It fails on its own: a Books that declines this leaves five cells saying so
 * and the rest of the dashboard untouched.
 */

import { useEffect, useState } from 'react'
import { api, ApiError, type QueryParams } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import type { SupplierPayable, SupplierPayablesResponse } from '../types'

export interface SupplierPayablesState {
  byId: Map<number, SupplierPayable>
  loading: boolean
  error: string | null
  asOnLabel: string | null
  basis: string | null
}

const EMPTY: SupplierPayablesState = {
  byId: new Map(),
  loading: false,
  error: null,
  asOnLabel: null,
  basis: null,
}

export function useSupplierPayables(supplierIds: number[], params: QueryParams, enabled: boolean): SupplierPayablesState {
  const { scope } = usePurchases()
  const [state, setState] = useState<SupplierPayablesState>(EMPTY)

  // The ids are the identity of this request as much as the filters are, and
  // they arrive as a new array on every render of the parent — so the effect
  // keys on their text, not on the array.
  const key = supplierIds.join(',')

  useEffect(() => {
    if (!enabled || !scope || key === '') {
      setState(EMPTY)
      return
    }

    const controller = new AbortController()
    let current = true
    setState((previous) => ({ ...previous, loading: true, error: null }))

    api
      .one<SupplierPayablesResponse>('v1/dashboards/overview/supplier-payables', { ...params, supplier_ids: key }, controller.signal)
      .then((response) => {
        if (!current) return
        const byId = new Map<number, SupplierPayable>()
        for (const row of response.data.suppliers) {
          byId.set(row.supplier_account_id, row)
        }
        setState({
          byId,
          loading: false,
          error: null,
          asOnLabel: response.data.as_on_label,
          basis: response.data.basis,
        })
      })
      .catch((error: unknown) => {
        if (!current || controller.signal.aborted) return
        setState({
          ...EMPTY,
          error: error instanceof ApiError ? error.message : 'Smart Books could not be reached for supplier payables.',
        })
      })

    return () => {
      current = false
      controller.abort()
    }
    // `params` is a fresh object each render; its contents are what matter.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, enabled, scope, JSON.stringify(params)])

  return state
}
