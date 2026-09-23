/**
 * Dashboard 3 — Supplier performance.
 *
 * HOW THE SCREEN IS LAID OUT, and why. The ranking is on the left because it is
 * where a reader starts: which suppliers, in what order. The two charts sit in
 * the middle, because a shape is read after a list and before a recommendation.
 * The two lists on the right — what is wrong, and what to do about it — are
 * read as prose, so they get the narrow column.
 *
 * Every panel here is fed by the one dashboard request the shell already makes.
 * Nothing on this screen opens a second connection, nothing is cached between
 * companies, and the two right-hand panels are worked out from the same rows
 * the left-hand table is drawn from — see `signals.ts`.
 */

import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import type { DashboardFilters } from '../filters'
import type { DashboardResponse } from '../types'
import type {
  ConcentrationPanel,
  DetailPanel,
  MatrixPanel,
  PricePanel,
  ScoreModel,
  TrendPanel,
} from './types'
import { supplierInsights, supplierRisks, thresholds } from './signals'
import { TopSuppliers } from './components/TopSuppliers'
import { PerformanceTrend } from './components/PerformanceTrend'
import { SpendConcentration } from './components/SpendConcentration'
import { RiskPanel } from './components/RiskPanel'
import { InsightsPanel } from './components/InsightsPanel'
import { PriceMovement } from './components/PriceMovement'
import { SupplierDrawer } from './components/SupplierDrawer'

export function SupplierPerformance({
  data,
  filters,
}: {
  data: DashboardResponse
  filters: DashboardFilters
}) {
  const navigate = useNavigate()

  // Panel-level view state. It is deliberately NOT in the URL: which way the
  // donut is cut is a glance, not a destination, and putting it in the query
  // string would put a history entry between the reader and the Back button.
  const [expanded, setExpanded] = useState(false)
  const [trendWindow, setTrendWindow] = useState('6')
  const [grouping, setGrouping] = useState('supplier')

  const matrix = data.panels.matrix as MatrixPanel
  const price = data.panels.price_movement as PricePanel
  const trend = data.panels.delivery_trend as TrendPanel
  const concentration = data.panels.concentration as ConcentrationPanel
  const detail = data.panels.detail as DetailPanel
  const scoreModel = data.score_model as ScoreModel

  const limits = useMemo(() => thresholds(scoreModel), [scoreModel])

  // Both right-hand panels are derived from panels already on the wire, so they
  // are recomputed only when the response or the thresholds change — never on
  // a hover or a tab.
  const risks = useMemo(() => supplierRisks(matrix, limits), [matrix, limits])
  const insights = useMemo(
    () => supplierInsights({ matrix, concentration, trend, limits }),
    [matrix, concentration, trend, limits],
  )

  const open = (route: string, extra?: Record<string, string>) => {
    const query = new URLSearchParams(extra ?? {}).toString()
    navigate(query === '' ? route : `${route}?${query}`)
  }

  return (
    <>
      <div className="purchase-analytics">
        <div className="purchase-stack">
        <TopSuppliers
          matrix={matrix}
          scoreModel={scoreModel}
          limits={limits}
          expanded={expanded}
          onToggleExpanded={() => setExpanded((was) => !was)}
          // The drawer is a filter, so the URL says which supplier is open and
          // Back closes it.
          onOpenSupplier={(row) => filters.set({ supplier_id: String(row.supplier_account_id) })}
        />

        {/* The one panel here that is about items rather than suppliers. It
            sits under the ranking because it needs the same width and answers
            the question the ranking raises: what did they charge? */}
        <PriceMovement price={price} />
        </div>

        <div className="purchase-stack">
          <PerformanceTrend trend={trend} window={trendWindow} onWindowChange={setTrendWindow} />
          <SpendConcentration
            concentration={concentration}
            grouping={grouping}
            onGroupingChange={setGrouping}
          />
        </div>

        <div className="purchase-stack purchase-stack--wide">
          <RiskPanel matrix={matrix} items={risks.items} onOpen={open} />
          <InsightsPanel insights={insights} generatedAt={data.generated_at} onOpen={open} />
        </div>
      </div>

      <SupplierDrawer
        panel={detail}
        onClose={() => filters.set({ supplier_id: null })}
        onOpen={(route) => navigate(route)}
      />
    </>
  )
}
