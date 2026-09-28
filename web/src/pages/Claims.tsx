import { useState } from 'react'
import { api, ApiError } from '../services/api'
import type { CatalogSupplier, Claim, ClaimResolution } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { LedgerPicker, SupplierPicker } from '../components/LivePicker'
import { Button, Card, DataTable, date, Field, Input, money, Notice, Select, StatusBadge, Textarea } from '../ui'

const KINDS = ['shortage', 'damage', 'rate_difference', 'scheme', 'rebate', 'quality', 'late_delivery', 'other']

const CLAIM_BADGE: Record<string, string> = {
  DRAFT: 'DRAFT',
  SUBMITTED: 'PENDING',
  SUPPLIER_RESPONDED: 'APPROVAL_PENDING',
  APPROVED: 'APPROVED',
  PARTIALLY_SETTLED: 'PARTIALLY_SETTLED',
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
  const [openClaimId, setOpenClaimId] = useState<number | null>(null)

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
        A claim is settled by what actually happens — a debit note in Smart Books, goods sent back, a replacement received,
        a refund recorded in Smart Books, or an outcome without money — and counts only once that has happened.
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
                  {['APPROVED', 'PARTIALLY_SETTLED', 'SETTLED'].includes(row.status) && (
                    <Button disabled={busy} onClick={() => setOpenClaimId(openClaimId === row.claim_id ? null : row.claim_id)}>
                      {openClaimId === row.claim_id ? 'Hide' : 'Resolutions'}
                    </Button>
                  )}
                </div>
              ),
            },
          ]}
        />
      </Card>

      {openClaimId !== null && <ClaimResolutions claimId={openClaimId} onChanged={reload} />}
    </div>
  )
}

const RESOLUTION_KINDS: Array<[ClaimResolution['kind'], string]> = [
  ['financial_adjustment', 'Debit note in Smart Books'],
  ['physical_return', 'Send goods back'],
  ['replacement', 'Supplier replaces the goods'],
  ['refund', 'Supplier refunds the money'],
  ['non_financial', 'Resolved without money'],
]

/**
 * How a claim is settled: each resolution shows what it will do before anyone approves it, and
 * counts only when what it depends on has happened in Books or Inventory.
 */
