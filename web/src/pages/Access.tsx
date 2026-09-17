/**
 * Administration → Access.
 *
 * Who may do what in Purchases. The permission tables have existed since the
 * first migration and the catalogue with them; what was missing was any way to
 * write to either, so the company owner held everything implicitly and nobody
 * else could be granted anything.
 *
 * Two rules are enforced on the server and SHOWN here, so they read as design
 * rather than as a rejection somebody hits by surprise:
 *
 *   - You can only grant permissions you hold yourself. A non-owner sees the
 *     rest of the catalogue greyed out with the reason on it.
 *   - You cannot remove your own last grant of access management.
 */

import { useCallback, useMemo, useState } from 'react'
import { Plus, ShieldCheck, Trash2, UserPlus, Wand2 } from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { Button, Card, DataTable, date, Field, Input, Notice, Select, Textarea } from '../ui'

interface Catalogue {
  catalog: Record<string, Record<string, string>>
  granted: string[]
  grantable: string[]
  is_owner: boolean
  my_uuid: string
  owner_note: string
}

interface Profile {
  profile_id: number
  profile_name: string
  description: string | null
  permissions: string[]
  permission_count: number
  is_active: boolean
  system_key: string | null
  member_count: number
  updated_at: string | null
}

interface Member {
  user_uuid: string
  label: string | null
  is_you: boolean
  permission_count: number
  permissions: string[]
  assignments: {
    assignment_id: number
    profile_id: number
    profile_name: string
    is_active: boolean
    note: string | null
    assigned_at: string
  }[]
}

interface Candidate {
  user_uuid: string
  actions: number
  last_seen: string
  is_you: boolean
}

