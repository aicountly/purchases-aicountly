/**
 * One return, in full.
 *
 * The same body is rendered by the drawer on the register and by the page at
 * /returns/:id, because they answer the same question and maintaining two
 * answers to one question is how they end up disagreeing.
 *
 * WHAT IT SHOWS ABOUT THE OTHER TWO PRODUCTS is asked at the moment it is
 * opened, never stored. Inventory is asked what became of the stock document
 * and Smart Books what became of the debit note; when either cannot be reached
 * the panel says "unavailable" and gives the reason, because "we could not ask"
 * and "nothing was posted" are different facts and showing the second in place
 * of the first is lying about the company's stock and its books.
 */

import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  Boxes,
  Check,
  CircleDashed,
  FileCheck2,
  Receipt,
} from 'lucide-react'
import { useApi } from '../../hooks/useApi'
import { CommandStrip } from '../../components/CommandStrip'
import { money, qty } from '../../ui'
import { purchaseReturnsApi } from './api'
import type { PurchaseReturnDetail as ReturnRecord, ReturnIntegration } from './types'
import { Notice, Skeleton, StatusPill, CreditPill, shortDate } from './ui'

/**
 * The workflow, as far as it has got.
 *
 * Every step's state comes from a fact rather than from the status alone: the
 * stock step is done when Inventory gave us a document, the accounting step
 * when Books gave us one, the credit step when somebody recorded the supplier's
 * note. A timeline driven by status alone claims things that may not have
 * happened.
 */
function Timeline({ record }: { record: ReturnRecord }) {
  const cancelled = record.status === 'CANCELLED'
  const dispatched = record.inventory.status === 'POSTED'
  const booked = record.books.status === 'POSTED'
  const credited = record.supplier_credit.status === 'RECEIVED'
  const creditNotDue = record.supplier_credit.status === 'NOT_REQUIRED'
  const approved = ['APPROVED', 'DISPATCHED', 'DEBITED', 'CLOSED'].includes(record.status)

  const failed = new Set(
    record.commands
      .filter((command) => command.status === 'FAILED' || command.status === 'BLOCKED')
      .map((command) => (command.target_service === 'books' ? 'books' : 'inventory')),
  )

  const steps: Array<{ id: string; label: string; detail: string; state: string }> = [
    {
      id: 'created',
      label: 'Return raised',
      detail: `${shortDate(record.return_date)}${record.created_by ? ` by ${record.created_by}` : ''}`,
      state: 'is-done',
    },
    {
      id: 'approved',
      label: 'Approved',
      detail: cancelled
        ? 'Cancelled before approval'
        : approved
          ? 'Cleared to go back to the supplier'
          : 'Waiting for approval',
      state: cancelled && !approved ? 'is-skipped' : approved ? 'is-done' : 'is-current',
    },
    {
      id: 'inventory',
      label: 'Stock moved out — Inventory',
      detail: dispatched
        ? `Inventory document ${record.inventory.reference}`
        : failed.has('inventory')
          ? 'Inventory did not accept the movement'
          : 'Inventory has not been asked yet',
      state: dispatched ? 'is-done' : failed.has('inventory') ? 'is-failed' : approved ? 'is-current' : '',
    },
    {
      id: 'books',
      label: 'Debit note — Smart Books',
      detail: booked
        ? `Voucher ${record.books.reference}`
        : failed.has('books')
          ? 'Smart Books did not accept the debit note'
          : 'No debit note raised yet',
      state: booked ? 'is-done' : failed.has('books') ? 'is-failed' : dispatched ? 'is-current' : '',
    },
    {
      id: 'credit',
      label: 'Supplier credit note',
      detail: credited
        ? `${record.supplier_credit.reference}${record.supplier_credit.date ? ` on ${shortDate(record.supplier_credit.date)}` : ''}`
        : creditNotDue
          ? 'No credit due from the supplier'
          : 'Not received from the supplier yet',
      state: credited ? 'is-done' : creditNotDue ? 'is-skipped' : booked ? 'is-current' : '',
    },
  ]

  if (cancelled) {
    steps.push({
      id: 'cancelled',
      label: 'Cancelled',
      detail: record.cancel_reason ?? 'No reason recorded',
      state: 'is-failed',
    })
  } else if (credited || creditNotDue) {
    steps.push({ id: 'done', label: 'Completed', detail: 'Nothing outstanding on this return', state: 'is-done' })
  }

  return (
    <ol className="pr-timeline">
      {steps.map((step) => (
        <li key={step.id} className={step.state}>
          <span className="pr-timeline__label">{step.label}</span>
          <span className="pr-timeline__detail">{step.detail}</span>
        </li>
      ))}
    </ol>
  )
}

