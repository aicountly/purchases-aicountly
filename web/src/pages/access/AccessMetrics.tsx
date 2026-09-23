/**
 * The four numbers at the top of the page.
 *
 * Every one of them is read from the server. A count that has not arrived yet
 * draws a skeleton, and a count this product genuinely cannot answer draws an
 * em dash with the reason under it — never a zero. "0 people with access" and
 * "we have not been told yet" look identical as a zero, and only one of them
 * means somebody needs to do something.
 */

import { Clock, ShieldCheck, UserCheck, Users } from 'lucide-react'
import type { ReactNode } from 'react'

function MetricValue({ value }: { value: number | null }) {
  if (value === null) {
    return <span className="access-skeleton" style={{ display: 'block', width: 42, height: 21 }} aria-hidden="true" />
  }

  return <strong className="access-metric__value">{value}</strong>
}

function Metric({
  tone,
  icon,
  value,
  label,
  hint,
  badge,
}: {
  tone: 'blue' | 'green' | 'purple'
  icon: ReactNode
  value: ReactNode
  label: string
  hint: string
  badge?: string
}) {
  return (
    <article className={`access-metric access-metric--${tone}`}>
      {badge && <span className="access-metric__you">{badge}</span>}
      <div className="access-metric__icon" aria-hidden="true">
        {icon}
      </div>
      {value}
      <span className="access-metric__label">{label}</span>
      <small className="access-metric__hint">{hint}</small>
    </article>
  )
}

export function AccessMetrics({
  profileCount,
  peopleCount,
  isOwner,
}: {
  profileCount: number | null
  peopleCount: number | null
  isOwner: boolean
}) {
  return (
    <div className="access-metrics">
      <Metric
        tone="blue"
        icon={<Users size={17} />}
        value={<MetricValue value={profileCount} />}
        label="Permission profiles"
        hint={
          profileCount === 0 ? 'Create starter profiles to get started' : 'Sets of permissions you can assign'
        }
      />

      <Metric
        tone="green"
        icon={<UserCheck size={17} />}
        value={<MetricValue value={peopleCount} />}
        label="People with access"
        hint={peopleCount === 0 ? 'Nobody has been given a profile yet' : 'People currently assigned a profile'}
      />

      {/* One per company, set in Manage rather than counted here. Purchases is
          told whether the caller is the owner; it is not given a roster, and
          must not keep one. */}
      <Metric
        tone="blue"
        icon={<ShieldCheck size={17} />}
        value={<strong className="access-metric__value">1</strong>}
        label="Company owner"
        hint="Full access to everything · set in Aicountly Manage"
        badge={isOwner ? 'YOU' : undefined}
      />

      {/* No request workflow exists in this product yet, so this is an em dash
          with the reason under it rather than a zero that reads as "none
          pending". See RequestsPanel. */}
      <Metric
        tone="purple"
        icon={<Clock size={17} />}
        value={
          <strong className="access-metric__value access-metric__value--quiet">
            <span aria-hidden="true">—</span>
            <span className="access-sr-only">Not available</span>
          </strong>
        }
        label="Access requests"
        hint="Requests are not enabled in Purchases yet"
      />
    </div>
  )
}
