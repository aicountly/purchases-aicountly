/**
 * Purchase risks and issues.
 *
 * One row per rule that matched, each with the rule itself in its tooltip and
 * the records behind it one click away. An empty list is a result, not an
 * error: nothing needing attention is the outcome this screen is hoping for.
 */

import { useNavigate } from 'react-router-dom'
import { AlertTriangle, ChevronRight, CircleAlert, Info } from 'lucide-react'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../shell'
import type { PurchaseRisk, RiskPanel } from '../types'

const FACE = {
  critical: { icon: CircleAlert, tone: 'danger' },
  warning: { icon: AlertTriangle, tone: 'warning' },
  info: { icon: Info, tone: 'info' },
} as const

export function RisksPanel({ panel, onViewAll }: { panel: RiskPanel; onViewAll: () => void }) {
  const navigate = useNavigate()

  const open = (risk: PurchaseRisk) => {
    const query = new URLSearchParams(risk.filters).toString()
    navigate(query === '' ? risk.route : `${risk.route}?${query}`)
  }

  return (
    <DashboardPanel
      title="Purchase risks & issues"
      className="purchase-intel-risks"
      action={
        panel.available && panel.rows.length > 0 ? (
          <button type="button" onClick={onViewAll} className="purchase-panel__action">
            View all
          </button>
        ) : undefined
      }
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.rows.length === 0 ? (
        <EmptyState title="No purchase risks require attention.">
          The rules behind this list still ran; none of them matched in this scope.
        </EmptyState>
      ) : (
        <ul className="purchase-intel-list">
          {panel.rows.map((risk) => {
            const face = FACE[risk.severity]
            const Icon = face.icon
            return (
              <li key={risk.id}>
                <button type="button" onClick={() => open(risk)} title={risk.basis}>
                  <span className={`purchase-intel-list__icon is-${face.tone}`} aria-hidden>
                    <Icon size={15} />
                  </span>
                  <span className="purchase-intel-list__copy">
                    <strong>{risk.title}</strong>
                    <span>{risk.detail}</span>
                  </span>
                  <ChevronRight size={15} aria-hidden className="purchase-intel-list__chevron" />
                </button>
              </li>
            )
          })}
        </ul>
      )}
    </DashboardPanel>
  )
}