export default function Access() {
  const { scope, can } = usePurchases()
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [editing, setEditing] = useState<Profile | 'new' | null>(null)
  const [reloadToken, setReloadToken] = useState(0)

  const reload = useCallback(() => setReloadToken((n) => n + 1), [])
  const deps = [scope?.cmp_id, scope?.fy_id, reloadToken]

  const catalogue = useApi((s) => api.one<Catalogue>('v1/access/catalogue', undefined, s), deps, Boolean(scope))
  const profiles = useApi((s) => api.one<Profile[]>('v1/access/profiles', undefined, s), deps, Boolean(scope))
  const members = useApi((s) => api.one<Member[]>('v1/access/members', undefined, s), deps, Boolean(scope))
  const people = useApi((s) => api.one<Candidate[]>('v1/access/people', undefined, s), deps, Boolean(scope))

  const cat = catalogue.data?.data
  const profileList = profiles.data?.data ?? []
  const memberList = members.data?.data ?? []

  const act = useCallback(
    async (run: () => Promise<unknown>) => {
      setBusy(true)
      setError(null)
      try {
        await run()
        reload()
      } catch (e) {
        // The server's refusal is the real answer — escalation and self-lockout
        // both come back with the reason spelled out, so it is shown verbatim.
        setError(e instanceof ApiError ? e.message : 'That change could not be saved.')
      } finally {
        setBusy(false)
      }
    },
    [reload],
  )

  if (!can('access.manage')) {
    return (
      <Notice tone="warning" title="Not available to you">
        Managing access needs the <code>access.manage</code> permission. The company owner always has
        it — ask them, or whoever administers Purchases for this company.
      </Notice>
    )
  }

  const loadError = catalogue.error ?? profiles.error ?? members.error

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Access</h1>
          <p style={{ margin: '0.25rem 0 0', color: 'var(--muted)', fontSize: '0.86rem' }}>
            Profiles decide what somebody can do. People hold profiles.
          </p>
        </div>
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {profileList.length === 0 && (
            <Button onClick={() => void act(() => api.post('v1/access/profiles/bootstrap'))} disabled={busy}>
              <Wand2 size={15} aria-hidden /> Create starter profiles
            </Button>
          )}
          <Button tone="primary" onClick={() => setEditing('new')} disabled={busy}>
            <Plus size={15} aria-hidden /> New profile
          </Button>
        </div>
      </header>

      {error && <Notice tone="danger" title="Not saved" onDismiss={() => setError(null)}>{error}</Notice>}
      {loadError && <Notice tone="danger" title="Could not load access">{loadError}</Notice>}

      {cat && !cat.is_owner && (
        <Notice tone="info" title="You can grant what you hold">
          You hold {cat.granted.length} of {countCatalogue(cat.catalog)} permissions, and can grant those.
          The rest are shown but cannot be given out — {cat.owner_note.toLowerCase()}
        </Notice>
      )}

      {cat && profileList.length === 0 && !profiles.loading && (
        <Notice tone="warning" title="Nobody but the owner can do anything yet">
          This company has no permission profiles, so only the company owner can use Purchases.
          Create the starter profiles above, or build your own — a buyer who cannot approve their own
          order is the point of having more than one.
        </Notice>
      )}

      <Card title={`${profileList.length} profile${profileList.length === 1 ? '' : 's'}`}>
        <DataTable
          loading={profiles.loading}
          rows={profileList}
          rowKey={(row) => row.profile_id}
          empty="No profiles yet."
          columns={[
            {
              key: 'name',
              header: 'Profile',
              render: (row) => (
                <>
                  <strong>{row.profile_name}</strong>
                  {row.description && (
                    <div style={{ color: 'var(--muted)', fontSize: '0.78rem' }}>{row.description}</div>
                  )}
                </>
              ),
            },
            {
              key: 'permissions',
              header: 'Grants',
              numeric: true,
              render: (row) => `${row.permission_count}`,
            },
            { key: 'members', header: 'People', numeric: true, render: (row) => row.member_count },
            {
              key: 'status',
              header: 'Status',
              // Not StatusBadge: its vocabulary is document workflow states, and
              // a profile reading "ACCEPTED" borrows a word that means something
              // else everywhere else in this product.
              render: (row) => (
                <span
                  style={{
                    display: 'inline-block',
                    padding: '0.1rem 0.5rem',
                    borderRadius: '999px',
                    fontSize: '0.72rem',
                    fontWeight: 600,
                    background: row.is_active ? 'var(--success-bg)' : 'var(--surface-2)',
                    color: row.is_active ? 'var(--success)' : 'var(--muted)',
                    border: `1px solid ${row.is_active ? 'var(--success)' : 'var(--border)'}`,
                  }}
                >
                  {row.is_active ? 'Active' : 'Disabled'}
                </span>
              ),
            },
            {
              key: 'actions',
              header: '',
              render: (row) => (
                <div style={{ display: 'flex', gap: '0.4rem', justifyContent: 'flex-end' }}>
                  <Button onClick={() => setEditing(row)} disabled={busy}>Edit</Button>
                  <Button
                    tone="danger"
                    disabled={busy || row.member_count > 0}
                    title={row.member_count > 0 ? 'Remove the people holding it first' : undefined}
                    onClick={() => void act(() => api.del(`v1/access/profiles/${row.profile_id}`))}
                  >
                    <Trash2 size={14} aria-hidden />
                  </Button>
                </div>
              ),
            },
          ]}
        />
      </Card>

      <Card title={`${memberList.length} ${memberList.length === 1 ? 'person' : 'people'} with access`}>
        <DataTable
          loading={members.loading}
          rows={memberList}
          rowKey={(row) => row.user_uuid}
          empty="Nobody has been given a profile yet."
          columns={[
            {
              key: 'person',
              header: 'Person',
              render: (row) => (
                <>
                  <strong>{row.label ?? 'Unlabelled'}</strong>
                  {row.is_you && <span style={{ color: 'var(--muted)' }}> · you</span>}
                  {/* The uuid is the identity; the label is just an administrator's note. */}
                  <div style={{ color: 'var(--muted)', fontSize: '0.74rem', fontFamily: 'ui-monospace, monospace' }}>
                    {row.user_uuid}
                  </div>
                </>
              ),
            },
            {
              key: 'profiles',
              header: 'Profiles',
              render: (row) => (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.3rem' }}>
                  {row.assignments.map((a) => (
                    <span
                      key={a.assignment_id}
                      title={a.note ?? `Assigned ${date(a.assigned_at)}`}
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '0.3rem',
                        padding: '0.1rem 0.45rem',
                        borderRadius: '999px',
                        fontSize: '0.74rem',
                        background: 'var(--surface-2)',
                        border: '1px solid var(--border)',
                        opacity: a.is_active ? 1 : 0.55,
                      }}
                    >
                      {a.profile_name}
                      <button
                        type="button"
                        aria-label={`Remove ${a.profile_name} from ${row.label ?? row.user_uuid}`}
                        disabled={busy}
                        onClick={() => void act(() => api.del(`v1/access/members/${a.assignment_id}`))}
                        style={{ border: 0, background: 'none', cursor: 'pointer', color: 'var(--muted)', padding: 0, lineHeight: 1 }}
                      >
                        ×
                      </button>
                    </span>
                  ))}
                </div>
              ),
            },
            { key: 'count', header: 'Permissions', numeric: true, render: (row) => row.permission_count },
          ]}
        />
      </Card>

      <AssignForm
        profiles={profileList.filter((p) => p.is_active)}
        candidates={people.data?.data ?? []}
        busy={busy}
        onAssign={(body) => act(() => api.post('v1/access/members', body))}
      />

      {editing !== null && cat && (
        <ProfileEditor
          profile={editing === 'new' ? null : editing}
          catalogue={cat}
          busy={busy}
          onClose={() => setEditing(null)}
          onSave={async (body) => {
            await act(() => api.post('v1/access/profiles', body))
            setEditing(null)
          }}
        />
      )}
    </div>
  )
}

