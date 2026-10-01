import { useState } from 'react'
import { Download } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import type { PoCommunication, PurchaseOrder, ReceiptAppliedLine, ReceiptRequest } from '../../services/types'
import { useWarehouses } from '../../hooks/useWarehouses'
import { Button, Card, date, Field, Input, Notice, qty, Select, Textarea } from '../../ui'

type Run = (path: string, body?: Record<string, unknown>) => Promise<boolean>

interface ReceiptDraftLine {
  line_id: number
  line_no: number
  label: string
  outstanding: number
  qty: string
  rejected_qty: string
  rejection_reason: string
  warehouse_id: string
  batch_no: string
  serials: string
  inspection_note: string
}

function newToken(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `grn-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

/**
 * A delivery, line by line: what arrived, what was turned away and why, where it went, and the
 * batch or serials it came with. Each delivery is its own GRN in Inventory; the form carries one
 * token, so pressing Save twice (or a retried request) records it once.
 */
export function ReceiveGoodsPanel({
  po,
  onDone,
  onCancel,
}: {
  po: PurchaseOrder
  onDone: () => void
  onCancel: () => void
}) {
  const warehouses = useWarehouses(true)
  const [token] = useState(newToken)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [dcNo, setDcNo] = useState('')
  const [dcDate, setDcDate] = useState('')
  const [vehicle, setVehicle] = useState('')
  const [note, setNote] = useState('')
  const [overReason, setOverReason] = useState('')
  const progress = new Map(po.progress.lines.map((l) => [l.line_id, l]))
  const [lines, setLines] = useState<ReceiptDraftLine[]>(() =>
    po.lines
      .filter((l) => !l.is_service && l.item_id !== null && (progress.get(l.line_id)?.to_receive_qty ?? 0) > 0)
      .map((l) => {
        const outstanding = progress.get(l.line_id)?.to_receive_qty ?? 0
        return {
          line_id: l.line_id,
          line_no: l.line_no,
          label: l.description ?? `Item #${l.item_id}`,
          outstanding,
          qty: String(outstanding),
          rejected_qty: '',
          rejection_reason: '',
          warehouse_id: String(l.warehouse_id ?? po.delivery_warehouse_id ?? ''),
          batch_no: '',
          serials: '',
          inspection_note: '',
        }
      }),
  )

  const over = lines.some((l) => Number(l.qty || 0) > l.outstanding)
  const set = (lineId: number, patch: Partial<ReceiptDraftLine>) =>
    setLines((current) => current.map((l) => (l.line_id === lineId ? { ...l, ...patch } : l)))

  async function save() {
    setBusy(true)
    setError(null)
    try {
      await api.post(`v1/purchase-orders/${po.po_id}/receive`, {
        client_token: token,
        received_at: new Date().toISOString().slice(0, 10),
        supplier_dc_no: dcNo.trim() || undefined,
        supplier_dc_date: dcDate || undefined,
        vehicle_no: vehicle.trim() || undefined,
        inspection_note: note.trim() || undefined,
        over_receipt_reason: over ? overReason.trim() || undefined : undefined,
        lines: lines
          .filter((l) => Number(l.qty || 0) > 0 || Number(l.rejected_qty || 0) > 0)
          .map((l) => ({
            line_id: l.line_id,
            qty: Number(l.qty || 0),
            rejected_qty: Number(l.rejected_qty || 0) || undefined,
            rejection_reason: l.rejection_reason.trim() || undefined,
            warehouse_id: l.warehouse_id ? Number(l.warehouse_id) : undefined,
            batch_no: l.batch_no.trim() || undefined,
            serials: l.serials.trim() ? l.serials.split(/[\s,]+/).filter(Boolean) : undefined,
            inspection_note: l.inspection_note.trim() || undefined,
          })),
      })
      onDone()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card title="Receive goods">
      <div style={{ display: 'grid', gap: '0.85rem' }}>
        <Notice tone="info">
          {po.grn_stock_effect === 'physical'
            ? "This delivery is recorded in Inventory as its own goods receipt (GRN): the goods are in stock from now, at the order's rate until the supplier's bill trues the cost up. The bill will not receive them again."
            : "This delivery is recorded in Inventory as its own goods receipt (GRN). The goods wait on Inventory's pending register and go into stock when the supplier's bill is posted — once, from this receipt."}
        </Notice>
        {error && <Notice tone="danger" title="The delivery was not recorded" onDismiss={() => setError(null)}>{error}</Notice>}

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.6rem' }}>
          <Field label="Supplier delivery challan no."><Input value={dcNo} onChange={(e) => setDcNo(e.target.value)} /></Field>
          <Field label="Challan date"><Input type="date" value={dcDate} onChange={(e) => setDcDate(e.target.value)} /></Field>
          <Field label="Vehicle no."><Input value={vehicle} onChange={(e) => setVehicle(e.target.value)} /></Field>
        </div>

        {lines.length === 0 && <Notice tone="warning">Nothing on this order is waiting to be received.</Notice>}
        {lines.map((l) => (
          <fieldset key={l.line_id} style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', padding: '0.65rem 0.8rem', margin: 0 }}>
            <legend style={{ fontSize: '0.85rem', fontWeight: 600, padding: '0 0.3rem' }}>
              {l.line_no}. {l.label} <span style={{ color: 'var(--muted)', fontWeight: 400 }}>· {qty(l.outstanding)} outstanding</span>
            </legend>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(9rem, 1fr))', gap: '0.55rem' }}>
              <Field label="Accepted"><Input type="number" min="0" step="any" value={l.qty} onChange={(e) => set(l.line_id, { qty: e.target.value })} /></Field>
              <Field label="Rejected at the gate"><Input type="number" min="0" step="any" value={l.rejected_qty} onChange={(e) => set(l.line_id, { rejected_qty: e.target.value })} /></Field>
              <Field label="Warehouse">
                <Select value={l.warehouse_id} onChange={(e) => set(l.line_id, { warehouse_id: e.target.value })}>
                  <option value="">As ordered</option>
                  {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                </Select>
              </Field>
              <Field label="Batch"><Input value={l.batch_no} onChange={(e) => set(l.line_id, { batch_no: e.target.value })} /></Field>
            </div>
            {Number(l.rejected_qty || 0) > 0 && (
              <Field label="Why was it rejected?"><Input value={l.rejection_reason} onChange={(e) => set(l.line_id, { rejection_reason: e.target.value })} /></Field>
            )}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(14rem, 1fr))', gap: '0.55rem', marginTop: '0.4rem' }}>
              <Field label="Serial numbers" hint="For an item Inventory tracks by serial: one per accepted unit in the item's base unit (a box of 10 needs 10), separated by spaces or commas. Each is registered in Inventory with the receipt; a unit already in stock cannot arrive again.">
                <Input value={l.serials} onChange={(e) => set(l.line_id, { serials: e.target.value })} />
              </Field>
              <Field label="Inspection note"><Input value={l.inspection_note} onChange={(e) => set(l.line_id, { inspection_note: e.target.value })} /></Field>
            </div>
          </fieldset>
        ))}

        {over && (
          <Field label="Reason for receiving more than was ordered" hint="Beyond the company's tolerance, only someone allowed to accept extra goods can do this, and the reason is kept on the GRN.">
            <Input value={overReason} onChange={(e) => setOverReason(e.target.value)} />
          </Field>
        )}
        <Field label="Note for the whole delivery"><Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} /></Field>

        <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
          <Button onClick={onCancel} disabled={busy}>Cancel</Button>
          <Button tone="primary" disabled={busy || lines.length === 0} onClick={save}>Record this delivery</Button>
        </div>
      </div>
    </Card>
  )
}

