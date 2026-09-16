import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { CatalogSupplier, Comparison, Rfq } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { ItemPicker, SupplierPicker } from '../components/LivePicker'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, Select, StatusBadge, Textarea } from '../ui'

const STATUSES = ['', 'DRAFT', 'ISSUED', 'RESPONSES_OPEN', 'EVALUATING', 'AWARDED', 'CANCELLED', 'CLOSED']

export function RfqList() {
  const navigate = useNavigate()
  const { scope, can } = usePurchases()
  const [status, setStatus] = useState('')

  const { data, loading, error } = useApi(
    (signal) => api.list<Rfq>('v1/rfqs', { status: status || undefined, limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id, status],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Sourcing</h1>
        {can('rfq.create') && (
          <Button tone="primary" onClick={() => navigate('/rfqs/new')}>
            <Plus size={15} aria-hidden /> New RFQ
          </Button>
        )}
      </header>

      {error && <Notice tone="danger" title="Could not load RFQs">{error}</Notice>}

      <Card
        title={`${data?.meta.total ?? 0} RFQ${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
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
          rowKey={(row) => row.rfq_id}
          onRowClick={(row) => navigate(`/rfqs/${row.rfq_id}`)}
          empty="No RFQs yet."
          columns={[
            { key: 'no', header: 'Number', render: (row) => <Link to={`/rfqs/${row.rfq_id}`} onClick={(e) => e.stopPropagation()}>{row.rfq_no}</Link> },
            { key: 'title', header: 'Title', render: (row) => row.title ?? '—' },
            { key: 'date', header: 'Raised', render: (row) => date(row.rfq_date) },
            { key: 'deadline', header: 'Responses by', render: (row) => date(row.response_deadline) },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
          ]}
        />
      </Card>
    </div>
  )
}

export function RfqDetail() {
  const { id } = useParams<{ id: string }>()
  const { scope, can } = usePurchases()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [inviting, setInviting] = useState(false)
  const [rationale, setRationale] = useState('')
  const [awardQuoteId, setAwardQuoteId] = useState<number | null>(null)

  const { data, loading, reload } = useApi(
    (signal) => api.one<Rfq>(`v1/rfqs/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  const comparison = useApi(
    (signal) => api.one<Comparison>(`v1/rfqs/${id}/comparison`, undefined, signal),
    [id, scope?.cmp_id, data?.data?.quotes.length],
    Boolean(scope && id && data),
  )

  async function act(path: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setError(null)
    try {
      await api.post(`v1/rfqs/${id}/${path}`, body)
      reload()
      comparison.reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>

  const rfq = data?.data
  if (!rfq) return <Notice tone="warning">That RFQ does not exist.</Notice>

  const columns = comparison.data?.data.quotes ?? []
  const lowest = comparison.data?.data.lowest_landed ?? null

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/rfqs" style={{ fontSize: '0.85rem' }}>← Sourcing</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {rfq.rfq_no}
            <StatusBadge status={rfq.status} />
          </h1>
          <p style={{ margin: '0.3rem 0 0', color: 'var(--muted)' }}>
            {rfq.title ?? 'Untitled'} · raised {date(rfq.rfq_date)}
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {rfq.status === 'DRAFT' && can('rfq.create') && (
            <Button tone="primary" disabled={busy} onClick={() => act('issue')}>Issue to suppliers</Button>
          )}
          {can('rfq.create') && rfq.status !== 'AWARDED' && (
            <Button disabled={busy} onClick={() => setInviting(!inviting)}>Invite a supplier</Button>
          )}
        </div>
      </header>

      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}

      {inviting && (
        <Card title="Invite a supplier">
          <SupplierPicker
            onPick={(supplier: CatalogSupplier) => {
              void act('invite', { supplier_account_id: supplier.acc_id })
              setInviting(false)
            }}
          />
        </Card>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(20rem, 1fr))', gap: '1rem' }}>
        <Card title="What we asked for">
          <DataTable
            rows={rfq.lines}
            rowKey={(line) => line.line_id}
            columns={[
              { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
              { key: 'item', header: 'Item', render: (line) => line.description ?? `Inventory item ${line.item_id}` },
              { key: 'qty', header: 'Quantity', numeric: true, render: (line) => qty(line.required_qty) },
              { key: 'by', header: 'Needed by', render: (line) => date(line.required_by) },
            ]}
          />
        </Card>

        <Card title="Who we asked">
          <DataTable
            rows={rfq.invitations}
            rowKey={(row) => row.invitation_id}
            empty="Nobody invited yet."
            columns={[
              { key: 'supplier', header: 'Supplier', render: (row) => `Account ${row.supplier_account_id}` },
              { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
              { key: 'responded', header: 'Responded', render: (row) => date(row.responded_at) },
            ]}
          />
        </Card>
      </div>

      <Card title="Comparative statement">
        {columns.length === 0 ? (
          <p style={{ color: 'var(--muted)', margin: 0 }}>No quotations yet.</p>
        ) : (
          <>
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.88rem' }}>
                <thead>
                  <tr>
                    <th style={headStyle}>Supplier</th>
                    <th style={{ ...headStyle, textAlign: 'right' }}>Lines</th>
                    <th style={{ ...headStyle, textAlign: 'right' }}>Freight</th>
                    <th style={{ ...headStyle, textAlign: 'right' }}>Other</th>
                    <th style={{ ...headStyle, textAlign: 'right' }}>Estimated landed</th>
                    <th style={headStyle}>Payment terms</th>
                    <th style={{ ...headStyle, textAlign: 'right' }}>Lead days</th>
                    <th style={headStyle}>Valid until</th>
                    <th style={headStyle} />
                  </tr>
                </thead>
                <tbody>
                  {columns.map((column) => {
                    const isLowest = lowest !== null && Math.abs(column.estimated_landed_total - lowest) < 0.005
                    return (
                      <tr key={column.quote_id} style={{ background: isLowest ? 'var(--success-bg)' : undefined }}>
                        <td style={cellStyle}>
                          Account {column.supplier_account_id}
                          {column.revision_no > 0 && (
                            <span style={{ color: 'var(--muted)', fontSize: '0.78rem' }}> rev {column.revision_no}</span>
                          )}
                        </td>
                        <td className="num" style={cellStyle}>{money(column.line_total, column.currency_code)}</td>
                        <td className="num" style={cellStyle}>{money(column.freight_amount, column.currency_code)}</td>
                        <td className="num" style={cellStyle}>{money(column.other_charges, column.currency_code)}</td>
                        <td className="num" style={{ ...cellStyle, fontWeight: 600, color: isLowest ? 'var(--success)' : undefined }}>
                          {money(column.estimated_landed_total, column.currency_code)}
                        </td>
                        <td style={cellStyle}>{column.payment_terms ?? '—'}</td>
                        <td className="num" style={cellStyle}>{column.delivery_days ?? '—'}</td>
                        <td style={cellStyle}>{date(column.valid_until)}</td>
                        <td style={cellStyle}>
                          {rfq.status !== 'AWARDED' && can('rfq.award') && (
                            <Button onClick={() => setAwardQuoteId(column.quote_id)}>Award</Button>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
              {comparison.data?.data.note}
            </p>
          </>
        )}
      </Card>

      {awardQuoteId !== null && (
        <Card title="Award this RFQ">
          <div style={{ display: 'grid', gap: '0.75rem' }}>
            <Notice tone="info">
              The cheapest unit rate is often not the cheapest purchase. Say why this supplier won — it is the one thing
              an auditor will want to read six months from now.
            </Notice>
            <Field label="Rationale">
              <Textarea value={rationale} onChange={(e) => setRationale(e.target.value)} placeholder="Dearer per unit but delivers free, and the alternative is two weeks out." />
            </Field>
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setAwardQuoteId(null)}>Cancel</Button>
              <Button
                tone="primary"
                disabled={busy}
                onClick={async () => {
                  const column = columns.find((c) => c.quote_id === awardQuoteId)
                  if (!column) return
                  await act('award', {
                    awards: column.lines.map((line) => ({
                      rfq_line_id: line.rfq_line_id,
                      quote_id: column.quote_id,
                      qty: line.quoted_qty,
                      rate: line.quoted_rate,
                      rationale: rationale.trim() || undefined,
                    })),
                  })
                  setAwardQuoteId(null)
                  setRationale('')
                }}
              >
                Award
              </Button>
            </div>
          </div>
        </Card>
      )}

      {rfq.awards.length > 0 && (
        <Card title="Awarded">
          <DataTable
            rows={rfq.awards}
            rowKey={(row) => row.award_id}
            columns={[
              { key: 'qty', header: 'Quantity', numeric: true, render: (row) => qty(row.awarded_qty) },
              { key: 'rate', header: 'Rate', numeric: true, render: (row) => money(row.awarded_rate) },
              { key: 'why', header: 'Rationale', render: (row) => row.rationale ?? '—' },
              { key: 'when', header: 'Decided', render: (row) => date(row.decided_at) },
            ]}
          />
          {can('po.create') && (
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '0.85rem' }}>
              <Button tone="primary" onClick={() => navigate(`/purchase-orders/new?rfq_id=${rfq.rfq_id}`)}>
                Raise the purchase order
              </Button>
            </div>
          )}
        </Card>
      )}
    </div>
  )
}

const headStyle = {
  textAlign: 'left' as const,
  padding: '0.5rem 0.6rem',
  borderBottom: '1px solid var(--border-strong)',
  color: 'var(--muted)',
  fontWeight: 600,
  fontSize: '0.78rem',
  textTransform: 'uppercase' as const,
  letterSpacing: '0.03em',
  whiteSpace: 'nowrap' as const,
}

const cellStyle = { padding: '0.5rem 0.6rem', borderBottom: '1px solid var(--border)' }

export function RfqEditor() {
  const navigate = useNavigate()
  const [title, setTitle] = useState('')
  const [deadline, setDeadline] = useState('')
  const [requiredBy, setRequiredBy] = useState('')
  const [terms, setTerms] = useState('')
  const [suppliers, setSuppliers] = useState<Array<{ id: number; name: string }>>([])
  const [lines, setLines] = useState<Array<{ key: string; item_id: number | null; label: string; qty: string }>>([
    { key: 'a', item_id: null, label: '', qty: '1' },
  ])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function save() {
    setSaving(true)
    setError(null)
    try {
      const response = await api.post<Rfq>('v1/rfqs', {
        title: title || undefined,
        response_deadline: deadline || undefined,
        required_by: requiredBy || undefined,
        commercial_terms: terms || undefined,
        supplier_account_ids: suppliers.map((s) => s.id),
        lines: lines.filter((l) => l.item_id).map((l) => ({ item_id: l.item_id, required_qty: Number(l.qty || 0), description: l.label })),
      })
      navigate(`/rfqs/${response.data.rfq_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '56rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>New RFQ</h1>
      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}

      <Card title="Enquiry">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.85rem' }}>
          <Field label="Title"><Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Q3 steel" /></Field>
          <Field label="Responses by"><Input type="date" value={deadline} onChange={(e) => setDeadline(e.target.value)} /></Field>
          <Field label="Delivery needed by"><Input type="date" value={requiredBy} onChange={(e) => setRequiredBy(e.target.value)} /></Field>
        </div>
        <div style={{ marginTop: '0.85rem' }}>
          <Field label="Commercial terms"><Textarea value={terms} onChange={(e) => setTerms(e.target.value)} /></Field>
        </div>
      </Card>

      <Card title="Suppliers to invite">
        <SupplierPicker
          onPick={(supplier: CatalogSupplier) =>
            setSuppliers((current) =>
              current.some((s) => s.id === supplier.acc_id) ? current : [...current, { id: supplier.acc_id, name: supplier.acc_name }],
            )
          }
        />
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginTop: '0.75rem' }}>
          {suppliers.map((supplier) => (
            <span
              key={supplier.id}
              style={{ padding: '0.2rem 0.6rem', borderRadius: '999px', background: 'var(--surface-2)', border: '1px solid var(--border)', fontSize: '0.85rem' }}
            >
              {supplier.name}
              <button
                type="button"
                onClick={() => setSuppliers((c) => c.filter((s) => s.id !== supplier.id))}
                style={{ marginLeft: '0.4rem', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--muted)' }}
                aria-label={`Remove ${supplier.name}`}
              >
                ×
              </button>
            </span>
          ))}
          {suppliers.length === 0 && <span style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>None yet.</span>}
        </div>
      </Card>

      <Card
        title="Lines"
        action={<Button onClick={() => setLines((c) => [...c, { key: Math.random().toString(36).slice(2), item_id: null, label: '', qty: '1' }])}>Add line</Button>}
      >
        <div style={{ display: 'grid', gap: '0.75rem' }}>
          {lines.map((line) => (
            <div key={line.key} style={{ display: 'grid', gridTemplateColumns: '3fr 1fr auto', gap: '0.6rem', alignItems: 'end' }}>
              <ItemPicker
                selectedLabel={line.label || null}
                onPick={(item) =>
                  setLines((c) => c.map((l) => (l.key === line.key ? { ...l, item_id: item.item_id, label: item.item_name } : l)))
                }
              />
              <Field label="Quantity">
                <Input value={line.qty} inputMode="decimal" onChange={(e) => setLines((c) => c.map((l) => (l.key === line.key ? { ...l, qty: e.target.value } : l)))} />
              </Field>
              <Button tone="ghost" onClick={() => setLines((c) => (c.length > 1 ? c.filter((l) => l.key !== line.key) : c))}>Remove</Button>
            </div>
          ))}
        </div>
      </Card>

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button tone="primary" disabled={saving} onClick={save}>{saving ? 'Saving…' : 'Save RFQ'}</Button>
      </div>
    </div>
  )
}

