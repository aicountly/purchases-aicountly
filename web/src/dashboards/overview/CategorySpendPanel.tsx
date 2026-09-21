/**
 * Category-wise spend.
 *
 * The categories are whatever Inventory says the items belong to — read live,
 * never a list kept here. Nothing is hard-coded: if this company's items are
 * grouped into eleven categories, the top six are drawn and the rest are rolled
 * into one honest "Other categories" slice that says how many it covers.
 */

import { useNavigate } from 'react-router-dom'
import { ArrowRight } from 'lucide-react'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../shell'
import { Donut } from '../charts'
import type { CategorySpendPanel as CategorySpendPanelData } from '../types'

export function CategorySpendPanel({ panel }: { panel: CategorySpendPanelData }) {
  const navigate = useNavigate()

  return (
    <DashboardPanel
      title="Category-wise spend"
      description={panel.available ? 'Ordered value by Inventory item group' : undefined}
      action={
        <button
          type="button"
          className="purchase-button purchase-button--quiet"
          onClick={() => navigate(panel.available ? (panel.route ?? '/purchase-orders') : '/purchase-orders')}
        >
          View details <ArrowRight size={13} aria-hidden />
        </button>
      }
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.categories.length === 0 ? (
        <EmptyState title="No order lines in this period." />
      ) : (
        <Donut
          title="Ordered value by category"
          centreValue={panel.total_compact}
          centreLabel="Total spend"
          data={panel.categories.map((category) => ({
            id: category.id,
            label: category.rolled_up ? `${category.label} (${category.rolled_up})` : category.label,
            formatted: category.formatted,
            compact: category.compact,
            sharePc: category.share_pc,
          }))}
        />
      )}
    </DashboardPanel>
  )
}
