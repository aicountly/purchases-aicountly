import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { CatalogSupplier, PurchaseOrder } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { CommandStrip } from '../components/CommandStrip'
import { ItemPicker, SupplierPicker } from '../components/LivePicker'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, Select, StatusBadge, Textarea } from '../ui'

const STATUSES = ['', 'DRAFT', 'APPROVAL_PENDING', 'APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED', 'CANCELLED']

export function PurchaseOrderList() {
  const navigate = useNavigate()
  const { scope, can } = usePurchases()
  const [status, setStatus] = useState('')
  const [overdue, setOverdue] = useState(false)

  const { data, loading, error } = useApi(
    (signal) => api.list<PurchaseOrder>('v1/purchase-orders', { status: status || undefined, overdue: overdue ? 1 : undefined, limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id, status, overdue],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Purchase orders</h1>
        {can('po.create') && (
          <Button tone="primary" onClick={() => navigate('/purchase-orders/new')}>
            <Plus size={15} aria-hidden /> New purchase order
          </Button>
        )}
      </header>

      {error && <Notice tone="danger" title="Could not load purchase orders">{error}</Notice>}

      <Card
        title={`${data?.meta.total ?? 0} order${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
        action={
          <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem' }}>
              <input type="checkbox" checked={overdue} onChange={(e) => setOverdue(e.target.checked)} /> Overdue only
            </label>
            <Select value={status} onChange={(e) => setStatus(e.target.value)} style={{ width: '12rem' }}>
              {STATUSES.map((value) => (
                <option key={value} value={value}>{value === '' ? 'All statuses' : value.replace(/_/g, ' ')}</option>
              ))}
            </Select>
          </div>
        }
      >
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.po_id}
          onRowClick={(row) => navigate(`/purchase-orders/${row.po_id}`)}
          empty="No purchase orders yet."
          columns={[
            { key: 'no', header: 'Number', render: (row) => <Link to={`/purchase-orders/${row.po_id}`} onClick={(e) => e.stopPropagation()}>{row.po_no}</Link> },
            { key: 'date', header: 'Raised', render: (row) => date(row.po_date) },
            { key: 'supplier', header: 'Supplier', render: (row) => row.supplier_name_snapshot ?? `Account ${row.supplier_account_id}` },
            {
              key: 'promised',
              header: 'Promised',
              render: (row) => {
                const late =
                  row.promised_date !== null &&
                  new Date(row.promised_date) < new Date() &&
                  ['ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED'].includes(row.status)
                return <span style={{ color: late ? 'var(--danger)' : undefined }}>{date(row.promised_date)}</span>
              },
            },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
            { key: 'total', header: 'Total', numeric: true, render: (row) => money(row.total_amount, row.currency_code) },
          ]}
        />
      </Card>
    </div>
  )
}

interface ReceiptStatus {
  po_id: number
  status: string
  inventory_reachable: boolean
  inventory_documents: Array<Record<string, unknown>>
  lines: Array<{
    line_id: number
    line_no: number
    ordered_qty: number
    received_qty: number
    rejected_qty: number
    billed_qty: number
    outstanding_qty: number
  }>
}

export function PurchaseOrderDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { scope, can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [note, setNote] = useState('')
  const [cancelling, setCancelling] = useState(false)
  const [receiving, setReceiving] = useState(false)
  const [dcNo, setDcNo] = useState('')

  const { data, loading, reload } = useApi(
    (signal) => api.one<PurchaseOrder>(`v1/purchase-orders/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  // Fetched separately so a slow Inventory delays the receipt panel and not the
  // order itself. The order is ours and always renders.
  const live = useApi(
    (signal) => api.one<ReceiptStatus>(`v1/purchase-orders/${id}/receipt-status`, undefined, signal),
    [id, scope?.cmp_id, data?.data?.status],
    Boolean(scope && id && data),
  )

  async function act(path: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setActionError(null)
    try {
      await api.post(`v1/purchase-orders/${id}/${path}`, body)
      setNote('')
      reload()
      live.reload()
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>

  const po = data?.data
  if (!po) return <Notice tone="warning">That purchase order does not exist.</Notice>

  const pendingApprovals = po.approvals.filter((a) => a.status === 'PENDING')
  const canReceive = ['ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED'].includes(po.status)
  const receivable = po.lines.some((l) => Number(l.ordered_qty) > Number(l.received_qty))
  const billable = po.lines.some((l) => Number(l.received_qty) > Number(l.billed_qty))

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/purchase-orders" style={{ fontSize: '0.85rem' }}>← Purchase orders</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {po.po_no}
            <StatusBadge status={po.status} />
          </h1>
          <p style={{ margin: '0.3rem 0 0', color: 'var(--muted)' }}>
            {po.supplier_name_snapshot ?? `Account ${po.supplier_account_id}`} · {date(po.po_date)}
            {po.promised_date && ` · promised ${date(po.promised_date)}`}
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {po.status === 'DRAFT' && can('po.create') && (
            <Button tone="primary" disabled={busy} onClick={() => act('submit')}>Submit</Button>
          )}
          {po.status === 'APPROVED' && can('po.create') && (
            <Button tone="primary" disabled={busy} onClick={() => act('issue')}>Issue to supplier</Button>
          )}
          {po.status === 'ISSUED' && can('po.create') && (
            <Button disabled={busy} onClick={() => act('acknowledge')}>Supplier acknowledged</Button>
          )}
          {canReceive && receivable && can('receipt.request') && (
            <Button tone="primary" disabled={busy} onClick={() => setReceiving(true)}>Receive goods</Button>
          )}
          {billable && can('bill.enter') && (
            <Button onClick={() => navigate(`/bills/new?po_id=${po.po_id}`)}>Enter the bill</Button>
          )}
          {!['CANCELLED', 'CLOSED'].includes(po.status) && can('po.cancel') && (
            <Button tone="danger" disabled={busy} onClick={() => setCancelling(true)}>Cancel</Button>
          )}
        </div>
      </header>

      {actionError && <Notice tone="danger" title="That did not work">{actionError}</Notice>}

      <CommandStrip
        commands={po.commands}
        busy={busy}
        onRetry={(command) => {
          if (command.command_type === 'purchases.receipt.request') {
            const failed = po.receipts.find((r) => r.status === 'FAILED')
            if (failed) {
              setBusy(true)
              api
                .post(`v1/receipt-requests/${failed.request_id}/retry`, {})
                .then(() => {
                  reload()
                  live.reload()
                })
                .catch((err: Error) => setActionError(err.message))
                .finally(() => setBusy(false))
            }
          }
        }}
      />

      {pendingApprovals.length > 0 && (
        <Card title="Waiting for approval">
          <ul style={{ margin: '0 0 0.85rem', paddingLeft: '1.1rem' }}>
            {pendingApprovals.map((a) => <li key={a.approval_id}>{a.reason_detail ?? a.reason_kind}</li>)}
          </ul>
          {can('po.approve') && (
            <div style={{ display: 'grid', gap: '0.6rem' }}>
              <Field label="Note" hint="Required when rejecting."><Input value={note} onChange={(e) => setNote(e.target.value)} /></Field>
              <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
                <Button tone="danger" disabled={busy || note.trim() === ''} onClick={() => act('reject', { note: note.trim() })}>Reject</Button>
                <Button tone="primary" disabled={busy} onClick={() => act('approve', { note: note.trim() || undefined })}>Approve</Button>
              </div>
              <p style={{ color: 'var(--muted)', fontSize: '0.78rem', margin: 0 }}>
                A purchase order cannot be approved by the buyer who raised it.
              </p>
            </div>
          )}
        </Card>
      )}

      {receiving && (
        <Card title="Receive goods">
          <div style={{ display: 'grid', gap: '0.75rem' }}>
            <Notice tone="info">
              The goods receipt is recorded in Inventory, which owns the stock movement, the batch and the valuation.
              Purchases keeps the reference so you can find it.
            </Notice>
            <Field label="Supplier delivery challan number"><Input value={dcNo} onChange={(e) => setDcNo(e.target.value)} /></Field>
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setReceiving(false)}>Cancel</Button>
              <Button
                tone="primary"
                disabled={busy}
                onClick={async () => {
                  await act('receive', { supplier_dc_no: dcNo.trim() || undefined })
                  setReceiving(false)
                  setDcNo('')
                }}
              >
                Receive everything outstanding
              </Button>
            </div>
          </div>
        </Card>
      )}

      {cancelling && (
        <Card title="Cancel this purchase order">
          <div style={{ display: 'grid', gap: '0.6rem' }}>
            <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Reason for cancellation" />
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setCancelling(false)}>Keep the order</Button>
              <Button
                tone="danger"
                disabled={busy || note.trim() === ''}
                onClick={async () => {
                  await act('cancel', { reason: note.trim() })
                  setCancelling(false)
                }}
              >
                Cancel order
              </Button>
            </div>
          </div>
        </Card>
      )}

      {live.data && !live.data.data.inventory_reachable && (
        <Notice tone="warning" title="Inventory did not answer">
          The receipts recorded against this order could not be read. The order's own figures below are unaffected.
        </Notice>
      )}

      <Card title="Lines">
        <DataTable
          rows={po.lines}
          rowKey={(line) => line.line_id}
          columns={[
            { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
            {
              key: 'item',
              header: 'Item',
              render: (line) =>
                line.is_service ? (
                  line.description ?? 'Service'
                ) : (
                  <span>
                    {line.description ?? `Item #${line.item_id}`}
                    <span style={{ color: 'var(--muted)', fontSize: '0.78rem', display: 'block' }}>Inventory item {line.item_id}</span>
                  </span>
                ),
            },
            { key: 'ordered', header: 'Ordered', numeric: true, render: (line) => qty(line.ordered_qty) },
            { key: 'received', header: 'Received', numeric: true, render: (line) => qty(line.received_qty) },
            {
              key: 'rejected',
              header: 'Rejected',
              numeric: true,
              render: (line) => (
                <span style={{ color: Number(line.rejected_qty) > 0 ? 'var(--danger)' : undefined }}>{qty(line.rejected_qty)}</span>
              ),
            },
            { key: 'billed', header: 'Billed', numeric: true, render: (line) => qty(line.billed_qty) },
            { key: 'rate', header: 'Agreed rate', numeric: true, render: (line) => money(line.agreed_rate, po.currency_code) },
            { key: 'amount', header: 'Amount', numeric: true, render: (line) => money(line.line_amount, po.currency_code) },
          ]}
        />

        <dl style={{ display: 'grid', gridTemplateColumns: 'auto auto', gap: '0.3rem 1.5rem', justifyContent: 'end', marginTop: '1rem', marginBottom: 0 }}>
          <dt style={{ color: 'var(--muted)' }}>Subtotal</dt>
          <dd className="num" style={{ margin: 0 }}>{money(po.subtotal_amount, po.currency_code)}</dd>
          {Number(po.freight_amount) > 0 && (
            <>
              <dt style={{ color: 'var(--muted)' }}>Freight</dt>
              <dd className="num" style={{ margin: 0 }}>{money(po.freight_amount, po.currency_code)}</dd>
            </>
          )}
          <dt style={{ color: 'var(--muted)' }}>Estimated tax</dt>
          <dd className="num" style={{ margin: 0 }}>{money(po.estimated_tax_amount, po.currency_code)}</dd>
          <dt style={{ fontWeight: 600 }}>Total</dt>
          <dd className="num" style={{ margin: 0, fontWeight: 600 }}>{money(po.total_amount, po.currency_code)}</dd>
        </dl>
        <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
          Tax shown here is an estimate. Smart Books computes the input tax actually claimed when the bill is posted.
        </p>
      </Card>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(20rem, 1fr))', gap: '1rem' }}>
        <Card title="Receipts">
          <DataTable
            rows={po.receipts}
            rowKey={(row) => row.request_id}
            empty="Nothing received yet."
            columns={[
              { key: 'date', header: 'Received', render: (row) => date(row.received_at) },
              { key: 'dc', header: 'Supplier DC', render: (row) => row.supplier_dc_no ?? '—' },
              { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
              { key: 'grn', header: 'GRN in Inventory', render: (row) => row.inventory_document_no ?? row.inventory_document_uuid ?? '—' },
            ]}
          />
          <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.6rem', marginBottom: 0 }}>
            The GRN itself lives in Inventory. Purchases keeps the reference, not a copy.
          </p>
        </Card>

        <Card title="Bills">
          <DataTable
            rows={po.bills}
            rowKey={(row) => row.request_id}
            empty="Nothing billed yet."
            columns={[
              { key: 'no', header: 'Invoice', render: (row) => <Link to={`/bills/${row.request_id}`}>{row.supplier_invoice_no ?? `#${row.request_id}`}</Link> },
              { key: 'date', header: 'Dated', render: (row) => date(row.supplier_invoice_date) },
              { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
              { key: 'voucher', header: 'Books voucher', render: (row) => row.books_voucher_no ?? (row.books_voucher_id ? `#${row.books_voucher_id}` : '—') },
            ]}
          />
        </Card>
      </div>
    </div>
  )
}

interface DraftLine {
  key: string
  item_id: number | null
  item_label: string
  unit_id: number | null
  is_service: boolean
  description: string
  ordered_qty: string
  agreed_rate: string
  estimated_tax_pc: string
}

function emptyLine(): DraftLine {
  return {
    key: Math.random().toString(36).slice(2),
    item_id: null,
    item_label: '',
    unit_id: null,
    is_service: false,
    description: '',
    ordered_qty: '1',
    agreed_rate: '0',
    estimated_tax_pc: '18',
  }
}

export function PurchaseOrderEditor() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [supplier, setSupplier] = useState<{ id: number; name: string; status?: string } | null>(null)
  const [poDate, setPoDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [promisedDate, setPromisedDate] = useState('')
  const [paymentTerms, setPaymentTerms] = useState('')
  const [freight, setFreight] = useState('0')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function patchLine(key: string, patch: Partial<DraftLine>) {
    setLines((c) => c.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  }

  const subtotal = lines.reduce((sum, l) => sum + Number(l.ordered_qty || 0) * Number(l.agreed_rate || 0), 0)
  const tax = lines.reduce((sum, l) => sum + (Number(l.ordered_qty || 0) * Number(l.agreed_rate || 0) * Number(l.estimated_tax_pc || 0)) / 100, 0)

  async function save() {
    if (!supplier) {
      setError('Choose a supplier first.')
      return
    }
    setSaving(true)
    setError(null)
    try {
      const response = await api.post<PurchaseOrder>('v1/purchase-orders', {
        supplier_account_id: supplier.id,
        supplier_name: supplier.name,
        po_date: poDate,
        promised_date: promisedDate || undefined,
        payment_terms: paymentTerms || undefined,
        freight_amount: Number(freight || 0),
        notes: notes || undefined,
        rfq_id: searchParams.get('rfq_id') ? Number(searchParams.get('rfq_id')) : undefined,
        requisition_id: searchParams.get('requisition_id') ? Number(searchParams.get('requisition_id')) : undefined,
        lines: lines
          .filter((l) => l.is_service || l.item_id)
          .map((l) => ({
            item_id: l.item_id,
            unit_id: l.unit_id,
            is_service: l.is_service,
            description: l.description || undefined,
            ordered_qty: Number(l.ordered_qty || 0),
            agreed_rate: Number(l.agreed_rate || 0),
            estimated_tax_pc: Number(l.estimated_tax_pc || 0),
          })),
      })
      navigate(`/purchase-orders/${response.data.po_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '64rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>New purchase order</h1>
      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}

      <Card title="Supplier and terms">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.85rem' }}>
          <SupplierPicker
            selectedLabel={supplier?.name}
            onPick={(picked: CatalogSupplier) =>
              setSupplier({
                id: picked.acc_id,
                name: picked.acc_name,
                status: picked.procurement_profile?.qualification_status,
              })
            }
          />
          <Field label="Order date"><Input type="date" value={poDate} onChange={(e) => setPoDate(e.target.value)} /></Field>
          <Field label="Promised delivery"><Input type="date" value={promisedDate} onChange={(e) => setPromisedDate(e.target.value)} /></Field>
          <Field label="Payment terms"><Input value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} placeholder="30 days" /></Field>
          <Field label="Freight"><Input value={freight} inputMode="decimal" onChange={(e) => setFreight(e.target.value)} /></Field>
        </div>
        {supplier && supplier.status !== 'approved' && (
          <div style={{ marginTop: '0.85rem' }}>
            <Notice tone="warning" title="This supplier is not approved">
              {supplier.status
                ? `Their procurement profile says "${supplier.status.replace(/_/g, ' ')}".`
                : 'They have no procurement profile yet.'}{' '}
              If this company only orders from approved suppliers, saving will be refused.
            </Notice>
          </div>
        )}
      </Card>

      <Card title="Lines" action={<Button onClick={() => setLines((c) => [...c, emptyLine()])}>Add line</Button>}>
        <div style={{ display: 'grid', gap: '0.85rem' }}>
          {lines.map((line) => (
            <div key={line.key} style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', padding: '0.75rem', display: 'grid', gap: '0.6rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.85rem' }}>
                  <input type="checkbox" checked={line.is_service} onChange={(e) => patchLine(line.key, { is_service: e.target.checked, item_id: null, item_label: '' })} />
                  Service line (no stock item)
                </label>
                <Button tone="ghost" onClick={() => setLines((c) => (c.length > 1 ? c.filter((l) => l.key !== line.key) : c))}>
                  <Trash2 size={14} aria-hidden />
                </Button>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(9rem, 1fr))', gap: '0.6rem', alignItems: 'end' }}>
                {line.is_service ? (
                  <div style={{ gridColumn: 'span 2' }}>
                    <Field label="Description"><Input value={line.description} onChange={(e) => patchLine(line.key, { description: e.target.value })} /></Field>
                  </div>
                ) : (
                  <div style={{ gridColumn: 'span 2' }}>
                    <ItemPicker
                      selectedLabel={line.item_label || null}
                      onPick={(item) => patchLine(line.key, { item_id: item.item_id, item_label: item.item_name, unit_id: item.unit_id, description: item.item_name })}
                    />
                  </div>
                )}
                <Field label="Quantity"><Input value={line.ordered_qty} inputMode="decimal" onChange={(e) => patchLine(line.key, { ordered_qty: e.target.value })} /></Field>
                <Field label="Agreed rate"><Input value={line.agreed_rate} inputMode="decimal" onChange={(e) => patchLine(line.key, { agreed_rate: e.target.value })} /></Field>
                <Field label="Tax %" hint="Estimate only"><Input value={line.estimated_tax_pc} inputMode="decimal" onChange={(e) => patchLine(line.key, { estimated_tax_pc: e.target.value })} /></Field>
                <div style={{ textAlign: 'right', paddingBottom: '0.35rem' }}>
                  <div style={{ fontSize: '0.78rem', color: 'var(--muted)' }}>Line</div>
                  <div className="num" style={{ fontWeight: 600 }}>{money(Number(line.ordered_qty || 0) * Number(line.agreed_rate || 0))}</div>
                </div>
              </div>
            </div>
          ))}
        </div>

        <dl style={{ display: 'grid', gridTemplateColumns: 'auto auto', gap: '0.3rem 1.5rem', justifyContent: 'end', marginTop: '1rem', marginBottom: 0 }}>
          <dt style={{ color: 'var(--muted)' }}>Subtotal</dt>
          <dd className="num" style={{ margin: 0 }}>{money(subtotal)}</dd>
          <dt style={{ color: 'var(--muted)' }}>Freight</dt>
          <dd className="num" style={{ margin: 0 }}>{money(Number(freight || 0))}</dd>
          <dt style={{ color: 'var(--muted)' }}>Estimated tax</dt>
          <dd className="num" style={{ margin: 0 }}>{money(tax)}</dd>
          <dt style={{ fontWeight: 600 }}>Total</dt>
          <dd className="num" style={{ margin: 0, fontWeight: 600 }}>{money(subtotal + Number(freight || 0) + tax)}</dd>
        </dl>
      </Card>

      <Card title="Notes">
        <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Anything the supplier should see on the order." />
      </Card>

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button tone="primary" disabled={saving} onClick={save}>{saving ? 'Saving…' : 'Save purchase order'}</Button>
      </div>
    </div>
  )
}
