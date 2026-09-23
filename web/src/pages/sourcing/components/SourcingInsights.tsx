/**
 * Three ways out of this screen, each carrying a figure that is true.
 *
 * COST SAVINGS POTENTIAL IS A SPREAD, NOT A SAVING. It is the difference
 * between the dearest and the cheapest comparable quotation on the enquiries
 * nobody has decided yet — money still on the table. The moment an enquiry is
 * awarded it stops counting, because by then the decision has been made and
 * calling the difference a "saving" would be flattering the buyer with
 * arithmetic. Where there is nothing to compare, the card says what it is
 * waiting for rather than showing a zero.
 */

import { ChevronRight, PiggyBank, Scale, Users } from 'lucide-react'
import type { SourcingSummary } from '../../../services/types'
import { compactMoney } from '../model'
import { money } from '../../../ui'

export function SourcingInsights({
  summary,
  onShowComparable,
  onFindSuppliers,
}: {
  summary: SourcingSummary | null
  onShowComparable: () => void
  onFindSuppliers: () => void
}) {
  if (!summary) return null

  const values = summary.values
  const visible = summary.values_visible
  const comparable = values?.comparison_ready ?? 0
  const open = values?.open_comparisons ?? 0
  const savings = values?.savings_potential ?? null

  interface InsightCard {
    key: string
    tone: 'green' | 'violet' | 'blue'
    icon: typeof PiggyBank
    label: string
    value: string
    title?: string
    hint: string
    action: (() => void) | null
    actionLabel: string
  }

  const cards: InsightCard[] = [
    {
      key: 'savings',
      tone: 'green',
      icon: PiggyBank,
      label: 'Cost savings potential',
      value: !visible
        ? 'Hidden'
        : savings === null || open === 0
          ? 'Nothing to compare yet'
          : compactMoney(savings, values?.currency ?? 'INR'),
      title: visible && savings !== null && open > 0 ? money(savings, values?.currency ?? 'INR') : undefined,
      hint: !visible
        ? 'Quoted values need the cost.view permission'
        : open === 0
          ? 'Appears once one enquiry has two quotations'
          : `Spread between the highest and lowest quotation on ${open} undecided enquir${open === 1 ? 'y' : 'ies'}`,
      action: open > 0 ? onShowComparable : null,
      actionLabel: 'Show those enquiries',
    },
    {
      key: 'compare',
      tone: 'violet',
      icon: Scale,
      label: 'Price comparison',
      value:
        comparable === 0
          ? 'Nothing comparable yet'
          : `${comparable} enquir${comparable === 1 ? 'y' : 'ies'} ready`,
      hint:
        comparable === 0
          ? 'Two suppliers have to quote the same enquiry before it can be compared'
          : 'Compared on estimated landed cost — rate, freight and other charges together',
      action: comparable > 0 ? onShowComparable : null,
      actionLabel: 'View comparisons',
    },
    {
      key: 'suppliers',
      tone: 'blue',
      icon: Users,
      label: 'Supplier discovery',
      value: 'Search your suppliers',
      hint: 'Read live from Books, with the procurement profile and qualification this company recorded',
      action: onFindSuppliers,
      actionLabel: 'Find suppliers',
    },
  ]

  return (
    <section className="sq-insights" aria-label="Sourcing intelligence">
      {cards.map((card) => {
        const Icon = card.icon
        const body = (
          <>
            <span className={`sq-insight__icon sq-insight__icon--${card.tone}`} aria-hidden>
              <Icon size={18} />
            </span>
            <span className="sq-insight__body">
              <span className="sq-insight__label">{card.label}</span>
              <strong className="sq-insight__value" title={card.title}>
                {card.value}
              </strong>
              <small className="sq-insight__hint">{card.hint}</small>
            </span>
            {card.action && <ChevronRight size={18} className="sq-insight__go" aria-hidden />}
          </>
        )

        return card.action ? (
          <button
            key={card.key}
            type="button"
            className={`sq-insight sq-insight--${card.tone} is-clickable`}
            onClick={card.action}
            aria-label={`${card.label}: ${card.actionLabel}`}
          >
            {body}
          </button>
        ) : (
          <div key={card.key} className={`sq-insight sq-insight--${card.tone}`}>
            {body}
          </div>
        )
      })}
    </section>
  )
}
