import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { BillRequest, CatalogSupplier, PurchaseOrder } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useWarehouses } from '../hooks/useWarehouses'
import { useUrlFilter } from '../hooks/useUrlFilter'
import { usePurchases } from '../context/PurchasesContext'
import { CommandStrip } from '../components/CommandStrip'
import { ItemPicker, LedgerPicker, SupplierPicker } from '../components/LivePicker'
import { LoadFailed, Pager } from '../components/LoadFailed'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, Select, StatusBadge, Textarea } from '../ui'
import { DiscussInConnect } from '../components/ConnectEmbed'
import { RecordHistory } from '../components/AuditDetail'
import * as decimal from '../utils/decimal'
import { localToday } from '../utils/localDate'
import { RegisterFilterBar } from '../registers/RegisterFilterBar'
import { useRegisterFilters } from '../registers/useRegisterFilters'
import { BILL_GST_KEYS, readFilterMeta } from '../registers/registerFilters'

const STATUSES = ['', 'DRAFT', 'MATCHING', 'MATCHED', 'EXCEPTION', 'REJECTED', 'POSTING', 'UNCERTAIN', 'POSTED', 'FAILED', 'BLOCKED', 'CANCELLED']

export function BillsList() {
  const navigate = useNavigate()
  const { scope, can } = usePurchases()
  const [searchParams] = useSearchParams()
  // `exceptions=1` is the older spelling of the same thing and still works.
  const [urlStatus, setStatus] = useUrlFilter('status')
  const status = urlStatus === '' && searchParams.get('exceptions') === '1' ? 'EXCEPTION' : urlStatus
  // GST chips, amount range and supplier — in the URL, applied by the server (RegisterFilter), so
  // the count and the pages describe the filtered bills.
  const filters = useRegisterFilters()

  // Paged (F145): every bill is reachable, not only the first hundred.
  const [offset, setOffset] = useState(0)
  useEffect(() => setOffset(0), [status, filters.key])
  const { data, loading, error, reload } = useApi(
    (signal) =>
      api.list<BillRequest>(
        'v1/bills',
        { status: status || undefined, ...filters.query, limit: 50, offset },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, status, filters.key, offset],
    Boolean(scope),
  )
  // The supplier's name from the rows it narrowed to; "Ledger #id" until they load.
  const supplierLabel = filters.supplierId ? data?.data.find((row) => String(row.supplier_account_id) === filters.supplierId)?.supplier_name : null

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

      {error && <LoadFailed what="bills" error={error} onRetry={reload} />}

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
        <RegisterFilterBar
          filters={filters}
          gstKeys={BILL_GST_KEYS}
          withSupplier
          supplierLabel={supplierLabel}
          amountHint="The bill's value before tax, as listed — Smart Books computes the GST when it posts."
          gstUnavailable={readFilterMeta(data?.meta).gstUnavailable}
        />
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.request_id}
          onRowClick={(row) => navigate(`/bills/${row.request_id}`)}
          empty={error ? "Not loaded — see above." : filters.active ? 'No bills match these filters.' : "No bills entered."}
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
            { key: 'supplier', header: 'Supplier', render: (row) => row.supplier_name ?? `Ledger #${row.supplier_account_id}` },
            {
              key: 'value',
              header: 'Value (before tax)',
              numeric: true,
              render: (row) => (row.subtotal == null ? '—' : money(row.subtotal, row.currency_code ?? 'INR')),
            },
            {
              key: 'status',
              header: 'Status',
              render: (row) => (
                <>
                  <StatusBadge status={row.status} />
                  {row.is_reverse_charge && <span style={{ marginLeft: '0.35rem', color: 'var(--muted)', fontSize: '0.75rem' }}>RCM</span>}
                </>
              ),
            },
            { key: 'voucher', header: 'Books voucher', render: (row) => row.books_voucher_no ?? '—' },
          ]}
        />
        <Pager offset={offset} limit={50} total={data?.meta.total ?? 0} onChange={setOffset} />
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
  const [reviseGstin, setReviseGstin] = useState('')
  const [reviseLines, setReviseLines] = useState<ReviseLine[]>([])
  const [reviseConfirm, setReviseConfirm] = useState(false)
  const [reviseConfirmReason, setReviseConfirmReason] = useState('')
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
  const rejectedExceptions = latest?.exceptions.filter((e) => e.status === 'REJECTED') ?? []
  // Re-running the match changes the bill's state: only while it is waiting on its match, and
  // only for someone who enters bills (never a cancelled, rejected, refused or posted one).
  const canRematch = ['MATCHING', 'MATCHED', 'EXCEPTION'].includes(bill.status) && can('bill.enter')
  const canPost = ['MATCHED', 'EXCEPTION', 'FAILED', 'UNCERTAIN', 'POSTING'].includes(bill.status) && can('bill.post')
  const canRevise = ['BLOCKED', 'REJECTED'].includes(bill.status) && can('bill.enter')

  function openRevise() {
    setReviseLines(revisableLines(bill?.requested_lines))
    setPanel('revise')
  }

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
            {bill.bill_kind === 'service' ? 'Service bill' : bill.bill_kind === 'direct' ? 'Direct purchase' : 'Against an order'} · {bill.supplier_name ?? `Ledger #${bill.supplier_account_id}`}
            {bill.is_reverse_charge ? ' · reverse charge' : ''}
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
          {canRematch && (
            <Button disabled={busy} onClick={() => act(`v1/bills/${id}/rematch`)}>Re-run the match</Button>
          )}
          {canPost && (
            <Button tone="primary" disabled={busy || openExceptions.length > 0 || rejectedExceptions.length > 0} onClick={() => act(`v1/bills/${id}/post`)}>
              Post to Smart Books
            </Button>
          )}
          {canRevise && (
            <Button tone="primary" disabled={busy} onClick={openRevise}>Revise</Button>
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

      {bill.status === 'REJECTED' && (
        <Notice tone="danger" title="A match exception was rejected">
          This bill cannot be posted as it stands. Revise it — the revision is matched afresh — or cancel it.
          {bill.last_error ? ` ${bill.last_error}` : ''}
        </Notice>
      )}

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
        <Card title={bill.status === 'REJECTED' ? 'Revise the rejected bill' : 'Revise what Smart Books refused'}>
          <div style={{ display: 'grid', gap: '0.6rem' }}>
            <p style={{ margin: 0, fontSize: '0.88rem' }}>
              {bill.last_error ?? (bill.status === 'REJECTED' ? 'A match exception was rejected.' : 'Smart Books refused this bill.')} A revision is a new request, matched afresh; the old one is withdrawn and cannot be sent again.
            </p>
            {reviseLines.length > 0 && (
              <div className="table-scroll" style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', fontSize: '0.85rem', borderCollapse: 'collapse' }}>
                  <thead>
                    <tr style={{ textAlign: 'left', color: 'var(--muted)' }}>
                      <th>Line</th><th>Description</th><th>Quantity</th><th>Rate</th><th>Discount %</th>
                    </tr>
                  </thead>
                  <tbody>
                    {reviseLines.map((line, index) => (
                      <tr key={line.key} data-testid="revise-line">
                        <td>{line.line_no}</td>
                        <td>{line.description ?? (line.item_id ? `Item ${line.item_id}` : 'Service')}</td>
                        {(['qty', 'rate', 'discount_pc'] as const).map((field) => (
                          <td key={field}>
                            <Input
                              aria-label={`${field} of line ${line.line_no}`}
                              inputMode="decimal"
                              value={line[field]}
                              onChange={(e) => setReviseLines(reviseLines.map((l, i) => (i === index ? { ...l, [field]: e.target.value } : l)))}
                              style={{ width: '7rem' }}
                            />
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.6rem' }}>
              <Field label="Supplier invoice date"><Input type="date" value={reviseDate} onChange={(e) => setReviseDate(e.target.value)} /></Field>
              <Field label="Posting date"><Input type="date" value={revisePosting} onChange={(e) => setRevisePosting(e.target.value)} /></Field>
              <Field label="Supplier GSTIN on the invoice" hint="Only if it differs from their ledger in Smart Books.">
                <Input value={reviseGstin} maxLength={15} onChange={(e) => setReviseGstin(e.target.value.toUpperCase())} />
              </Field>
            </div>
            {bill.bill_kind === 'direct' && (
              <div style={{ display: 'grid', gap: '0.4rem' }}>
                <label style={{ display: 'flex', gap: '0.4rem', alignItems: 'center', fontSize: '0.88rem' }}>
                  <input type="checkbox" checked={reviseConfirm} onChange={(e) => setReviseConfirm(e.target.checked)} />
                  These goods are not the ones on an open goods receipt of this supplier
                </label>
                {reviseConfirm && (
                  <Field label="Why (recorded with the bill)"><Input value={reviseConfirmReason} onChange={(e) => setReviseConfirmReason(e.target.value)} /></Field>
                )}
              </div>
            )}
            <Field label="What changed"><Input value={reviseNote} onChange={(e) => setReviseNote(e.target.value)} /></Field>
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setPanel('none')}>Back</Button>
              <Button
                tone="primary"
                disabled={busy}
                onClick={async () => {
                  await act(`v1/bills/${id}/revise`, {
                    supplier_invoice_date: reviseDate || undefined,
                    posting_date: revisePosting || undefined,
                    supplier_gstin: reviseGstin.trim() || undefined,
                    note: reviseNote || undefined,
                    lines: reviseLines.length > 0 ? reviseLines.map(revisedLine) : undefined,
                    ...(reviseConfirm ? { confirm_no_open_grn: true, confirm_no_open_grn_reason: reviseConfirmReason.trim() } : {}),
                  })
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

      <RecordHistory entityType="bill_request" entityId={bill.request_id} />
    </div>
  )
}

interface ReviseLine {
  key: string
  line_no: number
  description: string | null
  item_id: number | null
  qty: string
  rate: string
  discount_pc: string
  /** Everything else the line carries (order line, ledger, tax category, warehouse), sent back as it was. */
  rest: Record<string, unknown>
}

/** The bill's stored lines as an editable list: quantity, rate and discount are what a revision changes. */
function revisableLines(raw: unknown): ReviseLine[] {
  if (!Array.isArray(raw)) return []
  return raw
    .filter((l): l is Record<string, unknown> => typeof l === 'object' && l !== null)
    .map((l, i) => ({
      key: `${i}-${String(l.line_no ?? i)}`,
      line_no: Number(l.line_no ?? i + 1),
      description: typeof l.description === 'string' ? l.description : null,
      item_id: l.item_id == null ? null : Number(l.item_id),
      qty: String(l.qty ?? ''),
      rate: String(l.rate ?? ''),
      discount_pc: String(l.discount_pc ?? '0'),
      rest: l,
    }))
}

function revisedLine(line: ReviseLine): Record<string, unknown> {
  const { rest } = line
  return {
    po_line_id: rest.po_line_id ?? undefined,
    item_id: rest.item_id ?? undefined,
    unit_id: rest.unit_id ?? undefined,
    warehouse_id: rest.warehouse_id ?? undefined,
    is_service: rest.is_service ?? undefined,
    purchase_acc_id: rest.purchase_acc_id ?? undefined,
    description: rest.description ?? undefined,
    tax_cat_id: rest.tax_cat_id ?? undefined,
    hsn_sac: rest.hsn_sac ?? undefined,
    qty: line.qty,
    rate: line.rate,
    discount_pc: line.discount_pc,
  }
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

/** An unbilled goods receipt of the supplier holding the same items, as the server named it (A09). */
interface OpenReceipt {
  inventory_document_id: number
  document_no?: string
  document_date?: string
  item_id: number
  open_qty: number
  receipt_no?: string
  po_id?: number
  po_no?: string
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
  // One supplier invoice entered as several bills (F170): the number stays the supplier's.
  const [invoicePart, setInvoicePart] = useState('1')
  const [partReason, setPartReason] = useState('')
  // The GSTIN printed on the invoice, only when it is not the one on the supplier's ledger.
  const [supplierGstin, setSupplierGstin] = useState('')
  // Why the invoice's GSTIN is not the one on the supplier's ledger — asked only when they differ (F122).
  const [gstinReason, setGstinReason] = useState('')
  const [gstinDiffers, setGstinDiffers] = useState<string | null>(null)
  // '' = as the supplier's ledger implies (services from abroad are reverse charge); 'yes' / 'no' as marked (F220).
  const [reverseCharge, setReverseCharge] = useState<'' | 'yes' | 'no'>('')
  const [invoiceDate, setInvoiceDate] = useState(() => localToday())
  const [dueDate, setDueDate] = useState('')
  const [postingDate, setPostingDate] = useState('')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [rates, setRates] = useState<Record<number, string>>({})
  // The order line's discount, carried onto the bill: the bill is matched on the NET rate (A31),
  // so defaulting to the gross rate with no discount would show a variance nobody agreed.
  const [discounts, setDiscounts] = useState<Record<number, string>>({})
  const [narration, setNarration] = useState('')
  // The order's own charges billed on this invoice (F163): matched against what the order agreed.
  const [charges, setCharges] = useState<Record<'freight' | 'other_charges', { amount: string; ledger: { id: number; name: string } | null }>>({
    freight: { amount: '', ledger: null },
    other_charges: { amount: '', ledger: null },
  })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [supplier, setSupplier] = useState<CatalogSupplier | null>(null)
  const [direct, setDirect] = useState<DirectLine[]>(() => [blankLine('service')])
  // Goods an unbilled receipt of this supplier already brought in (A09): the bill would receive
  // them again. Bill against that order instead — or confirm, with a reason, they are different.
  const [openReceipts, setOpenReceipts] = useState<OpenReceipt[] | null>(null)
  const [confirmDifferent, setConfirmDifferent] = useState(false)
  const [confirmReason, setConfirmReason] = useState('')
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
    const nextDiscounts: Record<number, string> = {}
    const progress = new Map((po.progress?.lines ?? []).map((l) => [l.line_id, l]))
    for (const line of po.lines) {
      const toBill = progress.get(line.line_id)?.to_bill_qty ?? Math.max(0, Number(line.received_qty) - Number(line.billed_qty))
      nextQty[line.line_id] = String(toBill > 0 ? toBill : 0)
      nextRates[line.line_id] = line.agreed_rate
      nextDiscounts[line.line_id] = line.discount_pc ?? '0'
    }
    setQuantities(nextQty)
    setRates(nextRates)
    setDiscounts(nextDiscounts)
  }

  const dates = {
    supplier_invoice_date: invoiceDate,
    due_date: dueDate || undefined,
    posting_date: postingDate || undefined,
    supplier_gstin: supplierGstin.trim() || undefined,
    supplier_gstin_reason: gstinDiffers && gstinReason.trim() ? gstinReason.trim() : undefined,
    reverse_charge: reverseCharge === '' ? undefined : reverseCharge === 'yes',
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
            ...(Number(invoicePart) > 1 ? { invoice_part: Number(invoicePart), invoice_part_reason: partReason.trim() } : {}),
            ...dates,
            narration: narration || undefined,
            lines: [
              ...po.lines
                .filter((line) => Number(quantities[line.line_id] ?? 0) > 0)
                .map((line): Record<string, unknown> => ({
                  po_line_id: line.line_id,
                  qty: quantities[line.line_id] ?? '0',
                  rate: rates[line.line_id] ?? line.agreed_rate,
                  discount_pc: discounts[line.line_id] ?? line.discount_pc ?? '0',
                })),
              ...(['freight', 'other_charges'] as const)
                .filter((kind) => Number(charges[kind].amount || 0) > 0)
                .map((kind): Record<string, unknown> => ({
                  is_service: true,
                  charge_kind: kind,
                  description: kind === 'freight' ? 'Freight' : 'Other charges',
                  purchase_acc_id: charges[kind].ledger?.id,
                  qty: '1',
                  rate: charges[kind].amount,
                })),
            ],
          }
        : {
            supplier_account_id: supplier?.acc_id,
            bill_kind: direct.every((l) => l.kind === 'service') ? 'service' : 'direct',
            supplier_invoice_no: invoiceNo.trim(),
            ...dates,
            narration: narration || undefined,
            ...(openReceipts && confirmDifferent ? { confirm_no_open_grn: true, confirm_no_open_grn_reason: confirmReason.trim() } : {}),
            lines: direct
              .filter((l) => Number(l.qty || 0) > 0 && Number(l.rate || 0) > 0)
              .map((l) =>
                l.kind === 'service'
                  ? { is_service: true, description: l.description.trim() || 'Service', purchase_acc_id: l.purchase_acc_id ?? undefined, qty: l.qty, rate: l.rate }
                  : { item_id: l.item_id ?? undefined, description: l.description.trim() || undefined, warehouse_id: l.warehouse_id ? Number(l.warehouse_id) : undefined, qty: l.qty, rate: l.rate },
              ),
          }
      const response = await api.post<BillRequest>('v1/bills', body)
      navigate(`/bills/${response.data.request_id}`)
    } catch (err) {
      if (err instanceof ApiError && err.code === 'gstin_differs_from_ledger') {
        setGstinDiffers(String(err.details.ledger_gstin ?? ''))
        setError(err.message)
        return
      }
      if (err instanceof ApiError && err.code === 'open_goods_receipts' && Array.isArray(err.details.receipts)) {
        setOpenReceipts(err.details.receipts as OpenReceipt[])
        setError(null)
        return
      }
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  if (poId && order.loading) return <p style={{ color: 'var(--muted)' }}>Loading the purchase order…</p>
  if (poId && !po) return <Notice tone="warning">That purchase order does not exist.</Notice>

  // In fixed point, line by line as the server computes them (F452).
  const total = po
    ? decimal.sum(po.lines.map((line) => decimal.lineAmount(quantities[line.line_id] ?? '0', rates[line.line_id] ?? line.agreed_rate, discounts[line.line_id] ?? line.discount_pc ?? '0')))
    : decimal.sum(direct.map((l) => decimal.lineAmount(l.qty || '0', l.rate || '0')))
  const currency = po?.currency_code ?? 'INR'
  const setLine = (key: string, patch: Partial<DirectLine>) => setDirect((current) => current.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const missingLedger = !po && direct.some((l) => l.kind === 'service' && Number(l.rate || 0) > 0 && l.purchase_acc_id === null)

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '64rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Enter supplier bill</h1>
      {po ? (
        <p style={{ margin: 0, color: 'var(--muted)' }}>
          Against <Link to={`/purchase-orders/${po.po_id}`}>{po.po_no}</Link> · {po.supplier_name_snapshot ?? `Ledger #${po.supplier_account_id}`}
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
          {po && (
            <Field label="Part of the invoice" hint="When one supplier invoice covers several orders, enter each order's bill as a part of the same invoice number.">
              <Input type="number" min="1" max="20" step="1" value={invoicePart} onChange={(e) => setInvoicePart(e.target.value)} />
            </Field>
          )}
          {po && Number(invoicePart) > 1 && (
            <Field label="Why is this invoice entered in parts?" hint="Sent to Smart Books with the bill (at least 10 characters).">
              <Input value={partReason} onChange={(e) => setPartReason(e.target.value)} />
            </Field>
          )}
          <Field label="Supplier GSTIN on the invoice (optional)" hint="Only when it differs from the one on their ledger in Smart Books. Its state decides how the GST is split.">
            <Input value={supplierGstin} maxLength={15} onChange={(e) => setSupplierGstin(e.target.value.toUpperCase())} />
          </Field>
          {gstinDiffers !== null && (
            <Field label={`Why not their ledger's GSTIN${gstinDiffers ? ` (${gstinDiffers})` : ''}?`} hint="Recorded with the bill. Leave the GSTIN blank to use the ledger's.">
              <Input value={gstinReason} onChange={(e) => setGstinReason(e.target.value)} />
            </Field>
          )}
          <Field label="Reverse charge" hint="You owe this GST to the government, not the supplier: import of services, or a notified supply.">
            <Select value={reverseCharge} onChange={(e) => setReverseCharge(e.target.value as '' | 'yes' | 'no')}>
              <option value="">As the supplier's ledger implies</option>
              <option value="yes">Yes — reverse charge</option>
              <option value="no">No — an ordinary purchase</option>
            </Select>
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
              {
                key: 'discount',
                header: 'Discount %',
                numeric: true,
                render: (line) => (
                  <Input aria-label={`Discount of line ${line.line_no}`} value={discounts[line.line_id] ?? ''} inputMode="decimal" onChange={(e) => setDiscounts({ ...discounts, [line.line_id]: e.target.value })} style={{ width: '5rem', textAlign: 'right' }} />
                ),
              },
              {
                key: 'agreed',
                header: 'Agreed (net)',
                numeric: true,
                render: (line) => (
                  <span title={Number(line.discount_pc) > 0 ? `${money(line.agreed_rate, currency)} less ${line.discount_pc}%` : undefined}>
                    {money(Number(line.ordered_qty) > 0 ? Number(line.line_amount) / Number(line.ordered_qty) : Number(line.agreed_rate), currency)}
                  </span>
                ),
              },
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
      {openReceipts && (
        <Notice tone="warning" title="These goods may already be in stock">
          <div data-testid="open-receipts" style={{ display: 'grid', gap: '0.5rem' }}>
            <span>
              An unbilled goods receipt of this supplier already brought in the same items. A bill with no order receives its goods
              itself, so saving it as it stands would put them into stock a second time.
            </span>
            <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
              {openReceipts.map((r) => (
                <li key={`${r.inventory_document_id}-${r.item_id}`}>
                  {r.receipt_no ?? r.document_no ?? `Inventory document #${r.inventory_document_id}`}
                  {r.document_date ? ` (${date(r.document_date)})` : ''}: {qty(r.open_qty)} of item #{r.item_id} not billed
                  {r.po_id ? <> — <Link to={`/bills/new?po_id=${r.po_id}`}>bill it against {r.po_no ?? 'its order'}</Link></> : null}
                </li>
              ))}
            </ul>
            <label style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
              <input type="checkbox" checked={confirmDifferent} onChange={(e) => setConfirmDifferent(e.target.checked)} />
              These are different goods from the ones on those receipts
            </label>
            {confirmDifferent && (
              <Field label="Why (recorded with the bill and shown to whoever reviews it)">
                <Input value={confirmReason} onChange={(e) => setConfirmReason(e.target.value)} />
              </Field>
            )}
          </div>
        </Notice>
      )}
      {po && (Number(po.freight_amount || 0) > 0 || Number(po.other_charges || 0) > 0) && (
        <Card title="Charges the order agreed">
          <p style={{ margin: '0 0 0.6rem', color: 'var(--muted)', fontSize: '0.85rem' }}>
            Billed freight or other charges are matched against the order&apos;s own figure, and booked to the ledger you choose. They are not added to the goods&apos; stock cost: Smart Books does not capitalise a charge on a bill that settles goods receipts.
          </p>
          {(['freight', 'other_charges'] as const)
            .filter((kind) => Number(kind === 'freight' ? po.freight_amount : po.other_charges) > 0)
            .map((kind) => (
              <div key={kind} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.55rem', marginBottom: '0.5rem' }}>
                <Field label={`${kind === 'freight' ? 'Freight' : 'Other charges'} billed (order: ${kind === 'freight' ? po.freight_amount : po.other_charges})`}>
                  <Input type="number" min="0" step="any" value={charges[kind].amount} onChange={(e) => setCharges((c) => ({ ...c, [kind]: { ...c[kind], amount: e.target.value } }))} />
                </Field>
                <LedgerPicker
                  onPick={(ledger) => setCharges((c) => ({ ...c, [kind]: { ...c[kind], ledger: { id: ledger.acc_id, name: ledger.acc_name } } }))}
                  selectedLabel={charges[kind].ledger?.name ?? null}
                />
              </div>
            ))}
        </Card>
      )}
      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button tone="primary" disabled={saving || invoiceNo.trim() === '' || (['freight', 'other_charges'] as const).some((k) => Number(charges[k].amount || 0) > 0 && charges[k].ledger === null) || (!po && (supplier === null || missingLedger)) || (openReceipts !== null && (!confirmDifferent || confirmReason.trim().length < 10))} onClick={save}>
          {saving ? 'Matching…' : 'Save and match'}
        </Button>
      </div>
    </div>
  )
}
