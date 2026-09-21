/**
 * The four cards above the dashboard switcher.
 *
 * Each one is a POSITION, not a period total: how healthy the cycle is, what
 * could be saved, what is waiting on you, and how the supplier base is
 * performing. They sit above the tabs because they are true of the whole
 * screen, not of the Overview tab alone.
 *
 * Every figure here is derived on the server and arrives with its components,
 * its weights and its denominators. Nothing on this strip is computed in the
 * browser, and nothing on it is shown without the reader being able to find out
 * what went into it.
 */

import { useNavigate } from 'react-router-dom'
import { IndianRupee, Inbox, ShieldCheck, Sparkles } from 'lucide-react'
import type { HealthPanel, IntelligencePanel, ScoreBand, ScoreComponent, SupplierRiskPanel, DashboardMetric } from '../types'

/** A score ring whose sweep IS the score. */
function ScoreRing({ score, band }: { score: string | null; band: ScoreBand }) {
  if (score === null) {
    return (
      <div className="purchase-ring purchase-ring--empty" aria-hidden>
        <strong>—</strong>
      </div>
    )
  }

  const pc = Math.max(0, Math.min(100, Number.parseFloat(score)))
  const colour =
    band.tone === 'danger'
      ? 'var(--purchase-danger)'
      : band.tone === 'warning'
        ? '#c98a0e'
        : 'var(--purchase-brand)'

  return (
    <div
      className="purchase-ring"
      aria-hidden
      style={{
        background: `radial-gradient(closest-side, #fff 71%, transparent 73% 100%), conic-gradient(${colour} ${pc}%, #e6ece7 0)`,
      }}
    >
      <strong>{Math.round(pc)}</strong>
    </div>
  )
}

/**
 * The components behind a score, as the title attribute.
 *
 * The full working is on the Suppliers and AI Insights screens; what belongs on
 * a 84px card is enough to tell the reader the number was not conjured.
 */
function workingOf(components: ScoreComponent[]): string {
  return components
    .map((component) =>
      component.counted
        ? `${component.label}: ${component.value}% (weight ${component.weight})`
        : `${component.label}: not measured in this period`,
    )
    .join('\n')
}

/**
 * Value, then what it is, then what to make of it.
 *
 * That order is deliberate and is the same on all four cards: the figure is
 * what the eye lands on, the label tells you what it counts, and the note is
 * the reading. A card that puts its caption last leaves the reader holding a
 * number they cannot name.
 */
function Card({
  icon,
  tone = 'brand',
  title,
  value,
  note,
  onOpen,
  openLabel,
  hint,
}: {
  icon: React.ReactNode
  tone?: 'brand' | 'warning' | 'danger' | 'info' | 'neutral'
  title: string
  value: React.ReactNode
  note?: React.ReactNode
  onOpen?: () => void
  openLabel?: string
  hint?: string
}) {
  const body = (
    <>
      <span className={tone === 'brand' ? 'purchase-exec__icon' : `purchase-exec__icon is-${tone}`} aria-hidden>
        {icon}
      </span>
      <span className="purchase-exec__body">
        {value}
        <h3 className="purchase-exec__title">{title}</h3>
        {note}
      </span>
    </>
  )

  if (!onOpen) {
    return (
      <article className="purchase-exec" title={hint}>
        {body}
      </article>
    )
  }

  return (
    <button type="button" className="purchase-exec" onClick={onOpen} aria-label={openLabel} title={hint}>
      {body}
    </button>
  )
}

