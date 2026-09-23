/**
 * The three cards between the figures and the list.
 *
 * All three are built on data this product can stand behind, and each says in
 * one line where its numbers come from. Where a card would need something
 * Smart Books cannot answer, the card says which question it is NOT answering
 * rather than drawing a plausible line through the gap.
 */

import { useState } from 'react'
import { PieChart, TrendingUp, Users } from 'lucide-react'
import { DonutChart } from '../charts'
import type { AgeingBucket, Panel } from '../types'
import { Avatar, Card, EmptyState, Unavailable } from './parts'
import { formatMoney, type SupplierExposurePanel, type TrendPanel } from './types'

type AgeingPanel = Panel<{
  as_of_label: string
  currency: string
  buckets: AgeingBucket[]
  total_formatted: string
  basis: string
  caveat: string
}>

/** Ordered severity, for a bucket the server adds that this screen has no name for. */
const BUCKET_RAMP = ['#2463eb', '#f59e0b', '#fb923c', '#ef4444', '#9f1239']

/** A bar height as a percentage of the tallest bar in view. */
function heightPc(value: string, max: number): string {
  const amount = Number.parseFloat(value)
  if (!Number.isFinite(amount) || max <= 0 || amount <= 0) return '2px'
  return `${Math.max(2, (amount / max) * 100)}%`
}

// ---------------------------------------------------------------------------
// A. Payables trend
// ---------------------------------------------------------------------------

export function PayablesTrendCard({ panel }: { panel: TrendPanel }) {
  // Hovering picks a month; the keyboard picks one too, because a tooltip only
  // a mouse can reach is a tooltip half the readers never see.
  const [active, setActive] = useState<number | null>(null)

  if (!panel.available) {
    return (
      <Card title="Payables trend">
        <Unavailable reason={panel.reason} kind={panel.kind} />
      </Card>
    )
  }

  const points = panel.points
  const max = Math.max(
    ...points.map((point) => Number.parseFloat(point.booked) || 0),
    ...points.map((point) => Number.parseFloat(point.posted) || 0),
    0,
  )
  const shown = active !== null ? points[active] : points[points.length - 1]

  if (points.length === 0 || max <= 0) {
    return (
      <Card title="Payables trend" note={panel.basis}>
        <EmptyState icon={<TrendingUp size={22} aria-hidden />} title="No bills in these months.">
          Nothing has been entered in the last {panel.months} months for this company and financial year.
        </EmptyState>
      </Card>
    )
  }

  return (
    <Card
      title="Payables trend"
      action={<span className="aic-sub">Last {panel.months} months</span>}
      note={panel.basis}
    >
      <div className="aic-legend">
        {panel.series.map((series) => (
          <span key={series.id}>
            <i style={{ background: series.id === 'posted' ? '#15803d' : '#2463eb' }} aria-hidden />
            {series.label}
          </span>
        ))}
      </div>

      <div className="aic-trend" role="group" aria-label={`Bills booked over the last ${panel.months} months`}>
        {points.map((point, index) => (
          <button
            key={point.period}
            type="button"
            className={active === index ? 'aic-trend__col is-on' : 'aic-trend__col'}
            onMouseEnter={() => setActive(index)}
            onMouseLeave={() => setActive(null)}
            onFocus={() => setActive(index)}
            onBlur={() => setActive(null)}
            aria-label={`${point.label}: ${point.formatted} booked across ${point.bill_count} bills, ${point.posted_formatted} posted`}
          >
            <span className="aic-trend__bars" aria-hidden>
              <span className="aic-trend__bar" style={{ height: heightPc(point.booked, max) }} />
              <span className="aic-trend__bar aic-trend__bar--posted" style={{ height: heightPc(point.posted, max) }} />
            </span>
            <span className="aic-trend__label">{point.label.split(' ')[0]}</span>
          </button>
        ))}
      </div>

      {shown && (
        <div className="aic-trend__tip" aria-live="polite">
          <strong>{shown.label}</strong>
          <span>
            Bills booked <b>{shown.formatted}</b>
          </span>
          <span>
            Posted to Books <b>{shown.posted_formatted}</b>
          </span>
          <span>
            Bills <b>{shown.bill_count}</b>
          </span>
        </div>
      )}
    </Card>
  )
}

// ---------------------------------------------------------------------------
// B. Payables ageing
// ---------------------------------------------------------------------------

