import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { BillRequest, CatalogSupplier, PurchaseOrder } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useWarehouses } from '../hooks/useWarehouses'
import { useUrlFilter, useUrlId } from '../hooks/useUrlFilter'
import { usePurchases } from '../context/PurchasesContext'
import { CommandStrip } from '../components/CommandStrip'
import { ItemPicker, LedgerPicker, SupplierPicker } from '../components/LivePicker'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, Select, StatusBadge, Textarea } from '../ui'
import { DiscussInConnect } from '../components/ConnectEmbed'

const STATUSES = ['', 'DRAFT', 'MATCHING', 'MATCHED', 'EXCEPTION', 'POSTING', 'UNCERTAIN', 'POSTED', 'FAILED', 'BLOCKED', 'CANCELLED']

export function BillsList() {
  const navigate = useNavigate()
  const { scope, can } = usePurchases()
  const [searchParams] = useSearchParams()
  // `exceptions=1` is the older spelling of the same thing and still works.
  const [urlStatus, setStatus] = useUrlFilter('status')
  const status = urlStatus === '' && searchParams.get('exceptions') === '1' ? 'EXCEPTION' : urlStatus
  const supplierId = useUrlId('supplier_id')

  const { data, loading, error } = useApi(
    (signal) =>
      api.list<BillRequest>(
        'v1/bills',
        { status: status || undefined, supplier_account_id: supplierId, limit: 100 },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, status, supplierId],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Supplier bills</h1>
        {can('bill.enter') && <Button tone="primary" onClick={() => navigate('/bills/new')}>Bill without an order</Button>}
      </header>

      <Notice tone="info">
        Every bill is checked against the purchase order and what Inventory says actually arrived, before anything
        reaches Smart Books. The three documents stay in the three products — this screen compares them, it does not
        copy them.
      </Notice>

      {error && <Notice tone="danger" title="Could not load bills">{error}</Notice>}

      <Card
        title={`${data?.meta.total ?? 0} bill${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
        action={
          <Select value={status} onChange={(e) => setStatus(e.target.value)} style={{ width: '12rem' }}>
            {STATUSES.map((value) => (
              <option key={value} value={value}>{value === '' ? 'All statuses' : value.replace(/_/g, ' ')}</option>
            ))}
          </Select>
        }
      >
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.request_id}
          onRowClick={(row) => navigate(`/bills/${row.request_id}`)}
          empty="No bills entered."
          columns={[
            {
              key: 'no',
              header: 'Supplier invoice',
              render: (row) => (
                <Link to={`/bills/${row.request_id}`} onClick={(e) => e.stopPropagation()}>
                  {row.supplier_invoice_no ?? `#${row.request_id}`}
                </Link>
              ),
            },
            { key: 'date', header: 'Dated', render: (row) => date(row.supplier_invoice_date) },
            { key: 'supplier', header: 'Supplier', render: (row) => `Account ${row.supplier_account_id}` },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
            { key: 'voucher', header: 'Books voucher', render: (row) => row.books_voucher_no ?? '—' },
          ]}
        />
      </Card>
    </div>
  )
}

const VERDICT_TONE: Record<string, 'success' | 'warning' | 'danger'> = {
  MATCHED: 'success',
  WITHIN_TOLERANCE: 'success',
  REVIEW_REQUIRED: 'warning',
  BLOCKED: 'danger',
}

const VERDICT_MESSAGE: Record<string, string> = {
  MATCHED: 'The bill agrees with the order and with what arrived.',
  WITHIN_TOLERANCE: 'There are variances, but all of them are inside this company’s agreed tolerances.',
  REVIEW_REQUIRED: 'Something could not be checked. Look at it before posting.',
  BLOCKED: 'The bill does not agree with the order or with what arrived. It cannot be posted until this is resolved.',
}

