/**
 * The monthly shape behind each KPI on this screen.
 *
 * Only four of the six cards get one, and that is the point. On-time delivery,
 * acceptance, the supply base and the largest share are all things the server
 * can state month by month. Weighted payment terms is a property of supplier
 * profiles rather than of a month, and open claims is a queue rather than a
 * period total — neither has a shape, so neither is given one. A sparkline
 * under a number a reader cannot trace is decoration that reads as evidence.
 */

import type { DashboardResponse } from '../types'
import type { TrendPanel } from './types'

export type SparkSeries = Record<string, { period: string; value: string | null }[]>

export function supplierSparklines(data: DashboardResponse): SparkSeries {
  const trend = data.panels?.delivery_trend as TrendPanel | undefined
  if (trend === undefined || !trend.available) return {}

  const series: SparkSeries = {}

  const points = trend.points ?? []
  if (points.length > 1) {
    series.on_time_delivery = points.map((point) => ({ period: point.period, value: point.on_time_pc }))
    series.acceptance_rate = points.map((point) => ({ period: point.period, value: point.acceptance_pc ?? null }))
  }

  const supply = trend.supply ?? []
  if (supply.length > 1) {
    series.active_suppliers = supply.map((point) => ({ period: point.period, value: String(point.suppliers) }))
    series.concentration = supply.map((point) => ({ period: point.period, value: point.top_share_pc }))
  }

  return series
}
