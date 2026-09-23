/**
 * The five figures above the table.
 *
 * Every one of them is counted by the server over everything the filters match
 * — not over the page on screen, which would change when somebody turned the
 * page. A figure that has not arrived yet draws a skeleton, never a zero:
 * "0 requisitions" and "we have not been told yet" look identical as a zero,
 * and only one of them means somebody should do something.
 *
 * The trend line under each figure is this calendar month against last, and it
 * is left off entirely when there is no last month to compare with. A card that
 * invents "+100%" out of a first month of trading is a card nobody should
 * trust with the ones that are real.
 */

import { CircleCheck, CircleX, ClipboardList, Clock, IndianRupee } from 'lucide-react'
import type { ReactNode } from 'react'
import type { RequisitionSummary } from '../../services/types'
import { money } from '../../ui'
import { monthTrend, sparklinePoints, type Trend } from './model'
import { Sparkline } from './ui'

type CardTone = 'blue' | 'amber' | 'green' | 'red' | 'violet'

function Card({
  tone,
  icon,
  label,
  value,
  loading,
  trend,
  points,
  onSelect,
  selected,
  selectLabel,
}: {
  tone: CardTone
  icon: ReactNode
  label: string
  value: ReactNode
  loading: boolean
  trend: Trend | null
  points: number[]
  onSelect?: () => void
  selected?: boolean
  selectLabel?: string
}) {
  const body = (
    <>
      <div className="rq-kpi__top">
        <span className="rq-kpi__icon" aria-hidden="true">
          {icon}
        </span>
        <span className="rq-kpi__label">{label}</span>
      </div>

      {loading ? (
        <span className="rq-skeleton rq-kpi__skeleton" aria-hidden="true" />
      ) : (
        <strong className="rq-kpi__value">{value}</strong>
      )}

      <div className="rq-kpi__foot">
        <Sparkline points={points} />
        {trend && (
          <span className={`rq-kpi__trend rq-kpi__trend--${trend.tone}`}>
            {trend.direction === 'up' ? '↑' : trend.direction === 'down' ? '↓' : ''} {trend.label}
          </span>
        )}
        {trend && <span className="rq-kpi__comparison">{trend.comparison}</span>}
      </div>
    </>
  )

  if (!onSelect) {
    return <article className={`rq-kpi rq-kpi--${tone}`}>{body}</article>
  }

  return (
    <article className={selected ? `rq-kpi rq-kpi--${tone} is-selected` : `rq-kpi rq-kpi--${tone}`}>
      {/* The card filters the table. A button rather than a click handler on the
          article, so it is reachable by keyboard and announced as an action. */}
      <button type="button" className="rq-kpi__action" onClick={onSelect} aria-pressed={selected}>
        <span className="rq-sr-only">{selectLabel}</span>
      </button>
      {body}
    </article>
  )
}

export function SummaryCards({
  summary,
  loading,
  bucket,
  onPick,
}: {
  summary: RequisitionSummary | null
  loading: boolean
  bucket: string
  onPick: (bucket: string) => void
}) {
  const totals = summary?.totals
  const series = summary?.series ?? []

  const trendOf = (current: number | undefined, previous: number | undefined, improvementIsUp = true): Trend | null => {
    if (!summary || current === undefined || previous === undefined) return null
    return monthTrend(current, previous, improvementIsUp)
  }

  // "1 new today" is a fact, not a trend, so it replaces the comparison rather
  // than sitting beside it — and only on a day when something was raised.
  const pendingTrend: Trend | null = summary
    ? summary.today.pending > 0
      ? {
          direction: 'up',
          label: `+${summary.today.pending} new`,
          comparison: 'today',
          tone: 'quiet',
        }
      : monthTrend(summary.month.pending, summary.previous_month.pending, false)
    : null

  return (
    <section className="rq-kpis" aria-label="Requisition summary">
      <Card
        tone="blue"
        icon={<ClipboardList size={17} />}
        label="Total requisitions"
        value={totals?.total ?? 0}
        loading={loading}
        trend={trendOf(summary?.month.total, summary?.previous_month.total)}
        points={sparklinePoints(series.map((point) => point.total))}
        onSelect={() => onPick('all')}
        // Never marked selected: All is the default view, so a permanent
        // highlight on it would be saying nothing four times a day.
        selected={false}
        selectLabel="Show all requisitions"
      />

      <Card
        tone="amber"
        icon={<Clock size={17} />}
        label="Pending approval"
        value={totals?.pending ?? 0}
        loading={loading}
        trend={pendingTrend}
        points={sparklinePoints(series.map((point) => point.pending))}
        onSelect={() => onPick('pending')}
        selected={bucket === 'pending'}
        selectLabel="Show requisitions pending approval"
      />

      <Card
        tone="green"
        icon={<CircleCheck size={17} />}
        label="Approved"
        value={totals?.approved ?? 0}
        loading={loading}
        trend={trendOf(summary?.month.approved, summary?.previous_month.approved)}
        points={sparklinePoints(series.map((point) => point.approved))}
        onSelect={() => onPick('approved')}
        selected={bucket === 'approved'}
        selectLabel="Show approved requisitions"
      />

      <Card
        tone="red"
        icon={<CircleX size={17} />}
        label="Rejected"
        value={totals?.rejected ?? 0}
        loading={loading}
        // Fewer rejections is better, so a fall reads as good news here and a
        // rise does not. The arithmetic is the same; the colour is not.
        trend={trendOf(summary?.month.rejected, summary?.previous_month.rejected, false)}
        points={sparklinePoints(series.map((point) => point.rejected))}
        onSelect={() => onPick('rejected')}
        selected={bucket === 'rejected'}
        selectLabel="Show rejected requisitions"
      />

      <Card
        tone="violet"
        icon={<IndianRupee size={17} />}
        label="Requested value"
        value={<span className="rq-kpi__money">{money(totals?.estimated_value ?? 0, undefined, 0)}</span>}
        loading={loading}
        trend={trendOf(summary?.month.value, summary?.previous_month.value)}
        points={sparklinePoints(series.map((point) => point.value))}
      />
    </section>
  )
}
