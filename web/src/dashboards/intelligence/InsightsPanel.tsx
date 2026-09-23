/**
 * The short reads.
 *
 * Every row is labelled with what KIND of statement it is — observed, an
 * estimate, or a projection — because a reader who cannot tell them apart will
 * treat the weakest as if it were the strongest. None of them is written by a
 * model: where one is configured it can comment on these figures in Ask
 * Aicountly AI, and it still writes none of them.
 */

import { useNavigate } from 'react-router-dom'
import { ChevronRight, CircleAlert, Lightbulb, TrendingUp, TriangleAlert } from 'lucide-react'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../shell'
import type { InsightPanel, PurchaseInsight } from '../types'

const FACE = {
  success: { icon: Lightbulb, tone: 'success' },
  warning: { icon: TriangleAlert, tone: 'warning' },
  danger: { icon: CircleAlert, tone: 'danger' },
  info: { icon: TrendingUp, tone: 'info' },
} as const

export function InsightsPanel({ panel, onAsk }: { panel: InsightPanel; onAsk: () => void }) {
  const navigate = useNavigate()

  const open = (insight: PurchaseInsight) => {
    const query = new URLSearchParams(insight.filters).toString()
    navigate(query === '' ? insight.route : `${insight.route}?${query}`)
  }

  return (
    <DashboardPanel
      title="AI Insights"
      className="purchase-intel-insights"
      action={
        <button type="button" onClick={onAsk} className="purchase-panel__action">
          Ask AI
        </button>
      }
      description={
        panel.available && !panel.ai.available ? (
          <span className="purchase-panel__tag purchase-panel__tag--quiet">Rules only</span>
        ) : undefined
      }
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.rows.length === 0 ? (
        <EmptyState title="No insight available for the selected scope.">
          Nothing moved far enough against its own history to be worth a sentence.
        </EmptyState>
      ) : (
        <>
          <ul className="purchase-intel-list">
            {panel.rows.map((insight) => {
              const face = FACE[insight.tone]
              const Icon = face.icon
              return (
                <li key={insight.id}>
                  <button type="button" onClick={() => open(insight)} title={insight.basis}>
                    <span className={`purchase-intel-list__icon is-${face.tone}`} aria-hidden>
                      <Icon size={15} />
                    </span>
                    <span className="purchase-intel-list__copy">
                      <strong>
                        {insight.title}
                        <em className={`purchase-kind purchase-kind--${insight.kind}`}>{insight.kind_label}</em>
                      </strong>
                      <span>{insight.detail}</span>
                    </span>
                    <ChevronRight size={15} aria-hidden className="purchase-intel-list__chevron" />
                  </button>
                </li>
              )
            })}
          </ul>
          <p className="purchase-panel__note">{panel.method_label}</p>
        </>
      )}
    </DashboardPanel>
  )
}
