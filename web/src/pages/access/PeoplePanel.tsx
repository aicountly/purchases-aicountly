/**
 * People with access, grouped by person rather than by grant.
 *
 * "What can Priya do?" is the question an administrator actually has, and a row
 * per assignment answers a different one. The server groups it the same way.
 *
 * IDENTITY IS NOT OURS. Purchases stores a portal uuid, a label an
 * administrator typed, and the access decision — nothing else. There is no name
 * or email to show unless somebody wrote one in the label, so an unlabelled
 * person is shown as a truncated uuid with the full value on hover, never as an
 * invented display name.
 */

import { useMemo, useState } from 'react'
import { Eye, Search, UserCheck, UserPlus, X } from 'lucide-react'
import type { Member, Profile } from './types'
import { EmptyState, ErrorState, LoadingRows, RowMenu, fullTimestamp, initials, relativeTime, shortUuid } from './ui'

export function PeoplePanel({
  members,
  profiles,
  loading,
  error,
  onRetry,
  busy,
  myUuid,
  onView,
  onAssignAnother,
  onRevoke,
  onGrantFirst,
}: {
  members: Member[]
  profiles: Profile[]
  loading: boolean
  error: string | null
  onRetry: () => void
  busy: boolean
  myUuid: string | null
  onView: (member: Member) => void
  onAssignAnother: (member: Member) => void
  onRevoke: (member: Member, assignmentId: number, profileName: string) => void
  onGrantFirst: () => void
}) {
  const [search, setSearch] = useState('')
  const [profileFilter, setProfileFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all')

  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase()

    return members.filter((member) => {
      const active = member.assignments.some((assignment) => assignment.is_active)
      if (statusFilter === 'active' && !active) return false
      if (statusFilter === 'inactive' && active) return false
      if (profileFilter !== 'all' && !member.assignments.some((a) => String(a.profile_id) === profileFilter)) return false
      if (needle === '') return true

      return (
        (member.label ?? '').toLowerCase().includes(needle) ||
        member.user_uuid.toLowerCase().includes(needle) ||
        member.assignments.some((assignment) => assignment.profile_name.toLowerCase().includes(needle))
      )
    })
  }, [members, search, profileFilter, statusFilter])

  const body = () => {
    if (error) return <ErrorState what="the people with access" message={error} onRetry={onRetry} />
    if (loading && members.length === 0) return <LoadingRows rows={3} columns={5} />

    if (members.length === 0) {
      return (
        <EmptyState
          icon={<UserCheck size={26} />}
          title="Nobody has been given a profile yet"
          actions={
            <button type="button" className="access-btn access-btn--primary" onClick={onGrantFirst}>
              <UserPlus size={15} aria-hidden /> Give somebody a profile
            </button>
          }
          note="Being a member of the company in Aicountly Manage does not by itself grant anything in Purchases. Access here is granted on this page."
        >
          The company owner can already use everything. Everybody else needs a profile before Purchases
          will open for them.
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
                setProfileFilter('all')
                setStatusFilter('all')
              }}
            >
              Clear search and filters
            </button>
          }
        >
          {members.length} {members.length === 1 ? 'person holds' : 'people hold'} a profile here, but none match
          what you are looking for.
        </EmptyState>
      )
    }

    return (
      <div className="access-table-scroll">
        <table className="access-table">
          <caption className="access-sr-only">People holding a permission profile in this company.</caption>
          <thead>
            <tr>
              <th scope="col">Person</th>
              <th scope="col">Profiles</th>
              <th scope="col" className="is-numeric">
                Permissions
              </th>
              <th scope="col">Granted</th>
              <th scope="col">Status</th>
              <th scope="col" className="is-actions">
                <span className="access-sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {visible.map((member) => {
              const latest = [...member.assignments].sort((a, b) => b.assigned_at.localeCompare(a.assigned_at))[0]
              const active = member.assignments.some((assignment) => assignment.is_active)
              const grantedBy = latest?.assigned_by ?? null

              return (
                <tr key={member.user_uuid}>
                  <th scope="row">
                    <div className="access-person">
                      <span className="access-avatar" aria-hidden="true">
                        {initials(member.label)}
                      </span>
                      <span style={{ minWidth: 0 }}>
                        <span className="access-cell-title">
                          {member.label ?? 'Unlabelled'}
                          {member.is_you && <span className="access-badge access-badge--you" style={{ marginLeft: 6 }}>You</span>}
                        </span>
                        <span className="access-cell-sub access-uuid" title={member.user_uuid}>
                          {shortUuid(member.user_uuid, 12)}
                        </span>
                      </span>
                    </div>
                  </th>

                  <td>
                    <div className="access-chips">
                      {member.assignments.map((assignment) => (
                        <span
                          key={assignment.assignment_id}
                          className={assignment.is_active ? 'access-chip' : 'access-chip is-inactive'}
                          title={assignment.note ?? undefined}
                        >
                          {assignment.profile_name}
                          {!assignment.is_active && <span className="access-sr-only"> (profile disabled)</span>}
                          <button
                            type="button"
                            className="access-chip__remove"
                            disabled={busy}
                            aria-label={`Revoke ${assignment.profile_name} from ${member.label ?? member.user_uuid}`}
                            onClick={() => onRevoke(member, assignment.assignment_id, assignment.profile_name)}
                          >
                            <X size={12} aria-hidden />
                          </button>
                        </span>
                      ))}
                    </div>
                  </td>

                  <td className="is-numeric">{member.permission_count}</td>

                  <td>
                    <div className="access-nowrap" title={fullTimestamp(latest?.assigned_at)}>
                      {relativeTime(latest?.assigned_at)}
                    </div>
                    {grantedBy && (
                      <div className="access-cell-sub">
                        by{' '}
                        {grantedBy === myUuid ? (
                          'you'
                        ) : (
                          <span className="access-uuid" title={grantedBy}>
                            {shortUuid(grantedBy, 8)}
                          </span>
                        )}
                      </div>
                    )}
                  </td>

                  <td>
                    <span className={active ? 'access-badge access-badge--active' : 'access-badge access-badge--inactive'}>
                      {active ? 'Active' : 'No active profile'}
                    </span>
                  </td>

                  <td className="is-actions">
                    <RowMenu
                      label={`Actions for ${member.label ?? member.user_uuid}`}
                      actions={[
                        { label: 'View permissions', icon: <Eye size={14} aria-hidden />, onSelect: () => onView(member) },
                        {
                          label: 'Assign another profile',
                          icon: <UserPlus size={14} aria-hidden />,
                          onSelect: () => onAssignAnother(member),
                          disabled: busy || profiles.length === 0,
                          title: profiles.length === 0 ? 'There are no profiles to assign yet' : undefined,
                        },
                        ...member.assignments.map((assignment) => ({
                          label: `Revoke ${assignment.profile_name}`,
                          icon: <X size={14} aria-hidden />,
                          onSelect: () => onRevoke(member, assignment.assignment_id, assignment.profile_name),
                          disabled: busy,
                          danger: true,
                          separatorBefore: assignment.assignment_id === member.assignments[0]?.assignment_id,
                        })),
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
    <section className="access-panel" aria-labelledby="access-people-heading">
      <header className="access-panel__header">
        <div>
          <h2 id="access-people-heading">People with access</h2>
          <p>Everybody holding a profile in this company, and what it gives them.</p>
        </div>

        {members.length > 0 && (
          <div className="access-panel__tools">
            <span className="access-search">
              <Search size={14} aria-hidden />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search people..."
                aria-label="Search people by label, user id or profile"
              />
            </span>
            <select
              value={profileFilter}
              onChange={(event) => setProfileFilter(event.target.value)}
              aria-label="Filter by profile"
            >
              <option value="all">All profiles</option>
              {profiles.map((profile) => (
                <option key={profile.profile_id} value={String(profile.profile_id)}>
                  {profile.profile_name}
                </option>
              ))}
            </select>
            <select
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value as 'all' | 'active' | 'inactive')}
              aria-label="Filter by status"
            >
              <option value="all">Any status</option>
              <option value="active">Active</option>
              <option value="inactive">No active profile</option>
            </select>
          </div>
        )}
      </header>

      <div className="access-panel__body--flush">{body()}</div>
    </section>
  )
}
