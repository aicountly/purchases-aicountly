import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { CatalogSupplier, PurchaseOrder, PurchaseReturn } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { CommandStrip, recoveryPath } from '../components/CommandStrip'
import { LedgerPicker, SupplierPicker } from '../components/LivePicker'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, StatusBadge } from '../ui'
import { DiscussInConnect } from '../components/ConnectEmbed'

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
  const [panel, setPanel] = useState<'none' | 'recall' | 'cancel'>('none')
  const [reason, setReason] = useState('')

  const { data, loading, reload } = useApi(
    (signal) => api.one<PurchaseReturn>(`v1/returns/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  async function post(path: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setError(null)
    try {
      await api.post(path, body)
      setPanel('none')
      setReason('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
      reload()
    }
  }
  const act = (path: string, body: Record<string, unknown> = {}) => post(`v1/returns/${id}/${path}`, body)

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>

  const item = data?.data
  if (!item) return <Notice tone="warning">That return does not exist.</Notice>
  const physical = item.return_kind === 'physical'
  const canDebit = physical ? item.status === 'DISPATCHED' : item.status === 'APPROVED' && can('return.financial_adjustment')

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/returns" style={{ fontSize: '0.85rem' }}>← Purchase returns</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {item.return_no}
            <StatusBadge status={item.status} />
          </h1>
          <p style={{ margin: '0.3rem 0 0', color: 'var(--muted)' }}>
            {physical ? 'Goods going back' : 'A debit note with nothing going back'}
            {physical && (item.expect_replacement ? ' · the supplier will replace them' : ' · not to be replaced')}
            {item.po_id && <> · <Link to={`/purchase-orders/${item.po_id}`}>the purchase order</Link></>}
          </p>
        </div>
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          <DiscussInConnect entityType="purchase_return" entityId={Number(id)} />
          {item.status === 'DRAFT' && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('approve')}>Approve</Button>
          )}
          {physical && item.status === 'APPROVED' && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('dispatch')}>Record the dispatch in Inventory</Button>
          )}
          {canDebit && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('debit-note')}>Raise the debit note in Smart Books</Button>
          )}
          {physical && item.status === 'DISPATCHED' && can('return.approve') && (
            <Button disabled={busy} onClick={() => setPanel('recall')}>Recall</Button>
          )}
          {['DRAFT', 'APPROVED'].includes(item.status) && can('return.create') && (
            <Button tone="danger" disabled={busy} onClick={() => setPanel('cancel')}>Cancel</Button>
          )}
        </div>
      </header>

      {error && <Notice tone="danger" title="That did not work" onDismiss={() => setError(null)}>{error}</Notice>}

      <CommandStrip
        commands={item.commands}
        busy={busy}
        onRetry={(command) => {
          const path = recoveryPath(command, 'retry')
          if (path) void post(path)
        }}
      />

      {physical && item.status === 'APPROVED' && (
        <Notice tone="info">
          The debit note is raised once Inventory has recorded the goods leaving. The goods leave stock once — with the debit note, which settles the dispatch.
        </Notice>
      )}

      {panel !== 'none' && (
        <Card title={panel === 'recall' ? 'Bring the goods back' : 'Cancel this return'}>
          <div style={{ display: 'grid', gap: '0.6rem' }}>
            <Field label="Why"><Input value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setPanel('none')}>Back</Button>
              <Button tone="danger" disabled={busy || reason.trim() === ''} onClick={() => act(panel, { reason: reason.trim() })}>
                {panel === 'recall' ? 'Recall the goods' : 'Cancel the return'}
              </Button>
            </div>
          </div>
        </Card>
      )}

      <Card title="Where this stands">
        <dl style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.4rem 1rem', margin: 0 }}>
          <dt style={{ color: 'var(--muted)' }}>Reason</dt>
          <dd style={{ margin: 0 }}>{item.adjustment_reason ?? item.reason_note ?? item.reason_code ?? '—'}</dd>
          {physical && (
            <>
              <dt style={{ color: 'var(--muted)' }}>Dispatch in Inventory</dt>
              <dd style={{ margin: 0 }}>{item.inventory_document_id ? `Document #${item.inventory_document_id}` : 'Not recorded yet'}</dd>
            </>
          )}
          <dt style={{ color: 'var(--muted)' }}>Debit note in Smart Books</dt>
          <dd style={{ margin: 0 }}>{item.books_debit_note_id ? `Voucher #${item.books_debit_note_id}` : 'Not raised yet'}</dd>
          {item.recall_reason && (
            <>
              <dt style={{ color: 'var(--muted)' }}>Recalled</dt>
              <dd style={{ margin: 0 }}>{item.recall_reason}</dd>
            </>
          )}
        </dl>
      </Card>

      <Card title="Lines">
        <DataTable
          rows={item.lines}
          rowKey={(line) => line.line_id}
          columns={[
            { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
            { key: 'item', header: physical ? 'Item' : 'For', render: (line) => (line.item_id ? `Inventory item ${line.item_id}` : line.reason_code ?? 'Adjustment') },
            { key: 'qty', header: physical ? 'Returning' : 'Qty', numeric: true, render: (line) => qty(line.return_qty) },
            { key: 'rate', header: 'Rate', numeric: true, render: (line) => money(line.rate) },
            { key: 'amount', header: 'Amount', numeric: true, render: (line) => money(line.line_amount) },
          ]}
        />
      </Card>
    </div>
  )
}

/**
 * Raise a return: goods going back against an order (only what was received and billed, less
 * other returns), or a financial return — a debit note on a ledger with nothing going back,
 * which needs its own authority and a reason.
 */
