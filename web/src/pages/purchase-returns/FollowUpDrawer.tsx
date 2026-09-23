/**
 * Chasing the supplier credits that have not arrived.
 *
 * WHAT THIS DOES NOT DO IS SEND ANYTHING. This product has no mail service and
 * no supplier addresses — the contact belongs to Smart Books and is not
 * something a returns screen should be posting to. What it does is write the
 * letter, from the returns themselves, so the work that is actually tedious is
 * done: one draft per supplier, every outstanding return listed with its
 * number, date, value and how long it has been waiting, ready to paste.
 *
 * Nothing in the text is generated or approximated. Every figure in it is the
 * server's own formatted value for a return that is genuinely outstanding.
 */

import { useMemo, useState } from 'react'
import { Check, Copy, Mail } from 'lucide-react'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'
import { purchaseReturnsApi } from './api'
import type { PurchaseReturnRow } from './types'
import type { ReturnsFilters } from './filters'
import { ageInDays, Drawer, EmptyState, Notice, shortDate, Skeleton } from './ui'

function draftFor(supplier: string, rows: PurchaseReturnRow[], companyLabel: string): string {
  const lines = rows.map((row) => {
    const age = ageInDays(row.return_date)

    return `  • ${row.return_no} dated ${shortDate(row.return_date)} — ${row.total_value_formatted}${
      age === null ? '' : ` (${age} days outstanding)`
    }${row.source?.number ? `, against ${row.source.number}` : ''}`
  })

  return [
    `Subject: Credit note pending — ${rows.length} purchase return${rows.length === 1 ? '' : 's'}`,
    '',
    `Dear ${supplier},`,
    '',
    `The goods below were returned to you and we have not yet received your credit note against them.`,
    '',
    ...lines,
    '',
    `Please confirm the credit note reference and date for each, or let us know what is holding them up.`,
    '',
    'Thank you,',
    companyLabel,
  ].join('\n')
}

export function FollowUpDrawer({
  open,
  filters,
  onClose,
}: {
  open: boolean
  /** The period currently being looked at, so the chase matches the screen. */
  filters: ReturnsFilters
  onClose: () => void
}) {
  const { session } = usePurchases()
  const [copied, setCopied] = useState<string | null>(null)

  const pending = useApi(
    (signal) =>
      purchaseReturnsApi.list(
        {
          filters: { ...filters, supplier_credit: 'PENDING', status: 'APPROVED,DISPATCHED,DEBITED' },
          page: 1,
          sort: 'return_date',
          order: 'asc',
          limit: 200,
        },
        signal,
      ),
    [JSON.stringify(filters)],
    open,
  )

  const groups = useMemo(() => {
    const map = new Map<string, PurchaseReturnRow[]>()
    for (const row of pending.data?.data ?? []) {
      const key = row.supplier_name ?? `Account ${row.supplier_account_id}`
      map.set(key, [...(map.get(key) ?? []), row])
    }

    // The supplier owing the most goes first: that is the letter worth sending.
    return [...map.entries()].sort(
      (a, b) =>
        b[1].reduce((sum, row) => sum + Number.parseFloat(row.total_value), 0) -
        a[1].reduce((sum, row) => sum + Number.parseFloat(row.total_value), 0),
    )
  }, [pending.data])

  const companyLabel = session?.display_name ?? 'Purchases'

  async function copy(supplier: string, text: string) {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(supplier)
      window.setTimeout(() => setCopied(null), 2500)
    } catch {
      // A browser that refuses the clipboard — the text is on screen and
      // selectable, which is the fallback rather than a failure.
      setCopied(null)
    }
  }

  return (
    <Drawer
      open={open}
      wide
      title="Follow up on supplier credits"
      subtitle="One draft per supplier, written from the returns that are still outstanding."
      onClose={onClose}
      footer={
        <button type="button" className="pr-btn" onClick={onClose}>
          Close
        </button>
      }
    >
      <Notice tone="plain">
        <Mail size={14} aria-hidden style={{ verticalAlign: -2, marginRight: 5 }} />
        Nothing is sent from here. Copy a draft into your own mail — supplier contact details belong to Smart Books, and
        this screen does not hold them.
      </Notice>

      {pending.loading && pending.data === null && (
        <div className="pr-section">
          <Skeleton height={14} width="60%" />
          <Skeleton height={80} style={{ marginTop: 10, borderRadius: 8 }} />
        </div>
      )}

      {pending.error && <Notice tone="danger">Could not load the outstanding returns: {pending.error}</Notice>}

      {!pending.loading && groups.length === 0 && (
        <EmptyState icon={<Check size={26} />} title="Nothing to chase">
          Every return in this period has either had its credit note or does not need one.
        </EmptyState>
      )}

      {groups.map(([supplier, rows]) => {
        const text = draftFor(supplier, rows, companyLabel)

        return (
          <div className="pr-section" key={supplier}>
            <h3 style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
              <span>
                {supplier}
                <span style={{ fontWeight: 400, color: 'var(--pr-muted)' }}>
                  {' '}
                  · {rows.length} return{rows.length === 1 ? '' : 's'}
                </span>
              </span>
              <button type="button" className="pr-btn pr-btn--small" onClick={() => copy(supplier, text)}>
                {copied === supplier ? <Check size={13} aria-hidden /> : <Copy size={13} aria-hidden />}
                {copied === supplier ? 'Copied' : 'Copy'}
              </button>
            </h3>

            <textarea
              readOnly
              value={text}
              rows={Math.min(16, text.split('\n').length + 1)}
              aria-label={`Draft email to ${supplier}`}
              style={{
                width: '100%',
                padding: '10px 12px',
                border: '1px solid var(--pr-border)',
                borderRadius: 9,
                background: 'var(--pr-surface-soft)',
                fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
                fontSize: 12,
                lineHeight: 1.55,
                resize: 'vertical',
              }}
            />
          </div>
        )
      })}
    </Drawer>
  )
}
