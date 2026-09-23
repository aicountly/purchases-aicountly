/**
 * One requisition: what was asked for, why, and where the approval has got to.
 *
 * Unchanged by the list screen's rebuild beyond its new home — this is the
 * record, and the record was not the complaint.
 */

import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, ApiError } from '../../services/api'
import type { Requisition } from '../../services/types'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, StatusBadge } from '../../ui'

export function RequisitionDetail() {
  const { id } = useParams<{ id: string }>()
  const { scope, can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [note, setNote] = useState('')

  const { data, loading, error, reload } = useApi(
    (signal) => api.one<Requisition>(`v1/requisitions/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  async function act(path: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setActionError(null)
    try {
      await api.post(`v1/requisitions/${id}/${path}`, body)
      setNote('')
      reload()
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>
  if (error) return <Notice tone="danger" title="Could not load this requisition">{error}</Notice>

  const requisition = data?.data
  if (!requisition) return <Notice tone="warning">That requisition does not exist.</Notice>

  const pending = requisition.approvals.filter((a) => a.status === 'PENDING')

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/requisitions" style={{ fontSize: '0.85rem' }}>← Requisitions</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {requisition.requisition_no}
            <StatusBadge status={requisition.status} />
          </h1>
          <p style={{ margin: '0.3rem 0 0', color: 'var(--muted)' }}>
            {requisition.department ?? 'No department'} · raised {date(requisition.requisition_date)}
            {requisition.required_by && ` · needed by ${date(requisition.required_by)}`}
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {requisition.status === 'DRAFT' && can('requisition.create') && (
            <Button tone="primary" disabled={busy} onClick={() => act('submit')}>Submit for approval</Button>
          )}
          {requisition.status === 'APPROVED' && can('rfq.create') && (
            <Link to={`/rfqs/new?requisition_id=${requisition.requisition_id}`}>
              <Button tone="primary">Raise an RFQ</Button>
            </Link>
          )}
        </div>
      </header>

      {actionError && <Notice tone="danger" title="That did not work">{actionError}</Notice>}

      {pending.length > 0 && (
        <Card title="Waiting for approval">
          <ul style={{ margin: '0 0 0.85rem', paddingLeft: '1.1rem' }}>
            {pending.map((approval) => (
              <li key={approval.approval_id}>{approval.reason_detail ?? approval.reason_kind}</li>
            ))}
          </ul>
          {can('requisition.approve') && (
            <div style={{ display: 'grid', gap: '0.6rem' }}>
              <Field label="Note" hint="Required when rejecting.">
                <Input value={note} onChange={(e) => setNote(e.target.value)} />
              </Field>
              <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
                <Button tone="danger" disabled={busy || note.trim() === ''} onClick={() => act('reject', { note: note.trim() })}>
                  Reject
                </Button>
                <Button tone="primary" disabled={busy} onClick={() => act('approve', { note: note.trim() || undefined })}>
                  Approve
                </Button>
              </div>
              <p style={{ color: 'var(--muted)', fontSize: '0.78rem', margin: 0 }}>
                A requisition cannot be approved by the person who raised it.
              </p>
            </div>
          )}
        </Card>
      )}

      <Card title="Lines">
        <DataTable
          rows={requisition.lines}
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
            { key: 'qty', header: 'Needed', numeric: true, render: (line) => qty(line.required_qty) },
            { key: 'ordered', header: 'Ordered', numeric: true, render: (line) => qty(line.ordered_qty) },
            { key: 'rate', header: 'Estimated rate', numeric: true, render: (line) => money(line.estimated_rate) },
            { key: 'by', header: 'Needed by', render: (line) => date(line.required_by) },
          ]}
        />
        <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
          The estimated rate routes the approval. It is not a price — that is agreed with a supplier during sourcing —
          and it is not a valuation, which belongs to Inventory.
        </p>
      </Card>

      {requisition.justification && (
        <Card title="Justification">
          <p style={{ margin: 0 }}>{requisition.justification}</p>
        </Card>
      )}
    </div>
  )
}
