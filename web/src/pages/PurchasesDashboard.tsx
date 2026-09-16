import { Link } from 'react-router-dom'
import { api } from '../services/api'
import type { DashboardSummary } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { Card, DataTable, money, Notice, StatCard } from '../ui'

export default function PurchasesDashboard() {
  const { scope } = usePurchases()
  const { data, loading, error, reload } = useApi(
    (signal) => api.one<DashboardSummary>('v1/dashboard', undefined, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id],
    Boolean(scope),
  )

  if (error) {
    return (
      <Notice tone="danger" title="Could not load the dashboard">
        {error} <button onClick={reload}>Retry</button>
      </Notice>
    )
  }

  const summary = data?.data

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Procurement</h1>

      {summary && summary.attention.match_exceptions > 0 && (
        <Notice
          tone="danger"
          title={`${summary.attention.match_exceptions} bill${summary.attention.match_exceptions === 1 ? '' : 's'} failed the three-way match`}
          action={<Link to="/bills?exceptions=1">Review</Link>}
        >
          A bill does not agree with what was ordered or what arrived. Nothing is posted to Books until somebody decides
          what to do about it.
        </Notice>
      )}

      {summary && summary.attention.stuck_commands > 0 && (
        <Notice tone="danger" title={`${summary.attention.stuck_commands} cross-app request did not complete`}>
          Something we asked Books or Inventory to do did not finish. Open the document to see the error and retry —
          nothing is retried behind your back.
        </Notice>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))', gap: '0.75rem' }}>
        <StatCard
          label="Requisitions"
          value={loading ? '…' : (summary?.requisitions.total ?? 0)}
          hint={`${summary?.requisitions.awaiting_approval ?? 0} awaiting approval`}
          tone={(summary?.requisitions.awaiting_approval ?? 0) > 0 ? 'warning' : 'default'}
        />
        <StatCard
          label="RFQs out"
          value={loading ? '…' : (summary?.rfqs.awaiting_response ?? 0)}
          hint={`${summary?.rfqs.total ?? 0} in period`}
        />
        <StatCard label="Open orders" value={loading ? '…' : (summary?.orders.open ?? 0)} hint={`${summary?.orders.total ?? 0} raised`} />
        <StatCard
          label="Overdue deliveries"
          value={loading ? '…' : (summary?.orders.overdue ?? 0)}
          tone={(summary?.orders.overdue ?? 0) > 0 ? 'danger' : 'default'}
        />
        <StatCard label="Awaiting receipt" value={loading ? '…' : money(summary?.pipeline.awaiting_receipt_value)} hint="ordered, not yet arrived" />
        <StatCard label="Awaiting bill" value={loading ? '…' : money(summary?.pipeline.awaiting_bill_value)} hint="arrived, not yet billed" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(20rem, 1fr))', gap: '1rem' }}>
        <Card title="From Books, live">
          {summary?.financial.available ? (
            <div style={{ display: 'grid', gap: '0.75rem' }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem' }}>
                <StatCard
                  label="Payable"
                  value={money(summary.financial.payable_total ?? 0)}
                  hint={`${summary.financial.payable_count ?? 0} open bills`}
                />
                <StatCard
                  label="Overdue"
                  value={money(summary.financial.payable_overdue ?? 0)}
                  tone={(summary.financial.payable_overdue ?? 0) > 0 ? 'danger' : 'default'}
                />
              </div>
              <p style={{ color: 'var(--muted)', fontSize: '0.8rem', margin: 0 }}>
                Read from Smart Books on this page load. Purchases keeps no payable figure of its own, so this is never
                out of date and never disagrees with the accounts.
              </p>
            </div>
          ) : (
            <Notice tone="warning" title="Books did not answer">
              {summary?.financial.reason ??
                'The payable cards need Smart Books. Everything above is from Purchases and is unaffected.'}
            </Notice>
          )}
        </Card>

        <Card title="Top suppliers" action={<Link to="/purchase-orders">All orders</Link>}>
          <DataTable
            loading={loading}
            rows={summary?.top_suppliers ?? []}
            rowKey={(row) => row.supplier_account_id}
            empty="No orders in this period."
            columns={[
              {
                key: 'name',
                header: 'Supplier',
                render: (row) => row.supplier_name_snapshot ?? `Account ${row.supplier_account_id}`,
              },
              { key: 'orders', header: 'Orders', numeric: true, render: (row) => row.po_count },
              { key: 'value', header: 'Ordered', numeric: true, render: (row) => money(row.ordered_value) },
            ]}
          />
        </Card>
      </div>
    </div>
  )
}
