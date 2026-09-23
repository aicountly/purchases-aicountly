/**
 * Where the money went, by category.
 *
 * Categories are Inventory's item groups, read live on this request. With
 * Inventory unavailable the panel says so rather than grouping the spend by
 * something else and calling the result a category — which is the failure mode
 * that makes a chart quietly wrong instead of visibly absent.
 */

import { DashboardPanel, PanelUnavailable } from '../shell'
import { DonutChart } from '../charts'
import type { CategoryPanel } from '../types'

export function CategoryConcentration({ panel }: { panel: CategoryPanel }) {
  if (!panel.available) {
    return (
      <DashboardPanel title="Spend concentration by category">
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      </DashboardPanel>
    )
  }

  return (
    <DashboardPanel
      title="Spend concentration by category"
      className="purchase-intel-categories"
      action={<span className="purchase-panel__tag">By spend value</span>}
    >
      {panel.categories.length === 0 ? (
        <p className="purchase-empty">No purchase activity found for the selected period.</p>
      ) : (
        <DonutChart
          title="Spend by category"
          summary={panel.basis}
          centreLabel="Total spend"
          centreValue={panel.total_compact}
          segments={panel.categories.map((category) => ({
            id: category.id,
            label: category.name,
            share: category.share_pc,
            formatted: category.formatted,
          }))}
        />
      )}
      <p className="purchase-panel__note">{panel.basis}</p>
    </DashboardPanel>
  )
}
