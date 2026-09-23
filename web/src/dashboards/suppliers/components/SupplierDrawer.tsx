/**
 * One supplier, opened from the ranking.
 *
 * This is the only place on the screen that goes outside Purchases for a
 * figure: the ledger position is read live from Smart Books at the moment the
 * drawer opens. It is never copied into this product's tables, which is why it
 * can say "as at" and mean it.
 */

import { Badge, DataTable, EmptyState, PanelUnavailable } from '../../shell'
import { Drawer } from '../../Drawer'
import type { DetailPanel } from '../types'

export function SupplierDrawer({
  panel,
  onClose,
  onOpen,
}: {
  panel: DetailPanel
  onClose: () => void
  onOpen: (route: string) => void
}) {
  if (!panel.available || panel.loaded !== true) return null

  const profile = panel.profile as Record<string, string | number | boolean | null> | null
  const orders = panel.orders as { po_id: number; po_no: string; po_date_label: string; status: string; open_lines: number; value_formatted: string | null; route: string }[]
  const claims = panel.claims as { claim_id: number; claim_no: string; claim_date: string; claim_kind: string; status: string; claimed: string }[]
  const prices = panel.price_history as { item_id: number; item_label: string; unit: string | null; currency: string; observations: number; min_rate: string; max_rate: string }[]
  const dues = panel.dues as
    | { available: true; as_of: string; total: string; overdue: string; undated_count: number; basis: string; rows: { bill_ref: string | null; due_date: string | null; pending_amount: string; days_overdue: number | null }[] }
    | { available: false; reason: string }

  return (
    <Drawer
      open
      title={`Supplier ${panel.supplier_account_id}`}
      subtitle={panel.identity_note as string}
      onClose={onClose}
    >
      <div style={{ display: 'grid', gap: 22 }}>
        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Procurement profile</h3>
          {profile === null ? (
            <div className="purchase-notice purchase-notice--info">
              <div>
                <strong>No procurement profile yet</strong>
                <p>Orders have been raised against this account, but nobody has qualified them here.</p>
              </div>
            </div>
          ) : (
            <dl className="purchase-dl">
              <dt>Qualification</dt>
              <dd>
                <Badge tone={profile.qualification_status === 'approved' ? 'success' : 'warning'}>
                  {String(profile.qualification_status).replace(/_/g, ' ')}
                </Badge>
              </dd>
              <dt>Preferred</dt>
              <dd>{profile.is_preferred ? 'Yes' : 'No'}</dd>
              <dt>Lead time</dt>
              <dd>{profile.operational_lead_days === null ? '—' : `${profile.operational_lead_days} days`}</dd>
              <dt>Payment terms</dt>
              <dd>{profile.payment_terms ?? '—'}</dd>
              <dt>Incoterm</dt>
              <dd>{profile.incoterm ?? '—'}</dd>
              <dt>Risk flag</dt>
              <dd>{profile.risk_flag ?? 'None'}</dd>
            </dl>
          )}
        </section>

        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Live position in Smart Books</h3>
          {!dues.available ? (
            <PanelUnavailable reason={dues.reason} kind="source" />
          ) : (
            <>
              <dl className="purchase-dl">
                <dt>Outstanding</dt>
                <dd>{dues.total}</dd>
                <dt>Overdue</dt>
                <dd style={{ color: 'var(--purchase-danger)' }}>{dues.overdue}</dd>
                <dt>As at</dt>
                <dd>{dues.as_of}</dd>
                <dt>No due date recorded</dt>
                <dd>{dues.undated_count}</dd>
              </dl>
              <p className="purchase-muted" style={{ fontSize: 12, marginTop: 8 }}>{dues.basis}</p>
            </>
          )}
        </section>

        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Recent orders</h3>
          <DataTable
            caption="Purchase orders raised with this supplier"
            rows={orders}
            rowKey={(row) => row.po_id}
            empty={<EmptyState title="No orders yet." />}
            onRowOpen={(row) => onOpen(row.route)}
            columns={[
              { key: 'po', header: 'Order', render: (row) => <>{row.po_no}<span className="purchase-table__sub">{row.po_date_label}</span></> },
              { key: 'status', header: 'Status', render: (row) => row.status.replace(/_/g, ' ') },
              { key: 'value', header: 'Value', numeric: true, render: (row) => row.value_formatted ?? '—' },
            ]}
          />
        </section>

        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Price history</h3>
          <DataTable
            caption="Agreed rates by item, from this supplier"
            rows={prices}
            rowKey={(row) => `${row.item_id}-${row.currency}`}
            empty={<EmptyState title="No priced order lines yet." />}
            columns={[
              { key: 'item', header: 'Item', render: (row) => <>{row.item_label}<span className="purchase-table__sub">{row.observations} orders{row.unit ? ` · per ${row.unit}` : ''}</span></> },
              { key: 'low', header: 'Lowest', numeric: true, render: (row) => row.min_rate },
              { key: 'high', header: 'Highest', numeric: true, render: (row) => row.max_rate },
            ]}
          />
        </section>

        <section>
          <h3 style={{ margin: '0 0 10px', fontSize: 14 }}>Claims and quality</h3>
          <DataTable
            caption="Claims raised against this supplier"
            rows={claims}
            rowKey={(row) => row.claim_id}
            empty={<EmptyState title="No claims raised." />}
            columns={[
              { key: 'claim', header: 'Claim', render: (row) => <>{row.claim_no}<span className="purchase-table__sub">{row.claim_kind.replace(/_/g, ' ')}</span></> },
              { key: 'status', header: 'Status', render: (row) => row.status.replace(/_/g, ' ') },
              { key: 'amount', header: 'Claimed', numeric: true, render: (row) => row.claimed },
            ]}
          />
        </section>
      </div>
    </Drawer>
  )
}
