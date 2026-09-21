/**
 * The three analytics cards: what was ordered, who delivered on time, and where
 * the money was sent.
 *
 * Each one is a picture with its figures underneath — every chart in this
 * product ships with the table it was drawn from, because the drawing is the
 * illustration and the table is the data.
 */

import { ArrowDownRight, ArrowUpRight } from 'lucide-react'
import { BarChart, DonutChart, TrendChart } from '../../charts'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../../shell'
import type { MaterialCentrePanel, SpendTrendPanel, SupplierOnTimePanel } from '../../types'

export function SpendTrendCard({ panel }: { panel: SpendTrendPanel }) {
  if (!panel.available) {
    return (
      <DashboardPanel title="Spend trend">
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      </DashboardPanel>
    )
  }

  const change = panel.comparison.available ? panel.comparison.change_pc : null
  const rose = change !== null && !change.startsWith('-')

  return (
    <DashboardPanel
      title="Spend trend"
      description={`Ordered value by ${panel.granularity}`}
      action={
        <span className="purchase-panel__figure" title={`${panel.total_formatted} ordered in this period`}>
          <strong>{panel.total_compact}</strong>
          {change !== null && (
            <span className={rose ? 'purchase-delta is-up' : 'purchase-delta is-down'}>
              {rose ? <ArrowUpRight size={12} aria-hidden /> : <ArrowDownRight size={12} aria-hidden />}
              {change.replace('-', '')}%
            </span>
          )}
        </span>
      }
    >
      {panel.points.length === 0 ? (
        <EmptyState title="No orders in this period.">
          Widen the date range, or clear the filters, to see what has been ordered.
        </EmptyState>
      ) : (
        <>
          <TrendChart
            title={`Ordered value by ${panel.granularity}`}
            unitLabel={`Amount in ${panel.currency}`}
            height={150}
            points={panel.points.map((point) => ({
              label: point.label,
              value: point.amount,
              formatted: point.formatted,
            }))}
          />
          <p className="purchase-panel__note">{panel.basis}</p>
        </>
      )}
    </DashboardPanel>
  )
}

export function SupplierOnTimeCard({
  panel,
  onOpen,
}: {
  panel: SupplierOnTimePanel
  onOpen: (route: string, filters: Record<string, string>) => void
}) {
  if (!panel.available) {
    return (
      <DashboardPanel title="Supplier on-time delivery">
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      </DashboardPanel>
    )
  }

  return (
    <DashboardPanel title="Supplier on-time delivery" description="Completed deliveries, against the promised date">
      {panel.rows.length === 0 ? (
        <EmptyState title="Nothing has been delivered in this period.">
          On-time delivery appears once Inventory has accepted a receipt against an order that carried a promised date.
        </EmptyState>
      ) : (
        <>
          <BarChart
            title="On-time delivery by supplier"
            unitLabel="On time"
            compact
            // A rate is measured against 100, not against whoever did best.
            scaleMax={100}
            data={panel.rows.map((row) => ({
              id: String(row.supplier_account_id),
              label: row.supplier_name,
              value: row.on_time_pc,
              formatted: row.on_time_label,
              hint: `${row.supplier_name}: ${row.on_time_label} on time, ${row.sample_label}`,
              tone:
                row.tone === 'success' ? 'success' : row.tone === 'warning' ? 'warning' : row.tone === 'danger' ? 'danger' : 'brand',
              onOpen: () => onOpen(row.route, row.filters),
            }))}
          />
          <p className="purchase-panel__note">{panel.basis}</p>
        </>
      )}
    </DashboardPanel>
  )
}

export function MaterialCentreCard({
  panel,
  onOpen,
}: {
  panel: MaterialCentrePanel
  onOpen: (route: string, filters: Record<string, string>) => void
}) {
  if (!panel.available) {
    return (
      <DashboardPanel title="Material centre spend">
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      </DashboardPanel>
    )
  }

  return (
    <DashboardPanel title="Material centre spend" description="Ordered value by destination">
      {panel.centres.length === 0 ? (
        <EmptyState title="No ordered value in this period." />
      ) : (
        <>
          <DonutChart
            title="Ordered value by material centre"
            centreLabel="Total ordered"
            centreValue={panel.total_compact}
            data={panel.centres.map((row) => ({
              id: String(row.centre_id ?? 'none'),
              label: row.label,
              formatted: row.formatted,
              sharePc: row.share_pc,
              onOpen: row.centre_id === null ? undefined : () => onOpen(row.route, row.filters),
            }))}
            others={
              panel.others.count > 0
                ? { formatted: panel.others.formatted, sharePc: panel.others.share_pc }
                : undefined
            }
          />
          {!panel.names_available && (
            <p className="purchase-panel__note">
              Inventory did not answer, so the centres are shown by id rather than by name. The figures are this
              product's own and are unaffected.
            </p>
          )}
        </>
      )}
    </DashboardPanel>
  )
}