/**
 * What Inventory and Books say right now.
 *
 * Loaded separately from the return itself so a slow or unreachable service
 * delays this panel and nothing else on the screen.
 */
function LivePanel({ id }: { id: number }) {
  const { data, loading, error, reload } = useApi(
    (signal) => purchaseReturnsApi.integration(id, signal),
    [id],
    true,
  )

  if (loading && data === null) {
    return (
      <div className="pr-section">
        <h3>Where this stands in the other products</h3>
        <Skeleton height={14} width="70%" />
        <Skeleton height={14} width="55%" style={{ marginTop: 9 }} />
      </div>
    )
  }

  if (error) {
    return (
      <div className="pr-section">
        <h3>Where this stands in the other products</h3>
        <Notice tone="warning">
          Inventory and Smart Books could not be asked just now, so their status is unavailable rather than shown as
          nothing.{' '}
          <button type="button" className="pr-ai-link" onClick={reload}>
            Try again
          </button>
        </Notice>
      </div>
    )
  }

  const live = data?.data as ReturnIntegration | undefined
  if (!live) return null

  const legs: Array<{ key: string; icon: React.ReactNode; title: string; owner: string; leg: ReturnIntegration['inventory'] }> = [
    { key: 'inventory', icon: <Boxes size={15} aria-hidden />, title: 'Stock movement', owner: 'Aicountly Inventory', leg: live.inventory },
    { key: 'books', icon: <Receipt size={15} aria-hidden />, title: 'Debit note', owner: 'Aicountly Smart Books', leg: live.books },
  ]

  return (
    <div className="pr-section">
      <h3>Where this stands in the other products</h3>

      <div style={{ display: 'grid', gap: 10 }}>
        {legs.map((entry) => (
          <div
            key={entry.key}
            style={{
              display: 'flex',
              alignItems: 'flex-start',
              gap: 10,
              padding: 11,
              border: '1px solid var(--pr-border)',
              borderRadius: 10,
            }}
          >
            <span style={{ color: 'var(--pr-text-soft)', marginTop: 1 }}>{entry.icon}</span>
            <div style={{ minWidth: 0, flex: 1 }}>
              <strong style={{ fontSize: 12.8 }}>{entry.title}</strong>
              <span style={{ display: 'block', fontSize: 11, color: 'var(--pr-muted)' }}>{entry.owner} owns this</span>
              <span style={{ display: 'block', marginTop: 5, fontSize: 12.5 }}>
                {!entry.leg.available ? (
                  <span style={{ color: 'var(--pr-warning)', fontWeight: 600 }}>
                    <AlertTriangle size={12} aria-hidden style={{ verticalAlign: -1, marginRight: 4 }} />
                    Status unavailable — {entry.leg.reason}
                  </span>
                ) : entry.leg.status === 'POSTED' ? (
                  <span style={{ color: 'var(--pr-success)', fontWeight: 600 }}>
                    <Check size={12} aria-hidden style={{ verticalAlign: -1, marginRight: 4 }} />
                    Posted · {entry.leg.reference}
                  </span>
                ) : (
                  <span style={{ color: 'var(--pr-text-soft)' }}>
                    <CircleDashed size={12} aria-hidden style={{ verticalAlign: -1, marginRight: 4 }} />
                    {entry.leg.status === 'NOT_SENT' ? 'Nothing sent to Inventory yet' : 'No debit note raised yet'}
                  </span>
                )}
              </span>
            </div>
          </div>
        ))}
      </div>

      <p className="pr-ai-basis" style={{ marginTop: 10 }}>
        Read from Inventory and Smart Books just now. This product keeps the reference and nothing else — no copy of the
        stock movement, no copy of the ledger.
      </p>
    </div>
  )
}

