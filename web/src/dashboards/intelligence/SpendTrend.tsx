/**
 * Purchase value and order count, month by month.
 *
 * Twelve months are fetched and six are shown by default, because the question
 * this card answers — "is this period unusual?" — needs months either side of
 * the period, not the period itself.
 */

import { useState } from 'react'
import { DashboardPanel, PanelUnavailable } from '../shell'
import { DualTrendChart, type DualPoint } from '../charts'
import type { SpendTrendPanel } from '../types'

export function SpendTrend({ panel }: { panel: SpendTrendPanel }) {
  const [range, setRange] = useState('6')

  if (!panel.available) {
    return (
      <DashboardPanel title="Purchase spend trend">
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      </DashboardPanel>
    )
  }

  const months = panel.months.slice(-Number.parseInt(range, 10))
  const points: DualPoint[] = months.map((month) => ({
    label: month.label,
    value: month.value,
    formatted: month.formatted,
    count: month.orders,
  }))

  // The projection is drawn as a dashed tail, never joined into the solid line:
  // one of these months happened and the other has not.
  if (panel.projection.available) {
    points.push({
      label: 'Next',
      value: panel.projection.value,
      formatted: panel.projection.formatted,
      count: 0,
      projected: true,
    })
  }

  return (
    <DashboardPanel
      title="Purchase spend trend"
      className="purchase-intel-trend"
      action={
        <label className="purchase-inline-select">
          <span className="purchase-sr-only">Months shown</span>
          <select value={range} onChange={(event) => setRange(event.target.value)}>
            {panel.ranges.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
      }
    >
      {points.length < 2 ? (
        <p className="purchase-empty">Not enough months yet to draw a trend.</p>
      ) : (
        <DualTrendChart
          title="Purchase value and order count per month"
          moneyLabel="Purchase value"
          countLabel="Purchase orders"
          points={points}
        />
      )}

      <p className="purchase-panel__note">
        {panel.basis}
        {panel.projection.available
          ? ` The dashed tail is a projection: ${panel.projection.method}`
          : ` ${panel.projection.reason}`}
      </p>
    </DashboardPanel>
  )
}