export function PayablesAgeingCard({
  panel,
  activeBucket,
  onPick,
}: {
  panel: AgeingPanel
  activeBucket: string | null
  onPick: (bucketId: string | null) => void
}) {
  if (!panel.available) {
    return (
      <Card title="Payables ageing">
        <Unavailable reason={panel.reason} kind={panel.kind} />
      </Card>
    )
  }

  // A ramp, not a set of categories: these buckets are ordered severity, and
  // five unrelated hues would say the opposite. The same ramp fills the donut,
  // so a row's dot really is the slice beside it — the list below is the only
  // legend, and it carries the label, the amount and the share in words.
  const colours: Record<string, string> = {
    not_due: '#2463eb',
    b_0_30: '#f59e0b',
    b_31_60: '#fb923c',
    b_61_90: '#ef4444',
    b_90_plus: '#9f1239',
  }
  const bucketColour = (index: number, segment: { id: string }) =>
    colours[segment.id] ?? BUCKET_RAMP[index] ?? '#94a3b8'

  const segments = panel.buckets.map((bucket) => ({
    id: bucket.id,
    label: bucket.label,
    share: bucket.share_pc,
    formatted: bucket.formatted,
  }))

  return (
    <Card
      title="Payables ageing"
      action={<span className="aic-sub">As at {panel.as_of_label}</span>}
      note={panel.caveat}
    >
      <div className="aic-ageing">
        <DonutChart
          title="Payables ageing"
          summary={`Open payables by age, totalling ${panel.total_formatted}.`}
          segments={segments}
          centreLabel="Outstanding"
          centreValue={panel.total_formatted}
          colour={bucketColour}
          legend={false}
        />

        <div className="aic-ageing__list">
          {panel.buckets.map((bucket) => (
            <button
              key={bucket.id}
              type="button"
              className={activeBucket === bucket.id ? 'aic-ageing__row is-on' : 'aic-ageing__row'}
              aria-pressed={activeBucket === bucket.id}
              onClick={() => onPick(activeBucket === bucket.id ? null : bucket.id)}
            >
              <span className="aic-age-dot" style={{ background: colours[bucket.id] ?? '#2463eb' }} aria-hidden />
              <span>{bucket.label}</span>
              <strong>{bucket.formatted}</strong>
              <small>{bucket.share_pc === null ? '—' : `${bucket.share_pc}%`}</small>
            </button>
          ))}
        </div>
      </div>
    </Card>
  )
}

// ---------------------------------------------------------------------------
// C. Top suppliers by payable
// ---------------------------------------------------------------------------

export function TopSupplierPayablesCard({
  panel,
  activeSupplier,
  onPick,
}: {
  panel: SupplierExposurePanel
  activeSupplier: string | null
  onPick: (supplierId: string | null) => void
}) {
  if (!panel.available) {
    return (
      <Card title="Top suppliers by payable">
        <Unavailable reason={panel.reason} kind={panel.kind} />
      </Card>
    )
  }

  if (panel.rows.length === 0) {
    return (
      <Card title="Top suppliers by payable" note={panel.basis}>
        <EmptyState icon={<Users size={22} aria-hidden />} title="Nothing is outstanding.">
          Smart Books reports no open items for the suppliers this screen covers.
        </EmptyState>
      </Card>
    )
  }

  const top = Number.parseFloat(panel.rows[0].amount) || 0

  return (
    <Card title="Top suppliers by payable" note={panel.basis}>
      <div className="aic-supplier-list">
        {panel.rows.slice(0, 5).map((row) => {
          const id = String(row.supplier_account_id)
          const amount = Number.parseFloat(row.amount) || 0
          const width = top > 0 ? Math.max(4, (amount / top) * 100) : 0

          return (
            <button
              key={id}
              type="button"
              className="aic-supplier-row"
              aria-pressed={activeSupplier === id}
              onClick={() => onPick(activeSupplier === id ? null : id)}
              title={`${row.bill_count} open bill(s)${row.overdue_count > 0 ? `, ${row.overdue_count} overdue` : ''}`}
            >
              <Avatar name={row.supplier_name} id={row.supplier_account_id} />
              <span className="aic-supplier-row__info">
                <span className="aic-supplier-row__head">
                  <strong>{row.supplier_name ?? `Account ${row.supplier_account_id}`}</strong>
                  <b>{formatMoney(row.amount, panel.currency)}</b>
                </span>
                <span className="aic-track" aria-hidden>
                  <span style={{ width: `${width}%` }} />
                </span>
              </span>
            </button>
          )
        })}
      </div>

      <p className="aic-card__note" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        <PieChart size={12} aria-hidden />
        {panel.total_formatted} across {panel.covered_suppliers} supplier(s)
      </p>
    </Card>
  )
}
