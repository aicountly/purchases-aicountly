/**
 * The five figures above the list.
 *
 * WHERE A FIGURE CANNOT BE PRODUCED THE CARD KEEPS ITS PLACE AND SAYS SO. A
 * comparison against a month with no enquiries is not "+0%", an average with no
 * quotations behind it is not "₹0", and a user without `cost.view` sees the
 * card explain itself rather than a confident nought. Five cards that are
 * sometimes honest and sometimes invented are five cards nobody checks.
 */

import { ArrowDownRight, ArrowUpRight, CircleCheck, Clock, FileText, IndianRupee, Layers } from 'lucide-react'
import type { SourcingSummary } from '../../../services/types'
import { changePc, compactMoney } from '../model'
import { Skeleton } from './parts'
import { money } from '../../../ui'

type Tone = 'blue' | 'amber' | 'violet' | 'green' | 'cyan'

interface Kpi {
  key: string
  label: string
  icon: typeof FileText
  tone: Tone
  /** Null renders as an em dash with `hint` explaining why. */
  value: string | null
  hint: string
  title?: string
  trend?: { pc: number; label: string } | null
}

export function SourcingKpis({ summary, loading }: { summary: SourcingSummary | null; loading: boolean }) {
  if (loading && !summary) {
    return (
      <section className="sq-kpis" aria-label="Sourcing figures" aria-busy="true">
        {[0, 1, 2, 3, 4].map((n) => (
          <article key={n} className="sq-kpi">
            <span className="sq-kpi__icon sq-kpi__icon--blue" aria-hidden />
            <div className="sq-kpi__body">
              <Skeleton width="58%" />
              <Skeleton width="42%" height={22} />
              <Skeleton width="72%" height={10} />
            </div>
          </article>
        ))}
      </section>
    )
  }

  if (!summary) return null

  const { counts, values, values_visible: valuesVisible } = summary
  const raisedTrend = changePc(counts.raised_this_month, counts.raised_last_month)

  const cards: Kpi[] = [
    {
      key: 'total',
      label: 'Total RFQs',
      icon: FileText,
      tone: 'blue',
      value: String(counts.total),
      hint:
        counts.raised_this_month === 1
          ? '1 raised this month'
          : `${counts.raised_this_month} raised this month`,
      // Only when there is a previous month with something in it.
      trend: raisedTrend === null ? null : { pc: raisedTrend, label: 'vs last month' },
    },
    {
      key: 'open',
      label: 'Open RFQs',
      icon: Clock,
      tone: 'amber',
      value: String(counts.open),
      hint:
        counts.overdue > 0
          ? `${counts.awaiting_response} awaiting a response · ${counts.overdue} past deadline`
          : 'Awaiting supplier response',
    },
    {
      key: 'quoted',
      label: 'Quoted RFQs',
      icon: Layers,
      tone: 'violet',
      value: String(counts.quoted),
      hint: 'At least one quotation received',
    },
    {
      key: 'awarded',
      label: 'Awarded RFQs',
      icon: CircleCheck,
      tone: 'green',
      value: String(counts.awarded_this_month),
      hint: counts.awarded === counts.awarded_this_month
        ? 'Decided this month'
        : `Decided this month · ${counts.awarded} in this year`,
    },
    {
      key: 'value',
      label: 'Avg. quote value',
      icon: IndianRupee,
      tone: 'cyan',
      value: !valuesVisible || !values || values.average === null
        ? null
        : compactMoney(values.average, values.currency),
      title: !valuesVisible || !values || values.average === null ? undefined : money(values.average, values.currency),
      hint: !valuesVisible
        ? 'Quoted values need the cost.view permission'
        : !values || values.average === null
          ? 'Available once a supplier has quoted'
          : values.other_currencies > 0
            ? `Estimated landed cost · ${values.quotes} quotations · ${values.other_currencies} other currenc${values.other_currencies === 1 ? 'y' : 'ies'} excluded`
            : `Estimated landed cost across ${values.quotes} quotation${values.quotes === 1 ? '' : 's'}`,
      trend:
        values?.average_change_pc === null || values?.average_change_pc === undefined
          ? null
          : { pc: values.average_change_pc, label: 'vs last month' },
    },
  ]

  return (
    <section className="sq-kpis" aria-label="Sourcing figures">
      {cards.map((card) => {
        const Icon = card.icon
        const rising = (card.trend?.pc ?? 0) >= 0

        return (
          <article key={card.key} className="sq-kpi">
            <span className={`sq-kpi__icon sq-kpi__icon--${card.tone}`} aria-hidden>
              <Icon size={19} />
            </span>
            <div className="sq-kpi__body">
              <span className="sq-kpi__label">{card.label}</span>
              <strong className="sq-kpi__value num" title={card.title}>
                {card.value ?? <span className="sq-kpi__absent">—</span>}
                {card.trend && (
                  <span className={rising ? 'sq-trend sq-trend--up' : 'sq-trend sq-trend--down'}>
                    {rising ? <ArrowUpRight size={13} aria-hidden /> : <ArrowDownRight size={13} aria-hidden />}
                    {rising ? '+' : ''}
                    {card.trend.pc}%
                    <span className="sq-visually-hidden"> {card.trend.label}</span>
                  </span>
                )}
              </strong>
              <small className="sq-kpi__hint">
                {card.trend ? `${card.trend.label} · ${card.hint}` : card.hint}
              </small>
            </div>
          </article>
        )
      })}
    </section>
  )
}