const CHANNELS: Array<[string, string]> = [
  ['email', 'Email'],
  ['whatsapp', 'WhatsApp'],
  ['connect', 'Aicountly Connect'],
  ['courier', 'Courier'],
  ['hand_delivered', 'Handed over'],
  ['other', 'Other'],
]
const SOURCES: Array<[string, string]> = [
  ['supplier_email', "Supplier's email"],
  ['phone', 'Phone call'],
  ['supplier_document', "Supplier's own document"],
  ['in_person', 'In person'],
  ['other', 'Other'],
]

/**
 * Prepared, sent, acknowledged — three facts, each shown with who, when and how. Nothing is sent
 * from here: the buyer sends the document and records how; the supplier's acknowledgement is
 * recorded with where it came from and the evidence.
 */
export function OrderCommunicationsPanel({ po, can, run, busy }: { po: PurchaseOrder; can: (p: string) => boolean; run: Run; busy: boolean }) {
  const [mode, setMode] = useState<'none' | 'sent' | 'ack'>('none')
  const [channel, setChannel] = useState('email')
  const [recipient, setRecipient] = useState('')
  const [reference, setReference] = useState('')
  const [source, setSource] = useState('supplier_email')
  const [evidence, setEvidence] = useState('')
  const [promised, setPromised] = useState('')
  const [downloadError, setDownloadError] = useState<string | null>(null)
  const released = ['ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED'].includes(po.status)
  const hasDocument = !['DRAFT', 'APPROVAL_PENDING', 'REJECTED'].includes(po.status)
  const wasSent = po.communications.some((c) => c.kind === 'sent')

  async function download() {
    setDownloadError(null)
    try {
      await api.download(`v1/purchase-orders/${po.po_id}/document`, `${po.po_no.replace(/[^A-Za-z0-9._-]+/g, '-')}.pdf`)
    } catch (err) {
      setDownloadError(err instanceof ApiError ? err.message : String(err))
    }
  }

  return (
    <Card
      title="With the supplier"
      action={
        hasDocument && can('po.view') ? (
          <Button onClick={download}>
            <Download size={14} aria-hidden /> Purchase order PDF
          </Button>
        ) : undefined
      }
    >
      <div style={{ display: 'grid', gap: '0.75rem' }}>
        {downloadError && <Notice tone="danger" onDismiss={() => setDownloadError(null)}>{downloadError}</Notice>}
        <Timeline items={po.communications} />

        {released && can('po.create') && mode === 'none' && (
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            <Button onClick={() => setMode('sent')}>Record how it was sent</Button>
            {wasSent && po.status !== 'CLOSED' && <Button onClick={() => setMode('ack')}>Record the supplier's acknowledgement</Button>}
          </div>
        )}

        {mode === 'sent' && (
          <div style={{ display: 'grid', gap: '0.6rem' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.6rem' }}>
              <Field label="How"><Select value={channel} onChange={(e) => setChannel(e.target.value)}>{CHANNELS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
              <Field label="To whom" hint="Their email, number or name."><Input value={recipient} onChange={(e) => setRecipient(e.target.value)} /></Field>
            </div>
            <Field label="Reference (optional)" hint="The email's subject, a courier docket number."><Input value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setMode('none')}>Cancel</Button>
              <Button
                tone="primary"
                disabled={busy || recipient.trim() === ''}
                onClick={async () => {
                  if (await run('sent', { channel, recipient: recipient.trim(), reference: reference.trim() || undefined })) setMode('none')
                }}
              >
                Record as sent
              </Button>
            </div>
          </div>
        )}

        {mode === 'ack' && (
          <div style={{ display: 'grid', gap: '0.6rem' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.6rem' }}>
              <Field label="How they confirmed"><Select value={source} onChange={(e) => setSource(e.target.value)}>{SOURCES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
              <Field label="Promised delivery (optional)"><Input type="date" value={promised} onChange={(e) => setPromised(e.target.value)} /></Field>
            </div>
            <Field label="Evidence" hint="Their email's subject and time, who you spoke to, their document number.">
              <Input value={evidence} onChange={(e) => setEvidence(e.target.value)} />
            </Field>
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setMode('none')}>Cancel</Button>
              <Button
                tone="primary"
                disabled={busy || evidence.trim() === ''}
                onClick={async () => {
                  if (await run('acknowledge', { source, evidence: evidence.trim(), promised_date: promised || undefined })) setMode('none')
                }}
              >
                Record acknowledgement
              </Button>
            </div>
          </div>
        )}
      </div>
    </Card>
  )
}

function Timeline({ items }: { items: PoCommunication[] }) {
  if (items.length === 0) {
    return <p style={{ margin: 0, color: 'var(--muted)', fontSize: '0.85rem' }}>Not yet prepared, sent or acknowledged.</p>
  }
  const label = (c: PoCommunication) =>
    c.kind === 'prepared'
      ? 'Document prepared'
      : c.kind === 'sent'
        ? `Sent by ${CHANNELS.find(([v]) => v === c.channel)?.[1] ?? c.channel} to ${c.recipient}`
        : `Acknowledged — ${SOURCES.find(([v]) => v === c.source)?.[1] ?? c.source}`

  return (
    <ol style={{ margin: 0, paddingLeft: '1.1rem', display: 'grid', gap: '0.35rem' }}>
      {items.map((c) => (
        <li key={c.communication_id} style={{ fontSize: '0.88rem' }}>
          <strong>{label(c)}</strong> <span style={{ color: 'var(--muted)' }}>· {date(c.occurred_on ?? c.created_at)}</span>
          {c.evidence && <span style={{ display: 'block', color: 'var(--muted)', fontSize: '0.8rem' }}>{c.evidence}</span>}
        </li>
      ))}
    </ol>
  )
}

/** What will never come: recorded with a reason, by someone allowed to. The rest of the order stands. */
export function ShortClosePanel({ po, run, busy, onCancel }: { po: PurchaseOrder; run: Run; busy: boolean; onCancel: () => void }) {
  const [reason, setReason] = useState('')
  const remaining = po.progress.lines.filter((l) => l.is_stock && l.to_receive_qty > 0)

  return (
    <Card title="Short-close what will not be delivered">
      <div style={{ display: 'grid', gap: '0.6rem' }}>
        <p style={{ margin: 0, fontSize: '0.88rem' }}>
          {remaining.map((l) => `line ${l.line_no}: ${qty(l.to_receive_qty)}`).join(', ') || 'Nothing is outstanding.'} will be recorded as never coming.
          What already arrived still has to be billed before the order closes.
        </p>
        <Field label="Why"><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Supplier discontinued the item" /></Field>
        <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
          <Button onClick={onCancel}>Keep waiting</Button>
          <Button tone="danger" disabled={busy || reason.trim() === '' || remaining.length === 0} onClick={async () => { if (await run('short-close', { reason: reason.trim() })) onCancel() }}>
            Short-close
          </Button>
        </div>
      </div>
    </Card>
  )
}

/** A JSON column as the API hands it back: decoded already, or a string. */
function jsonList<T>(raw: T[] | string | null | undefined): T[] {
  if (Array.isArray(raw)) return raw
  if (typeof raw === 'string' && raw !== '') {
    try {
      const decoded = JSON.parse(raw) as unknown
      return Array.isArray(decoded) ? (decoded as T[]) : []
    } catch {
      return []
    }
  }
  return []
}

/**
 * A GRN no bill has settled, undone through Inventory (C10):
 *
 *   reverse  recorded by mistake — the order counts it as never received (its rejections too)
 *   return   goods accepted and then sent back before the bill — all of them, or some; the
 *            order counts them as rejected, still owed by the supplier
 *
 * Goods a bill has settled go back on a purchase return (debit note) instead; the server refuses
 * this for them, before Inventory is asked.
 */
export function ReceiptReturnPanel({
  po,
  receipt,
  mode,
  run,
  busy,
  onCancel,
}: {
  po: PurchaseOrder
  receipt: ReceiptRequest
  mode: 'return' | 'reverse'
  run: (fullPath: string, body?: Record<string, unknown>) => Promise<boolean>
  busy: boolean
  onCancel: () => void
}) {
  const [reason, setReason] = useState('')
  const labels = new Map(po.lines.map((l) => [l.line_id, `Line ${l.line_no} · ${l.description ?? `Item #${l.item_id}`}`]))
  // One row per order line on the GRN: a line delivered into two warehouses is one figure here.
  const onReceipt = new Map<number, number>()
  for (const l of jsonList<ReceiptAppliedLine>(receipt.applied_lines ?? null)) {
    onReceipt.set(l.line_id, (onReceipt.get(l.line_id) ?? 0) + Number(l.qty || 0))
  }
  const lines = [...onReceipt.entries()].filter(([, q]) => q > 0)
  const [back, setBack] = useState<Record<number, string>>(() => Object.fromEntries(lines.map(([id, q]) => [id, String(q)])))

  const asked = lines.map(([id, q]) => ({ line_id: id, held: q, qty: Number(back[id] || 0) }))
  const invalid = asked.some((l) => l.qty < 0 || l.qty > l.held)
  const all = asked.every((l) => l.qty === l.held)
  const nothing = asked.every((l) => l.qty === 0)
  const grn = receipt.receipt_no ?? `#${receipt.request_id}`

  async function submit() {
    const path = `v1/receipt-requests/${receipt.request_id}/${mode === 'reverse' ? 'reverse' : 'return'}`
    const body: Record<string, unknown> = { reason: reason.trim() }
    if (mode === 'return' && !all) body.lines = asked.filter((l) => l.qty > 0).map((l) => ({ line_id: l.line_id, qty: l.qty }))
    if (await run(path, body)) onCancel()
  }

  return (
    <Card title={mode === 'reverse' ? `Reverse ${grn}` : `Give back goods from ${grn}`}>
      <div style={{ display: 'grid', gap: '0.7rem' }}>
        <Notice tone="info">
          {mode === 'reverse'
            ? 'For a GRN recorded by mistake. Inventory reverses it — the goods leave the warehouse they were received into — and this order counts them, and anything rejected on it, as never received.'
            : 'For goods accepted and then sent back before the supplier billed them. Inventory takes them out of the warehouse they were received into; the order counts them as rejected and still owed. Give back part of the GRN and Inventory restates it for what you keep — only that can be billed.'}
          {' '}Goods a bill has already settled go back on a purchase return instead.
        </Notice>
        {mode === 'return' && (
          <div style={{ display: 'grid', gap: '0.45rem' }}>
            {lines.map(([id, held]) => (
              <Field key={id} label={labels.get(id) ?? `Order line ${id}`} hint={`${qty(held)} on this GRN`}>
                <Input type="number" min={0} max={held} step="any" value={back[id] ?? ''} onChange={(e) => setBack((b) => ({ ...b, [id]: e.target.value }))} />
              </Field>
            ))}
            {invalid && <Notice tone="warning">A line cannot give back more than the GRN holds of it.</Notice>}
          </div>
        )}
        <Field label="Why"><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={mode === 'reverse' ? 'Keyed against the wrong order' : 'Rejected at inspection after the GRN'} /></Field>
        <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
          <Button onClick={onCancel}>Keep the GRN</Button>
          <Button tone="danger" disabled={busy || reason.trim() === '' || (mode === 'return' && (invalid || nothing))} onClick={() => void submit()}>
            {mode === 'reverse' ? 'Reverse GRN' : all ? 'Give back everything' : 'Give back these goods'}
          </Button>
        </div>
      </div>
    </Card>
  )
}
