/**
 * Profiles: the sets of permissions everything else on this page hands out.
 *
 * The old screen led with a full-width amber warning on any company that had
 * no profiles — the loudest thing this product can say, said to somebody whose
 * only crime was not having set up Purchases yet. A brand new company is not an
 * incident. It gets an empty state with the two things to do next, and the one
 * consequence that actually matters stated once, quietly.
 */

import { useMemo, useState } from 'react'
import {
  BadgeCheck,
  Ban,
  Copy,
  Eye,
  Pencil,
  Plus,
  Power,
  Search,
  Sparkles,
  Trash2,
  Users,
} from 'lucide-react'
import type { Profile } from './types'
import { EmptyState, ErrorState, LoadingRows, RowMenu, relativeTime, fullTimestamp } from './ui'

type Filter = 'all' | 'starter' | 'custom' | 'active' | 'inactive'

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'All profiles' },
  { value: 'starter', label: 'Starter profiles' },
  { value: 'custom', label: 'Custom profiles' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
]

export function ProfilesPanel({
  profiles,
  loading,
  error,
  onRetry,
  grantable,
  busy,
  canCreateStarters,
  onCreateStarters,
  onNewProfile,
  onView,
  onEdit,
  onDuplicate,
  onToggleActive,
  onDelete,
}: {
  profiles: Profile[]
  loading: boolean
  error: string | null
  onRetry: () => void
  grantable: Set<string>
  busy: boolean
  canCreateStarters: boolean
  onCreateStarters: () => void
  onNewProfile: () => void
  onView: (profile: Profile) => void
  onEdit: (profile: Profile) => void
  onDuplicate: (profile: Profile) => void
  onToggleActive: (profile: Profile) => void
  onDelete: (profile: Profile) => void
}) {
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState<Filter>('all')

  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase()

    return profiles.filter((profile) => {
      if (filter === 'starter' && profile.system_key === null) return false
      if (filter === 'custom' && profile.system_key !== null) return false
      if (filter === 'active' && !profile.is_active) return false
      if (filter === 'inactive' && profile.is_active) return false
      if (needle === '') return true

      return (
        profile.profile_name.toLowerCase().includes(needle) ||
        (profile.description ?? '').toLowerCase().includes(needle) ||
        profile.permissions.some((permission) => permission.toLowerCase().includes(needle))
      )
    })
  }, [profiles, search, filter])

  const body = () => {
    if (error) return <ErrorState what="permission profiles" message={error} onRetry={onRetry} />
    if (loading && profiles.length === 0) return <LoadingRows rows={4} columns={5} />

    if (profiles.length === 0) {
      return (
        <EmptyState
          icon={<Users size={26} />}
          title="No profiles yet"
          actions={
            <>
              <button
                type="button"
                className="access-btn access-btn--primary"
                onClick={onCreateStarters}
                disabled={busy || !canCreateStarters}
                title={canCreateStarters ? undefined : 'The starter profiles already exist in this company'}
              >
                <Sparkles size={15} aria-hidden /> Create starter profiles
              </button>
              <button type="button" className="access-btn access-btn--secondary" onClick={onNewProfile} disabled={busy}>
                <Plus size={15} aria-hidden /> Create your own
              </button>
            </>
          }
          note={
            <>
              Until a profile exists, only the company owner can use Purchases. A buyer who cannot approve
              their own order is the point of having more than one.
            </>
          }
        >
          This company has no permission profiles, so only the company owner can use Purchases.
        </EmptyState>
      )
    }

    if (visible.length === 0) {
      return (
        <EmptyState
          icon={<Search size={24} />}
          title="Nothing matches that"
          actions={
            <button
              type="button"
              className="access-btn access-btn--secondary"
              onClick={() => {
                setSearch('')
                setFilter('all')
              }}
            >
              Clear search and filter
            </button>
          }
        >
          {profiles.length} profile{profiles.length === 1 ? '' : 's'} exist here, but none match what you are
          looking for.
        </EmptyState>
      )
    }

    return (
      <div className="access-table-scroll">
        <table className="access-table">
          <caption className="access-sr-only">
            Permission profiles in this company, with their type, members and status.
          </caption>
          <thead>
            <tr>
              <th scope="col">Profile</th>
              <th scope="col">Type</th>
              <th scope="col" className="is-numeric">
                Members
              </th>
              <th scope="col" className="is-numeric">
                Permissions
              </th>
              <th scope="col">Last updated</th>
              <th scope="col">Status</th>
              <th scope="col" className="is-actions">
                <span className="access-sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {visible.map((profile) => {
              // The server refuses to write a profile containing permissions
              // the editor does not hold. Duplicating or deactivating rewrites
              // the whole permission list, so both are unavailable here rather
              // than failing after the click.
              const beyondMe = profile.permissions.filter((permission) => !grantable.has(permission))
              const locked = beyondMe.length > 0
              const lockReason = locked
                ? `This profile grants ${beyondMe.length} permission${beyondMe.length === 1 ? '' : 's'} you do not hold, so you cannot rewrite it. A company owner can.`
                : undefined

              return (
                <tr key={profile.profile_id}>
                  <th scope="row">
                    <div className="access-cell-title">{profile.profile_name}</div>
                    {profile.description && <div className="access-cell-sub">{profile.description}</div>}
                  </th>
                  <td>
                    <span className={profile.system_key ? 'access-badge access-badge--starter' : 'access-badge access-badge--custom'}>
                      {profile.system_key ? 'Starter' : 'Custom'}
                    </span>
                  </td>
                  <td className="is-numeric">{profile.member_count}</td>
                  <td className="is-numeric">{profile.permission_count}</td>
                  <td>
                    <span className="access-nowrap" title={fullTimestamp(profile.updated_at)}>
                      {relativeTime(profile.updated_at)}
                    </span>
                  </td>
                  <td>
                    {/* The word carries the status, not the colour — this is
                        read by people who cannot tell green from grey. */}
                    <span className={profile.is_active ? 'access-badge access-badge--active' : 'access-badge access-badge--inactive'}>
                      {profile.is_active ? <BadgeCheck size={12} aria-hidden /> : <Ban size={12} aria-hidden />}
                      {profile.is_active ? 'Active' : 'Disabled'}
                    </span>
                  </td>
                  <td className="is-actions">
                    <RowMenu
                      label={`Actions for ${profile.profile_name}`}
                      actions={[
                        { label: 'View permissions', icon: <Eye size={14} aria-hidden />, onSelect: () => onView(profile) },
                        { label: 'Edit profile', icon: <Pencil size={14} aria-hidden />, onSelect: () => onEdit(profile), disabled: busy },
                        {
                          label: 'Duplicate',
                          icon: <Copy size={14} aria-hidden />,
                          onSelect: () => onDuplicate(profile),
                          disabled: busy || locked,
                          title: lockReason,
                        },
                        {
                          label: profile.is_active ? 'Deactivate' : 'Reactivate',
                          icon: <Power size={14} aria-hidden />,
                          onSelect: () => onToggleActive(profile),
                          disabled: busy || locked,
                          title: lockReason,
                          separatorBefore: true,
                        },
                        {
                          label: 'Delete profile',
                          icon: <Trash2 size={14} aria-hidden />,
                          onSelect: () => onDelete(profile),
                          disabled: busy || profile.member_count > 0,
                          title:
                            profile.member_count > 0
                              ? 'Somebody holds this profile. Remove them first, so nobody loses access without you seeing it.'
                              : undefined,
                          danger: true,
                        },
                      ]}
                    />
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    )
  }

  return (
    <section className="access-panel" aria-labelledby="access-profiles-heading">
      <header className="access-panel__header">
        <div>
          <h2 id="access-profiles-heading">Permission profiles</h2>
          <p>Profiles define what somebody can do in Aicountly Purchase.</p>
        </div>

        {profiles.length > 0 && (
          <div className="access-panel__tools">
            <span className="access-search">
              <Search size={14} aria-hidden />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search profiles..."
                aria-label="Search profiles by name, description or permission"
              />
            </span>
            <select
              value={filter}
              onChange={(event) => setFilter(event.target.value as Filter)}
              aria-label="Filter profiles"
            >
              {FILTERS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        )}
      </header>

      <div className="access-panel__body--flush">{body()}</div>
    </section>
  )
}
