/**
 * Nothing to show, and the three different reasons for it.
 *
 * "No RFQs yet" and "nothing matches your filters" are not the same sentence
 * and must not share one: the first needs a way to start, the second needs a
 * way back. The old screen said "No RFQs yet." to both, so a buyer who had
 * filtered themselves into an empty list was told their company had never
 * raised an enquiry.
 */

import { FileSearch, RotateCcw, SearchX, ServerCrash, Sparkles } from 'lucide-react'

export function FirstRunState({
  canCreate,
  onCreate,
  onDraft,
}: {
  canCreate: boolean
  onCreate: () => void
  onDraft: () => void
}) {
  return (
    <div className="sq-state">
      <span className="sq-state__mark" aria-hidden>
        <FileSearch size={26} />
      </span>
      <h3>No RFQs yet</h3>
      <p>
        Raise your first enquiry and invite suppliers to quote. Their prices, freight and delivery terms land
        here side by side, on estimated landed cost — so the cheapest quotation and the cheapest purchase stop
        being two different things.
      </p>
      {canCreate ? (
        <div className="sq-state__actions">
          <button type="button" className="sq-button sq-button--primary" onClick={onCreate}>
            Create an RFQ
          </button>
          <button type="button" className="sq-button sq-button--quiet" onClick={onDraft}>
            <Sparkles size={15} aria-hidden />
            Draft one from a brief
          </button>
        </div>
      ) : (
        <p className="sq-state__note">
          Raising an enquiry needs the <code>rfq.create</code> permission. Ask whoever administers Purchases for
          this company.
        </p>
      )}
    </div>
  )
}

export function NoResultsState({ onClear }: { onClear: () => void }) {
  return (
    <div className="sq-state">
      <span className="sq-state__mark" aria-hidden>
        <SearchX size={26} />
      </span>
      <h3>Nothing matches these filters</h3>
      <p>There are enquiries in this financial year — none of them answer what you have asked for.</p>
      <div className="sq-state__actions">
        <button type="button" className="sq-button sq-button--primary" onClick={onClear}>
          Clear filters
        </button>
      </div>
    </div>
  )
}

export function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="sq-state sq-state--error" role="alert">
      <span className="sq-state__mark sq-state__mark--error" aria-hidden>
        <ServerCrash size={26} />
      </span>
      <h3>Could not load these RFQs</h3>
      <p>{message}</p>
      <div className="sq-state__actions">
        <button type="button" className="sq-button sq-button--primary" onClick={onRetry}>
          <RotateCcw size={15} aria-hidden />
          Try again
        </button>
      </div>
    </div>
  )
}
