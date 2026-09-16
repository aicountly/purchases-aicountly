import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { PurchaseReturn } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { CommandStrip } from '../components/CommandStrip'
import { Button, Card, DataTable, date, money, Notice, qty, StatusBadge } from '../ui'

export function ReturnsList() {
  const navigate = useNavigate()
  const { scope } = usePurchases()
  const { data, loading, error } = useApi(
    (signal) => api.list<PurchaseReturn>('v1/returns', { limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Purchase returns</h1>

      <Notice tone="info">
        Purchases decides whether goods go back and on what terms. Inventory moves the stock out and Smart Books raises
        the debit note — this screen links the three without holding a copy of either.
      </Notice>

      {error && <Notice tone="danger" title="Could not load returns">{error}</Notice>}

      <Card title={`${data?.meta.total ?? 0} return${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}>
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.return_id}
          onRowClick={(row) => navigate(`/returns/${row.return_id}`)}
          empty="No returns raised."
          columns={[
            { key: 'no', header: 'Number', render: (row) => <Link to={`/returns/${row.return_id}`} onClick={(e) => e.stopPropagation()}>{row.return_no}</Link> },
            { key: 'date', header: 'Raised', render: (row) => date(row.return_date) },
            { key: 'supplier', header: 'Supplier', render: (row) => `Account ${row.supplier_account_id}` },
            { key: 'reason', header: 'Reason', render: (row) => row.reason_note ?? row.reason_code ?? '—' },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
          ]}
        />
      </Card>
    </div>
  )
}

export function ReturnDetail() {
  const { id } = useParams<{ id: string }>()
  const { scope, can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data, loading, reload } = useApi(
    (signal) => api.one<PurchaseReturn>(`v1/returns/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  async function act(path: string) {
    setBusy(true)
    setError(null)
    try {
      await api.post(`v1/returns/${id}/${path}`, {})
      reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>

  const item = data?.data
  if (!item) return <Notice tone="warning">That return does not exist.</Notice>

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/returns" style={{ fontSize: '0.85rem' }}>← Purchase returns</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {item.return_no}
            <StatusBadge status={item.status} />
          </h1>
        </div>
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {item.status === 'DRAFT' && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('approve')}>Approve</Button>
          )}
          {item.status === 'APPROVED' && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('dispatch')}>Send back through Inventory</Button>
          )}
          {['DISPATCHED', 'APPROVED'].includes(item.status) && !item.books_debit_note_uuid && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('debit-note')}>Raise debit note in Books</Button>
          )}
        </div>
      </header>

      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}

      <CommandStrip commands={item.commands} busy={busy} />

      <Card title="Where this stands">
        <dl style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.4rem 1rem', margin: 0 }}>
          <dt style={{ color: 'var(--muted)' }}>Reason</dt>
          <dd style={{ margin: 0 }}>{item.reason_note ?? item.reason_code ?? '—'}</dd>
          <dt style={{ color: 'var(--muted)' }}>Stock movement</dt>
          <dd style={{ margin: 0 }}>{item.inventory_document_uuid ?? 'Not sent back yet'}</dd>
          <dt style={{ color: 'var(--muted)' }}>Books debit note</dt>
          <dd style={{ margin: 0 }}>{item.books_debit_note_uuid ?? 'Not raised yet'}</dd>
        </dl>
      </Card>

      <Card title="Lines">
        <DataTable
          rows={item.lines}
          rowKey={(line) => line.line_id}
          columns={[
            { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
            { key: 'item', header: 'Item', render: (line) => `Inventory item ${line.item_id}` },
            { key: 'qty', header: 'Returning', numeric: true, render: (line) => qty(line.return_qty) },
            { key: 'rate', header: 'Rate', numeric: true, render: (line) => money(line.rate) },
            { key: 'amount', header: 'Amount', numeric: true, render: (line) => money(line.line_amount) },
            { key: 'reason', header: 'Reason', render: (line) => line.reason_code ?? '—' },
          ]}
        />
      </Card>
    </div>
  )
}
