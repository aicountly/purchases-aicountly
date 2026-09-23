/**
 * Step 2 — what the claim argues from.
 *
 * All four are optional to the API, and all four are the difference between a
 * claim that is settled and one that is argued about: "ten cartons short"
 * against "ten cartons short on GRN-0441, against PO-1245, billed on INV-9982".
 *
 * EVERY LIST IS FILTERED BY THE SUPPLIER ALREADY CHOSEN. That is not a
 * convenience — the server refuses a reference belonging to a different
 * supplier, so offering one would be offering a choice that cannot be saved.
 */

import { useCallback } from 'react'
import { ClipboardList, PackageCheck, Receipt, RotateCcw } from 'lucide-react'
import { searchDeliveries, searchPurchaseBills, searchPurchaseOrders, searchReturns } from '../service'
import type { ReferenceQuery } from '../service'
import type { ReferenceOption } from '../types'
import { RecordCombobox } from '../components/RecordCombobox'
import { ClaimAttachments } from '../components/ClaimAttachments'
import { Notice } from '../components/ui'
import type { StepProps } from './props'

export function ClaimReferencesStep({ draft, meta, patch, goTo }: StepProps) {
  const supplierId = draft.supplier?.id ?? null
  const locked = supplierId === null
  const lockedReason = locked ? 'Choose a supplier first — these lists are filtered to their documents.' : undefined

  // One binder, four searches. Each closes over the supplier currently chosen,
  // which is also what `scopeKey` tells the combobox to watch.
  const bind = useCallback(
    (fn: (query: ReferenceQuery) => Promise<ReferenceOption[]>) =>
      (term: string, signal: AbortSignal) =>
        fn({ supplierId, term, signal }),
    [supplierId],
  )

  const attachments = meta?.capabilities.attachments ?? { available: false, reason: null }

  return (
    <section className="claim-card claim-step-panel">
      <div className="claim-card-header">
        <h2>
          <ClipboardList size={15} aria-hidden />
          Reference documents
        </h2>
        <span className="claim-card-hint">Optional, and worth the minute</span>
      </div>

      {locked && (
        <div style={{ marginBottom: 14 }}>
          <Notice
            tone="info"
            title="No supplier chosen yet"
            actions={
              <button type="button" className="sc-btn sc-btn--sm sc-btn--secondary" onClick={() => goTo('details')}>
                Back to step 1
              </button>
            }
          >
            Orders, bills, deliveries and returns are searched within one supplier's records.
          </Notice>
        </div>
      )}

      <div className="claim-form-grid claim-form-grid--4">
        <RecordCombobox
          label="Purchase order"
          placeholder="Search PO no."
          selected={draft.purchaseOrder}
          search={bind(searchPurchaseOrders)}
          scopeKey={supplierId}
          disabled={locked}
          disabledReason={lockedReason}
          emptyMessage="No purchase orders found for this supplier."
          onPick={(option) => patch({ purchaseOrder: option })}
          onClear={() => patch({ purchaseOrder: null })}
        />

        <RecordCombobox
          label="Purchase bill"
          placeholder="Search bill no."
          selected={draft.purchaseBill}
          search={bind(searchPurchaseBills)}
          scopeKey={supplierId}
          disabled={locked}
          disabledReason={lockedReason}
          emptyMessage="No bills entered for this supplier yet."
          onPick={(option) => patch({ purchaseBill: option })}
          onClear={() => patch({ purchaseBill: null })}
        />

        <RecordCombobox
          label="Delivery / GRN"
          placeholder="Search GRN no."
          selected={draft.delivery}
          search={bind(searchDeliveries)}
          scopeKey={supplierId}
          disabled={locked}
          disabledReason={lockedReason}
          emptyMessage="No deliveries recorded against this supplier's orders."
          onPick={(option) => patch({ delivery: option })}
          onClear={() => patch({ delivery: null })}
        />

        <RecordCombobox
          label="Return (if any)"
          placeholder="Search return no."
          selected={draft.returnRef}
          search={bind(searchReturns)}
          scopeKey={supplierId}
          disabled={locked}
          disabledReason={lockedReason}
          emptyMessage="No returns raised against this supplier."
          onPick={(option) => patch({ returnRef: option })}
          onClear={() => patch({ returnRef: null })}
        />
      </div>

      {draft.purchaseOrder && (
        <div style={{ marginTop: 16 }}>
          <Notice tone="info" title="Order linked">
            The next step can bring this order's lines in with what was ordered and what has been received against each,
            so a shortage adds itself up.
          </Notice>
        </div>
      )}

      <ClaimAttachments
        attachments={draft.attachments}
        capability={attachments}
        onChange={(next) => patch({ attachments: next })}
      />

      <p className="sc-field__hint" style={{ marginTop: 14 }}>
        <Receipt size={12} aria-hidden style={{ verticalAlign: '-1px', marginRight: 5 }} />
        Bills, deliveries and returns are read live from the products that own them —
        <PackageCheck size={12} aria-hidden style={{ verticalAlign: '-1px', margin: '0 4px' }} />
        Inventory for the goods receipt, Books for the voucher —
        <RotateCcw size={12} aria-hidden style={{ verticalAlign: '-1px', margin: '0 4px' }} />
        and what this claim keeps is the reference, not a copy.
      </p>
    </section>
  )
}
