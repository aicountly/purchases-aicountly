/**
 * Supplier risk and the insight cards, worked out from what the API returned.
 *
 * WHY THESE ARE RULES AND NOT A MODEL. Every line on both panels is a threshold
 * over a figure the server has already computed and already shows somewhere
 * else on this screen — the on-time rate a supplier was given, the share of
 * ordered value the largest one holds, the claims that are open. Nothing here
 * recomputes a procurement formula, nothing here invents a number, and every
 * card can name the rows it came from. That is the whole contract: an insight a
 * buyer cannot check is an insight they will stop reading.
 *
 * WHAT IS NOT HERE. No forecast, no supplier ranking the server did not
 * produce, no rupee saving that is not a figure the server formatted. Where a
 * signal would need data this product does not hold, it is absent rather than
 * estimated.
 *
 * THE THRESHOLDS are constants in one place, deliberately: they are a product
 * decision and they belong somewhere a reviewer can find them and change them
 * once. Where the backend later publishes its own, this module reads those
 * instead — see `thresholds()`.
 */

import type { SupplierRow } from '../types'
import type { ConcentrationPanel, MatrixPanel, ScoreModel, TrendPanel } from './types'

export type Severity = 'high' | 'medium' | 'low' | 'info'

export interface SupplierRiskItem {
  id: string
  severity: Severity
  title: string
  detail: string
  /** Where the rows behind this line live. Never a dead end. */
  route: string
  filters?: Record<string, string>
}

export interface SupplierInsight {
  id: string
  kind: 'concentration' | 'delivery' | 'quality' | 'negotiation' | 'terms' | 'coverage'
  severity: Severity
  title: string
  summary: string
  /** The figures this was read off. Shown when the card is opened. */
  evidence: string[]
  actionLabel: string
  route: string
  filters?: Record<string, string>
}

export interface Thresholds {
  /** Largest-supplier share at or above which concentration is flagged, in %. */
  concentration: number
  /** A drop in on-time delivery, in percentage points, worth naming. */
  deliveryDrop: number
  /** An on-time rate at or below which a supplier is failing, in %. */
  onTimeFloor: number
  /** An acceptance rate at or below which quality is failing, in %. */
  acceptanceFloor: number
  /** Score at or above which a supplier reads as good / as adequate. */
  scoreGood: number
  scoreFair: number
}

const DEFAULTS: Thresholds = {
  concentration: 25,
  deliveryDrop: 10,
  onTimeFloor: 80,
  acceptanceFloor: 90,
  scoreGood: 85,
  scoreFair: 70,
}

/**
 * The thresholds in force.
 *
 * The score bands come from the server's own score model where it publishes
 * them, so the chip in the table and the wording in an insight cannot disagree
 * about what "good" means.
 */
export function thresholds(scoreModel?: ScoreModel): Thresholds {
  const bands = (scoreModel as unknown as { bands?: { good?: number; fair?: number } } | undefined)?.bands

  return {
    ...DEFAULTS,
    scoreGood: bands?.good ?? DEFAULTS.scoreGood,
    scoreFair: bands?.fair ?? DEFAULTS.scoreFair,
  }
}

/** Values arrive as exact decimal strings; parsed only to compare, never to display. */
function num(value: string | null | undefined): number | null {
  if (value === null || value === undefined) return null
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed : null
}

function name(row: { supplier_name: string | null; supplier_account_id: number }): string {
  return row.supplier_name ?? `Account ${row.supplier_account_id}`
}

function plural(count: number, one: string, many = `${one}s`): string {
  return `${count} ${count === 1 ? one : many}`
}

/**
 * The most recent month a supplier was rated in, and the one before it.
 *
 * Months with too small a sample carry no rate, and they are skipped rather
 * than read as a fall to zero — the same rule the sparkline draws by.
 */
