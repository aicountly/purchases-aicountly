/**
 * The insight banner.
 *
 * What it says is built from counts this API returned — see `insights.ts` for
 * why there is no model behind it. The button applies the filter that shows the
 * exact rows the sentence is about, so an insight is never a statement you
 * cannot check.
 *
 * It fails quietly. If the summary call did not come back there is no banner,
 * and the table underneath is unaffected: a list of requisitions must not go
 * missing because a headline could not be written.
 */

import { useState } from 'react'
import { ArrowRight, ChevronLeft, ChevronRight, Sparkles } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import type { Insight } from './insights'

export function AiInsight({
  insights,
  onApply,
}: {
  insights: Insight[]
  onApply: (insight: Insight) => void
}) {
  const navigate = useNavigate()
  const [index, setIndex] = useState(0)

  if (insights.length === 0) return null

  const current = insights[Math.min(index, insights.length - 1)]
  const move = (step: number) => setIndex((was) => (was + step + insights.length) % insights.length)

  return (
    <section className={`rq-insight rq-insight--${current.severity}`} aria-label="Procurement insight">
      <span className="rq-insight__mark" aria-hidden="true">
        <Sparkles size={20} />
      </span>

      <div className="rq-insight__copy">
        <span className="rq-insight__eyebrow">
          AI insight
          {insights.length > 1 && (
            <span className="rq-insight__position">
              {index + 1} of {insights.length}
            </span>
          )}
        </span>
        <h2>{current.title}</h2>
        <p>{current.description}</p>
      </div>

      <div className="rq-insight__actions">
        {insights.length > 1 && (
          <span className="rq-insight__stepper">
            <button type="button" onClick={() => move(-1)} aria-label="Previous insight">
              <ChevronLeft size={15} aria-hidden />
            </button>
            <button type="button" onClick={() => move(1)} aria-label="Next insight">
              <ChevronRight size={15} aria-hidden />
            </button>
          </span>
        )}

        {/* An insight with no rows behind it cannot be "shown", so that one
            hands the reader to the assistant that can actually answer instead
            of to a filter that would do nothing. */}
        {current.id === 'all-clear' ? (
          <button type="button" className="rq-insight__cta" onClick={() => navigate('/dashboard/ai-insights')}>
            {current.actionLabel} <ArrowRight size={14} aria-hidden />
          </button>
        ) : (
          <button type="button" className="rq-insight__cta" onClick={() => onApply(current)}>
            {current.actionLabel} <ArrowRight size={14} aria-hidden />
          </button>
        )}
      </div>
    </section>
  )
}