export function BillDetail() {
  const { id } = useParams<{ id: string }>()
  const { scope, can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [decisionNote, setDecisionNote] = useState<Record<number, string>>({})
  const [panel, setPanel] = useState<'none' | 'revise' | 'cancel'>('none')
  const [reviseDate, setReviseDate] = useState('')
  const [revisePosting, setRevisePosting] = useState('')
  const [reviseNote, setReviseNote] = useState('')
  const [cancelReason, setCancelReason] = useState('')

  const { data, loading, reload } = useApi(
    (signal) => api.one<BillRequest>(`v1/bills/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  async function act(path: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setError(null)
    try {
      await api.post(path, body)
      reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>

  const bill = data?.data
  if (!bill) return <Notice tone="warning">That bill does not exist.</Notice>

  const latest = bill.matches[0]
  const openExceptions = latest?.exceptions.filter((e) => e.status === 'OPEN') ?? []

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/bills" style={{ fontSize: '0.85rem' }}>← Supplier bills</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {bill.supplier_invoice_no ?? `Bill #${bill.request_id}`}
            <StatusBadge status={bill.status} />
          </h1>
          <p style={{ margin: '0.3rem 0 0', color: 'var(--muted)' }}>
            {bill.bill_kind === 'service' ? 'Service bill' : bill.bill_kind === 'direct' ? 'Direct purchase' : 'Against an order'} · Account {bill.supplier_account_id}
            {bill.supplier_invoice_date && ` · dated ${date(bill.supplier_invoice_date)}`}
            {bill.posting_date && ` · booked ${date(bill.posting_date)}`}
            {bill.due_date && ` · due ${date(bill.due_date)}`}
            {bill.po_id && (
              <>
                {' · against '}
                <Link to={`/purchase-orders/${bill.po_id}`}>the purchase order</Link>
              </>
            )}
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          <DiscussInConnect entityType="purchase_bill" entityId={Number(id)} />
          {bill.status !== 'POSTED' && can('match.view') && (
            <Button disabled={busy} onClick={() => act(`v1/bills/${id}/rematch`)}>Re-run the match</Button>
          )}
          {!['POSTED', 'CANCELLED', 'BLOCKED'].includes(bill.status) && can('bill.post') && (
            <Button tone="primary" disabled={busy || openExceptions.length > 0} onClick={() => act(`v1/bills/${id}/post`)}>
              Post to Smart Books
            </Button>
          )}
          {bill.status === 'BLOCKED' && can('bill.enter') && (
            <Button tone="primary" disabled={busy} onClick={() => setPanel('revise')}>Revise</Button>
          )}
          {bill.status === 'POSTED' && can('bill.post') && (
            <Button disabled={busy} onClick={() => act(`v1/bills/${id}/verify`)}>Check again in Smart Books</Button>
          )}
          {!['POSTED', 'CANCELLED'].includes(bill.status) && can('bill.enter') && (
            <Button tone="danger" disabled={busy} onClick={() => setPanel('cancel')}>Cancel</Button>
          )}
        </div>
      </header>

      {error && <Notice tone="danger" title="That did not work" onDismiss={() => setError(null)}>{error}</Notice>}

      <CommandStrip commands={bill.commands} busy={busy} onRetry={() => act(`v1/bills/${id}/post`)} />

      {bill.status === 'POSTED' && bill.posting_check && (
        <Notice
          tone={bill.posting_check.verified ? 'success' : 'danger'}
          title={bill.posting_check.verified ? 'Smart Books recorded this bill as it should' : 'What Smart Books recorded does not match this bill'}
        >
          {bill.posting_check.verified ? (
            <span>Balanced, the supplier credited with the total, under the supplier's invoice number. Checked {date(bill.posting_check.checked_at)}.</span>
          ) : (
            <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
              {bill.posting_check.problems.map((p) => <li key={p}>{p}</li>)}
            </ul>
          )}
        </Notice>
      )}

      {panel === 'revise' && (
        <Card title="Revise what Smart Books refused">
          <div style={{ display: 'grid', gap: '0.6rem' }}>
            <p style={{ margin: 0, fontSize: '0.88rem' }}>
              {bill.last_error ?? 'Smart Books refused this bill.'} A revision is a new request; the refused one is withdrawn and cannot be sent again.
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.6rem' }}>
              <Field label="Supplier invoice date"><Input type="date" value={reviseDate} onChange={(e) => setReviseDate(e.target.value)} /></Field>
              <Field label="Posting date"><Input type="date" value={revisePosting} onChange={(e) => setRevisePosting(e.target.value)} /></Field>
            </div>
            <Field label="What changed"><Input value={reviseNote} onChange={(e) => setReviseNote(e.target.value)} /></Field>
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setPanel('none')}>Back</Button>
              <Button
                tone="primary"
                disabled={busy}
                onClick={async () => {
                  await act(`v1/bills/${id}/revise`, { supplier_invoice_date: reviseDate || undefined, posting_date: revisePosting || undefined, note: reviseNote || undefined })
                  setPanel('none')
                }}
              >
                Save the revision
              </Button>
            </div>
          </div>
        </Card>
      )}

      {panel === 'cancel' && (
        <Card title="Cancel this bill">
          <div style={{ display: 'grid', gap: '0.6rem' }}>
            <Input value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} placeholder="Why" />
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setPanel('none')}>Keep it</Button>
              <Button
                tone="danger"
                disabled={busy || cancelReason.trim() === ''}
                onClick={async () => {
                  await act(`v1/bills/${id}/cancel`, { reason: cancelReason.trim() })
                  setPanel('none')
                }}
              >
                Cancel the bill
              </Button>
            </div>
          </div>
        </Card>
      )}

      {latest && (
        <Card title="Three-way match">
          <Notice tone={VERDICT_TONE[latest.verdict] ?? 'warning'} title={latest.verdict.replace(/_/g, ' ')}>
            {VERDICT_MESSAGE[latest.verdict] ?? ''}
          </Notice>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(13rem, 1fr))', gap: '0.75rem', marginTop: '0.85rem' }}>
            <LegCard title="Purchase order" owner="Purchases" detail={bill.po_id ? `#${bill.po_id}` : 'None — cannot be matched'} />
            <LegCard title="Goods receipt" owner="Inventory" detail="Read live at the moment of the match" />
            <LegCard title="Supplier bill" owner="Books, once posted" detail={bill.books_voucher_no ?? 'Not yet posted'} />
          </div>

          {latest.exceptions.length > 0 && (
            <div style={{ marginTop: '1rem', display: 'grid', gap: '0.75rem' }}>
              {latest.exceptions.map((exception) => (
                <div
                  key={exception.exception_id}
                  style={{
                    border: `1px solid ${exception.status === 'OPEN' ? 'var(--danger)' : 'var(--border)'}`,
                    borderRadius: 'var(--radius-sm)',
                    padding: '0.75rem',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.75rem', flexWrap: 'wrap' }}>
                    <strong>{exception.exception_kind.replace(/_/g, ' ')}</strong>
                    <StatusBadge status={exception.status === 'OPEN' ? 'BLOCKED' : exception.status === 'ACCEPTED' ? 'COMPLETED' : 'FAILED'} />
                  </div>
                  <p style={{ margin: '0.35rem 0 0' }}>{exception.detail}</p>

                  {(exception.po_value !== null || exception.receipt_value !== null || exception.bill_value !== null) && (
                    <div style={{ display: 'flex', gap: '1.5rem', marginTop: '0.5rem', fontSize: '0.85rem', flexWrap: 'wrap' }}>
                      {exception.po_value !== null && <span>Ordered <strong className="num">{qty(exception.po_value)}</strong></span>}
                      {exception.receipt_value !== null && <span>Received <strong className="num">{qty(exception.receipt_value)}</strong></span>}
                      {exception.bill_value !== null && <span>Billed <strong className="num">{qty(exception.bill_value)}</strong></span>}
                    </div>
                  )}

                  {exception.status === 'OPEN' && can('match.resolve') && (
                    <div style={{ display: 'grid', gap: '0.5rem', marginTop: '0.75rem' }}>
                      <Field label="Why are you accepting or rejecting this?" hint="Recorded against the bill — an accepted variance costs money.">
                        <Input
                          value={decisionNote[exception.exception_id] ?? ''}
                          onChange={(e) => setDecisionNote({ ...decisionNote, [exception.exception_id]: e.target.value })}
                        />
                      </Field>
                      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
                        <Button
                          tone="danger"
                          disabled={busy || !(decisionNote[exception.exception_id] ?? '').trim()}
                          onClick={() => act(`v1/match-exceptions/${exception.exception_id}/reject`, { note: decisionNote[exception.exception_id] })}
                        >
                          Reject
                        </Button>
                        <Button
                          tone="primary"
                          disabled={busy || !(decisionNote[exception.exception_id] ?? '').trim()}
                          onClick={() => act(`v1/match-exceptions/${exception.exception_id}/accept`, { note: decisionNote[exception.exception_id] })}
                        >
                          Accept the variance
                        </Button>
                      </div>
                    </div>
                  )}

                  {exception.status !== 'OPEN' && exception.decision_note && (
                    <p style={{ margin: '0.5rem 0 0', color: 'var(--muted)', fontSize: '0.85rem' }}>
                      {exception.status === 'ACCEPTED' ? 'Accepted' : 'Rejected'} by {exception.decided_by}: {exception.decision_note}
                    </p>
                  )}
                </div>
              ))}
            </div>
          )}

          <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '1rem', marginBottom: 0 }}>
            Matched {date(latest.matched_at)}. What is stored is this verdict and the decisions about it — never a copy
            of the receipt or the invoice.
          </p>
        </Card>
      )}
    </div>
  )
}

