import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { Claim } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { Button, Card, DataTable, date, Field, Input, money, Notice, StatusBadge } from '../ui'

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
  const navigate = useNavigate()
  // "My claims" is the same list, filtered by the server to the signed-in
  // session. It is a query parameter rather than a route so the filter is part
  // of the link somebody shares.
  const [params] = useSearchParams()
  const mine = params.get('mine') === '1'
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [settleFor, setSettleFor] = useState<Claim | null>(null)
  const [settleAmount, setSettleAmount] = useState('')

  const { data, loading, reload } = useApi(
    (signal) => api.list<Claim>('v1/claims', { limit: 100, mine: mine ? 1 : undefined }, signal),
    [scope?.cmp_id, scope?.fy_id, mine],
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
        <h1 style={{ margin: 0, fontSize: '1.3rem' }}>{mine ? 'My supplier claims' : 'Supplier claims'}</h1>
        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
          {mine ? (
            <Link to="/claims" style={{ fontSize: '0.85rem' }}>All claims</Link>
          ) : (
            <Link to="/claims?mine=1" style={{ fontSize: '0.85rem' }}>My claims</Link>
          )}
          {/* Raising a claim is its own screen now. It asks for the references,
              the lines and the resolution a supplier will want to see, none of
              which fitted in the three fields that used to sit here. */}
          {can('claim.create') && <Button tone="primary" onClick={() => navigate('/claims/new')}>New claim</Button>}
        </div>
      </header>

      <Notice tone="info">
        A claim recovers money where there are no goods to send back — a shortage, a rate difference, a scheme not
        passed on. Where it has an accounting consequence, Smart Books records it.
      </Notice>

      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}

      <Card title={`${data?.meta.total ?? 0} claim${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}>
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.claim_id}
          empty={mine ? 'You have not raised a claim yet.' : 'No claims raised.'}
          columns={[
            {
              key: 'no',
              header: 'Number',
              render: (row) => (
                <div>
                  <div>{row.claim_no}</div>
                  {row.subject && (
                    <div style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>{row.subject}</div>
                  )}
                </div>
              ),
            },
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
