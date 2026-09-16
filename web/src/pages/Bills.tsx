import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { BillRequest, PurchaseOrder } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { CommandStrip } from '../components/CommandStrip'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, Select, StatusBadge, Textarea } from '../ui'

const STATUSES = ['', 'DRAFT', 'MATCHING', 'MATCHED', 'EXCEPTION', 'POSTED', 'FAILED', 'CANCELLED']

export function BillsList() {
  const navigate = useNavigate()
  const { scope } = usePurchases()
  const [searchParams] = useSearchParams()
  const [status, setStatus] = useState(searchParams.get('exceptions') === '1' ? 'EXCEPTION' : '')

  const { data, loading, error } = useApi(
    (signal) => api.list<BillRequest>('v1/bills', { status: status || undefined, limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id, status],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Supplier bills</h1>

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
            Account {bill.supplier_account_id}
            {bill.supplier_invoice_date && ` · dated ${date(bill.supplier_invoice_date)}`}
            {bill.po_id && (
              <>
                {' · against '}
                <Link to={`/purchase-orders/${bill.po_id}`}>the purchase order</Link>
              </>
            )}
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {bill.status !== 'POSTED' && can('match.view') && (
            <Button disabled={busy} onClick={() => act(`v1/bills/${id}/rematch`)}>Re-run the match</Button>
          )}
          {bill.status !== 'POSTED' && can('bill.post') && (
            <Button tone="primary" disabled={busy || openExceptions.length > 0} onClick={() => act(`v1/bills/${id}/post`)}>
              Post to Smart Books
            </Button>
          )}
        </div>
      </header>

      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}

      <CommandStrip commands={bill.commands} busy={busy} onRetry={() => act(`v1/bills/${id}/post`)} />

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

export function BillEditor() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const poId = searchParams.get('po_id')
  const { scope } = usePurchases()

  const [invoiceNo, setInvoiceNo] = useState('')
  const [invoiceDate, setInvoiceDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [rates, setRates] = useState<Record<number, string>>({})
  const [narration, setNarration] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const order = useApi(
    (signal) => api.one<PurchaseOrder>(`v1/purchase-orders/${poId}`, undefined, signal),
    [poId, scope?.cmp_id],
    Boolean(scope && poId),
  )

  const po = order.data?.data

  // Default to what is received but not yet billed — the quantity a correct
  // bill would show, so the common case needs no typing.
  const [seeded, setSeeded] = useState(false)
  if (po && !seeded) {
    setSeeded(true)
    const nextQty: Record<number, string> = {}
    const nextRates: Record<number, string> = {}
    for (const line of po.lines) {
      const billable = Number(line.received_qty) - Number(line.billed_qty)
      nextQty[line.line_id] = String(billable > 0 ? billable : 0)
      nextRates[line.line_id] = line.agreed_rate
    }
    setQuantities(nextQty)
    setRates(nextRates)
  }

  async function save() {
    if (!po) return
    setSaving(true)
    setError(null)
    try {
      const response = await api.post<BillRequest>('v1/bills', {
        supplier_account_id: po.supplier_account_id,
        po_id: po.po_id,
        supplier_invoice_no: invoiceNo.trim(),
        supplier_invoice_date: invoiceDate,
        narration: narration || undefined,
        lines: po.lines
          .filter((line) => Number(quantities[line.line_id] ?? 0) > 0)
          .map((line) => ({
            po_line_id: line.line_id,
            qty: Number(quantities[line.line_id] ?? 0),
            rate: Number(rates[line.line_id] ?? line.agreed_rate),
          })),
      })
      navigate(`/bills/${response.data.request_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  if (!poId) return <Notice tone="warning">Open a purchase order and choose "Enter the bill".</Notice>
  if (order.loading) return <p style={{ color: 'var(--muted)' }}>Loading the purchase order…</p>
  if (!po) return <Notice tone="warning">That purchase order does not exist.</Notice>

  const total = po.lines.reduce(
    (sum, line) => sum + Number(quantities[line.line_id] ?? 0) * Number(rates[line.line_id] ?? line.agreed_rate),
    0,
  )

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '60rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Enter supplier bill</h1>
      <p style={{ margin: 0, color: 'var(--muted)' }}>
        Against <Link to={`/purchase-orders/${po.po_id}`}>{po.po_no}</Link> ·{' '}
        {po.supplier_name_snapshot ?? `Account ${po.supplier_account_id}`}
      </p>

      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}

      <Card title="Invoice">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.85rem' }}>
          <Field label="Supplier invoice number" hint="A number already entered for this supplier is refused.">
            <Input value={invoiceNo} onChange={(e) => setInvoiceNo(e.target.value)} />
          </Field>
          <Field label="Invoice date"><Input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} /></Field>
        </div>
      </Card>

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
                <Input
                  value={quantities[line.line_id] ?? ''}
                  inputMode="decimal"
                  onChange={(e) => setQuantities({ ...quantities, [line.line_id]: e.target.value })}
                  style={{ width: '6rem', textAlign: 'right' }}
                />
              ),
            },
            {
              key: 'rate',
              header: 'Rate',
              numeric: true,
              render: (line) => (
                <Input
                  value={rates[line.line_id] ?? ''}
                  inputMode="decimal"
                  onChange={(e) => setRates({ ...rates, [line.line_id]: e.target.value })}
                  style={{ width: '7rem', textAlign: 'right' }}
                />
              ),
            },
            { key: 'agreed', header: 'Agreed', numeric: true, render: (line) => money(line.agreed_rate, po.currency_code) },
          ]}
        />

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '1.5rem', marginTop: '1rem' }}>
          <span style={{ color: 'var(--muted)' }}>Bill total before tax</span>
          <span className="num" style={{ fontWeight: 600 }}>{money(total, po.currency_code)}</span>
        </div>
        <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.5rem', marginBottom: 0 }}>
          Quantities default to what has been received but not yet billed. Smart Books computes the input tax when the
          bill is posted.
        </p>
      </Card>

      <Card title="Narration">
        <Textarea value={narration} onChange={(e) => setNarration(e.target.value)} />
      </Card>

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button tone="primary" disabled={saving || invoiceNo.trim() === ''} onClick={save}>
          {saving ? 'Matching…' : 'Save and match'}
        </Button>
      </div>
    </div>
  )
}
