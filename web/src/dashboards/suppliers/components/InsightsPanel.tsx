/**
 * The insight cards.
 *
 * WHY EVERY ONE OF THESE OPENS. A recommendation a reader cannot check is a
 * recommendation they are asked to take on faith, and procurement is not a
 * place to take spending advice on faith. Each card carries the figures it was
 * read off and the rule that fired, and the "Why this?" toggle shows them
 * without leaving the page.
 *
 * Nothing here decides anything. No supplier is blocked, no status is changed,
 * no order is raised — every card ends in a link to the screen where a person
 * does that, with their own permissions.
 */

import { useState } from 'react'
import { ChevronRight, Coins, Lightbulb, PieChart, ShieldAlert, TrendingDown, TrendingUp } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { DashboardPanel, EmptyState } from '../../shell'
import type { Severity, SupplierInsight } from '../signals'

const FACE: Record<SupplierInsight['kind'], LucideIcon> = {
  concentration: PieChart,
  delivery: TrendingDown,
  quality: ShieldAlert,
  negotiation: Coins,
  terms: Lightbulb,
  coverage: ShieldAlert,
}

const TONE: Record<Severity, string> = {
  high: 'is-danger',
  medium: 'is-warning',
  low: 'is-info',
  info: 'is-success',
}

const WORD: Record<Severity, string> = {
  high: 'Act now',
  medium: 'Review',
  low: 'Consider',
  info: 'Good news',
}

export function InsightsPanel({
  insights,
  generatedAt,
  onOpen,
}: {
  insights: SupplierInsight[]
  generatedAt: string | undefined
  onOpen: (route: string, filters?: Record<string, string>) => void
}) {
  const [openId, setOpenId] = useState<string | null>(null)

  return (
    <DashboardPanel
      title={
        <>
          AI Insights
          <span className="purchase-beta">Beta</span>
        </>
      }
      description="Rules over this period's own figures — never a stored guess"
      flush
      action={
        <button
          type="button"
          className="purchase-button purchase-button--quiet"
          onClick={() => onOpen('/dashboard/ai-insights')}
        >
          View all
        </button>
      }
    >
      {insights.length === 0 ? (
        <EmptyState reassuring title="Nothing stands out in this period.">
          Insights appear when a rule finds something in the figures — concentration, a delivery
          drop, a supplier scoring poorly.
        </EmptyState>
      ) : (
        insights.map((insight) => {
          const Face = insight.severity === 'info' ? TrendingUp : FACE[insight.kind]
          const open = openId === insight.id

          return (
            <div key={insight.id}>
              <button
                type="button"
                className="purchase-row"
                onClick={() => onOpen(insight.route, insight.filters)}
              >
                <span className={`purchase-row__icon ${TONE[insight.severity]}`} aria-hidden>
                  <Face size={15} />
                </span>

                <span className="purchase-row__body">
                  <span className="purchase-row__title">
                    <span className={`purchase-row__tag ${TONE[insight.severity]}`}>{WORD[insight.severity]}</span>
                    {insight.title}
                  </span>
                  <span className="purchase-row__subtitle">{insight.summary}</span>
                </span>

                <ChevronRight size={15} className="purchase-row__chevron" aria-hidden />
              </button>

              <div className="purchase-evidence">
                <button
                  type="button"
                  className="purchase-button purchase-button--quiet"
                  style={{ padding: 0 }}
                  aria-expanded={open}
                  onClick={() => setOpenId(open ? null : insight.id)}
                >
                  {open ? 'Hide the evidence' : 'Why this?'}
                </button>

                {open && (
                  <>
                    <ul>
                      {insight.evidence.map((line) => (
                        <li key={line}>{line}</li>
                      ))}
                    </ul>
                    {generatedAt !== undefined && (
                      <span>Read off the figures generated at {new Date(generatedAt).toLocaleString('en-IN')}.</span>
                    )}
                  </>
                )}
              </div>
            </div>
          )
        })
      )}
    </DashboardPanel>
  )
}
