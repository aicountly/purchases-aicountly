/**
 * A short brief, turned into a draft enquiry.
 *
 * WHAT THIS IS NOT. It is not a model writing an RFQ. This product has no
 * endpoint that drafts an enquiry — `AiClient` chooses between approved intents
 * and writes prose about figures already fetched; it does not compose
 * documents — so nothing here is sent anywhere and nothing comes back. The
 * answers are assembled into a draft and handed to the RFQ editor this product
 * already has, where the buyer checks every field before anything is saved.
 *
 * The panel says so in as many words. A screen that implied a model had
 * written the enquiry would be lying about where the words came from, and the
 * first person to find out would be the supplier reading it.
 */

import { useState } from 'react'
import { Info } from 'lucide-react'
import { SourcingDrawer } from './parts'

export interface RfqBrief {
  title: string
  quantity: string
  requiredBy: string
  deadline: string
  specification: string
  budgetNote: string
}

const EMPTY: RfqBrief = { title: '', quantity: '', requiredBy: '', deadline: '', specification: '', budgetNote: '' }

/**
 * The brief, as the editor's own fields.
 *
 * `commercial_terms` is a real column on the RFQ and is what a supplier reads,
 * so the budget note and the specification go there as sentences rather than
 * into some new field nobody else reads.
 */
export function briefToDraft(brief: RfqBrief): {
  title: string
  deadline: string
  requiredBy: string
  terms: string
  lineLabel: string
  quantity: string
} {
  const terms = [
    brief.specification.trim() ? `Specification: ${brief.specification.trim()}` : '',
    brief.budgetNote.trim() ? `Budget guidance: ${brief.budgetNote.trim()}` : '',
  ]
    .filter(Boolean)
    .join('\n')

  return {
    title: brief.title.trim(),
    deadline: brief.deadline,
    requiredBy: brief.requiredBy,
    terms,
    lineLabel: brief.title.trim(),
    quantity: brief.quantity.trim(),
  }
}

export function RfqBriefDrawer({
  open,
  onClose,
  onContinue,
}: {
  open: boolean
  onClose: () => void
  onContinue: (brief: RfqBrief) => void
}) {
  const [brief, setBrief] = useState<RfqBrief>(EMPTY)
  const set = (patch: Partial<RfqBrief>) => setBrief((current) => ({ ...current, ...patch }))
  const ready = brief.title.trim() !== ''

  return (
    <SourcingDrawer
      open={open}
      title="Draft an RFQ"
      subtitle="Six questions, then the RFQ editor opens with the answers filled in."
      onClose={onClose}
      footer={
        <>
          <button type="button" className="sq-button sq-button--quiet" onClick={onClose}>
            Cancel
          </button>
          <button
            type="button"
            className="sq-button sq-button--primary"
            disabled={!ready}
            title={ready ? undefined : 'Say what you are buying first'}
            onClick={() => {
              onContinue(brief)
              setBrief(EMPTY)
            }}
          >
            Open the RFQ editor
          </button>
        </>
      }
    >
      <p className="sq-drawer__notice">
        <Info size={15} aria-hidden />
        <span>
          This draft is assembled here, in your browser, from what you type. Nothing is sent to a language
          model — this product has no endpoint that writes an enquiry, and one that pretended to would be
          putting words in front of a supplier that nobody wrote. You will add the items, the suppliers and the
          quantities in the editor, and nothing is saved until you do.
        </span>
      </p>

      <div className="sq-form">
        <div className="sq-form__field">
          <label className="sq-form__label" htmlFor="sq-brief-title">
            What are you buying?
          </label>
          <input
            id="sq-brief-title"
            value={brief.title}
            onChange={(event) => set({ title: event.target.value })}
            placeholder="Office laptops for the sales team"
          />
        </div>

        <div className="sq-form__row">
          <div className="sq-form__field">
            <label className="sq-form__label" htmlFor="sq-brief-qty">
              How many? <span className="sq-form__optional">optional</span>
            </label>
            <input
              id="sq-brief-qty"
              value={brief.quantity}
              inputMode="decimal"
              onChange={(event) => set({ quantity: event.target.value })}
              placeholder="20"
            />
          </div>
          <div className="sq-form__field">
            <label className="sq-form__label" htmlFor="sq-brief-required">
              Needed by <span className="sq-form__optional">optional</span>
            </label>
            <input
              id="sq-brief-required"
              type="date"
              value={brief.requiredBy}
              onChange={(event) => set({ requiredBy: event.target.value })}
            />
          </div>
        </div>

        <div className="sq-form__field">
          <label className="sq-form__label" htmlFor="sq-brief-deadline">
            Quotations due back by <span className="sq-form__optional">optional</span>
          </label>
          <input
            id="sq-brief-deadline"
            type="date"
            value={brief.deadline}
            onChange={(event) => set({ deadline: event.target.value })}
          />
        </div>

        <div className="sq-form__field">
          <label className="sq-form__label" htmlFor="sq-brief-spec">
            Specification <span className="sq-form__optional">optional</span>
          </label>
          <textarea
            id="sq-brief-spec"
            value={brief.specification}
            rows={3}
            onChange={(event) => set({ specification: event.target.value })}
            placeholder="14 inch, 16GB RAM, 3 year on-site warranty. Dell Latitude or equivalent."
          />
        </div>

        <div className="sq-form__field">
          <label className="sq-form__label" htmlFor="sq-brief-budget">
            Budget guidance <span className="sq-form__optional">optional</span>
          </label>
          <input
            id="sq-brief-budget"
            value={brief.budgetNote}
            onChange={(event) => set({ budgetNote: event.target.value })}
            placeholder="Around ₹75,000 each, delivered"
          />
          <p className="sq-form__hint">
            Goes into the commercial terms the supplier reads. Leave it out if you would rather not anchor them.
          </p>
        </div>
      </div>
    </SourcingDrawer>
  )
}