function lastTwoRated(points: { on_time_pc: string | null }[]): [number, number] | null {
  const rated = points.map((point) => num(point.on_time_pc)).filter((value): value is number => value !== null)
  if (rated.length < 2) return null
  return [rated[rated.length - 2], rated[rated.length - 1]]
}

// ---------------------------------------------------------------------------
// Risk and issues
// ---------------------------------------------------------------------------

export function supplierRisks(
  matrix: MatrixPanel,
  limits: Thresholds,
): { items: SupplierRiskItem[]; available: boolean } {
  if (!matrix.available) return { items: [], available: false }

  const rows = matrix.rows
  const items: SupplierRiskItem[] = []

  const delayed = rows.filter((row) => row.overdue_lines > 0)
  if (delayed.length > 0) {
    const lines = delayed.reduce((sum, row) => sum + row.overdue_lines, 0)
    items.push({
      id: 'delayed',
      severity: 'high',
      title: `${plural(delayed.length, 'supplier')} with overdue deliveries`,
      detail: `${plural(lines, 'order line')} past the promised date`,
      route: '/dashboard/procurement',
      filters: { panel: 'overdue' },
    })
  }

  const failing = rows.filter((row) => {
    const onTime = num(row.on_time_pc)
    return onTime !== null && onTime <= limits.onTimeFloor
  })
  if (failing.length > 0) {
    items.push({
      id: 'on-time',
      severity: 'medium',
      title: `${plural(failing.length, 'supplier')} below ${limits.onTimeFloor}% on time`,
      detail: failing
        .slice(0, 2)
        .map((row) => `${name(row)} ${row.on_time_pc}%`)
        .join(', '),
      route: '/dashboard/suppliers',
      filters: { supplier_id: String(failing[0].supplier_account_id) },
    })
  }

  const rejecting = rows.filter((row) => {
    const acceptance = num(row.acceptance_pc)
    return acceptance !== null && acceptance <= limits.acceptanceFloor
  })
  if (rejecting.length > 0) {
    items.push({
      id: 'acceptance',
      severity: 'medium',
      title: `${plural(rejecting.length, 'supplier')} with rejected receipts`,
      detail: `Acceptance at or below ${limits.acceptanceFloor}% on inspected lines`,
      route: '/dashboard/suppliers',
      filters: { supplier_id: String(rejecting[0].supplier_account_id) },
    })
  }

  const claims = rows.reduce((sum, row) => sum + row.open_claims, 0)
  if (claims > 0) {
    const against = rows.filter((row) => row.open_claims > 0)
    items.push({
      id: 'claims',
      severity: 'medium',
      title: `${plural(claims, 'open supplier claim')}`,
      detail: `Against ${plural(against.length, 'supplier')}, not settled, closed or rejected`,
      route: '/claims',
    })
  }

  // The profile flags. These are somebody's judgement recorded against the
  // supplier, not a rate this screen computed, so they are reported as-is.
  const flagged = rows.filter((row) => row.risk_flag !== null && row.risk_flag !== '')
  if (flagged.length > 0) {
    items.push({
      id: 'risk-flag',
      severity: 'high',
      title: `${plural(flagged.length, 'supplier')} carrying a risk flag`,
      detail: flagged
        .slice(0, 2)
        .map((row) => `${name(row)}: ${String(row.risk_flag).replace(/_/g, ' ')}`)
        .join(' · '),
      route: '/suppliers',
    })
  }

  const unqualified = rows.filter(
    (row) => row.qualification_status === 'none' || row.qualification_status === 'pending',
  )
  if (unqualified.length > 0) {
    items.push({
      id: 'qualification',
      severity: 'low',
      title: `${plural(unqualified.length, 'supplier')} without an approved profile`,
      detail: 'Bought from in this period, never qualified here',
      route: '/suppliers',
    })
  }

  return { items, available: true }
}

// ---------------------------------------------------------------------------
// Insights
// ---------------------------------------------------------------------------

