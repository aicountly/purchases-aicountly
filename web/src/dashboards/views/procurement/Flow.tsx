/**
 * The procurement flow — requisition to receipt.
 *
 * Six stages of one workflow, read left to right, each a button onto the rows
 * behind it. The money is deliberately NOT totalled across them: a requisition
 * carries an estimate, a comparison carries the best offer received and an order
 * carries a price somebody agreed to, and adding those together would produce a
 * number that describes nothing. Each stage says which kind of money it holds.
 */

import { Fragment } from 'react'
import {
  BarChart3,
  ChevronRight,
  FileText,
  Mail,
  PackageCheck,
  ShoppingCart,
  Truck,
  type LucideIcon,
} from 'lucide-react'
import { DashboardPanel, PanelUnavailable } from '../../shell'
import type { FlowPanel, FlowStage } from '../../types'

const STAGE_ICONS: Record<string, LucideIcon> = {
  requisition: FileText,
  rfq: Mail,
  quote_comparison: BarChart3,
  purchase_order: ShoppingCart,
  delivery: Truck,
  receipt: PackageCheck,
}

export function ProcurementFlow({
  panel,
  onOpen,
}: {
  panel: FlowPanel
  onOpen: (route: string, filters: Record<string, string>) => void
}) {
  return (
    <DashboardPanel
      title="Procurement flow"
      description="End-to-end visibility from requirement to receipt"
      className="purchase-span-all purchase-flow-panel"
      action={
        <button type="button" className="purchase-button purchase-button--quiet" onClick={() => onOpen('/purchase-orders', {})}>
          View all <ChevronRight size={13} aria-hidden />
        </button>
      }
      flush
    >
      {!panel.available ? (
        <div style={{ padding: 20 }}>
          <PanelUnavailable reason={panel.reason} kind={panel.kind} />
        </div>
      ) : (
        <>
          <div className="purchase-flow">
            {panel.stages.map((stage, index) => (
              <Fragment key={stage.id}>
                {index > 0 && (
                  <span className="purchase-flow__arrow" aria-hidden>
                    <ChevronRight size={15} />
                  </span>
                )}
                <Stage stage={stage} onOpen={onOpen} />
              </Fragment>
            ))}
          </div>

          <p className="purchase-flow__basis">
            {panel.basis}
            {panel.values_hidden_reason !== null && ` ${panel.values_hidden_reason}`}
          </p>
        </>
      )}
    </DashboardPanel>
  )
}

function Stage({
  stage,
  onOpen,
}: {
  stage: FlowStage
  onOpen: (route: string, filters: Record<string, string>) => void
}) {
  const Icon = STAGE_ICONS[stage.id] ?? FileText

  return (
    <button
      type="button"
      className="purchase-flow__stage"
      onClick={() => onOpen(stage.route, stage.filters)}
      title={`${stage.detail}${stage.value_formatted === null ? '' : `\n\nExactly: ${stage.value_formatted} ${stage.value_label}.`}`}
      aria-label={`${stage.label}: ${stage.count_label}${stage.value_formatted === null ? '' : `, ${stage.value_formatted} ${stage.value_label}`}. ${stage.status_label}. Open the records.`}
    >
      <span className={`purchase-flow__icon purchase-flow__icon--${stage.tone}`} aria-hidden>
        <Icon size={15} aria-hidden />
      </span>
      <span className="purchase-flow__label">{stage.label}</span>
      <strong className="purchase-flow__count">{stage.count_label}</strong>
      {/* The kind of money is part of the figure, not a footnote to it. */}
      <span className="purchase-flow__value">
        {stage.value_compact ?? '—'}
        <em>{stage.value_compact === null ? 'not valued' : stage.value_label}</em>
      </span>
      <span className={`purchase-pill purchase-pill--${stage.tone}`}>{stage.status_label}</span>
    </button>
  )
}
