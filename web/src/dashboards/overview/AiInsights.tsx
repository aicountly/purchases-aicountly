/**
 * Recommendations, and the card that points at the rest of them.
 *
 * WHAT THESE ARE IS PRINTED ON THE PANEL. They are fixed rules run over this
 * company's own orders — the same rules, and the same arithmetic, as the AI
 * Insights screen — and the header says "no AI model was consulted" in those
 * words. A panel headed "AI recommendations" that is really three SQL queries
 * teaches its reader to believe the next thing it says, which is the problem.
 *
 * Every card carries the baseline it was computed from and the assumption
 * behind the estimate, and a card whose saving describes the same rupees as one
 * already counted says so rather than being silently added in twice.
 */

import { useNavigate } from 'react-router-dom'
import { ArrowRight, Bot, Lightbulb, Sparkles, TrendingUp, Truck } from 'lucide-react'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../shell'
import type { IntelligencePanel } from '../types'

function KindIcon({ kind }: { kind: string }) {
  switch (kind) {
    case 'consolidation':
      return <Lightbulb size={15} />
    case 'price':
      return <TrendingUp size={15} />
    case 'fragmentation':
      return <Truck size={15} />
    default:
      return <Sparkles size={15} />
  }
}

export function AiInsightsPanel({ panel }: { panel: IntelligencePanel }) {
  const navigate = useNavigate()

  const open = (route: string, filters: Record<string, string>) => {
    const query = new URLSearchParams(filters).toString()
    navigate(query === '' ? route : `${route}?${query}`)
  }

  return (
    <DashboardPanel
      title="Insights & recommendations"
      description={panel.available ? panel.method_label : 'Powered by Aicountly'}
      action={
        <button type="button" className="purchase-button purchase-button--quiet" onClick={() => navigate('/dashboard/ai-insights')}>
          View all insights <ArrowRight size={13} aria-hidden />
        </button>
      }
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : panel.cards.length === 0 ? (
        <EmptyState title="No new recommendations for this period.">
          The rules ran and found nothing to raise. That is an answer, not an outage.
        </EmptyState>
      ) : (
        <>
        {panel.scope_note !== null && (
          <p className="purchase-muted" style={{ margin: '0 0 10px', fontSize: 10.5 }}>
            {panel.scope_note}
          </p>
        )}
        <div className="purchase-insight-grid">
          {panel.cards.map((card) => (
            <article key={card.id} className="purchase-insight">
              <div className="purchase-insight__top">
                <span className="purchase-insight__icon" aria-hidden>
                  <KindIcon kind={card.kind} />
                </span>
                <h3>{card.title}</h3>
              </div>

              <p>{card.detail}</p>

              {card.estimate_formatted !== null && (
                <span className="purchase-insight__estimate" title={card.assumption}>
                  Up to {card.estimate_formatted}
                  {!card.counted_in_total && (
                    <span className="purchase-table__sub">Not added to the total — it describes spend already counted.</span>
                  )}
                </span>
              )}

              <div className="purchase-insight__foot">
                <button
                  type="button"
                  className="purchase-button purchase-button--secondary"
                  style={{ minHeight: 32, padding: '5px 11px', fontSize: 11.5 }}
                  onClick={() => open(card.route, card.filters)}
                >
                  {card.action_label} <ArrowRight size={12} aria-hidden />
                </button>
              </div>
            </article>
          ))}
        </div>
        </>
      )}
    </DashboardPanel>
  )
}

/**
 * The assistant card.
 *
 * It promises exactly what the AI Insights screen does — and that screen is
 * honest about which of its three sections needs a model configured and which
 * two work without one. No claim is made here that it does not keep.
 */
export function AiBanner() {
  const navigate = useNavigate()

  return (
    <aside className="purchase-ai-banner" aria-label="Purchase intelligence">
      <div style={{ minWidth: 0 }}>
        <h2>
          Let intelligence work
          <br />
          for your procurement
        </h2>
        <p style={{ marginTop: 8 }}>
          Evidence-backed suggestions, supplier risk and savings opportunities — each one showing the figures behind it.
        </p>
      </div>

      <button
        type="button"
        className="purchase-button purchase-button--primary"
        style={{ minHeight: 34, fontSize: 12 }}
        onClick={() => navigate('/dashboard/ai-insights')}
      >
        Explore insights <ArrowRight size={13} aria-hidden />
      </button>

      {/* CSS and an icon from the pack already in use — no third-party artwork
          is downloaded for a decorative corner. */}
      <span className="purchase-ai-banner__glyph" aria-hidden>
        <Bot size={38} />
      </span>
    </aside>
  )
}
