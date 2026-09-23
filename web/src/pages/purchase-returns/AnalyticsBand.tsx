/**
 * The band above the register: the trend, the reasons and the insights.
 *
 * All three describe the same filtered set as the cards above and the register
 * below, because all three are drawn from one call with one set of filters.
 *
 * A FAILURE HERE DOES NOT TAKE THE PAGE. When the summary cannot be loaded the
 * band says so and offers Retry, and the register underneath keeps working —
 * which is the part somebody came here for.
 */

import { Mail, Sparkles } from 'lucide-react'
import { ReasonsDonut, ReturnsTrendChart } from './charts'
import type { ReturnInsight, ReturnSummary } from './types'
import { ErrorState, Skeleton } from './ui'

function Loading() {
  return (
    <section className="pr-analytics" aria-label="Loading the analytics">
      {[0, 1, 2].map((index) => (
        <article className="pr-card" key={index}>
          <Skeleton width="42%" height={13} />
          <Skeleton height={index === 2 ? 132 : 158} style={{ marginTop: 16, borderRadius: 10 }} />
          <Skeleton width="60%" height={10} style={{ marginTop: 14 }} />
        </article>
      ))}
    </section>
  )
}

const SEVERITY_CLASS: Record<ReturnInsight['severity'], string> = {
  positive: '',
  info: 'is-info',
  warning: 'is-warning',
  critical: 'is-critical',
}

export function AnalyticsBand({
  summary,
  loading,
  error,
  onRetry,
  onApplyInsight,
  onPickReason,
  onAct,
}: {
  summary: ReturnSummary | null
  loading: boolean
  error: string | null
  onRetry: () => void
  onApplyInsight: (insight: ReturnInsight) => void
  onPickReason: (reasonCode: string) => void
  onAct: (insight: ReturnInsight) => void
}) {
  if (error && summary === null) {
    return (
      <section className="pr-analytics" style={{ gridTemplateColumns: '1fr' }}>
        <article className="pr-card" style={{ padding: 16 }}>
          <ErrorState what="The analytics" message={error} onRetry={onRetry} />
        </article>
      </section>
    )
  }

  if (loading && summary === null) return <Loading />
  if (summary === null) return null

  const hasTrend = summary.trend.some((point) => point.count > 0)
  const insights = summary.insights
  const followUp = insights.find((insight) => insight.action !== null)

  return (
    <section className="pr-analytics">
      <article className="pr-card">
        <div className="pr-card__head">
          <h2 className="pr-card__title">Returns trend</h2>
          <span className="pr-card__note">Last 6 months</span>
        </div>
        {hasTrend ? (
          <ReturnsTrendChart points={summary.trend} />
        ) : (
          // Six flat bars and a line along the floor is a drawing of nothing.
          <p className="pr-notice" style={{ margin: 0 }}>
            No returns were raised in the last six months, so there is no trend to draw yet.
          </p>
        )}
      </article>

      <article className="pr-card">
        <div className="pr-card__head">
          <h2 className="pr-card__title">Reasons for return</h2>
        </div>
        {summary.reasons.length > 0 ? (
          <ReasonsDonut reasons={summary.reasons} total={summary.totals.returns} onPick={onPickReason} />
        ) : (
          <p className="pr-notice" style={{ margin: 0 }}>
            Nothing to divide up yet. The reason on each return is what this chart groups by.
          </p>
        )}
      </article>

      <article className="pr-card pr-ai-card">
        <div className="pr-card__head">
          <h2 className="pr-card__title" style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
            <Sparkles size={16} aria-hidden style={{ color: 'var(--pr-purple)' }} />
            Aicountly AI Insights
          </h2>
          <span className="pr-badge-new">Rules</span>
        </div>

        <ul className="pr-ai-list">
          {insights.map((insight) => (
            <li className="pr-ai-item" key={insight.id}>
              <span className={`pr-ai-dot ${SEVERITY_CLASS[insight.severity]}`} aria-hidden />
              <span>
                {insight.message}
                {Object.keys(insight.filters).length > 0 && (
                  <button
                    type="button"
                    className="pr-ai-link"
                    onClick={() => onApplyInsight(insight)}
                  >
                    Show these
                  </button>
                )}
              </span>
            </li>
          ))}
        </ul>

        {followUp?.action && (
          <div className="pr-ai-foot">
            <button type="button" className="pr-btn pr-btn--small" onClick={() => onAct(followUp)}>
              <Mail size={14} aria-hidden /> {followUp.action.label}
            </button>
          </div>
        )}

        {/* Said plainly, because the card is called Insights and a reader is
            entitled to know whether a model wrote this. Nothing here is
            generated: the same figures always produce the same list. */}
        <p className="pr-ai-basis">
          Worked out from this company's own returns by fixed rules — not a model, and the same figures always give the
          same answer.
        </p>
      </article>
    </section>
  )
}