export function ReturnEditor() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const poId = searchParams.get('po_id')
  const { scope, can } = usePurchases()
  const [kind, setKind] = useState<'physical' | 'financial'>(poId ? 'physical' : 'financial')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [replace, setReplace] = useState(false)
  const [reason, setReason] = useState('')
  const [supplier, setSupplier] = useState<CatalogSupplier | null>(null)
  const [ledger, setLedger] = useState<{ id: number; name: string } | null>(null)
  const [amount, setAmount] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const order = useApi(
    (signal) => api.one<PurchaseOrder>(`v1/purchase-orders/${poId}`, undefined, signal),
    [poId, scope?.cmp_id],
    Boolean(scope && poId),
  )
  const po = order.data?.data

  async function save() {
    setSaving(true)
    setError(null)
    try {
      const body =
        kind === 'physical'
          ? {
              return_kind: 'physical',
              supplier_account_id: po?.supplier_account_id,
              po_id: po?.po_id,
              expect_replacement: replace,
              reason_note: reason.trim() || undefined,
              lines: (po?.lines ?? [])
                .filter((l) => Number(quantities[l.line_id] ?? 0) > 0)
                .map((l) => ({ po_line_id: l.line_id, return_qty: Number(quantities[l.line_id]) })),
            }
          : {
              return_kind: 'financial',
              supplier_account_id: po?.supplier_account_id ?? supplier?.acc_id,
              po_id: po?.po_id,
              adjustment_acc_id: ledger?.id,
              adjustment_reason: reason.trim(),
              lines: [{ description: reason.trim() || 'Adjustment', return_qty: 1, rate: Number(amount) }],
            }
      const response = await api.post<PurchaseReturn>('v1/returns', body)
      navigate(`/returns/${response.data.return_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  if (poId && order.loading) return <p style={{ color: 'var(--muted)' }}>Loading the purchase order…</p>
  const progress = new Map((po?.progress?.lines ?? []).map((l) => [l.line_id, l]))

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '56rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Raise a purchase return</h1>
      {error && <Notice tone="danger" title="Could not save" onDismiss={() => setError(null)}>{error}</Notice>}

      <Card title="What kind">
        <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
          <label style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
            <input type="radio" checked={kind === 'physical'} disabled={!po} onChange={() => setKind('physical')} /> Goods go back to the supplier
          </label>
          <label style={{ display: 'flex', gap: '0.4rem', alignItems: 'center' }}>
            <input type="radio" checked={kind === 'financial'} disabled={!can('return.financial_adjustment')} onChange={() => setKind('financial')} /> A debit note only — nothing goes back
          </label>
        </div>
        {!can('return.financial_adjustment') && (
          <p style={{ margin: '0.5rem 0 0', color: 'var(--muted)', fontSize: '0.8rem' }}>A debit note with nothing going back needs the financial-adjustment permission.</p>
        )}
      </Card>

      {kind === 'physical' && po && (
        <Card title={`Against ${po.po_no}`}>
          <DataTable
            rows={po.lines.filter((l) => !l.is_service)}
            rowKey={(l) => l.line_id}
            columns={[
              { key: 'no', header: '#', width: '3rem', render: (l) => l.line_no },
              { key: 'item', header: 'Item', render: (l) => l.description ?? `Inventory item ${l.item_id}` },
              { key: 'kept', header: 'Received', numeric: true, render: (l) => qty(progress.get(l.line_id)?.net_received_qty ?? l.received_qty) },
              { key: 'billed', header: 'Billed', numeric: true, render: (l) => qty(progress.get(l.line_id)?.net_billed_qty ?? l.billed_qty) },
              {
                key: 'qty',
                header: 'Returning',
                numeric: true,
                render: (l) => (
                  <Input value={quantities[l.line_id] ?? ''} inputMode="decimal" onChange={(e) => setQuantities({ ...quantities, [l.line_id]: e.target.value })} style={{ width: '6rem', textAlign: 'right' }} />
                ),
              },
            ]}
          />
          <label style={{ display: 'flex', gap: '0.4rem', alignItems: 'center', marginTop: '0.75rem', fontSize: '0.9rem' }}>
            <input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} /> The supplier will replace these goods (the order keeps waiting for them)
          </label>
          <p style={{ color: 'var(--muted)', fontSize: '0.78rem', margin: '0.5rem 0 0' }}>
            Only goods that were received and billed can go back here. Goods not yet billed: bill what arrived first.
          </p>
        </Card>
      )}

      {kind === 'financial' && (
        <Card title="The adjustment">
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.75rem' }}>
            {!po && <SupplierPicker onPick={setSupplier} selectedLabel={supplier?.acc_name ?? null} />}
            <LedgerPicker onPick={(l) => setLedger({ id: l.acc_id, name: l.acc_name })} selectedLabel={ledger?.name ?? null} />
            <Field label="Amount"><Input value={amount} inputMode="decimal" onChange={(e) => setAmount(e.target.value)} /></Field>
          </div>
        </Card>
      )}

      <Card title="Why">
        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={kind === 'financial' ? 'Required: why a debit note is raised with nothing going back' : 'Quality, wrong item, damaged…'} />
      </Card>

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button
          tone="primary"
          disabled={saving || (kind === 'financial' ? !ledger || reason.trim() === '' || Number(amount) <= 0 || (!po && !supplier) : !po)}
          onClick={save}
        >
          Raise the return
        </Button>
      </div>
    </div>
  )
}
