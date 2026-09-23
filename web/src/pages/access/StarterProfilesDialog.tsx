/**
 * "Create starter profiles", previewed before anything is written.
 *
 * THE PREVIEW COMES FROM THE SERVER. `v1/access/starters` returns the same
 * constant `bootstrap()` writes from, so what is listed here is exactly what
 * gets created — a dialog that previews four profiles and creates three
 * different ones is worse than no dialog. It also reports which already exist,
 * which is how a second press stops being offered as though it were the first.
 *
 * A non-owner sees each starter trimmed to what they may actually grant, with
 * the shortfall stated. The server does that trimming either way; saying so
 * beforehand is the difference between a considered result and a surprise.
 */

import { CheckCircle2, Eye, Receipt, ShoppingCart, Sparkles, Stamp, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { AccessDialog } from './AccessDialog'
import type { Starter } from './types'

const ICONS: Record<string, ReactNode> = {
  buyer: <ShoppingCart size={17} aria-hidden />,
  approver: <Stamp size={17} aria-hidden />,
  payables: <Receipt size={17} aria-hidden />,
  viewer: <Eye size={17} aria-hidden />,
}

export function StarterProfilesDialog({
  open,
  starters,
  loading,
  error,
  busy,
  onConfirm,
  onClose,
}: {
  open: boolean
  starters: Starter[]
  loading: boolean
  /** Set when the preview could not be read. Creating is still offered. */
  error: string | null
  busy: boolean
  onConfirm: () => void
  onClose: () => void
}) {
  const creatable = starters.filter((starter) => !starter.exists && starter.grantable_count > 0)

  return (
    <AccessDialog
      open={open}
      title="Create starter profiles"
      description="Aicountly can prepare commonly used purchase access profiles, which you can edit, rename or delete afterwards. They are ordinary profiles once created — nothing about them is special."
      onClose={onClose}
      busy={busy}
      wide
      footer={
        <>
          <span className="access-dialog__footer-note">
            {loading
              ? 'Checking what this company already has…'
              : error
                ? 'Anything that already exists will be skipped.'
                : creatable.length === 0
                  ? 'Nothing left to create.'
                  : `${creatable.length} profile${creatable.length === 1 ? '' : 's'} will be created.`}
          </span>
          <button type="button" className="access-btn access-btn--secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button
            type="button"
            className="access-btn access-btn--primary"
            onClick={onConfirm}
            disabled={busy || loading || (!error && creatable.length === 0)}
          >
            <Sparkles size={15} aria-hidden /> {busy ? 'Creating…' : 'Create profiles'}
          </button>
        </>
      }
    >
      {error && (
        <div className="access-notice access-notice--warning" role="status" style={{ marginBottom: 14 }}>
          <Sparkles size={15} aria-hidden />
          <div>
            <strong>Could not preview the starter profiles</strong>
            {error} You can still create them — the server skips anything this company already has.
          </div>
        </div>
      )}

      {loading && (
        <div className="access-starters">
          {[0, 1, 2, 3].map((row) => (
            <div className="access-skeleton" key={row} style={{ height: 64, borderRadius: 12 }} aria-hidden="true" />
          ))}
        </div>
      )}

      {!loading && (
        <div className="access-starters">
          {starters.map((starter) => {
            const trimmed = !starter.exists && starter.grantable_count < starter.permission_count

            return (
              <article
                className={starter.exists ? 'access-starter is-existing' : 'access-starter'}
                key={starter.key}
              >
                <span className="access-starter__icon" aria-hidden="true">
                  {ICONS[starter.key] ?? <Users size={17} />}
                </span>

                <div style={{ minWidth: 0 }}>
                  <strong>{starter.name}</strong>
                  <p>{starter.description}</p>
                  {trimmed && (
                    <p style={{ color: 'var(--ap-warning)' }}>
                      {starter.grantable_count} of {starter.permission_count} permissions — the rest are
                      beyond what you hold, so they cannot be included. A company owner can add them later.
                    </p>
                  )}
                  {starter.grantable_count === 0 && !starter.exists && (
                    <p style={{ color: 'var(--ap-warning)' }}>
                      None of its permissions are yours to grant, so this one will be skipped.
                    </p>
                  )}
                </div>

                <span style={{ whiteSpace: 'nowrap' }}>
                  {starter.exists ? (
                    <span className="access-badge access-badge--active">
                      <CheckCircle2 size={12} aria-hidden /> Already created
                    </span>
                  ) : (
                    <span className="access-badge access-badge--custom">
                      {starter.grantable_count} permission{starter.grantable_count === 1 ? '' : 's'}
                    </span>
                  )}
                </span>
              </article>
            )
          })}
        </div>
      )}
    </AccessDialog>
  )
}
