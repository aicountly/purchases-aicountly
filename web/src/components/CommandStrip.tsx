import type { IntegrationCommand } from '../services/types'
import { Button, Notice, StatusBadge } from '../ui'

/**
 * What this product has asked Books and Inventory to do, and how it went.
 *
 * This strip is the visible half of an architecture with no reconciliation job.
 * Nothing sweeps failures up quietly at 2am: a request that did not complete is
 * shown here, on the document it belongs to, with the error that stopped it and
 * what the person looking at it can do.
 *
 *   FAILED     the other product could not be reached, or could not act yet. Retry
 *              sends the same request on the same key — it cannot act twice.
 *   UNCERTAIN  it may have acted and the answer was lost. Reconcile asks it what it
 *              holds, without sending anything; Retry sends the same request on the
 *              same key, which it recognises.
 *   BLOCKED    it was reached and refused. Retrying unchanged would be refused the
 *              same way, so no Retry is offered; the document has to change.
 *   CANCELLED  withdrawn before it reached the other product. Not shown.
 */
export function CommandStrip({
  commands,
  onRetry,
  onReconcile,
  busy,
}: {
  commands: IntegrationCommand[]
  onRetry?: (command: IntegrationCommand) => void
  onReconcile?: (command: IntegrationCommand) => void
  busy?: boolean
}) {
  const unresolved = commands.filter((command) => command.status !== 'COMPLETED' && command.status !== 'CANCELLED')
  if (unresolved.length === 0) return null

  return (
    <div style={{ display: 'grid', gap: '0.6rem' }}>
      {unresolved.map((command) => (
        <Notice
          key={command.command_id}
          tone={command.status === 'BLOCKED' || command.status === 'FAILED' ? 'danger' : 'warning'}
          title={describe(command)}
          action={
            <div style={{ display: 'flex', gap: '0.4rem' }}>
              {command.status === 'UNCERTAIN' && onReconcile && (
                <Button tone="secondary" disabled={busy} onClick={() => onReconcile(command)}>
                  Reconcile
                </Button>
              )}
              {(command.status === 'FAILED' || command.status === 'UNCERTAIN') && onRetry && (
                <Button tone="secondary" disabled={busy} onClick={() => onRetry(command)}>
                  Retry
                </Button>
              )}
            </div>
          }
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
            <StatusBadge status={command.status} />
            <span style={{ color: 'var(--muted)', fontSize: '0.82rem' }}>
              {command.attempts} attempt{command.attempts === 1 ? '' : 's'}
            </span>
          </div>
          {command.last_error && (
            <p style={{ margin: '0.4rem 0 0', fontSize: '0.85rem' }}>{command.last_error}</p>
          )}
          {command.status === 'UNCERTAIN' && (
            <p style={{ margin: '0.4rem 0 0', fontSize: '0.82rem', color: 'var(--muted)' }}>
              The answer was lost, so it may already be recorded. Reconcile asks without sending; Retry sends the same request, which cannot be recorded twice.
            </p>
          )}
          {command.status === 'BLOCKED' && (
            <p style={{ margin: '0.4rem 0 0', fontSize: '0.82rem', color: 'var(--muted)' }}>
              This was refused rather than missed, so retrying it unchanged will fail the same way.
            </p>
          )}
          {command.status === 'POSTING' && (
            <p style={{ margin: '0.4rem 0 0', fontSize: '0.82rem', color: 'var(--muted)' }}>
              Being sent right now. Refresh in a moment.
            </p>
          )}
        </Notice>
      ))}
    </div>
  )
}

const WHAT: Record<string, string> = {
  'purchases.receipt.request': 'Recording the goods receipt',
  'purchases.bill.post': 'Posting the supplier\'s bill',
  'purchases.return.dispatch': 'Recording the dispatch of returned goods',
  'purchases.return.recall': 'Recalling the returned goods',
  'purchases.return.debit_note': 'Raising the debit note for the return',
  'purchases.claim.debit_note': 'Raising the debit note settling the claim',
}

export function describe(command: IntegrationCommand): string {
  const target = command.target_service === 'books' ? 'Smart Books' : command.target_service === 'inventory' ? 'Inventory' : command.target_service
  const what = WHAT[command.command_type] ?? command.command_type

  return `${what} in ${target}`
}

/**
 * The endpoint that retries (or reconciles) a command, from what it was about.
 * One place, so every screen offers the same recovery for the same command.
 */
export function recoveryPath(command: IntegrationCommand, mode: 'retry' | 'reconcile'): string | null {
  switch (command.command_type) {
    case 'purchases.receipt.request':
      return `v1/receipt-requests/${command.entity_id}/${mode === 'retry' ? 'retry' : 'reconcile'}`
    case 'purchases.bill.post':
      // Books recognises the key, so a lost answer is recovered by sending it again.
      return mode === 'retry' ? `v1/bills/${command.entity_id}/post` : null
    case 'purchases.return.dispatch':
      return mode === 'retry' ? `v1/returns/${command.entity_id}/dispatch` : null
    case 'purchases.return.debit_note':
      return mode === 'retry' ? `v1/returns/${command.entity_id}/debit-note` : null
    case 'purchases.claim.debit_note':
      return mode === 'retry' ? `v1/claim-resolutions/${command.entity_id}/approve` : null
    default:
      return null
  }
}
