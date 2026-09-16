import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { ApprovalRequest, MatchException } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { Button, Card, DataTable, date, money, Notice, StatusBadge } from '../ui'

type ApprovalRow = ApprovalRequest & {
  requisition_no?: string | null
  requisition_value?: string | null
  po_no?: string | null
  supplier_name_snapshot?: string | null
  po_value?: string | null
}

type ExceptionRow = MatchException & {
  verdict?: string
  bill_request_id?: number | null
  supplier_invoice_no?: string | null
  supplier_account_id?: number | null
  po_no?: string | null
}

export default function Approvals() {
  const navigate = useNavigate()
  const { scope, can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const approvals = useApi(
    (signal) => api.list<ApprovalRow>('v1/approvals', { status: 'PENDING', limit: 100 }, signal),
    [scope?.cmp_id],
    Boolean(scope),
  )

  const exceptions = useApi(
    (signal) => api.list<ExceptionRow>('v1/match-exceptions', { limit: 100 }, signal),
    [scope?.cmp_id],
    Boolean(scope && can('match.view')),
  )

  async function decide(row: ApprovalRow, action: 'approve' | 'reject') {
    const base = row.entity_type === 'requisition' ? 'v1/requisitions' : 'v1/purchase-orders'
    setBusy(true)
    setError(null)
    try {
      await api.post(`${base}/${row.entity_id}/${action}`, action === 'reject' ? { note: 'Rejected from the approvals inbox.' } : {})
      approvals.reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Approvals</h1>

      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}

      <Card title={`${approvals.data?.meta.total ?? 0} waiting on you`}>
        <DataTable
          loading={approvals.loading}
          rows={approvals.data?.data ?? []}
          rowKey={(row) => row.approval_id}
          empty="Nothing is waiting for approval."
          columns={[
            {
              key: 'doc',
              header: 'Document',
              render: (row) => (
                <button
                  type="button"
                  onClick={() =>
                    navigate(row.entity_type === 'requisition' ? `/requisitions/${row.entity_id}` : `/purchase-orders/${row.entity_id}`)
                  }
                  style={{ background: 'none', border: 'none', color: 'var(--link)', cursor: 'pointer', padding: 0 }}
                >
                  {row.requisition_no ?? row.po_no ?? `${row.entity_type} #${row.entity_id}`}
                </button>
              ),
            },
            { key: 'who', header: 'Supplier / raised by', render: (row) => row.supplier_name_snapshot ?? row.requested_by },
            { key: 'why', header: 'Why', render: (row) => row.reason_detail ?? row.reason_kind },
            { key: 'value', header: 'Value', numeric: true, render: (row) => money(row.po_value ?? row.requisition_value) },
            { key: 'raised', header: 'Raised', render: (row) => date(row.created_at) },
            {
              key: 'actions',
              header: '',
              render: (row) => {
                const permitted = row.entity_type === 'requisition' ? can('requisition.approve') : can('po.approve')
                if (!permitted) return null
                return (
                  <div style={{ display: 'flex', gap: '0.35rem' }}>
                    <Button tone="primary" disabled={busy} onClick={() => decide(row, 'approve')}>Approve</Button>
                    <Button tone="danger" disabled={busy} onClick={() => decide(row, 'reject')}>Reject</Button>
                  </div>
                )
              },
            },
          ]}
        />
        <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
          A document cannot be approved by the person who raised it.
        </p>
      </Card>

      {can('match.view') && (
        <Card title={`${exceptions.data?.meta.total ?? 0} open match exception${(exceptions.data?.meta.total ?? 0) === 1 ? '' : 's'}`}>
          <DataTable
            loading={exceptions.loading}
            rows={exceptions.data?.data ?? []}
            rowKey={(row) => row.exception_id}
            onRowClick={(row) => row.bill_request_id && navigate(`/bills/${row.bill_request_id}`)}
            empty="Every bill agrees with its order and its receipt."
            columns={[
              { key: 'invoice', header: 'Supplier invoice', render: (row) => row.supplier_invoice_no ?? `#${row.bill_request_id}` },
              { key: 'po', header: 'Purchase order', render: (row) => row.po_no ?? '—' },
              { key: 'kind', header: 'Problem', render: (row) => row.exception_kind.replace(/_/g, ' ') },
              { key: 'detail', header: 'Detail', render: (row) => row.detail ?? '—' },
              { key: 'verdict', header: 'Verdict', render: (row) => <StatusBadge status={row.verdict === 'BLOCKED' ? 'BLOCKED' : 'PENDING'} /> },
              { key: 'raised', header: 'Found', render: (row) => date(row.created_at) },
            ]}
          />
        </Card>
      )}
    </div>
  )
}
