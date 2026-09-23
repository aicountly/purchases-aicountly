/**
 * Section 5 — who may do what, read from the system that decides it.
 *
 * NOTHING IS GRANTED HERE. Permissions are held in Purchases' own access
 * tables, granted on Administration → Access and enforced in the API on every
 * request. This card reads the catalogue and the caller's grants and shows
 * them; a second place to edit access would be a second place to get it wrong,
 * and a browser-side RBAC engine would be a picture of one.
 */

import { ExternalLink, Info, KeyRound, ShieldCheck } from 'lucide-react'
import { Link } from 'react-router-dom'
import { PpCard, PpStrip } from './ProfileUi'

export interface PermissionCatalogue {
  catalog: Record<string, Record<string, string>>
  granted: string[]
}

export function ProfilePermissionsCard({
  catalogue,
  loading,
  error,
  isOwner,
  accessResolved,
  canManageAccess,
}: {
  catalogue: PermissionCatalogue | null
  loading: boolean
  error: string | null
  isOwner: boolean
  accessResolved: boolean
  canManageAccess: boolean
}) {
  const held = new Set(catalogue?.granted ?? [])
  const groups = Object.entries(catalogue?.catalog ?? {})

  return (
    <PpCard
      id="permissions"
      icon={<KeyRound size={19} aria-hidden />}
      tone="blue"
      title="Permissions"
      subtitle="Control who can use this purchase profile and what actions they can perform."
      aside={
        canManageAccess && (
          <Link to="/access" className="pp-btn pp-btn--secondary pp-btn--small">
            Manage access <ExternalLink size={12} aria-hidden />
          </Link>
        )
      }
    >
      {isOwner ? (
        <PpStrip tone="success" icon={<ShieldCheck size={14} aria-hidden />}>
          You own this company, so you hold all Purchases permissions — including changing this profile.
        </PpStrip>
      ) : !accessResolved ? (
        <PpStrip tone="warning" icon={<Info size={14} aria-hidden />}>
          Aicountly Manage has not named a role for you in this company, so no permissions could be resolved. That is
          a question for whoever administers the company in Manage, not something this screen can grant.
        </PpStrip>
      ) : null}

      {loading && (
        <div style={{ display: 'grid', gap: 8, marginTop: 14 }} aria-busy="true">
          <div className="pp-skeleton" style={{ height: 18, width: '40%' }} />
          <div className="pp-skeleton" style={{ height: 46 }} />
          <div className="pp-skeleton" style={{ height: 46 }} />
        </div>
      )}

      {error && !loading && (
        <PpStrip tone="danger">Permissions could not be read right now: {error}</PpStrip>
      )}

      {!loading && !error && groups.length > 0 && (
        <div className="pp-grid pp-grid--2" style={{ marginTop: isOwner || !accessResolved ? 14 : 0 }}>
          {groups.map(([group, permissions]) => {
            const entries = Object.entries(permissions)
            const mine = entries.filter(([key]) => held.has(key))
            return (
              <div key={group} className="pp-policy" style={{ alignItems: 'flex-start', flexDirection: 'column', gap: 6 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%' }}>
                  <strong style={{ fontSize: 12.5 }}>{group}</strong>
                  <span
                    className={mine.length === entries.length ? 'pp-badge pp-badge--active' : 'pp-badge pp-badge--inactive'}
                    style={{ marginLeft: 'auto' }}
                  >
                    {mine.length} of {entries.length}
                  </span>
                </div>
                <ul className="pp-list pp-list--plain" style={{ color: 'var(--pp-text-muted)', fontSize: 11.5 }}>
                  {mine.length === 0 ? (
                    <li>None of these are yours.</li>
                  ) : (
                    mine.map(([key, label]) => <li key={key}>{label}</li>)
                  )}
                </ul>
              </div>
            )
          })}
        </div>
      )}

      <PpStrip tone="muted">
        Segregation of duties is enforced by the API, not by this screen: whoever raised a requisition or an order
        cannot approve it, whatever permissions they hold.
      </PpStrip>
    </PpCard>
  )
}
