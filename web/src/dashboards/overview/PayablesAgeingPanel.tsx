/**
 * Payables ageing.
 *
 * Books' own buckets, against Books' own due dates. Nothing is re-bucketed
 * here, and the caveat Books' bucketing carries — bills with no due date land
 * in "Not due" — is printed rather than quietly absorbed.
 *
 * Each column opens Bills & Payables filtered to that bucket, because the
 * question a reader has after seeing ₹6.2L over ninety days is "which bills",
 * and a chart that cannot answer it sends them to a menu instead.
 */

import { useNavigate } from 'react-router-dom'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../shell'
import { BucketColumns } from '../charts'
import type { AgeingPanel } from '../types'

export function PayablesAgeingPanel({ panel }: { panel: AgeingPanel }) {
  const navigate = useNavigate()

  const empty = panel.available && panel.buckets.every((bucket) => Number.parseFloat(bucket.amount) === 0)

  return (
    <DashboardPanel
      title="Payables aging"
      description={panel.available ? `Outstanding payables by aging bucket · as at ${panel.as_of_label}` : undefined}
    >
      {!panel.available ? (
        <PanelUnavailable reason={panel.reason} kind={panel.kind} />
      ) : empty ? (
        <EmptyState title="No outstanding supplier dues.">Smart Books reports nothing open as at {panel.as_of_label}.</EmptyState>
      ) : (
        <>
          <BucketColumns
            title="Outstanding payables by aging bucket"
            data={panel.buckets.map((bucket) => ({
              id: bucket.id,
              label: bucket.label,
              value: bucket.amount,
              formatted: bucket.formatted,
              compact: bucket.compact,
              sharePc: bucket.share_pc,
              tone: bucket.tone === 'danger' ? 'danger' : bucket.tone === 'warning' ? 'warning' : 'neutral',
              onOpen: () => {
                const query = new URLSearchParams(bucket.filters).toString()
                navigate(query === '' ? bucket.route : `${bucket.route}?${query}`)
              },
            }))}
          />
          <p className="purchase-muted" style={{ margin: '10px 0 0', fontSize: 10, lineHeight: 1.45 }}>
            {panel.caveat}
          </p>
        </>
      )}
    </DashboardPanel>
  )
}
