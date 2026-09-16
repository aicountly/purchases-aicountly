import { useState } from 'react'
import { api, ApiError } from '../services/api'
import type { CatalogSupplier, Claim } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { SupplierPicker } from '../components/LivePicker'
import { Button, Card, DataTable, date, Field, Input, money, Notice, Select, StatusBadge, Textarea } from '../ui'

const KINDS = ['shortage', 'damage', 'rate_difference', 'scheme', 'rebate', 'quality', 'late_delivery', 'other']

const CLAIM_BADGE: Record<string, string> = {
  DRAFT: 'DRAFT',
  SUBMITTED: 'PENDING',
  SUPPLIER_RESPONDED: 'APPROVAL_PENDING',
  APPROVED: 'APPROVED',
  REJECTED: 'FAILED',
  SETTLED: 'COMPLETED',
  CLOSED: 'CLOSED',
}

export default function Claims() {
  const { scope, can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ supplier: null as { id: number; name: string } | null, kind: 'shortage', amount: '', description: '' })
  const [settleFor, setSettleFor] = useState<Claim | null>(null)
  const [settleAmount, setSettleAmount] = useState('')

  const { data, loading, reload } = useApi(
    (signal) => api.list<Claim>('v1/claims', { limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id],
    Boolean(scope),
  )

  async function run(fn: () => Promise<unknown>) {
    setBusy(true)
    setError(null)
    try {
      await fn()
      reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Supplier claims</h1>
        {can('claim.create') && <Button tone="primary" onClick={() => setCreating(!creating)}>New claim</Button>}
      </header>

      <Notice tone="info">
        A claim recovers money where there are no goods to send back — a shortage, a rate difference, a scheme not
        passed on. Where it has an accounting consequence, Smart Books records it.
      </Notice>

      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}

      {creating && (
        <Card title="Raise a claim">
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.85rem' }}>
            <SupplierPicker
              selectedLabel={form.supplier?.name}
              onPick={(s: CatalogSupplier) => setForm({ ...form, supplier: { id: s.acc_id, name: s.acc_name } })}
            />
            <Field label="Kind">
              <Select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                {KINDS.map((kind) => <option key={kind} value={kind}>{kind.replace(/_/g, ' ')}</option>)}
              </Select>
            </Field>
            <Field label="Amount claimed"><Input value={form.amount} inputMode="decimal" onChange={(e) => setForm({ ...form, amount: e.target.value })} /></Field>
          </div>
          <div style={{ marginTop: '0.85rem' }}>
            <Field label="What happened"><Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
          </div>
          <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end', marginTop: '0.85rem' }}>
            <Button onClick={() => setCreating(false)}>Cancel</Button>
            <Button
              tone="primary"
              disabled={busy || !form.supplier || Number(form.amount) <= 0}
              onClick={() =>
                run(async () => {
                  await api.post('v1/claims', {
                    supplier_account_id: form.supplier!.id,
                    claim_kind: form.kind,
                    claimed_amount: Number(form.amount),
                    description: form.description || undefined,
                  })
                  setCreating(false)
                  setForm({ supplier: null, kind: 'shortage', amount: '', description: '' })
                })
              }
            >
              Raise claim
            </Button>
          </div>
        </Card>
      )}

      <Card title={`${data?.meta.total ?? 0} claim${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}>
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.claim_id}
          empty="No claims raised."
          columns={[
            { key: 'no', header: 'Number', render: (row) => row.claim_no },
            { key: 'date', header: 'Raised', render: (row) => date(row.claim_date) },
            { key: 'supplier', header: 'Supplier', render: (row) => `Account ${row.supplier_account_id}` },
            { key: 'kind', header: 'Kind', render: (row) => row.claim_kind.replace(/_/g, ' ') },
            { key: 'claimed', header: 'Claimed', numeric: true, render: (row) => money(row.claimed_amount) },
            { key: 'settled', header: 'Settled', numeric: true, render: (row) => (Number(row.settled_amount) > 0 ? money(row.settled_amount) : '—') },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={CLAIM_BADGE[row.status] ?? 'DRAFT'} /> },
            {
              key: 'actions',
              header: '',
              render: (row) => (
                <div style={{ display: 'flex', gap: '0.35rem' }}>
                  {row.status === 'DRAFT' && can('claim.create') && (
                    <Button disabled={busy} onClick={() => run(() => api.post(`v1/claims/${row.claim_id}/submit`, {}))}>Submit</Button>
                  )}
                  {['SUBMITTED', 'SUPPLIER_RESPONDED'].includes(row.status) && can('claim.settle') && (
                    <>
                      <Button tone="primary" disabled={busy} onClick={() => run(() => api.post(`v1/claims/${row.claim_id}/approve`, {}))}>Approve</Button>
                      <Button tone="danger" disabled={busy} onClick={() => run(() => api.post(`v1/claims/${row.claim_id}/reject`, {}))}>Reject</Button>
                    </>
                  )}
                  {row.status === 'APPROVED' && can('claim.settle') && (
                    <Button
                      disabled={busy}
                      onClick={() => {
                        setSettleFor(row)
                        setSettleAmount(row.claimed_amount)
                      }}
                    >
                      Settle
                    </Button>
                  )}
                </div>
              ),
            },
          ]}
        />
      </Card>

      {settleFor && (
        <Card title={`Settle ${settleFor.claim_no}`}>
          <Field label="Amount settled" hint={`Claimed ${money(settleFor.claimed_amount)}. A claim cannot settle for more than was claimed.`}>
            <Input value={settleAmount} inputMode="decimal" onChange={(e) => setSettleAmount(e.target.value)} />
          </Field>
          <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end', marginTop: '0.85rem' }}>
            <Button onClick={() => setSettleFor(null)}>Cancel</Button>
            <Button
              tone="primary"
              disabled={busy}
              onClick={() =>
                run(async () => {
                  await api.post(`v1/claims/${settleFor.claim_id}/settle`, { settled_amount: Number(settleAmount) })
                  setSettleFor(null)
                })
              }
            >
              Settle
            </Button>
          </div>
        </Card>
      )}
    </div>
  )
}
