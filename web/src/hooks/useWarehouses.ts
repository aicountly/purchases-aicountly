import { useMemo } from 'react'
import { api } from '../services/api'
import { useApi } from './useApi'

export interface Warehouse {
  id: number
  name: string
}

/** Inventory's warehouses, read live through this product's relay (v1/catalog/warehouses). */
export function useWarehouses(enabled: boolean): Warehouse[] {
  const { data } = useApi(
    (signal) => api.get<{ data?: Array<Record<string, unknown>> }>('v1/catalog/warehouses', undefined, signal),
    [],
    enabled,
  )

  return useMemo(
    () =>
      (data?.data ?? [])
        .map((w) => ({
          id: Number(w.warehouse_id ?? w.id ?? 0),
          name: String(w.warehouse_name ?? w.name ?? `Warehouse ${w.warehouse_id ?? w.id}`),
        }))
        .filter((w) => w.id > 0),
    [data],
  )
}
