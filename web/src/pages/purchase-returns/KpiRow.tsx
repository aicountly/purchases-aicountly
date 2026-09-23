/**
 * The four figures a buyer opens this screen to see.
 *
 * Each describes the CURRENT FILTERS, not the company — the register beneath
 * them lists exactly the same set, and a card that quietly described something
 * wider would be the one number on the page nobody could reconcile.
 *
 * The comparison is the equal-length period immediately before. When the
 * filters name no period — "All time" — there is nothing to compare with and
 * the chip is absent rather than reading "↑ 0%".
 */

import { FileCheck2, Hourglass, IndianRupee, PackageCheck, TrendingDown, TrendingUp } from 'lucide-react'
import type { ReturnDelta, ReturnSummary } from './types'
import { inrShort, Skeleton } from './ui'

/**
 * Which direction is good news.
 *
 * Fewer returns and less money going back are improvements, so a fall is green
 * and a rise is amber — the opposite of a sales figure, and getting it the
 * usual way round would congratulate somebody on a bad month.
 */
type Polarity = 'lower-is-better' | 'higher-is-better'

function TrendChip({ delta, polarity }: { delta: ReturnDelta | undefined; polarity: Polarity }) {
  if (!delta || delta.direction === 'flat') return null

  if (delta.direction === 'new') {
    return (
      <span className="pr-trend-chip is-flat" title="Nothing in the period before this one">
        new
      </span>
    )
  }

  const rising = delta.direction === 'up'
  const good = polarity === 'higher-is-better' ? rising : !rising
  const Icon = rising ? TrendingUp : TrendingDown

  return (
    <span className={`pr-trend-chip ${good ? 'is-up' : 'is-adverse'}`}>
      <Icon size={12} aria-hidden />
      {rising ? '+' : '−'}
      {delta.percent}%
    </span>
  )
}

function Kpi({
  icon,
  tone,
  label,
  value,
  delta,
  polarity,
  caption,
}: {
  icon: React.ReactNode
  tone?: 'success' | 'warning'
  label: string
  value: string
  delta?: ReturnDelta
  polarity: Polarity
  caption: string
}) {
  return (
    <article className="pr-card pr-kpi">
      <div className={tone ? `pr-kpi__icon is-${tone}` : 'pr-kpi__icon'} aria-hidden="true">
        {icon}
      </div>
      <div className="pr-kpi__body">
        <div className="pr-kpi__label">{label}</div>
        <div className="pr-kpi__value-row">
          <strong className="pr-kpi__value">{value}</strong>
          <TrendChip delta={delta} polarity={polarity} />
        </div>
        <small className="pr-kpi__caption">{caption}</small>
      </div>
    </article>
  )
}

export function KpiRow({
  summary,
  loading,
}: {
  summary: ReturnSummary | null
  loading: boolean
}) {
  if (loading && summary === null) {
    return (
      <section className="pr-kpi-grid" aria-label="Loading the figures">
        {[0, 1, 2, 3].map((index) => (
          <article className="pr-card pr-kpi" key={index}>
            <Skeleton width={50} height={50} style={{ borderRadius: '50%', flexShrink: 0 }} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <Skeleton width="55%" height={10} />
              <Skeleton width="42%" height={22} style={{ marginTop: 9 }} />
              <Skeleton width="33%" height={8} style={{ marginTop: 8 }} />
            </div>
          </article>
        ))}
      </section>
    )
  }

  if (summary === null) return null

  const { totals, deltas, period } = summary
  // Said once, on all four cards, because all four are the same window.
  const caption = period.comparable ? 'vs the period before' : 'over the whole period'

  return (
    <section className="pr-kpi-grid" aria-label="Purchase return figures">
      <Kpi
        icon={<PackageCheck size={22} />}
        label="Total returns"
        value={String(totals.returns)}
        delta={deltas?.returns}
        polarity="lower-is-better"
        caption={caption}
      />
      <Kpi
        icon={<IndianRupee size={22} />}
        tone="success"
        label="Return value"
        // The compact form on the card; the exact figure is in the title, in
        // the register below and in the export, so nothing here is the number
        // somebody reconciles against.
        value={inrShort(totals.return_value)}
        delta={deltas?.return_value}
        polarity="lower-is-better"
        caption={`${totals.return_value_formatted} ${caption}`}
      />
      <Kpi
        icon={<FileCheck2 size={22} />}
        label="Supplier credits received"
        value={String(totals.credits_received)}
        delta={deltas?.credits_received}
        polarity="higher-is-better"
        caption={caption}
      />
      <Kpi
        icon={<Hourglass size={22} />}
        tone="warning"
        label="Pending supplier credits"
        value={String(totals.credits_pending)}
        delta={deltas?.credits_pending}
        polarity="lower-is-better"
        caption={
          totals.credits_pending > 0
            ? `${totals.credits_pending_formatted} still to be credited`
            : 'Nothing awaiting a supplier credit'
        }
      />
    </section>
  )
}
