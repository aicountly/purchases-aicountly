/**
 * Step 3 — what is actually being claimed, line by line.
 *
 * THE ARITHMETIC IS SUGGESTED AND NEVER IMPOSED. Importing an order's lines
 * fills in what was ordered, what was received and the rate that was agreed,
 * and for a shortage it proposes the difference — because that is the claim in
 * nine cases out of ten. Every one of those numbers stays editable, nothing is
 * submitted without somebody looking at it, and a claim type with no quantity
 * behind it (a scheme that was not passed on) is typed straight into the
 * amount.
 *
 * The total shown here is computed by `model.ts`. The total that is STORED is
 * computed again by the server from the same lines — this one is what the user
 * watches while they type, not what anybody is charged.
 */

import { useState } from 'react'
import { Download, ListPlus, Loader2, Package, Plus, X } from 'lucide-react'
import { money } from '../../../ui'
import { ApiError } from '../../../services/api'
import { fetchPurchaseOrder } from '../service'
import { emptyLine, lineAmount, totalAmount } from '../model'
import type { ClaimLineDraft } from '../types'
import { Button, EmptyState, Notice } from '../components/ui'
import type { StepProps } from './props'

function num(value: string): number {
  const parsed = Number.parseFloat(value)

  return Number.isFinite(parsed) ? parsed : 0
}

/** Trailing zeros on a quantity are noise: 10.0000 reads worse than 10. */
function qtyText(value: number): string {
  if (!Number.isFinite(value) || value === 0) return ''

  return String(Number.parseFloat(value.toFixed(4)))
}

