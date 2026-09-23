/**
 * The supplier dashboard's panels, as the API returns them.
 *
 * These were declared inline in the view. They are shared now: the shell needs
 * the monthly series to draw the KPI sparklines, the signals module needs the
 * matrix to work out what is at risk, and both would otherwise re-describe the
 * same payload slightly differently.
 */

import type { Panel, PriceMovementRow, SupplierRow } from '../types'

export type MatrixPanel = Panel<{
  rows: SupplierRow[]
  currency: string
  values_visible: boolean
  basis: string
}>

export type PricePanel = Panel<{
  rows: PriceMovementRow[]
  observation_period: string
  min_sample: number
  index_note?: string
  basis: string
}>

export interface TrendPoint {
  period: string
  sample: number
  on_time: number
  on_time_pc: string | null
  rated: boolean
  /** Lines on that month's accepted receipts with nothing rejected. */
  accepted_lines: number
  received_lines: number
  acceptance_pc: string | null
  /** The two monthly components, weighted with the published weights. */
  score: string | null
}

export interface SupplyPoint {
  period: string
  suppliers: number
  top_share_pc: string | null
}

export type TrendPanel = Panel<{
  points: TrendPoint[]
  supply: SupplyPoint[]
  series: { key: string; label: string }[]
  min_sample: number
  still_waiting: { lines: number; orders: number; note: string }
  basis: string
}>

export interface ConcentrationSupplier {
  supplier_account_id: number
  supplier_name: string | null
  formatted: string
  share_pc: string | null
  qualification_status: string
}

export type ConcentrationPanel = Panel<{
  currency: string
  total_formatted: string
  suppliers: ConcentrationSupplier[]
  sole_source: {
    item_id: number
    item_label: string
    supplier_account_id: number
    supplier_name: string | null
    formatted: string
    line_count: number
  }[]
  basis: string
}>

export type DetailPanel = Panel<Record<string, unknown>>

export interface ScoreModel {
  weights: Record<string, number>
  min_sample: number
  description: string
}

/** Every panel this screen reads, pulled out of the envelope once. */
export interface SupplierPanels {
  matrix: MatrixPanel
  price: PricePanel
  trend: TrendPanel
  concentration: ConcentrationPanel
  detail: DetailPanel
  scoreModel: ScoreModel
}
