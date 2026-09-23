/**
 * What a profile — or a person — can actually do, read out in full.
 *
 * Grouped exactly as the catalogue groups it, and labelled with the catalogue's
 * own words rather than the permission codes, which are shown underneath for
 * anybody who needs to match them against the API. A count in a table cell
 * answers "how many"; this answers "which", which is the question somebody has
 * before they hand it to a person.
 */

import { CheckCircle2 } from 'lucide-react'
import { AccessDialog } from './AccessDialog'
import type { PermissionCatalog } from './types'

export function PermissionsDialog({
  open,
  title,
  description,
  held,
  catalog,
  onClose,
}: {
  open: boolean
  title: string
  description?: string
  /** The permission codes this profile or person has. */
  held: string[]
  catalog: PermissionCatalog
  onClose: () => void
}) {
  const heldSet = new Set(held)
  const groups = Object.entries(catalog)
    .map(([group, permissions]) => {
      const rows = Object.entries(permissions).filter(([code]) => heldSet.has(code))
      return { group, rows }
    })
    .filter((entry) => entry.rows.length > 0)

  // A permission the catalogue no longer lists still has to be shown: a profile
  // written before a permission was retired still carries it, and silently
  // dropping it from this view would understate what somebody can do.
  const known = new Set(groups.flatMap((entry) => entry.rows.map(([code]) => code)))
  const unknown = held.filter((code) => !known.has(code))

  return (
    <AccessDialog
      open={open}
      title={title}
      description={description}
      onClose={onClose}
      wide
      footer={
        <>
          <span className="access-dialog__footer-note">
            {held.length} permission{held.length === 1 ? '' : 's'} in total
          </span>
          <button type="button" className="access-btn access-btn--secondary" onClick={onClose}>
            Close
          </button>
        </>
      }
    >
      {held.length === 0 && (
        <p style={{ margin: 0, color: 'var(--ap-text-secondary)', fontSize: 12.5 }}>
          No permissions at all, so this grants nothing.
        </p>
      )}

      {groups.map(({ group, rows }) => (
        <div className="access-permgroup" key={group}>
          <div className="access-permgroup__head">
            <span>{group}</span>
            <span className="access-permgroup__count">{rows.length}</span>
          </div>
          <div className="access-permlist">
            {rows.map(([code, label]) => (
              <div className="access-perm" key={code}>
                <CheckCircle2 size={15} aria-hidden style={{ color: 'var(--ap-green)', flexShrink: 0, marginTop: 1 }} />
                <span>
                  {label}
                  <code>{code}</code>
                </span>
              </div>
            ))}
          </div>
        </div>
      ))}

      {unknown.length > 0 && (
        <div className="access-permgroup">
          <div className="access-permgroup__head">
            <span>No longer in the catalogue</span>
            <span className="access-permgroup__count">{unknown.length}</span>
          </div>
          <div className="access-permlist">
            {unknown.map((code) => (
              <div className="access-perm" key={code}>
                <span>
                  <code>{code}</code>
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </AccessDialog>
  )
}
