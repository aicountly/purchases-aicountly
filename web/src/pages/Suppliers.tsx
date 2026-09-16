import { useState } from 'react'
import { api, ApiError } from '../services/api'
import type { CatalogSupplier, Scorecard, SupplierProfile } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { SupplierPicker } from '../components/LivePicker'
import { Card, DataTable, money, Notice, qty, Select, StatCard, StatusBadge } from '../ui'

const STATUS_BADGE: Record<string, string> = {
  approved: 'APPROVED',
  pending_approval: 'APPROVAL_PENDING',
  draft: 'DRAFT',
  suspended: 'FAILED',
  blacklisted: 'BLOCKED',
}

export default function Suppliers() {
  const { scope, can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [openId, setOpenId] = useState<number | null>(null)

  const profiles = useApi(
    (signal) => api.get<{ data: SupplierProfile[] }>('v1/suppliers', undefined, signal),
    [scope?.cmp_id],
    Boolean(scope),
  )

  const scorecard = useApi(
    (signal) => api.one<Scorecard>(`v1/suppliers/${openId}/scorecard`, undefined, signal),
    [openId, scope?.cmp_id],
    Boolean(scope && openId),
  )

  async function addSupplier(supplier: CatalogSupplier) {
    setBusy(true)
    setError(null)
    try {
      await api.post('v1/suppliers', { supplier_account_id: supplier.acc_id })
      profiles.reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function setStatus(supplierId: number, status: string) {
    setBusy(true)
    setError(null)
    try {
      await api.post(`v1/suppliers/${supplierId}/status`, { qualification_status: status })
      profiles.reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  const card = scorecard.data?.data

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Suppliers</h1>

      <Notice tone="info">
        A supplier's identity belongs to Contacts and their account ledger to Smart Books. What lives here is only what
        procurement decides about them: approved or not, preferred or not, and how they actually perform.
      </Notice>

      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}

      {can('supplier.manage') && (
        <Card title="Add a supplier to the procurement list">
          <SupplierPicker onPick={(supplier) => void addSupplier(supplier)} />
        </Card>
      )}

      <Card title={`${profiles.data?.data.length ?? 0} supplier profile${(profiles.data?.data.length ?? 0) === 1 ? '' : 's'}`}>
        <DataTable
          loading={profiles.loading}
          rows={profiles.data?.data ?? []}
          rowKey={(row) => row.profile_id}
          onRowClick={(row) => setOpenId(row.supplier_account_id === openId ? null : row.supplier_account_id)}
          empty="No procurement profiles yet."
          columns={[
            { key: 'account', header: 'Books account', render: (row) => `#${row.supplier_account_id}` },
            { key: 'status', header: 'Qualification', render: (row) => <StatusBadge status={STATUS_BADGE[row.qualification_status] ?? 'DRAFT'} /> },
            { key: 'preferred', header: 'Preferred', render: (row) => (row.is_preferred ? 'Yes' : '—') },
            { key: 'lead', header: 'Lead days', numeric: true, render: (row) => row.operational_lead_days ?? '—' },
            { key: 'terms', header: 'Payment terms', render: (row) => row.payment_terms ?? '—' },
            {
              key: 'actions',
              header: '',
              render: (row) =>
                can('supplier.approve') ? (
                  <Select
                    value={row.qualification_status}
                    disabled={busy}
                    onClick={(e) => e.stopPropagation()}
                    onChange={(e) => {
                      e.stopPropagation()
                      void setStatus(row.supplier_account_id, e.target.value)
                    }}
                    style={{ width: '10rem' }}
                  >
                    {['draft', 'pending_approval', 'approved', 'suspended', 'blacklisted'].map((value) => (
                      <option key={value} value={value}>{value.replace(/_/g, ' ')}</option>
                    ))}
                  </Select>
                ) : null,
            },
          ]}
        />
      </Card>

      {openId !== null && card && (
        <Card title={`Scorecard — account #${openId}`}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(10rem, 1fr))', gap: '0.75rem' }}>
            <StatCard
              label="On-time delivery"
              value={card.on_time_delivery_pc === null ? '—' : `${qty(card.on_time_delivery_pc)}%`}
              tone={card.on_time_delivery_pc !== null && card.on_time_delivery_pc < 80 ? 'warning' : 'default'}
            />
            <StatCard
              label="Rejection rate"
              value={card.quality_rejection_pc === null ? '—' : `${qty(card.quality_rejection_pc)}%`}
              tone={card.quality_rejection_pc !== null && card.quality_rejection_pc > 2 ? 'danger' : 'default'}
            />
            <StatCard label="Fulfilment" value={card.fulfilment_pc === null ? '—' : `${qty(card.fulfilment_pc)}%`} />
            <StatCard label="Claims" value={card.claim_count} tone={card.claim_count > 0 ? 'warning' : 'default'} />
            <StatCard label="Overall" value={card.overall_score === null ? 'Not enough data' : qty(card.overall_score)} />
          </div>
          <p style={{ color: 'var(--muted)', fontSize: '0.82rem', marginTop: '0.85rem', marginBottom: 0 }}>
            {card.basis_summary.po_count} purchase order{card.basis_summary.po_count === 1 ? '' : 's'} worth{' '}
            {money(card.basis_summary.ordered_value)}, {card.basis_summary.receipt_count} receipt
            {card.basis_summary.receipt_count === 1 ? '' : 's'}. {card.basis_summary.note}
          </p>
        </Card>
      )}
    </div>
  )
}