export function supplierInsights({
  matrix,
  concentration,
  trend,
  limits,
}: {
  matrix: MatrixPanel
  concentration: ConcentrationPanel
  trend: TrendPanel
  limits: Thresholds
}): SupplierInsight[] {
  const insights: SupplierInsight[] = []

  // 1. Concentration. The share is the server's; the threshold is ours.
  if (concentration.available && concentration.suppliers.length > 0) {
    const top = concentration.suppliers[0]
    const share = num(top.share_pc)

    if (share !== null && share >= limits.concentration) {
      insights.push({
        id: 'concentration',
        kind: 'concentration',
        severity: share >= limits.concentration * 2 ? 'high' : 'medium',
        title: 'High concentration risk',
        summary: `${top.share_pc}% of ordered value sits with ${top.supplier_name ?? `Account ${top.supplier_account_id}`}. Consider a second source.`,
        evidence: [
          `${top.supplier_name ?? `Account ${top.supplier_account_id}`}: ${top.formatted} of ${concentration.total_formatted} ordered`,
          `Flagged because one supplier holds ${limits.concentration}% or more of the period's ordered value`,
          concentration.basis,
        ],
        actionLabel: 'See the concentration',
        route: '/dashboard/suppliers',
        filters: { supplier_id: String(top.supplier_account_id) },
      })
    }
  }

  // 2. Items with exactly one supplier. Dependence, evidenced by the orders.
  if (concentration.available && concentration.sole_source.length > 0) {
    const items = concentration.sole_source
    insights.push({
      id: 'sole-source',
      kind: 'coverage',
      severity: 'medium',
      title: 'Single-sourced items',
      summary: `${plural(items.length, 'item')} were bought more than once this period and always from the same supplier.`,
      evidence: items
        .slice(0, 3)
        .map((item) => `${item.item_label}: ${item.line_count} lines, all from ${item.supplier_name ?? 'one supplier'} (${item.formatted})`)
        .concat('Evidence of dependence, not proof that no alternative exists.'),
      actionLabel: 'Open sourcing',
      route: '/rfqs',
    })
  }

  // 3. Delivery, month against month, from the rated months only.
  if (trend.available) {
    const pair = lastTwoRated(trend.points)
    if (pair !== null) {
      const [before, now] = pair
      const move = now - before

      if (move <= -limits.deliveryDrop) {
        insights.push({
          id: 'delivery-drop',
          kind: 'delivery',
          severity: 'high',
          title: 'Delivery inconsistency',
          summary: `On-time delivery fell ${Math.abs(move).toFixed(1)} pp between the last two rated months.`,
          evidence: [
            `${before.toFixed(1)}% → ${now.toFixed(1)}% on time`,
            `Months with fewer than ${trend.min_sample} receipts carry no rate and were skipped`,
            trend.basis,
          ],
          actionLabel: 'Open the receipts',
          route: '/dashboard/procurement',
        })
      } else if (move >= limits.deliveryDrop) {
        insights.push({
          id: 'delivery-rise',
          kind: 'delivery',
          severity: 'info',
          title: 'Delivery improving',
          summary: `On-time delivery rose ${move.toFixed(1)} pp between the last two rated months.`,
          evidence: [`${before.toFixed(1)}% → ${now.toFixed(1)}% on time`, trend.basis],
          actionLabel: 'See the trend',
          route: '/dashboard/suppliers',
        })
      }
    }

    // 4. Orders that are late and have produced nothing at all. These never
    //    reach an on-time rate, which is exactly why they are worth surfacing.
    if (trend.still_waiting.lines > 0) {
      insights.push({
        id: 'still-waiting',
        kind: 'delivery',
        severity: 'high',
        title: 'Overdue with nothing received',
        summary: `${plural(trend.still_waiting.lines, 'line')} across ${plural(trend.still_waiting.orders, 'order')} are past the promised date with no receipt at all.`,
        evidence: [trend.still_waiting.note],
        actionLabel: 'Chase the orders',
        route: '/purchase-orders',
      })
    }
  }

  // 5. The suppliers worth a conversation: rated, scored, and scoring badly.
  if (matrix.available) {
    const weak = matrix.rows
      .filter((row) => {
        const score = num(row.score)
        return score !== null && score < limits.scoreFair
      })
      .sort((a, b) => (num(a.score) ?? 0) - (num(b.score) ?? 0))

    if (weak.length > 0) {
      insights.push({
        id: 'weak-scores',
        kind: 'negotiation',
        severity: 'medium',
        title: 'Performance conversations due',
        summary: `${plural(weak.length, 'supplier')} scored below ${limits.scoreFair} on the published model.`,
        evidence: weak
          .slice(0, 3)
          .map((row) => `${name(row)}: ${row.score}, ${row.on_time_label.toLowerCase()}`)
          .concat('Scored only on the components with enough observations in this period.'),
        actionLabel: 'Open the scorecard',
        route: '/dashboard/suppliers',
        filters: { supplier_id: String(weak[0].supplier_account_id) },
      })
    }

    // 6. Terms. Only where the profile actually records days, which is the
    //    same restriction the KPI above applies to itself.
    const noTerms = matrix.rows.filter((row) => row.qualification_status !== 'none').length
    if (noTerms > 0 && matrix.rows.length >= 3) {
      const preferred = matrix.rows.filter((row) => row.is_preferred).length
      if (preferred === 0) {
        insights.push({
          id: 'no-preferred',
          kind: 'terms',
          severity: 'low',
          title: 'No preferred supplier set',
          summary: `${plural(matrix.rows.length, 'supplier')} were bought from and none is marked preferred, so nothing steers the next order.`,
          evidence: ['Preferred is a flag on the procurement profile in this product, not something Books or Inventory hold.'],
          actionLabel: 'Open suppliers',
          route: '/suppliers',
        })
      }
    }
  }

  return insights
}

