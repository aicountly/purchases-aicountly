/**
 * Three rates over the last twelve months.
 *
 * On-time and acceptance are both measured from the receipts booked in each
 * month; the score is the two of them weighted with the model's own published
 * weights. All three are percentages of the same shape, so they share one axis
 * honestly — which is the only reason three series are allowed on one chart.
 *
 * A month nobody could rate is a GAP. The line breaks rather than dropping to
 * the floor, because a chart that says July collapsed when July had two
 * deliveries is a drawing, not a measurement.
 */

import { DashboardPanel, EmptyState, PanelUnavailable } from '../../shell'
import { RateLineChart } from '../../charts'
import type { TrendPanel } from '../types'

const WINDOWS = [
  { id: '6', label: 'Last 6 months', months: 6 },
  { id: '12', label: 'Last 12 months', months: 12 },
] as const

export function PerformanceTrend({
  trend,
  window,
  onWindowChange,
}: {
  trend: TrendPanel
  window: string
  onWindowChange: (next: string) => void
}) {
  const months = WINDOWS.find((option) => option.id === window)?.months ?? 6

  const points = trend.available ? trend.points.slice(-months) : []
  const periods = points.map((point) => point.period)
  const rated = points.filter((point) => point.on_time_pc !== null)

  return (
    <DashboardPanel
      title="Supplier performance trend"
      description={trend.available ? undefined : 'Delivery and acceptance, month by month'}
      action={
        <label style={{ margin: 0 }}>
          <span className="purchase-sr-only">Trend window</span>
          <select
            value={window}
            onChange={(event) => onWindowChange(event.target.value)}
            style={{ minHeight: 32, fontSize: '0.78rem', paddingBlock: 0 }}
          >
            {WINDOWS.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
      }
    >
      {!trend.available ? (
        <PanelUnavailable reason={trend.reason} kind={trend.kind} />
      ) : rated.length < 2 ? (
        <EmptyState title="No trend yet.">
          Performance trends appear once receipts have been recorded across more than one month —
          a month with fewer than {trend.min_sample} receipts is counted but not rated.
        </EmptyState>
      ) : (
        <>
          <RateLineChart
            title="Supplier performance trend"
            periods={periods}
            note={`Months with fewer than ${trend.min_sample} receipts carry no rate and are left as a gap.`}
            summary={
              `On-time delivery, receipt acceptance and the composite supplier score by month. ` +
              rated
                .map((point) => `${point.period}: ${point.on_time_pc}% on time from ${point.sample} receipts`)
                .join('. ') +
              '.'
            }
            series={[
              {
                id: 'on_time',
                label: 'On-time delivery',
                points: points.map((point) => ({ period: point.period, value: point.on_time_pc })),
              },
              {
                id: 'acceptance',
                label: 'Receipt acceptance',
                points: points.map((point) => ({ period: point.period, value: point.acceptance_pc })),
              },
              {
                id: 'score',
                label: 'Supplier score',
                dashed: true,
                points: points.map((point) => ({ period: point.period, value: point.score })),
              },
            ]}
          />

          {trend.still_waiting.lines > 0 && (
            <div className="purchase-notice purchase-notice--warning" style={{ marginTop: 14 }}>
              <div>
                <strong>
                  {trend.still_waiting.lines} overdue line{trend.still_waiting.lines === 1 ? '' : 's'} with nothing
                  received, across {trend.still_waiting.orders} order{trend.still_waiting.orders === 1 ? '' : 's'}
                </strong>
                <p>{trend.still_waiting.note}</p>
              </div>
            </div>
          )}
        </>
      )}
    </DashboardPanel>
  )
}