function ClaimResolutions({ claimId, onChanged }: { claimId: number; onChanged: () => void }) {
  const { can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [kind, setKind] = useState<ClaimResolution['kind']>('financial_adjustment')
  const [amount, setAmount] = useState('')
  const [ledger, setLedger] = useState<{ id: number; name: string } | null>(null)
  const [note, setNote] = useState('')
  const [poLine, setPoLine] = useState('')
  const [lineQty, setLineQty] = useState('')
  const [evidence, setEvidence] = useState<Record<number, string>>({})

  const { data, reload } = useApi((signal) => api.one<Claim>(`v1/claims/${claimId}`, undefined, signal), [claimId], true)
  const claim = data?.data

  async function run(path: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setError(null)
    try {
      await api.post(path, body)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
      reload()
      onChanged()
    }
  }

  if (!claim) return null
  const approved = Number(claim.approved_amount ?? claim.claimed_amount)
  const open = ['APPROVED', 'PARTIALLY_SETTLED'].includes(claim.status)

  return (
    <Card title={`${claim.claim_no} — ${money(claim.settled_amount)} of ${money(approved)} settled`}>
      <div style={{ display: 'grid', gap: '0.85rem' }}>
        {error && <Notice tone="danger" onDismiss={() => setError(null)}>{error}</Notice>}

        {(claim.resolutions ?? []).map((r) => (
          <div key={r.resolution_id} style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', padding: '0.65rem 0.8rem' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '0.75rem', flexWrap: 'wrap', alignItems: 'center' }}>
              <strong>{RESOLUTION_KINDS.find(([k]) => k === r.kind)?.[1]} · {money(r.amount)}</strong>
              <StatusBadge status={r.status} />
            </div>
            {r.proposed_effect?.summary && <p style={{ margin: '0.35rem 0 0', fontSize: '0.88rem' }}>{r.proposed_effect.summary}</p>}
            {r.last_error && <p style={{ margin: '0.35rem 0 0', fontSize: '0.85rem', color: 'var(--danger)' }}>{r.last_error}</p>}
            {can('claim.settle') && (
              <div style={{ display: 'flex', gap: '0.4rem', marginTop: '0.5rem', flexWrap: 'wrap', alignItems: 'end' }}>
                {['PROPOSED', 'FAILED', 'UNCERTAIN'].includes(r.status) && (
                  <Button tone="primary" disabled={busy} onClick={() => run(`v1/claim-resolutions/${r.resolution_id}/approve`)}>
                    {r.status === 'PROPOSED' ? 'Approve and carry out' : 'Retry'}
                  </Button>
                )}
                {r.status === 'IN_PROGRESS' && ['replacement', 'refund'].includes(r.kind) && (
                  <>
                    <Field label={r.kind === 'refund' ? 'Books receipt voucher id' : 'GRN (receipt request id)'}>
                      <Input value={evidence[r.resolution_id] ?? ''} inputMode="numeric" onChange={(e) => setEvidence({ ...evidence, [r.resolution_id]: e.target.value })} style={{ width: '10rem' }} />
                    </Field>
                    <Button
                      disabled={busy || !(evidence[r.resolution_id] ?? '').trim()}
                      onClick={() =>
                        run(`v1/claim-resolutions/${r.resolution_id}/link`, r.kind === 'refund' ? { books_voucher_id: Number(evidence[r.resolution_id]) } : { receipt_request_id: Number(evidence[r.resolution_id]) })
                      }
                    >
                      Link and verify
                    </Button>
                  </>
                )}
                {['PROPOSED', 'APPROVED', 'FAILED', 'BLOCKED', 'IN_PROGRESS'].includes(r.status) && (
                  <Button
                    tone="ghost"
                    disabled={busy}
                    onClick={() => {
                      const reason = window.prompt('Why is this resolution dropped?')
                      if (reason && reason.trim()) void run(`v1/claim-resolutions/${r.resolution_id}/cancel`, { reason: reason.trim() })
                    }}
                  >
                    Drop
                  </Button>
                )}
              </div>
            )}
          </div>
        ))}

        {open && can('claim.create') && (
          <div style={{ display: 'grid', gap: '0.6rem', borderTop: '1px solid var(--border)', paddingTop: '0.75rem' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.6rem' }}>
              <Field label="Resolve by">
                <Select value={kind} onChange={(e) => setKind(e.target.value as ClaimResolution['kind'])}>
                  {RESOLUTION_KINDS.filter(([k]) => claim.po_id !== null || !['physical_return', 'replacement'].includes(k)).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                </Select>
              </Field>
              <Field label="Amount it settles"><Input value={amount} inputMode="decimal" onChange={(e) => setAmount(e.target.value)} /></Field>
              {kind === 'financial_adjustment' && <LedgerPicker onPick={(l) => setLedger({ id: l.acc_id, name: l.acc_name })} selectedLabel={ledger?.name ?? null} />}
              {kind === 'physical_return' && (
                <>
                  <Field label="Order line id"><Input value={poLine} inputMode="numeric" onChange={(e) => setPoLine(e.target.value)} /></Field>
                  <Field label="Qty going back"><Input value={lineQty} inputMode="decimal" onChange={(e) => setLineQty(e.target.value)} /></Field>
                </>
              )}
            </div>
            {kind === 'non_financial' && <Field label="What the supplier agreed instead"><Input value={note} onChange={(e) => setNote(e.target.value)} /></Field>}
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <Button
                disabled={busy || (kind !== 'non_financial' && Number(amount) <= 0) || (kind === 'financial_adjustment' && !ledger) || (kind === 'non_financial' && note.trim() === '')}
                onClick={() =>
                  run(`v1/claims/${claim.claim_id}/resolutions`, {
                    kind,
                    amount: Number(amount || 0),
                    adjustment_acc_id: ledger?.id,
                    note: note.trim() || undefined,
                    lines: kind === 'physical_return' ? [{ po_line_id: Number(poLine), return_qty: Number(lineQty) }] : undefined,
                  })
                }
              >
                Propose — see its effect before approving
              </Button>
            </div>
          </div>
        )}
      </div>
    </Card>
  )
}
