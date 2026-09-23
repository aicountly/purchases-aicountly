/**
 * The claim as it stands, in the rail, updating as it is typed.
 *
 * It reads the same draft the form writes and the same totals `model.ts`
 * computes, so it cannot drift from the form beside it. Nothing here is fetched
 * and nothing here is stored — it is the draft, rendered.
 */

import { FileCheck2 } from 'lucide-react'
import { date, money } from '../../../ui'
import { CLAIM_STEPS, filledLines, stepIndex, totalAmount } from '../model'
import type { ClaimDraft, ClaimMeta, ClaimStepId } from '../types'

function label(options: Array<{ value: string; label: string }> | undefined, value: string): string | null {
  return options?.find((option) => option.value === value)?.label ?? null
}

export function ClaimSummary({
  draft,
  meta,
  step,
}: {
  draft: ClaimDraft
  meta: ClaimMeta | null
  step: ClaimStepId
}) {
  const total = totalAmount(draft)
  const lines = filledLines(draft)
  const references = [draft.purchaseOrder, draft.purchaseBill, draft.delivery, draft.returnRef].filter(Boolean)

  const rows: Array<{ term: string; value: string; empty?: boolean; total?: boolean }> = [
    { term: 'Supplier', value: draft.supplier?.name ?? 'Not selected', empty: !draft.supplier },
    {
      term: 'Claim type',
      value: label(meta?.kinds, draft.claimKind) ?? 'Not selected',
      empty: draft.claimKind === '',
    },
    { term: 'Claim date', value: date(draft.claimDate) },
    { term: 'Total claim amount', value: money(total), total: true },
    {
      term: 'Claim lines',
      value: lines.length === 0 ? 'None added' : `${lines.length} line${lines.length === 1 ? '' : 's'}`,
      empty: lines.length === 0,
    },
    {
      term: 'Linked references',
      value:
        references.length === 0
          ? 'None linked'
          : [
              draft.purchaseOrder && `PO ${draft.purchaseOrder.primary}`,
              draft.purchaseBill && `Bill ${draft.purchaseBill.primary}`,
              draft.delivery && `GRN ${draft.delivery.primary}`,
              draft.returnRef && `Return ${draft.returnRef.primary}`,
            ]
              .filter(Boolean)
              .join(' · '),
      empty: references.length === 0,
    },
    {
      term: 'Attachments',
      value: `${draft.attachments.length} file${draft.attachments.length === 1 ? '' : 's'}`,
      empty: draft.attachments.length === 0,
    },
    {
      term: 'Current step',
      value: `${stepIndex(step) + 1} of ${CLAIM_STEPS.length} · ${CLAIM_STEPS[stepIndex(step)].title}`,
    },
  ]

  return (
    <section className="claim-card claim-summary-card">
      <div className="claim-card-header">
        <h2>
          <FileCheck2 size={15} aria-hidden />
          Claim summary
        </h2>
      </div>

      <dl className="claim-summary">
        {rows.map((row) => (
          <div key={row.term}>
            <dt>{row.term}</dt>
            <dd className={`${row.empty ? 'is-empty' : ''}${row.total ? ' claim-summary__total' : ''}`.trim()}>
              {row.value}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  )
}
