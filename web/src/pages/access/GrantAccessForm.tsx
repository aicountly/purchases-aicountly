/**
 * Giving somebody a profile.
 *
 * THE PLACEHOLDER IS A UUID, NOT AN EMAIL. The mock asks for
 * "priya@company.com or user id", and there is no lookup endpoint behind that
 * — `v1/access/members` takes a portal uuid and nothing else. Promising email
 * here would produce a validation failure on every first attempt, so the field
 * asks for what the server accepts and says where to find it. If Manage ever
 * exposes a directory search, this is the one field that changes.
 *
 * Input survives a failure. A rejected grant that also clears the form makes
 * the administrator retype a uuid they just pasted, and the second attempt is
 * where the typo comes from.
 */

import { useEffect, useRef, useState } from 'react'
import { Plus, UserPlus } from 'lucide-react'
import type { Candidate, Profile } from './types'
import { shortUuid } from './ui'

export interface GrantResult {
  message: string
  /** Which field the server blamed, when it named one. */
  field?: string
}

export interface GrantBody {
  user_uuid: string
  profile_id: number
  member_label?: string
  note?: string
}

export function GrantAccessForm({
  profiles,
  candidates,
  busy,
  prefillUuid,
  onSubmit,
  onNewProfile,
}: {
  profiles: Profile[]
  candidates: Candidate[]
  busy: boolean
  /** Set when another panel sent somebody here. Changing it refocuses the form. */
  prefillUuid: { uuid: string; token: number } | null
  onSubmit: (body: GrantBody) => Promise<GrantResult | null>
  onNewProfile: () => void
}) {
  const [uuid, setUuid] = useState('')
  const [profileId, setProfileId] = useState('')
  const [label, setLabel] = useState('')
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  const uuidField = useRef<HTMLInputElement>(null)
  const profileField = useRef<HTMLSelectElement>(null)

  useEffect(() => {
    if (!prefillUuid) return
    setUuid(prefillUuid.uuid)
    setErrors({})
    // The person came from another tab expecting to finish here, so the form
    // takes focus rather than leaving them to find it.
    uuidField.current?.focus()
    uuidField.current?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  }, [prefillUuid])

  const noProfiles = profiles.length === 0

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    // Double-submit guard. Two clicks on a slow network is two grants, and the
    // second one overwrites the note on the first.
    if (submitting || busy) return

    const found: Record<string, string> = {}
    const trimmedUuid = uuid.trim()
    if (trimmedUuid === '') found.user_uuid = 'Which person? Their Aicountly user id is required.'
    else if (trimmedUuid.length > 128) found.user_uuid = 'That does not look like an Aicountly user id.'
    if (profileId === '') found.profile_id = 'Choose the profile they should hold.'

    setErrors(found)
    if (Object.keys(found).length > 0) {
      if (found.user_uuid) uuidField.current?.focus()
      else profileField.current?.focus()
      return
    }

    setSubmitting(true)
    const failure = await onSubmit({
      user_uuid: trimmedUuid,
      profile_id: Number(profileId),
      member_label: label.trim() || undefined,
      note: note.trim() || undefined,
    })
    setSubmitting(false)

    if (failure) {
      setErrors(failure.field ? { [failure.field]: failure.message } : { form: failure.message })
      if (failure.field === 'profile_id') profileField.current?.focus()
      else uuidField.current?.focus()
      return
    }

    // Only the identity fields clear. An administrator granting three people
    // the same profile for the same reason should not retype the reason three
    // times, and the label belongs to the person who has just been cleared.
    setUuid('')
    setLabel('')
    setErrors({})
  }

  const working = submitting || busy

  return (
    <section className="access-panel" id="access-grant" aria-labelledby="access-grant-heading">
      <header className="access-panel__header">
        <div>
          <h2 id="access-grant-heading">Give somebody a profile</h2>
          <p>Add a team member and assign a profile to give them access.</p>
        </div>
      </header>

      <form className="access-form" onSubmit={submit} noValidate>
        {errors.form && (
          <div className="access-notice access-notice--danger" role="alert" style={{ marginBottom: 12 }}>
            <UserPlus size={15} aria-hidden />
            <div>
              <strong>Not saved</strong>
              {errors.form}
            </div>
          </div>
        )}

        <div className="access-form__grid">
          <div className="access-field">
            <label htmlFor="access-user">Aicountly user id</label>
            <input
              id="access-user"
              ref={uuidField}
              type="text"
              value={uuid}
              autoComplete="off"
              spellCheck={false}
              onChange={(event) => setUuid(event.target.value)}
              placeholder="e.g. 8f2c1d94-3b7a-4e55-…"
              aria-invalid={errors.user_uuid ? true : undefined}
              aria-describedby={errors.user_uuid ? 'access-user-error' : 'access-user-hint'}
            />
            {errors.user_uuid ? (
              <span className="access-field__error" id="access-user-error">
                {errors.user_uuid}
              </span>
            ) : (
              <span className="access-field__hint" id="access-user-hint">
                Their portal user id. Identity lives in Aicountly Manage — Purchases only stores the id.
              </span>
            )}
          </div>

          <div className="access-field">
            <label htmlFor="access-profile">Profile</label>
            <select
              id="access-profile"
              ref={profileField}
              value={profileId}
              disabled={noProfiles}
              onChange={(event) => setProfileId(event.target.value)}
              aria-invalid={errors.profile_id ? true : undefined}
              aria-describedby={errors.profile_id ? 'access-profile-error' : noProfiles ? 'access-profile-hint' : undefined}
            >
              <option value="">Choose a profile</option>
              {profiles.map((profile) => (
                <option key={profile.profile_id} value={profile.profile_id}>
                  {profile.profile_name} ({profile.permission_count} permission
                  {profile.permission_count === 1 ? '' : 's'})
                </option>
              ))}
            </select>
            {errors.profile_id ? (
              <span className="access-field__error" id="access-profile-error">
                {errors.profile_id}
              </span>
            ) : noProfiles ? (
              <span className="access-field__hint" id="access-profile-hint">
                There are no profiles to give yet.{' '}
                <button type="button" className="access-textbtn" onClick={onNewProfile}>
                  <Plus size={11} aria-hidden /> Create one
                </button>
              </span>
            ) : null}
          </div>

          <div className="access-field">
            <label htmlFor="access-label">Label (optional)</label>
            <input
              id="access-label"
              type="text"
              value={label}
              onChange={(event) => setLabel(event.target.value)}
              placeholder="e.g. Priya, production buyer"
              aria-describedby="access-label-hint"
            />
            <span className="access-field__hint" id="access-label-hint">
              A short note so the list reads as people, not ids.
            </span>
          </div>
        </div>

        <div className="access-field">
          <label htmlFor="access-reason">Why (optional)</label>
          <textarea
            id="access-reason"
            rows={3}
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder="Add a reason (e.g. handles purchase orders)"
            aria-describedby="access-reason-hint"
          />
          <span className="access-field__hint" id="access-reason-hint">
            Recorded against the grant, and the most useful thing an auditor reads later.
          </span>
        </div>

        {candidates.length > 0 && (
          <div className="access-suggestions">
            <p className="access-suggestions__label">
              People who have used Purchases in this company and hold no profile yet — from this product's own
              audit trail, not a user directory:
            </p>
            <div className="access-chips">
              {candidates.slice(0, 8).map((candidate) => (
                <button
                  key={candidate.user_uuid}
                  type="button"
                  className="access-suggestion"
                  disabled={working}
                  onClick={() => {
                    setUuid(candidate.user_uuid)
                    setErrors({})
                    uuidField.current?.focus()
                  }}
                  title={candidate.user_uuid}
                >
                  <span className="access-uuid">{shortUuid(candidate.user_uuid, 10)}</span>
                  <span className="access-field__hint">· {candidate.actions} actions</span>
                </button>
              ))}
            </div>
          </div>
        )}

        <div className="access-form__footer">
          <button type="submit" className="access-btn access-btn--primary" disabled={working || noProfiles}>
            <UserPlus size={15} aria-hidden /> {submitting ? 'Giving access…' : 'Give access'}
          </button>
          <span className="access-field__hint">
            The grant takes effect immediately, and is recorded against your name.
          </span>
        </div>
      </form>
    </section>
  )
}