/**
 * The band a score falls in. One place, so the chip in the table and the
 * wording in an insight cannot drift apart.
 */
export function scoreBand(score: string | null, limits: Thresholds): 'good' | 'warn' | 'risk' | 'none' {
  const value = num(score)
  if (value === null) return 'none'
  if (value >= limits.scoreGood) return 'good'
  if (value >= limits.scoreFair) return 'warn'
  return 'risk'
}

/** The risk word beside a supplier, worked out from the rates behind it. */
export function riskLevel(row: SupplierRow, limits: Thresholds): { label: string; tone: 'success' | 'warning' | 'danger' | 'neutral' } {
  if (row.risk_flag !== null && row.risk_flag !== '') return { label: 'High', tone: 'danger' }

  const band = scoreBand(row.score, limits)
  if (band === 'none') return { label: 'Unrated', tone: 'neutral' }
  if (band === 'risk' || row.overdue_lines > 0 || row.open_claims > 0) return { label: 'High', tone: 'danger' }
  if (band === 'warn') return { label: 'Medium', tone: 'warning' }
  return { label: 'Low', tone: 'success' }
}

/** The status word beside a supplier, from the profile this product owns. */
export function supplierStanding(row: SupplierRow): { label: string; tone: 'success' | 'warning' | 'danger' | 'neutral' } {
  if (row.qualification_status === 'blocked' || row.qualification_status === 'suspended') {
    return { label: 'Blocked', tone: 'danger' }
  }
  if (row.is_preferred) return { label: 'Preferred', tone: 'success' }
  if (row.overdue_lines > 0 || row.open_claims > 0) return { label: 'Watch', tone: 'warning' }
  if (row.qualification_status === 'approved') return { label: 'Active', tone: 'neutral' }
  if (row.qualification_status === 'none') return { label: 'Unqualified', tone: 'warning' }
  return { label: row.qualification_status.replace(/_/g, ' '), tone: 'neutral' }
}
