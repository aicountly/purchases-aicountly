/**
 * One enquiry: what was asked for, who was asked, what they came back with, and
 * the decision.
 *
 * Moved here from `pages/Sourcing.tsx` when sourcing became a folder. The code
 * is unchanged — the list beside it was rebuilt, and this screen was not part
 * of that.
 */

import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError } from '../../services/api'
import type { CatalogSupplier, Comparison, Rfq } from '../../services/types'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'
import { SupplierPicker } from '../../components/LivePicker'
import { Button, Card, DataTable, date, Field, money, Notice, qty, StatusBadge, Textarea } from '../../ui'

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
