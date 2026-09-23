/**
 * Raising a purchase return.
 *
 * Four steps, because a return is four decisions: who it goes back to, what it
 * came in on, what is going back, and why. Everything is held in the drawer
 * until Create, and Create is the only call that writes anything.
 *
 * ELIGIBLE QUANTITIES ARE NEVER INVENTED. When a return is raised against a
 * purchase order, the most that may go back on each line is what was received
 * less what has already been returned — both read from the order this product
 * owns, live, when the order is chosen. Nothing here guesses at stock: what is
 * physically on hand is Inventory's, and Inventory checks it when the goods
 * actually move.
 */

import { useMemo, useState } from 'react'
import { ArrowLeft, ArrowRight, Check, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'
import { SupplierPicker } from '../../components/LivePicker'
import { money } from '../../ui'
import type { PurchaseOrder } from '../../services/types'
import { purchaseReturnsApi } from './api'
import type { PurchaseReturnRow, ReturnOptions } from './types'
import { Drawer, Notice, shortDate } from './ui'
import { iso } from './filters'

interface DraftLine {
  key: string
  po_line_id: number | null
  item_id: string
  warehouse_id: string
  description: string
  /** What may still go back on this line. Null when there is no order behind it. */
  eligible: number | null
  return_qty: string
  rate: string
}

const STEPS = ['Supplier', 'Source', 'Items', 'Reason'] as const

export function NewReturnDrawer({
  open,
  options,
  seed,
  onClose,
  onCreated,
}: {
  open: boolean
  options: ReturnOptions | null
  /** A return being duplicated: its supplier and reason start the new one. */
  seed: PurchaseReturnRow | null
  onClose: () => void
  onCreated: (created: PurchaseReturnRow) => void
}) {
  const { scope } = usePurchases()
  const [step, setStep] = useState(0)

  const [supplierId, setSupplierId] = useState<number | null>(seed?.supplier_account_id ?? null)
  const [supplierName, setSupplierName] = useState<string | null>(seed?.supplier_name ?? null)
  const [poId, setPoId] = useState<number | null>(null)
  const [returnDate, setReturnDate] = useState(() => iso(new Date()))
  const [pickupDate, setPickupDate] = useState('')
  const [reason, setReason] = useState(seed?.reason?.code ?? '')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Only orders for the chosen supplier, and only once one is chosen.
  const orders = useApi(
    (signal) =>
      api.list<PurchaseOrder>(
        'v1/purchase-orders',
        { supplier_account_id: supplierId ?? undefined, limit: 50 },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, supplierId],
    open && supplierId !== null,
  )

  const chosenOrder = useApi(
    (signal) => api.one<PurchaseOrder>(`v1/purchase-orders/${poId}`, undefined, signal),
    [scope?.cmp_id, poId],
    open && poId !== null,
  )

  const total = useMemo(
    () =>
      lines.reduce((sum, line) => sum + (Number.parseFloat(line.return_qty) || 0) * (Number.parseFloat(line.rate) || 0), 0),
    [lines],
  )

  const overEligible = lines.filter(
    (line) => line.eligible !== null && (Number.parseFloat(line.return_qty) || 0) > line.eligible,
  )
  const usableLines = lines.filter((line) => (Number.parseFloat(line.return_qty) || 0) > 0)
  const needWarehouse = usableLines.filter((line) => line.item_id.trim() !== '' && line.warehouse_id.trim() === '')

  function reset() {
    setStep(0)
    setSupplierId(seed?.supplier_account_id ?? null)
    setSupplierName(seed?.supplier_name ?? null)
    setPoId(null)
    setReturnDate(iso(new Date()))
    setPickupDate('')
    setReason(seed?.reason?.code ?? '')
    setNote('')
    setLines([])
    setError(null)
  }

  function close() {
    reset()
    onClose()
  }

  /** Seed the lines from the order, at the quantity that may still go back. */
  function loadFromOrder(order: PurchaseOrder) {
    setLines(
      order.lines.map((line) => {
        const received = Number.parseFloat(line.received_qty) || 0
        const returned = Number.parseFloat(line.returned_qty) || 0
        const eligible = Math.max(0, Number.parseFloat((received - returned).toFixed(4)))

        return {
          key: `po-${line.line_id}`,
          po_line_id: line.line_id,
          item_id: line.item_id === null ? '' : String(line.item_id),
          warehouse_id: line.warehouse_id === null ? '' : String(line.warehouse_id),
          description: line.description ?? (line.item_id === null ? 'Service line' : `Inventory item ${line.item_id}`),
          eligible,
          // Nothing is pre-filled. A return is usually part of a delivery, and
          // defaulting to "all of it" is how a whole order goes back by
          // accident.
          return_qty: '',
          rate: line.agreed_rate,
        }
      }),
    )
  }

  async function create() {
    if (supplierId === null) return
    setSaving(true)
    setError(null)

    try {
      const response = await purchaseReturnsApi.create({
        supplier_account_id: supplierId,
        supplier_name: supplierName ?? undefined,
        po_id: poId ?? undefined,
        return_date: returnDate,
        expected_pickup_date: pickupDate || undefined,
        reason_code: reason,
        reason_note: note.trim() || undefined,
        lines: usableLines.map((line) => ({
          po_line_id: line.po_line_id ?? undefined,
          item_id: line.item_id.trim() === '' ? undefined : Number.parseInt(line.item_id, 10),
          warehouse_id: line.warehouse_id.trim() === '' ? undefined : Number.parseInt(line.warehouse_id, 10),
          return_qty: Number.parseFloat(line.return_qty),
          rate: Number.parseFloat(line.rate) || 0,
          reason_code: reason || undefined,
        })),
      })

      onCreated(response.data)
      reset()
    } catch (failure) {
      setError(failure instanceof ApiError ? failure.message : String(failure))
    } finally {
      setSaving(false)
    }
  }

  const canContinue =
    step === 0
      ? supplierId !== null && returnDate !== ''
      : step === 1
        ? true
        : step === 2
          ? usableLines.length > 0 && overEligible.length === 0 && needWarehouse.length === 0
          : reason !== ''

  return (
    <Drawer
      open={open}
      wide
      title="New purchase return"
      subtitle="Nothing is created until the last step, and everything created here is a draft."
      onClose={close}
      footer={
        <>
          <span className="pr-steps" style={{ marginRight: 'auto' }}>
            {STEPS.map((label, index) => (
              <span key={label} className={index === step ? 'is-current' : index < step ? 'is-done' : undefined}>
                {index < step && <Check size={12} aria-hidden />}
                {index + 1}. {label}
              </span>
            ))}
          </span>

          {step > 0 && (
            <button type="button" className="pr-btn" onClick={() => setStep(step - 1)}>
              <ArrowLeft size={14} aria-hidden /> Back
            </button>
          )}

          {step < STEPS.length - 1 ? (
            <button
              type="button"
              className="pr-btn pr-btn--primary"
              disabled={!canContinue}
              onClick={() => setStep(step + 1)}
            >
              Continue <ArrowRight size={14} aria-hidden />
            </button>
          ) : (
            <button
              type="button"
              className="pr-btn pr-btn--primary"
              disabled={!canContinue || saving || usableLines.length === 0}
              onClick={create}
            >
              {saving ? 'Creating…' : 'Create the return'}
            </button>
          )}
        </>
      }
    >
      {error && <Notice tone="danger">{error}</Notice>}

      {step === 0 && (
        <div className="pr-section">
          <h3>Who is it going back to?</h3>
          <SupplierPicker
            selectedLabel={supplierName}
            onPick={(supplier) => {
              setSupplierId(supplier.acc_id)
              setSupplierName(supplier.acc_name)
              // The order and its lines belonged to the previous supplier.
              setPoId(null)
              setLines([])
            }}
          />
          {supplierName && (
            <p className="pr-field__hint" style={{ marginTop: 6 }}>
              Returning to <strong>{supplierName}</strong>.
            </p>
          )}

          <div className="pr-grid-2" style={{ marginTop: 14 }}>
            <label className="pr-field">
              <span>Return date</span>
              <input type="date" value={returnDate} onChange={(event) => setReturnDate(event.target.value)} />
            </label>
            <label className="pr-field">
              <span>Expected pickup (optional)</span>
              <input
                type="date"
                value={pickupDate}
                min={returnDate}
                onChange={(event) => setPickupDate(event.target.value)}
              />
              <span className="pr-field__hint">When the supplier is collecting. Shown on the calendar.</span>
            </label>
          </div>
        </div>
      )}

      {step === 1 && (
        <div className="pr-section">
          <h3>What did it come in on?</h3>
          <p className="pr-field__hint" style={{ marginBottom: 12 }}>
            Choosing the purchase order fills the lines in and sets what may still go back on each of them. A return
            without one is possible where your company's policy allows it, and has to be typed by hand.
          </p>

          {orders.loading && <p style={{ color: 'var(--pr-muted)', fontSize: 13 }}>Looking for this supplier's orders…</p>}
          {orders.error && <Notice tone="warning">Could not load this supplier's purchase orders: {orders.error}</Notice>}

          <div style={{ display: 'grid', gap: 8 }}>
            <button
              type="button"
              className={poId === null ? 'pr-pop__option is-active' : 'pr-pop__option'}
              onClick={() => {
                setPoId(null)
                setLines([])
              }}
            >
              No source document
            </button>

            {(orders.data?.data ?? []).map((order) => (
              <button
                key={order.po_id}
                type="button"
                className={poId === order.po_id ? 'pr-pop__option is-active' : 'pr-pop__option'}
                onClick={() => {
                  setPoId(order.po_id)
                  // The list row has no lines on it; the order is fetched in
                  // full below and its lines are loaded from there.
                  setLines([])
                }}
              >
                <span>
                  <strong>{order.po_no}</strong>
                  <span style={{ display: 'block', fontSize: 11.5, color: 'var(--pr-muted)' }}>
                    {shortDate(order.po_date)} · {order.status.replace(/_/g, ' ').toLowerCase()}
                  </span>
                </span>
                <span style={{ fontVariantNumeric: 'tabular-nums' }}>{money(order.total_amount, order.currency_code)}</span>
              </button>
            ))}

            {!orders.loading && (orders.data?.data ?? []).length === 0 && supplierId !== null && (
              <Notice tone="plain">This supplier has no purchase orders in this financial year.</Notice>
            )}
          </div>
        </div>
      )}

      {step === 2 && (
        <div className="pr-section">
          <h3>What is going back?</h3>

          {poId !== null && chosenOrder.loading && (
            <p style={{ color: 'var(--pr-muted)', fontSize: 13 }}>Reading the order…</p>
          )}

          {poId !== null && chosenOrder.data?.data && lines.length === 0 && (
            <button
              type="button"
              className="pr-btn pr-btn--primary"
              onClick={() => loadFromOrder(chosenOrder.data!.data)}
            >
              Load the lines from {chosenOrder.data.data.po_no}
            </button>
          )}

          {lines.length > 0 && (
            <div style={{ overflowX: 'auto' }}>
              <table className="pr-lines">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th style={{ width: 90 }}>Warehouse</th>
                    <th style={{ width: 110, textAlign: 'right' }}>May go back</th>
                    <th style={{ width: 110, textAlign: 'right' }}>Returning</th>
                    <th style={{ width: 110, textAlign: 'right' }}>Rate</th>
                    <th style={{ width: 110, textAlign: 'right' }}>Amount</th>
                    <th style={{ width: 40 }} />
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line, index) => {
                    const quantity = Number.parseFloat(line.return_qty) || 0
                    const invalid = line.eligible !== null && quantity > line.eligible

                    return (
                      <tr key={line.key} className={invalid ? 'is-invalid' : undefined}>
                        <td>
                          {line.po_line_id === null ? (
                            <input
                              value={line.item_id}
                              inputMode="numeric"
                              placeholder="Inventory item id"
                              aria-label={`Item on line ${index + 1}`}
                              onChange={(event) =>
                                setLines(lines.map((l) => (l.key === line.key ? { ...l, item_id: event.target.value } : l)))
                              }
                            />
                          ) : (
                            <>
                              {line.description}
                              {line.item_id !== '' && <span className="pr-cell-sub">Item {line.item_id}</span>}
                            </>
                          )}
                        </td>
                        <td>
                          <input
                            value={line.warehouse_id}
                            inputMode="numeric"
                            placeholder="—"
                            aria-label={`Warehouse on line ${index + 1}`}
                            onChange={(event) =>
                              setLines(lines.map((l) => (l.key === line.key ? { ...l, warehouse_id: event.target.value } : l)))
                            }
                          />
                        </td>
                        <td style={{ textAlign: 'right', color: 'var(--pr-muted)' }}>
                          {line.eligible === null ? '—' : line.eligible}
                        </td>
                        <td>
                          <input
                            className="is-numeric"
                            value={line.return_qty}
                            inputMode="decimal"
                            aria-label={`Quantity returning on line ${index + 1}`}
                            aria-invalid={invalid}
                            onChange={(event) =>
                              setLines(lines.map((l) => (l.key === line.key ? { ...l, return_qty: event.target.value } : l)))
                            }
                          />
                        </td>
                        <td>
                          <input
                            className="is-numeric"
                            value={line.rate}
                            inputMode="decimal"
                            aria-label={`Rate on line ${index + 1}`}
                            onChange={(event) =>
                              setLines(lines.map((l) => (l.key === line.key ? { ...l, rate: event.target.value } : l)))
                            }
                          />
                        </td>
                        <td style={{ textAlign: 'right', fontWeight: 600 }}>
                          {money(quantity * (Number.parseFloat(line.rate) || 0))}
                        </td>
                        <td>
                          <button
                            type="button"
                            className="pr-btn pr-btn--quiet pr-btn--small"
                            aria-label={`Remove line ${index + 1}`}
                            onClick={() => setLines(lines.filter((l) => l.key !== line.key))}
                          >
                            <Trash2 size={14} aria-hidden />
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}

          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12, flexWrap: 'wrap' }}>
            <button
              type="button"
              className="pr-btn pr-btn--small"
              onClick={() =>
                setLines([
                  ...lines,
                  {
                    key: `manual-${Date.now()}`,
                    po_line_id: null,
                    item_id: '',
                    warehouse_id: '',
                    description: '',
                    eligible: null,
                    return_qty: '',
                    rate: '',
                  },
                ])
              }
            >
              <Plus size={14} aria-hidden /> Add a line
            </button>

            <span style={{ flex: 1 }} />
            <span style={{ color: 'var(--pr-text-soft)', fontSize: 13 }}>Return value</span>
            <strong style={{ fontSize: 17, fontVariantNumeric: 'tabular-nums' }}>{money(total)}</strong>
          </div>

          {overEligible.length > 0 && (
            <Notice tone="danger">
              {overEligible.length === 1 ? 'One line is' : `${overEligible.length} lines are`} returning more than came
              in and has not already gone back. Inventory would refuse the movement.
            </Notice>
          )}

          {needWarehouse.length > 0 && (
            <Notice tone="warning">
              A stock line needs the warehouse it is going out of. Inventory cannot move goods from nowhere.
            </Notice>
          )}
        </div>
      )}

      {step === 3 && (
        <>
          <div className="pr-section">
            <h3>Why is it going back?</h3>
            <label className="pr-field">
              <span>Reason</span>
              <select value={reason} onChange={(event) => setReason(event.target.value)}>
                <option value="">Choose a reason…</option>
                {(options?.reasons ?? []).map((entry) => (
                  <option key={entry.value} value={entry.value}>
                    {entry.label}
                  </option>
                ))}
              </select>
              <span className="pr-field__hint">
                Required. It is what the analytics on this screen group by, which is why it is a list rather than a
                sentence.
              </span>
            </label>

            <label className="pr-field" style={{ marginTop: 12 }}>
              <span>Notes for the supplier (optional)</span>
              <textarea
                value={note}
                placeholder="Batch numbers, who agreed it, what was said on the phone…"
                onChange={(event) => setNote(event.target.value)}
              />
            </label>
          </div>

          <div className="pr-section">
            <h3>Review</h3>
            <dl className="pr-facts">
              <dt>Supplier</dt>
              <dd>{supplierName ?? `Account ${supplierId}`}</dd>
              <dt>Against</dt>
              <dd>{chosenOrder.data?.data?.po_no ?? 'No source document'}</dd>
              <dt>Date</dt>
              <dd>{shortDate(returnDate)}</dd>
              <dt>Lines</dt>
              <dd>{usableLines.length}</dd>
              <dt>Value</dt>
              <dd style={{ fontWeight: 650 }}>{money(total)}</dd>
            </dl>

            <Notice tone="plain">
              Creating this saves a DRAFT. Nothing reaches Inventory or Smart Books until it is approved and the goods
              are sent back, and each of those is its own step with its own button.
            </Notice>
          </div>
        </>
      )}
    </Drawer>
  )
}