export function ExecutiveStrip({
  health,
  intelligence,
  supplierRisk,
  approvals,
}: {
  health: HealthPanel
  intelligence: IntelligencePanel
  supplierRisk: SupplierRiskPanel
  /** The pending-approval KPI, reused so the strip and the row cannot disagree. */
  approvals: DashboardMetric | undefined
}) {
  const navigate = useNavigate()

  return (
    <section className="purchase-executive" aria-label="Procurement position">
      {/* 1 — procurement health */}
      {health.available ? (
        <article
          className="purchase-exec"
          title={`${health.basis}\n\n${workingOf(health.components)}\n\n${health.method}`}
        >
          <ScoreRing score={health.score} band={health.band} />
          <div className="purchase-exec__body">
            <h3 className="purchase-exec__title">Procurement health score</h3>
            <span className={`purchase-exec__status is-${health.band.tone}`}>{health.band.label}</span>
            <p className="purchase-exec__note">
              {health.summary}
              {health.confidence.partial && (
                <>
                  {' · '}
                  <span>{health.confidence.label}</span>
                </>
              )}
            </p>
          </div>
        </article>
      ) : (
        <Card
          icon={<ShieldCheck size={19} />}
          tone="neutral"
          title="Procurement health score"
          hint={health.reason}
          value={<strong className="purchase-exec__value is-unavailable">Unavailable</strong>}
        />
      )}

      {/* 2 — what the rules say could be saved */}
      {intelligence.available ? (
        <Card
          icon={<IndianRupee size={19} />}
          title="Savings opportunity"
          hint={`${intelligence.basis}\n\n${intelligence.method_label}${intelligence.scope_note ? `\n\n${intelligence.scope_note}` : ''}`}
          onOpen={() => navigate(intelligence.route)}
          openLabel={`Open purchase intelligence: ${intelligence.total_formatted} identified`}
          value={<strong className="purchase-exec__value">{intelligence.total_formatted}</strong>}
          note={
            <p className="purchase-exec__note">
              {intelligence.card_count === 0
                ? 'No opportunities found in this period'
                : `${intelligence.card_count} rule-based ${intelligence.card_count === 1 ? 'finding' : 'findings'} identified`}
              {/* These rules read the company and the year, not the branch or
                  supplier in the command bar. A figure the reader believes is
                  about the branch they filtered to would be worse than none. */}
              {intelligence.scope_note !== null && <span className="purchase-exec__caveat">Whole company</span>}
            </p>
          }
        />
      ) : (
        <Card
          icon={<IndianRupee size={19} />}
          tone="neutral"
          title="Savings opportunity"
          hint={intelligence.reason}
          value={<strong className="purchase-exec__value is-unavailable">Analysis unavailable</strong>}
        />
      )}

      {/* 3 — what is waiting on this reader */}
      <Card
        icon={<Inbox size={19} />}
        tone={approvals && approvals.raw_value !== '0' ? 'warning' : 'brand'}
        title="Pending approvals"
        hint={approvals?.explanation}
        onOpen={() => navigate('/approvals')}
        openLabel="Open your approvals"
        value={
          <strong className={approvals?.status === 'ready' ? 'purchase-exec__value' : 'purchase-exec__value is-unavailable'}>
            {approvals?.status === 'ready' ? approvals.formatted_value : 'Unavailable'}
          </strong>
        }
        note={
          <p className="purchase-exec__note">
            {approvals?.raw_value === '0' ? 'Nothing waiting on you' : 'Needs your attention'}
          </p>
        }
      />

      {/* 4 — how the supplier base is performing */}
      {supplierRisk.available ? (
        <Card
          icon={<Sparkles size={19} />}
          tone={supplierRisk.band.tone === 'success' ? 'brand' : supplierRisk.band.tone === 'neutral' ? 'neutral' : supplierRisk.band.tone}
          title="Supplier risk score"
          hint={`${supplierRisk.basis}\n\n${workingOf(supplierRisk.components)}`}
          onOpen={() => navigate(supplierRisk.route)}
          openLabel="Open supplier performance"
          value={
            <strong className={supplierRisk.score === null ? 'purchase-exec__value is-unavailable' : 'purchase-exec__value'}>
              {supplierRisk.score === null ? 'Not enough data' : supplierRisk.score_formatted}
            </strong>
          }
          note={
            // The band is stated in words, not only in the icon's colour: a
            // reader who cannot tell amber from green still gets the answer.
            <span className={`purchase-exec__status is-${supplierRisk.band.tone}`}>
              {supplierRisk.band.label}
              {supplierRisk.band.action ? ` · ${supplierRisk.band.action}` : ''}
            </span>
          }
        />
      ) : (
        <Card
          icon={<Sparkles size={19} />}
          tone="neutral"
          title="Supplier risk score"
          hint={supplierRisk.reason}
          value={<strong className="purchase-exec__value is-unavailable">Unavailable</strong>}
        />
      )}
    </section>
  )
}