function LegCard({ title, owner, detail }: { title: string; owner: string; detail: string }) {
  return (
    <div style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', padding: '0.7rem' }}>
      <div style={{ fontSize: '0.78rem', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{title}</div>
      <div style={{ fontWeight: 600, marginTop: '0.2rem' }}>{owner}</div>
      <div style={{ color: 'var(--muted)', fontSize: '0.82rem', marginTop: '0.15rem' }}>{detail}</div>
    </div>
  )
}

interface DirectLine {
  key: string
  kind: 'service' | 'item'
  description: string
  item_id: number | null
  item_label: string | null
  purchase_acc_id: number | null
  ledger_label: string | null
  /** Where an item line's goods go: the bill receives them into stock itself. '' = the default from Settings. */
  warehouse_id: string
  qty: string
  rate: string
}

function blankLine(kind: 'service' | 'item'): DirectLine {
  return { key: Math.random().toString(36).slice(2), kind, description: '', item_id: null, item_label: null, purchase_acc_id: null, ledger_label: null, warehouse_id: '', qty: '1', rate: '' }
}

/**
 * A supplier's bill: against an order (the goods on it arrived on GRNs, which the bill settles —
 * it never receives them again), or with no order at all — a service, an expense, a direct
 * purchase. A bill with no order is reviewed before it posts, and a service line names the
 * Books ledger it is booked to.
 */
export function BillEditor() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const poId = searchParams.get('po_id')
  const { scope } = usePurchases()

  const [invoiceNo, setInvoiceNo] = useState('')
  const [invoiceDate, setInvoiceDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [dueDate, setDueDate] = useState('')
  const [postingDate, setPostingDate] = useState('')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [rates, setRates] = useState<Record<number, string>>({})
  const [narration, setNarration] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [supplier, setSupplier] = useState<CatalogSupplier | null>(null)
  const [direct, setDirect] = useState<DirectLine[]>(() => [blankLine('service')])
  // A bill without an order receives its goods itself, so each item line says where they go.
  const warehouses = useWarehouses(!poId)

  const order = useApi(
    (signal) => api.one<PurchaseOrder>(`v1/purchase-orders/${poId}`, undefined, signal),
    [poId, scope?.cmp_id],
    Boolean(scope && poId),
  )

  const po = order.data?.data

  // Default to what arrived and is not yet billed — the quantity a correct bill shows.
  const [seeded, setSeeded] = useState(false)
  if (po && !seeded) {
    setSeeded(true)
    const nextQty: Record<number, string> = {}
    const nextRates: Record<number, string> = {}
    const progress = new Map((po.progress?.lines ?? []).map((l) => [l.line_id, l]))
    for (const line of po.lines) {
      const toBill = progress.get(line.line_id)?.to_bill_qty ?? Math.max(0, Number(line.received_qty) - Number(line.billed_qty))
      nextQty[line.line_id] = String(toBill > 0 ? toBill : 0)
      nextRates[line.line_id] = line.agreed_rate
    }
    setQuantities(nextQty)
    setRates(nextRates)
  }

  const dates = {
    supplier_invoice_date: invoiceDate,
    due_date: dueDate || undefined,
    posting_date: postingDate || undefined,
  }

  async function save() {
    setSaving(true)
    setError(null)
    try {
      const body = po
        ? {
            supplier_account_id: po.supplier_account_id,
            po_id: po.po_id,
            bill_kind: 'po',
            supplier_invoice_no: invoiceNo.trim(),
            ...dates,
            narration: narration || undefined,
            lines: po.lines
              .filter((line) => Number(quantities[line.line_id] ?? 0) > 0)
              .map((line) => ({ po_line_id: line.line_id, qty: Number(quantities[line.line_id] ?? 0), rate: Number(rates[line.line_id] ?? line.agreed_rate) })),
          }
        : {
            supplier_account_id: supplier?.acc_id,
            bill_kind: direct.every((l) => l.kind === 'service') ? 'service' : 'direct',
            supplier_invoice_no: invoiceNo.trim(),
            ...dates,
            narration: narration || undefined,
            lines: direct
              .filter((l) => Number(l.qty || 0) > 0 && Number(l.rate || 0) > 0)
              .map((l) =>
                l.kind === 'service'
                  ? { is_service: true, description: l.description.trim() || 'Service', purchase_acc_id: l.purchase_acc_id ?? undefined, qty: Number(l.qty), rate: Number(l.rate) }
                  : { item_id: l.item_id ?? undefined, description: l.description.trim() || undefined, warehouse_id: l.warehouse_id ? Number(l.warehouse_id) : undefined, qty: Number(l.qty), rate: Number(l.rate) },
              ),
          }
      const response = await api.post<BillRequest>('v1/bills', body)
      navigate(`/bills/${response.data.request_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  if (poId && order.loading) return <p style={{ color: 'var(--muted)' }}>Loading the purchase order…</p>
  if (poId && !po) return <Notice tone="warning">That purchase order does not exist.</Notice>

  const total = po
    ? po.lines.reduce((sum, line) => sum + Number(quantities[line.line_id] ?? 0) * Number(rates[line.line_id] ?? line.agreed_rate), 0)
    : direct.reduce((sum, l) => sum + Number(l.qty || 0) * Number(l.rate || 0), 0)
  const currency = po?.currency_code ?? 'INR'
  const setLine = (key: string, patch: Partial<DirectLine>) => setDirect((current) => current.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const missingLedger = !po && direct.some((l) => l.kind === 'service' && Number(l.rate || 0) > 0 && l.purchase_acc_id === null)

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '64rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Enter supplier bill</h1>
      {po ? (
        <p style={{ margin: 0, color: 'var(--muted)' }}>
          Against <Link to={`/purchase-orders/${po.po_id}`}>{po.po_no}</Link> · {po.supplier_name_snapshot ?? `Account ${po.supplier_account_id}`}
        </p>
      ) : (
        <Notice tone="info">
          A bill with no purchase order — a service, an expense, a direct purchase. It is reviewed before it posts to Smart Books,
          and each service line is booked to the ledger you choose.
        </Notice>
      )}

      {error && <Notice tone="danger" title="Could not save" onDismiss={() => setError(null)}>{error}</Notice>}

      <Card title="Invoice">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.85rem' }}>
          {!po && <SupplierPicker onPick={setSupplier} selectedLabel={supplier?.acc_name ?? null} />}
          <Field label="Supplier invoice number" hint="The same invoice cannot be booked twice — here, in Billing or in Smart Books.">
            <Input value={invoiceNo} onChange={(e) => setInvoiceNo(e.target.value)} />
          </Field>
          <Field label="Invoice date" hint="As printed on the supplier's invoice."><Input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} /></Field>
          <Field label="Due date (optional)"><Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} /></Field>
          <Field label="Posting date (optional)" hint="Leave blank to book it on the invoice date. Needed when the invoice is dated in last year.">
            <Input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} />
          </Field>
        </div>
      </Card>

      {po ? (
        <Card title="Lines">
          <DataTable
            rows={po.lines}
            rowKey={(line) => line.line_id}
            columns={[
              { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
              { key: 'item', header: 'Item', render: (line) => line.description ?? `Inventory item ${line.item_id}` },
              { key: 'ordered', header: 'Ordered', numeric: true, render: (line) => qty(line.ordered_qty) },
              { key: 'received', header: 'Received', numeric: true, render: (line) => qty(line.received_qty) },
              { key: 'billed', header: 'Already billed', numeric: true, render: (line) => qty(line.billed_qty) },
              {
                key: 'qty',
                header: 'Billing now',
                numeric: true,
                render: (line) => (
                  <Input value={quantities[line.line_id] ?? ''} inputMode="decimal" onChange={(e) => setQuantities({ ...quantities, [line.line_id]: e.target.value })} style={{ width: '6rem', textAlign: 'right' }} />
                ),
              },
              {
                key: 'rate',
                header: 'Rate',
                numeric: true,
                render: (line) => (
                  <Input value={rates[line.line_id] ?? ''} inputMode="decimal" onChange={(e) => setRates({ ...rates, [line.line_id]: e.target.value })} style={{ width: '7rem', textAlign: 'right' }} />
                ),
              },
              { key: 'agreed', header: 'Agreed', numeric: true, render: (line) => money(line.agreed_rate, currency) },
            ]}
          />
          <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
            Goods on this order are already in stock on their GRNs; the bill settles those and trues their cost up to the rate charged.
            Goods not yet received cannot be billed here — record their GRN first.
          </p>
        </Card>
      ) : (
        <Card
          title="Lines"
          action={
            <div style={{ display: 'flex', gap: '0.4rem' }}>
              <Button onClick={() => setDirect([...direct, blankLine('service')])}>Add a service line</Button>
              <Button onClick={() => setDirect([...direct, blankLine('item')])}>Add an item line</Button>
            </div>
          }
        >
          <div style={{ display: 'grid', gap: '0.75rem' }}>
            {direct.map((l, index) => (
              <div key={l.key} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))', gap: '0.55rem', alignItems: 'end', borderBottom: '1px solid var(--border)', paddingBottom: '0.65rem' }}>
                {l.kind === 'service' ? (
                  <>
                    <Field label={`${index + 1}. Service`}><Input value={l.description} onChange={(e) => setLine(l.key, { description: e.target.value })} placeholder="Freight, repairs, AMC…" /></Field>
                    <LedgerPicker onPick={(ledger) => setLine(l.key, { purchase_acc_id: ledger.acc_id, ledger_label: ledger.acc_name })} selectedLabel={l.ledger_label} />
                  </>
                ) : (
                  <>
                    <ItemPicker onPick={(item) => setLine(l.key, { item_id: item.item_id, item_label: item.item_name })} selectedLabel={l.item_label} />
                    <Field label="Warehouse" hint="Where the goods go into stock.">
                      <Select value={l.warehouse_id} onChange={(e) => setLine(l.key, { warehouse_id: e.target.value })}>
                        <option value="">Default from Settings</option>
                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                      </Select>
                    </Field>
                    <Field label="Description (optional)"><Input value={l.description} onChange={(e) => setLine(l.key, { description: e.target.value })} /></Field>
                  </>
                )}
                <Field label="Qty"><Input value={l.qty} inputMode="decimal" onChange={(e) => setLine(l.key, { qty: e.target.value })} /></Field>
                <Field label="Rate"><Input value={l.rate} inputMode="decimal" onChange={(e) => setLine(l.key, { rate: e.target.value })} /></Field>
                <Button tone="ghost" onClick={() => setDirect(direct.filter((x) => x.key !== l.key))} disabled={direct.length === 1}>Remove</Button>
              </div>
            ))}
          </div>
          <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
            Services move no stock. Items bought without an order are received into stock by the bill itself, into the warehouse
            each line names (or the default warehouse from Settings).
          </p>
        </Card>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '1.5rem' }}>
        <span style={{ color: 'var(--muted)' }}>Bill total before tax</span>
        <span className="num" style={{ fontWeight: 600 }}>{money(total, currency)}</span>
      </div>

      <Card title="Narration">
        <Textarea value={narration} onChange={(e) => setNarration(e.target.value)} />
      </Card>

      {missingLedger && <Notice tone="warning">Choose the ledger each service line is booked to.</Notice>}
      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button tone="primary" disabled={saving || invoiceNo.trim() === '' || (!po && (supplier === null || missingLedger))} onClick={save}>
          {saving ? 'Matching…' : 'Save and match'}
        </Button>
      </div>
    </div>
  )
}