function countCatalogue(catalog: Record<string, Record<string, string>>): number {
  return Object.values(catalog).reduce((total, group) => total + Object.keys(group).length, 0)
}

// ---------------------------------------------------------------------------

function AssignForm({
  profiles,
  candidates,
  busy,
  onAssign,
}: {
  profiles: Profile[]
  candidates: Candidate[]
  busy: boolean
  onAssign: (body: Record<string, unknown>) => Promise<void>
}) {
  const [uuid, setUuid] = useState('')
  const [profileId, setProfileId] = useState('')
  const [label, setLabel] = useState('')
  const [note, setNote] = useState('')

  const ready = uuid.trim() !== '' && profileId !== ''

  return (
    <Card title="Give somebody a profile">
      <div style={{ display: 'grid', gap: '0.75rem' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(13rem, 1fr))', gap: '0.75rem' }}>
          <Field
            label="Aicountly user id"
            hint="Their portal uuid. Identity lives in Aicountly Manage; this product only stores the id."
          >
            <Input value={uuid} onChange={(e) => setUuid(e.target.value)} placeholder="e.g. 8f2c…" />
          </Field>

          <Field label="Profile">
            <Select value={profileId} onChange={(e) => setProfileId(e.target.value)}>
              <option value="">Choose a profile</option>
              {profiles.map((p) => (
                <option key={p.profile_id} value={p.profile_id}>
                  {p.profile_name} ({p.permission_count})
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Label" hint="Your own note so the list reads as people, not ids.">
            <Input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="e.g. Priya, production buyer" />
          </Field>
        </div>

        <Field label="Why" hint="Recorded against the grant, and the most useful thing an auditor reads later.">
          <Textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} />
        </Field>

        {candidates.length > 0 && (
          <div>
            <div style={{ fontSize: '0.78rem', color: 'var(--muted)', marginBottom: '0.35rem' }}>
              People who have used Purchases in this company and hold no profile yet — from this
              product's own audit trail, not a user directory:
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
              {candidates.slice(0, 12).map((c) => (
                <Button key={c.user_uuid} onClick={() => setUuid(c.user_uuid)} disabled={busy}>
                  <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '0.74rem' }}>
                    {c.user_uuid.length > 18 ? `${c.user_uuid.slice(0, 18)}…` : c.user_uuid}
                  </span>
                  <span style={{ color: 'var(--muted)', fontSize: '0.72rem' }}>· {c.actions} actions</span>
                </Button>
              ))}
            </div>
          </div>
        )}

        <div>
          <Button
            tone="primary"
            disabled={!ready || busy}
            onClick={async () => {
              await onAssign({
                user_uuid: uuid.trim(),
                profile_id: Number(profileId),
                member_label: label.trim() || undefined,
                note: note.trim() || undefined,
              })
              setUuid('')
              setProfileId('')
              setLabel('')
              setNote('')
            }}
          >
            <UserPlus size={15} aria-hidden /> Give access
          </Button>
        </div>
      </div>
    </Card>
  )
}

// ---------------------------------------------------------------------------

function ProfileEditor({
  profile,
  catalogue,
  busy,
  onClose,
  onSave,
}: {
  profile: Profile | null
  catalogue: Catalogue
  busy: boolean
  onClose: () => void
  onSave: (body: Record<string, unknown>) => Promise<void>
}) {
  const [name, setName] = useState(profile?.profile_name ?? '')
  const [description, setDescription] = useState(profile?.description ?? '')
  const [chosen, setChosen] = useState<Set<string>>(new Set(profile?.permissions ?? []))

  const grantable = useMemo(() => new Set(catalogue.grantable), [catalogue.grantable])

  const toggle = (permission: string) => {
    setChosen((prev) => {
      const next = new Set(prev)
      if (next.has(permission)) next.delete(permission)
      else next.add(permission)
      return next
    })
  }

  return (
    <Card
      title={profile === null ? 'New profile' : `Edit ${profile.profile_name}`}
      action={<Button onClick={onClose}>Close</Button>}
    >
      <div style={{ display: 'grid', gap: '1rem' }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(16rem, 1fr))', gap: '0.75rem' }}>
          <Field label="Name">
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Accounts payable" />
          </Field>
          <Field label="Description" hint="What this profile is for.">
            <Input value={description} onChange={(e) => setDescription(e.target.value)} />
          </Field>
        </div>

        {Object.entries(catalogue.catalog).map(([group, permissions]) => (
          <fieldset key={group} style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', padding: '0.75rem' }}>
            <legend style={{ fontSize: '0.8rem', fontWeight: 600, padding: '0 0.35rem' }}>{group}</legend>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(18rem, 1fr))', gap: '0.4rem' }}>
              {Object.entries(permissions).map(([permission, label]) => {
                const allowed = grantable.has(permission)
                return (
                  <label
                    key={permission}
                    title={allowed ? permission : `${permission} — you do not hold this, so you cannot grant it`}
                    style={{
                      display: 'flex',
                      alignItems: 'flex-start',
                      gap: '0.5rem',
                      fontSize: '0.84rem',
                      opacity: allowed ? 1 : 0.5,
                      cursor: allowed ? 'pointer' : 'not-allowed',
                    }}
                  >
                    <input
                      type="checkbox"
                      checked={chosen.has(permission)}
                      disabled={!allowed || busy}
                      onChange={() => toggle(permission)}
                      style={{ marginTop: '0.15rem' }}
                    />
                    <span>
                      {label}
                      <span style={{ display: 'block', color: 'var(--muted)', fontSize: '0.72rem', fontFamily: 'ui-monospace, monospace' }}>
                        {permission}
                      </span>
                    </span>
                  </label>
                )
              })}
            </div>
          </fieldset>
        ))}

        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', flexWrap: 'wrap' }}>
          <Button
            tone="primary"
            disabled={busy || name.trim() === '' || chosen.size === 0}
            onClick={() =>
              void onSave({
                profile_id: profile?.profile_id,
                profile_name: name.trim(),
                description: description.trim() || undefined,
                permissions: [...chosen],
              })
            }
          >
            <ShieldCheck size={15} aria-hidden /> {profile === null ? 'Create profile' : 'Save profile'}
          </Button>
          <span style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
            {chosen.size} permission{chosen.size === 1 ? '' : 's'} selected
          </span>
        </div>
      </div>
    </Card>
  )
}
