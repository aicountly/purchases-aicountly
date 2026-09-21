/**
 * Procurement insights — an action list, not a chat box.
 *
 * Everything here is measured: an order is late or it is not, an approval has
 * been waiting three days or it has not, a rate is above the average paid for
 * the same item in the same unit or it is not. The panel states that in its own
 * header rather than letting a sparkle imply a prediction, and where a model is
 * configured it still does not write these — it has nothing to add to a fact.
 */

import {
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  ChevronRight,
  Hourglass,
  Leaf,
  ServerCrash,
  Sparkles,
  TrendingUp,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../../shell'
import type { ProcurementInsight, ProcurementInsightsPanel } from '../../types'

const CATEGORY_ICONS: Record<string, LucideIcon> = {
  delay_risk: AlertTriangle,
  integration: ServerCrash,
  approval_bottleneck: Hourglass,
  price_variance: TrendingUp,
  follow_up: Users,
  savings: Leaf,
  clear: CheckCircle2,
}

export function ProcurementInsightsPanelCard({
  panel,
  onOpen,
}: {
  panel: ProcurementInsightsPanel
  onOpen: (route: string, filters: Record<string, string>) => void
}) {
  return (
    <DashboardPanel
      title="Procurement insights"
      description={panel.available ? panel.method_label : undefined}
      className="purchase-insight-panel"
      action={
        <span className="purchase-panel__badge" aria-hidden>
          <Sparkles size={13} />
        </span>
      }
      flush
    >
      {!panel.available ? (
        <div style={{ padding: 16 }}>
          <PanelUnavailable reason={panel.reason} kind={panel.kind} />
        </div>
      ) : panel.items.length === 0 ? (
        <EmptyState title="No urgent procurement insights right now." />
      ) : (
        <div className="purchase-insight-list">
          {panel.items.map((item) => (
            <InsightCard key={item.id} item={item} onOpen={onOpen} />
          ))}
        </div>
      )}
    </DashboardPanel>
  )
}

function InsightCard({
  item,
  onOpen,
}: {
  item: ProcurementInsight
  onOpen: (route: string, filters: Record<string, string>) => void
}) {
  const Icon = CATEGORY_ICONS[item.category] ?? AlertTriangle

  return (
    <button
      type="button"
      className="purchase-insight"
      onClick={() => onOpen(item.route, item.filters)}
      aria-label={`${item.severity_label}: ${item.title}. ${item.action_label}.`}
    >
      <span className={`purchase-insight__icon purchase-insight__icon--${item.severity}`} aria-hidden>
        <Icon size={15} aria-hidden />
      </span>

      <span className="purchase-insight__body">
        <span className="purchase-insight__title">{item.title}</span>
        <span className="purchase-insight__detail">{item.explanation}</span>
      </span>

      <span className={`purchase-insight__action purchase-insight__action--${item.severity}`}>{item.action_label}</span>
      <ChevronRight size={13} aria-hidden className="purchase-insight__chevron" />
    </button>
  )
}

/**
 * The card at the foot of the insight column.
 *
 * It says what the product is for and opens the screen that does it. It makes
 * no claim about this company's data, because a promotional card that quoted a
 * figure would be a figure nobody could check.
 */
export function IntelligenceCard({ onOpen }: { onOpen: (route: string, filters: Record<string, string>) => void }) {
  return (
    <section className="purchase-brand-card" aria-labelledby="purchase-brand-card-title">
      <span className="purchase-brand-card__waves" aria-hidden />
      <h2 id="purchase-brand-card-title">
        Smarter purchases.
        <br />
        Stronger growth.
      </h2>
      <p>Evidence-backed suggestions across spend, suppliers and stock — with the working shown for every one.</p>
      <button type="button" onClick={() => onOpen('/dashboard/ai-insights', {})}>
        Explore purchase intelligence <ArrowRight size={13} aria-hidden />
      </button>
    </section>
  )
}
