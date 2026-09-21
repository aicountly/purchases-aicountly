/**
 * Purchase spend trend — this period against the last.
 *
 * The bucket width (day, week, month) is a real request parameter, so choosing
 * "Monthly" changes what the server totals rather than what the browser
 * re-adds. Two screens adding the same daily points into two different sets of
 * months is exactly the drift this product's decimal-on-the-server rule exists
 * to prevent.
 */

import { DashboardPanel, EmptyState, PanelUnavailable } from '../shell'
import { GroupedColumns } from '../charts'
import type { SpendTrendPanel as SpendTrendPanelData } from '../types'

const CHOICES: { id: string; label: string }[] = [
  { id: 'auto', label: 'Auto' },
  { id: 'day', label: 'Daily' },
  { id: 'week', label: 'Weekly' },
  { id: 'month', label: 'Monthly' },
]

export function SpendTrendPanel({
  panel,
  granularity,
  onGranularityChange,
}: {
  panel: SpendTrendPanelData
  /** What the URL asked for, which may be `auto` while the server picked `month`. */
  granularity: string
  onGranularityChange: (next: string) => void
}) {
  return (
    <DashboardPanel
      title="Purchase spend trend"
      // What Auto resolved to is stated here rather than on the Auto chip: a
      // chip reading "Auto · month" is wide enough to wrap the row onto two
      // lines, and the description has to say the bucket width anyway.
      description={panel.available ? `Posted purchases ${panel.granularity_label} · ${panel.total_formatted}` : undefined}
      action={
        panel.available ? (
          <div className="purchase-segments">
            {CHOICES.map((choice) => (
              <button
                key={choice.id}
                type="button"
                className={granularity === choice.id ? 'purchase-segment is-active' : 'purchase-segment'}
                aria-pressed={granularity === choice.id}
                onClick={() => onGranularityChange(choice.id)}
              >
                {choice.label}
              </button>
            ))}
          </div>
        ) : undefined
      }
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.points.length === 0 ? (
        <EmptyState title="No posted purchases for the selected period.">
          Smart Books answered; it has nothing dated in this range.
        </EmptyState>
      ) : (
        <>
          <GroupedColumns
            title="Net posted purchases"
            data={panel.points.map((point) => ({
              key: point.key,
              label: point.label,
              value: point.amount,
              formatted: point.formatted,
              previousValue: point.previous_amount,
              previousFormatted: point.previous_formatted,
              previousLabel: point.previous_label,
            }))}
            currentLabel="This period"
            previousLabel={panel.comparison.label}
            unitLabel={`Amount in ${panel.currency}`}
          />
          {!panel.comparison.available && panel.comparison.reason && (
            <p className="purchase-muted" style={{ margin: '8px 0 0', fontSize: 10.5 }}>
              No comparison bars: {panel.comparison.reason}
            </p>
          )}
          {/* Not drawn, not swallowed. An upstream answering about days nobody
              asked for is worth one sentence rather than a silently different
              total. */}
          {panel.outside_range !== null && (
            <p className="purchase-muted" style={{ margin: '6px 0 0', fontSize: 10.5 }}>
              {panel.outside_range.note} ({panel.outside_range.formatted})
            </p>
          )}
        </>
      )}
    </DashboardPanel>
  )
}
