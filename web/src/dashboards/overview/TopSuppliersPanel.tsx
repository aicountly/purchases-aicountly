/**
 * Top suppliers by spend.
 *
 * Three different products answer for one row, and the table keeps them apart:
 * SPEND is Books' (or, when Books is down, our own ordered value — the panel
 * says which), ON-TIME and RISK are ours, and PAYABLES is Books' again, fetched
 * separately once the screen has drawn.
 *
 * The distinction this table exists to preserve: "we have not rated this
 * supplier" is not "this supplier is risky". An unrated row says so in words
 * and wears a neutral badge; it never borrows the red one.
 */

import { useNavigate } from 'react-router-dom'
import { ArrowRight, Loader2 } from 'lucide-react'
import { Badge, DashboardPanel, EmptyState, PanelUnavailable } from '../shell'
import type { SupplierPayablesState } from './useSupplierPayables'
import type { TopSuppliersPanel as TopSuppliersPanelData } from '../types'

export function TopSuppliersPanel({
  panel,
  payables,
}: {
  panel: TopSuppliersPanelData
  payables: SupplierPayablesState
}) {
  const navigate = useNavigate()

  const open = (route: string, filters: Record<string, string> = {}) => {
    const query = new URLSearchParams(filters).toString()
    navigate(query === '' ? route : `${route}?${query}`)
  }

  return (
    <DashboardPanel
      title="Top suppliers by spend"
      description={panel.available ? `Top ${panel.suppliers.length} of ${panel.base_formatted}` : undefined}
      action={
        <button type="button" className="purchase-button purchase-button--quiet" onClick={() => open('/dashboard/suppliers')}>
          View all <ArrowRight size={13} aria-hidden />
        </button>
      }
      flush
    >
      {!panel.available ? (
        <div style={{ padding: 14 }}>
          <PanelUnavailable reason={panel.reason} kind={panel.kind} />
        </div>
      ) : panel.suppliers.length === 0 ? (
        <EmptyState title="No supplier spend in this period." />
      ) : (
        <>
          <div className="purchase-table-scroll">
            <table className="purchase-table purchase-table--compact">
              <caption className="purchase-sr-only">
                {panel.basis} {panel.scorecard_basis} {panel.payables_basis}
              </caption>
              <thead>
                <tr>
                  <th scope="col" style={{ width: 28 }}>#</th>
                  <th scope="col">Supplier</th>
                  <th scope="col" className="is-numeric">Spend</th>
                  <th scope="col" className="is-numeric">On-time</th>
                  <th scope="col">Risk</th>
                  <th scope="col" className="is-numeric">Payables</th>
                </tr>
              </thead>
              <tbody>
                {panel.suppliers.map((row, index) => {
                  const payable = row.supplier_account_id === null ? undefined : payables.byId.get(row.supplier_account_id)
                  const name = row.supplier_name ?? `Account ${row.supplier_account_id ?? '—'}`

                  return (
                    <tr key={`${row.supplier_account_id ?? name}`}>
                      <td>
                        <span className="purchase-table__rank">{index + 1}</span>
                      </td>
                      <td>
                        {row.supplier_account_id === null ? (
                          <span className="purchase-table__name" title={name}>{name}</span>
                        ) : (
                          <button
                            type="button"
                            className="purchase-table__link purchase-table__name"
                            title={name}
                            onClick={() => open(row.route, row.filters)}
                          >
                            {name}
                          </button>
                        )}
                        {row.share_pc !== null && <span className="purchase-table__sub">{row.share_pc}% of spend</span>}
                      </td>
                      <td className="is-numeric" title={row.formatted_amount}>
                        {row.compact_amount}
                      </td>
                      <td className="is-numeric" title={row.on_time_label}>
                        {row.on_time_pc === null ? <span className="purchase-muted">—</span> : `${row.on_time_pc}%`}
                      </td>
                      <td>
                        {/* "Not enough data" is the honest phrase and it is
                            what the tooltip says; in a six-column table on a
                            1366px laptop it is also 110px of badge that pushes
                            the payables column off the card. */}
                        <Badge tone={row.risk.tone === 'neutral' ? 'neutral' : row.risk.tone}>
                          <span title={row.risk.label}>{row.risk.id === 'unknown' ? 'Unrated' : row.risk.label}</span>
                        </Badge>
                      </td>
                      <td className="is-numeric">
                        {payables.loading && payable === undefined ? (
                          <Loader2 size={12} aria-label="Loading payables" className="purchase-spin" />
                        ) : payable === undefined ? (
                          <span className="purchase-muted">—</span>
                        ) : payable.available ? (
                          <span title={`${payable.formatted} across ${payable.bills} open bill${payable.bills === 1 ? '' : 's'}`}>
                            {payable.compact}
                          </span>
                        ) : (
                          <span className="purchase-muted" title={payable.reason}>
                            n/a
                          </span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <p className="purchase-muted" style={{ margin: 0, padding: '0 12px 12px', fontSize: 10 }}>
            {panel.scorecard_basis}
            {payables.error !== null && ` Payables could not be read: ${payables.error}`}
            {payables.asOnLabel !== null && ` Payables as at ${payables.asOnLabel}.`}
          </p>
        </>
      )}
    </DashboardPanel>
  )
}