export function ReturnDetailBody({
  record,
  onRetryCommand,
  busy,
}: {
  record: ReturnRecord
  onRetryCommand?: () => void
  busy?: boolean
}) {
  const currency = record.currency || 'INR'

  return (
    <>
      <CommandStrip commands={record.commands} busy={busy} onRetry={onRetryCommand} />

      <div className="pr-section">
        <h3>The return</h3>
        <dl className="pr-facts">
          <dt>Supplier</dt>
          <dd>{record.supplier_name ?? `Account ${record.supplier_account_id}`}</dd>

          <dt>Raised</dt>
          <dd>{shortDate(record.return_date)}</dd>

          {record.expected_pickup_date && (
            <>
              <dt>Expected pickup</dt>
              <dd>{shortDate(record.expected_pickup_date)}</dd>
            </>
          )}

          <dt>Against</dt>
          <dd>
            {record.source?.number ? (
              <Link className="pr-link" to={`/purchase-orders/${record.source.id}`}>
                {record.source.number}
              </Link>
            ) : (
              'No source document'
            )}
          </dd>

          <dt>Reason</dt>
          <dd>
            {record.reason?.label ?? 'Not stated'}
            {record.reason_note && <span style={{ display: 'block', color: 'var(--pr-text-soft)' }}>{record.reason_note}</span>}
          </dd>

          <dt>Value</dt>
          <dd style={{ fontWeight: 650 }}>{record.total_value_formatted}</dd>

          {record.cancel_reason && (
            <>
              <dt>Cancelled because</dt>
              <dd>{record.cancel_reason}</dd>
            </>
          )}
        </dl>
      </div>

      <div className="pr-section">
        <h3>Lines</h3>
        <div style={{ overflowX: 'auto' }}>
          <table className="pr-lines">
            <thead>
              <tr>
                <th>#</th>
                <th>Item</th>
                <th style={{ textAlign: 'right' }}>Returning</th>
                <th style={{ textAlign: 'right' }}>Rate</th>
                <th style={{ textAlign: 'right' }}>Amount</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              {record.lines.map((line) => (
                <tr key={line.line_id}>
                  <td>{line.line_no}</td>
                  <td>
                    {/* The description this product holds on the order line it
                        was raised against. The item itself is Inventory's. */}
                    {line.source_description ??
                      (line.item_id === null ? 'Not a stock item' : `Inventory item ${line.item_id}`)}
                    {line.warehouse_id !== null && (
                      <span className="pr-cell-sub">Warehouse {line.warehouse_id}</span>
                    )}
                  </td>
                  <td style={{ textAlign: 'right' }}>{qty(line.return_qty)}</td>
                  <td style={{ textAlign: 'right' }}>{money(line.rate, currency)}</td>
                  <td style={{ textAlign: 'right', fontWeight: 600 }}>{money(line.line_amount, currency)}</td>
                  <td>{line.reason_code ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="pr-total-row">
          <span style={{ color: 'var(--pr-text-soft)' }}>Return value</span>
          <strong>{record.total_value_formatted}</strong>
        </div>
        <p className="pr-ai-basis">
          Tax on the return is Smart Books' to compute when the debit note is raised. Nothing here is an accounting
          figure.
        </p>
      </div>

      <div className="pr-section">
        <h3>Supplier credit</h3>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <CreditPill status={record.supplier_credit.status} label={record.supplier_credit.label} />
          {record.supplier_credit.reference && <span style={{ fontSize: 12.8 }}>{record.supplier_credit.reference}</span>}
          {record.supplier_credit.date && (
            <span style={{ fontSize: 12, color: 'var(--pr-muted)' }}>{shortDate(record.supplier_credit.date)}</span>
          )}
          {record.supplier_credit.amount && (
            <span style={{ fontSize: 12.8, fontWeight: 600 }}>{money(record.supplier_credit.amount, currency)}</span>
          )}
        </div>
        <p className="pr-ai-basis" style={{ marginTop: 8 }}>
          <FileCheck2 size={12} aria-hidden style={{ verticalAlign: -1, marginRight: 4 }} />
          The credit note the SUPPLIER issues. The debit note this company raises for the same event is Smart Books',
          and is shown above — they are related, and they are not the same document.
        </p>
      </div>

      <LivePanel id={record.return_id} />

      <div className="pr-section">
        <h3>How it got here</h3>
        <Timeline record={record} />
      </div>
    </>
  )
}

/** The header badge and one-line summary both surfaces use. */
export function ReturnDetailHeading({ record }: { record: ReturnRecord }) {
  return {
    badge: <StatusPill status={record.status} label={record.status_label} />,
    subtitle: (
      <>
        {record.supplier_name ?? `Account ${record.supplier_account_id}`} · {shortDate(record.return_date)} ·{' '}
        {record.total_value_formatted}
        {record.source?.number && (
          <>
            {' · against '}
            <Link className="pr-link" to={`/purchase-orders/${record.source.id}`}>
              {record.source.number}
            </Link>
          </>
        )}
      </>
    ),
  }
}