export function ClaimItemsStep({ draft, errors, patch }: StepProps) {
  const [importing, setImporting] = useState(false)
  const [importError, setImportError] = useState<string | null>(null)
  const [imported, setImported] = useState<string | null>(null)

  const total = totalAmount(draft)

  function updateLine(key: string, change: Partial<ClaimLineDraft>) {
    patch({ lines: draft.lines.map((line) => (line.key === key ? { ...line, ...change } : line)) })
  }

  function addLine() {
    patch({ lines: [...draft.lines, emptyLine()] })
  }

  function removeLine(key: string) {
    const next = draft.lines.filter((line) => line.key !== key)
    patch({ lines: next.length === 0 ? [emptyLine()] : next })
  }

  /**
   * Bring the linked order's lines in.
   *
   * What arrives is what the order says: ordered, received so far, and the rate
   * agreed. For a shortage the gap between ordered and received is proposed as
   * the quantity to claim; for anything else the quantity is left blank,
   * because guessing it would be inventing the claim.
   */
  async function importFromOrder() {
    if (!draft.purchaseOrder) return

    setImporting(true)
    setImportError(null)
    setImported(null)

    try {
      const order = await fetchPurchaseOrder(draft.purchaseOrder.id)
      const lines = (order.lines ?? [])
        .map((line) => {
          const ordered = Number.parseFloat(line.ordered_qty) || 0
          const received = Number.parseFloat(line.received_qty) || 0
          const short = Math.max(0, ordered - received)

          return emptyLine({
            itemId: line.item_id,
            description: line.description ?? (line.item_id ? `Item #${line.item_id}` : ''),
            referenceKind: 'po',
            referenceNo: draft.purchaseOrder?.primary ?? '',
            orderedQty: qtyText(ordered),
            receivedQty: qtyText(received),
            claimQty: draft.claimKind === 'shortage' ? qtyText(short) : '',
            rate: qtyText(Number.parseFloat(line.agreed_rate) || 0),
            claimAmount: '',
            reason: '',
          })
        })
        // A line that was fully received has no shortage to claim, so it is not
        // carried onto a shortage claim at all.
        .filter((line) => (draft.claimKind === 'shortage' ? line.claimQty !== '' : true))

      if (lines.length === 0) {
        setImportError(
          draft.claimKind === 'shortage'
            ? 'Every line on that order has been received in full, so there is no shortage to claim.'
            : 'That order has no lines to bring in.',
        )

        return
      }

      const existing = draft.lines.filter(
        (line) => line.description.trim() !== '' || line.claimQty.trim() !== '' || line.claimAmount.trim() !== '',
      )
      patch({ lines: [...existing, ...lines, emptyLine()] })
      setImported(`${lines.length} line${lines.length === 1 ? '' : 's'} brought in from ${draft.purchaseOrder.primary}.`)
    } catch (error) {
      setImportError(error instanceof ApiError ? error.message : 'That order could not be read.')
    } finally {
      setImporting(false)
    }
  }

  return (
    <section className="claim-card claim-step-panel">
      <div className="claim-card-header">
        <h2>
          <Package size={15} aria-hidden />
          Items &amp; amounts
        </h2>
        <span className="claim-card-hint">
          {draft.claimKind === 'scheme' || draft.claimKind === 'rebate'
            ? 'Enter the amount directly — a scheme has no quantity behind it'
            : 'Claim amount is quantity × rate unless you type one'}
        </span>
      </div>

      {errors.lines && (
        <div style={{ marginBottom: 14 }}>
          <Notice tone="danger">{errors.lines}</Notice>
        </div>
      )}

      {importError && (
        <div style={{ marginBottom: 14 }}>
          <Notice tone="warning" actions={<Button small onClick={() => setImportError(null)}>Dismiss</Button>}>
            {importError}
          </Notice>
        </div>
      )}

      {imported && (
        <div style={{ marginBottom: 14 }}>
          <Notice tone="success" actions={<Button small onClick={() => setImported(null)}>Dismiss</Button>}>
            {imported} Check the quantities before you submit.
          </Notice>
        </div>
      )}

      {draft.lines.length === 0 ? (
        <EmptyState icon={<ListPlus size={18} />} title="Nothing claimed yet">
          Add a line for each item, or type the amount straight in where there is no quantity behind it.
        </EmptyState>
      ) : (
        <div className="claim-lines-scroll">
          <table className="claim-lines">
            <caption className="sc-sr-only">
              The lines of this claim. Each row is one item, what was ordered and received, and what is being claimed.
            </caption>
            <thead>
              <tr>
                <th scope="col" style={{ minWidth: 200 }}>Item</th>
                <th scope="col" style={{ minWidth: 120 }}>Reference</th>
                <th scope="col" className="is-numeric">Ordered</th>
                <th scope="col" className="is-numeric">Received</th>
                <th scope="col" className="is-numeric">Claim qty</th>
                <th scope="col" className="is-numeric">Rate</th>
                <th scope="col" className="is-numeric">Claim amount</th>
                <th scope="col" style={{ minWidth: 150 }}>Reason</th>
                <th scope="col"><span className="sc-sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              {draft.lines.map((line, index) => {
                const amount = lineAmount(line)
                const overClaimed = num(line.orderedQty) > 0 && num(line.claimQty) > num(line.orderedQty)

                return (
                  <tr key={line.key}>
                    <td>
                      <input
                        className="sc-input"
                        value={line.description}
                        placeholder="What was short, damaged or wrong"
                        aria-label={`Item on line ${index + 1}`}
                        onChange={(event) => updateLine(line.key, { description: event.target.value })}
                      />
                    </td>
                    <td>
                      <input
                        className="sc-input"
                        value={line.referenceNo}
                        placeholder="PO / GRN / bill"
                        aria-label={`Reference on line ${index + 1}`}
                        onChange={(event) => updateLine(line.key, { referenceNo: event.target.value })}
                      />
                    </td>
                    <td className="is-numeric">
                      <input
                        className="sc-input is-numeric"
                        inputMode="decimal"
                        value={line.orderedQty}
                        aria-label={`Ordered quantity on line ${index + 1}`}
                        onChange={(event) => updateLine(line.key, { orderedQty: event.target.value })}
                      />
                    </td>
                    <td className="is-numeric">
                      <input
                        className="sc-input is-numeric"
                        inputMode="decimal"
                        value={line.receivedQty}
                        aria-label={`Received quantity on line ${index + 1}`}
                        onChange={(event) => updateLine(line.key, { receivedQty: event.target.value })}
                      />
                    </td>
                    <td className="is-numeric">
                      <input
                        className="sc-input is-numeric"
                        inputMode="decimal"
                        value={line.claimQty}
                        aria-label={`Claimed quantity on line ${index + 1}`}
                        aria-invalid={overClaimed ? true : undefined}
                        onChange={(event) => updateLine(line.key, { claimQty: event.target.value })}
                      />
                    </td>
                    <td className="is-numeric">
                      <input
                        className="sc-input is-numeric"
                        inputMode="decimal"
                        value={line.rate}
                        aria-label={`Rate on line ${index + 1}`}
                        onChange={(event) => updateLine(line.key, { rate: event.target.value })}
                      />
                    </td>
                    <td className="is-numeric">
                      <input
                        className="sc-input is-numeric"
                        inputMode="decimal"
                        value={line.claimAmount}
                        placeholder={amount ? String(amount) : '0'}
                        aria-label={`Claim amount on line ${index + 1}`}
                        onChange={(event) => updateLine(line.key, { claimAmount: event.target.value })}
                      />
                    </td>
                    <td>
                      <input
                        className="sc-input"
                        value={line.reason}
                        placeholder="Optional"
                        aria-label={`Reason on line ${index + 1}`}
                        onChange={(event) => updateLine(line.key, { reason: event.target.value })}
                      />
                    </td>
                    <td>
                      <button
                        type="button"
                        className="claim-line-remove"
                        onClick={() => removeLine(line.key)}
                        aria-label={`Remove line ${index + 1}`}
                      >
                        <X size={15} aria-hidden />
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      <div className="claim-lines-actions">
        <Button onClick={addLine}>
          <Plus size={14} aria-hidden />
          Add claim line
        </Button>

        {draft.purchaseOrder && (
          <Button onClick={importFromOrder} disabled={importing}>
            {importing ? <Loader2 size={14} className="sc-spin" aria-hidden /> : <Download size={14} aria-hidden />}
            Import items from {draft.purchaseOrder.primary}
          </Button>
        )}
      </div>

      <div className="claim-lines-total">
        <span>Total claim amount</span>
        <strong>{money(total)}</strong>
      </div>
    </section>
  )
}
