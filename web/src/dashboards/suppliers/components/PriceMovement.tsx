/**
 * What the same things cost over the period.
 *
 * PRICES ARE COMPARED LIKE WITH LIKE — same item, same unit, same currency,
 * rate before discount, freight and tax. Anything else is not a price move, and
 * the server is what enforces that; this card only draws what it returns.
 *
 * The table already states a first and a last rate. Two numbers cannot tell a
 * steady climb from a spike that came back down, and those are different
 * negotiations, so the indexed path is drawn above them.
 */

import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-react'
import { DashboardPanel, DataTable, EmptyState, PanelUnavailable } from '../../shell'
import { IndexLineChart } from '../../charts'
import type { PricePanel } from '../types'

export function PriceMovement({ price }: { price: PricePanel }) {
  return (
    <DashboardPanel
      title="Price movement"
      description={price.available ? price.basis : undefined}
      className="purchase-span-all"
    >
      {!price.available ? (
        <PanelUnavailable reason={price.reason} kind={price.kind} />
      ) : price.rows.length === 0 ? (
        <EmptyState title="No item has enough repeat orders to show a movement.">
          At least {price.min_sample} orders of the same item, unit and currency are needed.
        </EmptyState>
      ) : (
        <>
          <IndexLineChart
            title="Price path by item"
            summary={
              'Agreed rate per month for the items that moved most, indexed to 100 at the first month each was ordered. ' +
              price.rows
                .slice(0, 4)
                .map((row) => `${row.item_label} ended at ${row.last_formatted}`)
                .join('. ')
            }
            baseLabel={price.index_note ?? 'Indexed to 100 at each item’s first month in this period.'}
            series={price.rows.slice(0, 4).map((row) => ({
              id: `${row.item_id}-${row.currency}`,
              label: row.item_label,
              points: (row.points ?? []).map((point) => ({
                period: point.period,
                index: point.index,
                formatted: point.formatted,
              })),
            }))}
          />

          <DataTable
            caption={price.basis}
            rows={price.rows}
            rowKey={(row) => `${row.item_id}-${row.currency}`}
            empty={<EmptyState title="No comparable price history." />}
            columns={[
              {
                key: 'item',
                header: 'Item',
                render: (row) => (
                  <>
                    {row.item_label}
                    <span className="purchase-table__sub">
                      {row.unit ? `per ${row.unit} · ` : ''}
                      {row.observations} order{row.observations === 1 ? '' : 's'}, {row.supplier_count} supplier
                      {row.supplier_count === 1 ? '' : 's'}
                    </span>
                  </>
                ),
              },
              {
                key: 'first',
                header: 'First',
                numeric: true,
                render: (row) => (
                  <>
                    {row.first_formatted}
                    <span className="purchase-table__sub">{row.first_date}</span>
                  </>
                ),
              },
              {
                key: 'last',
                header: 'Latest',
                numeric: true,
                render: (row) => (
                  <>
                    {row.last_formatted}
                    <span className="purchase-table__sub">{row.last_date}</span>
                  </>
                ),
              },
              {
                key: 'change',
                header: 'Change',
                numeric: true,
                render: (row) => (
                  <span
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: 4,
                      color:
                        row.direction === 'up'
                          ? 'var(--purchase-bad)'
                          : row.direction === 'down'
                            ? 'var(--purchase-good)'
                            : 'var(--purchase-muted)',
                      fontWeight: 650,
                    }}
                  >
                    {row.direction === 'up' ? (
                      <ArrowUpRight size={13} aria-hidden />
                    ) : row.direction === 'down' ? (
                      <ArrowDownRight size={13} aria-hidden />
                    ) : (
                      <ArrowRight size={13} aria-hidden />
                    )}
                    {row.change_pc === null ? '—' : `${row.change_pc}%`}
                  </span>
                ),
              },
            ]}
          />
        </>
      )}
    </DashboardPanel>
  )
}
