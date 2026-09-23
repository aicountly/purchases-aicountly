/**
 * Where the ordered value went.
 *
 * TWO VIEWS, BOTH REAL. By supplier is the server's own list. By standing
 * groups those same suppliers on the qualification status their procurement
 * profile carries — it is a re-cut of the rows already on the wire, not a
 * second figure from somewhere else, and the shares still sum to the same
 * total.
 *
 * Four named slices and the tail folded into one neutral one. Past six,
 * adjacent slices blur and the legend is doing all the work anyway.
 */

import { useMemo } from 'react'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../../shell'
import { DonutChart } from '../../charts'
import type { ConcentrationPanel, ConcentrationSupplier } from '../types'

const GROUPING = [
  { id: 'supplier', label: 'By supplier' },
  { id: 'standing', label: 'By supplier standing' },
] as const

const STANDING_LABEL: Record<string, string> = {
  approved: 'Approved suppliers',
  preferred: 'Preferred suppliers',
  pending: 'Pending qualification',
  suspended: 'Suspended suppliers',
  blocked: 'Blocked suppliers',
  none: 'No procurement profile',
}

function bySupplier(suppliers: ConcentrationSupplier[]) {
  const head = suppliers.slice(0, 4).map((row) => ({
    id: String(row.supplier_account_id),
    label: row.supplier_name ?? `Account ${row.supplier_account_id}`,
    share: row.share_pc,
    formatted: row.formatted,
  }))

  const tail = suppliers.slice(4)
  if (tail.length === 0) return head

  // Shares are exact decimal strings from the server. Summing them here is
  // geometry for one slice, not a figure anybody reads as money — the count is
  // what the label states.
  const rest = tail.reduce((sum, row) => sum + Number.parseFloat(row.share_pc ?? '0'), 0)

  return [
    ...head,
    {
      id: 'others',
      label: `${tail.length} other supplier${tail.length === 1 ? '' : 's'}`,
      share: rest.toFixed(1),
      formatted: `${tail.length} supplier${tail.length === 1 ? '' : 's'}`,
    },
  ]
}

function byStanding(suppliers: ConcentrationSupplier[]) {
  const groups = new Map<string, { share: number; count: number }>()

  for (const row of suppliers) {
    const key = row.qualification_status || 'none'
    const current = groups.get(key) ?? { share: 0, count: 0 }
    groups.set(key, {
      share: current.share + Number.parseFloat(row.share_pc ?? '0'),
      count: current.count + 1,
    })
  }

  return [...groups.entries()]
    .sort((a, b) => b[1].share - a[1].share)
    .map(([key, group]) => ({
      id: key,
      label: STANDING_LABEL[key] ?? key.replace(/_/g, ' '),
      share: group.share.toFixed(1),
      formatted: `${group.count} supplier${group.count === 1 ? '' : 's'}`,
    }))
}

export function SpendConcentration({
  concentration,
  grouping,
  onGroupingChange,
}: {
  concentration: ConcentrationPanel
  grouping: string
  onGroupingChange: (next: string) => void
}) {
  const segments = useMemo(() => {
    if (!concentration.available) return []
    return grouping === 'standing' ? byStanding(concentration.suppliers) : bySupplier(concentration.suppliers)
  }, [concentration, grouping])

  return (
    <DashboardPanel
      title="Spend concentration"
      description={concentration.available ? 'Share of ordered value in this period' : undefined}
      action={
        <label style={{ margin: 0 }}>
          <span className="purchase-sr-only">Group the concentration by</span>
          <select
            value={grouping}
            onChange={(event) => onGroupingChange(event.target.value)}
            style={{ minHeight: 32, fontSize: '0.78rem', paddingBlock: 0 }}
          >
            {GROUPING.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
      }
    >
      {!concentration.available ? (
        <PanelUnavailable reason={concentration.reason} kind={concentration.kind} />
      ) : segments.length === 0 ? (
        <EmptyState title="Nothing was ordered in this period.">
          Concentration is a share of ordered value, so it needs at least one order to divide up.
        </EmptyState>
      ) : (
        <DonutChart
          title="Ordered value by supplier"
          centreLabel="Total ordered"
          centreValue={concentration.total_formatted}
          summary={
            `Ordered value of ${concentration.total_formatted} across ${concentration.suppliers.length} suppliers. ` +
            segments.map((segment) => `${segment.label} ${segment.share ?? '—'}%`).join(', ') +
            '.'
          }
          segments={segments}
        />
      )}
    </DashboardPanel>
  )
}
