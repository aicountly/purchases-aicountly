import { Button, Notice } from '../ui'

/**
 * A list or a form that could not be loaded says so, with a retry — never an empty state
 * (F146: an approver whose inbox failed to load was told nothing was pending, and Settings showed
 * unchecked boxes over values that were on).
 */
export function LoadFailed({ what, error, onRetry }: { what: string; error: string; onRetry: () => void }) {
  return (
    <Notice
      tone="danger"
      title={`Could not load ${what}`}
      action={<Button onClick={onRetry}>Try again</Button>}
    >
      {error} Nothing shown here is a real empty list until it loads.
    </Notice>
  )
}

/**
 * Previous / next over a list the server pages (F145): the title's total is reachable, not only
 * the first page.
 */
export function Pager({
  offset,
  limit,
  total,
  onChange,
}: {
  offset: number
  limit: number
  total: number
  onChange: (offset: number) => void
}) {
  if (total <= limit && offset === 0) return null
  const from = total === 0 ? 0 : offset + 1
  const to = Math.min(offset + limit, total)
  return (
    <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', justifyContent: 'flex-end', marginTop: '0.6rem', flexWrap: 'wrap' }}>
      <span style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>
        Showing {from}–{to} of {total}
      </span>
      <Button disabled={offset === 0} onClick={() => onChange(Math.max(0, offset - limit))}>Previous</Button>
      <Button disabled={offset + limit >= total} onClick={() => onChange(offset + limit)}>Next</Button>
    </div>
  )
}
