/**
 * Creating and editing a profile.
 *
 * The rules this renders are the server's, shown rather than discovered:
 *
 *   NO ESCALATION. A permission the administrator does not hold is checkboxed
 *   but disabled, with the reason on it. The API refuses it either way; showing
 *   it greyed is what makes that read as design instead of as a rejection
 *   somebody hits by surprise.
 *
 *   A PROTECTED PERMISSION STAYS. Editing a profile that already grants more
 *   than you hold keeps those permissions checked and locked, because the
 *   server will refuse an edit that drops them.
 *
 * `is_active` is sent explicitly. It used not to be, and the API defaults a
 * missing value to true — so editing the name of a disabled profile quietly
 * switched it back on.
 */

import { useMemo, useState } from 'react'
import { Info, Layers, ShieldCheck } from 'lucide-react'
import { AccessDialog } from './AccessDialog'
import type { Catalogue, Profile } from './types'

export type EditorMode = 'new' | 'edit' | 'duplicate'

export interface ProfileBody {
  profile_id?: number
  profile_name: string
  description?: string
  permissions: string[]
  is_active: boolean
}

export function ProfileEditorDialog({
  open,
  mode,
  profile,
  catalogue,
  busy,
  error,
  onClose,
  onSave,
}: {
  open: boolean
  mode: EditorMode
  /** The profile being edited or copied. Null for a brand new one. */
  profile: Profile | null
  catalogue: Catalogue
  busy: boolean
  error: string | null
  onClose: () => void
  onSave: (body: ProfileBody) => void
}) {
  const [name, setName] = useState(
    mode === 'duplicate' ? `${profile?.profile_name ?? ''} (copy)` : (profile?.profile_name ?? ''),
  )
  const [description, setDescription] = useState(profile?.description ?? '')
  const [active, setActive] = useState(mode === 'edit' ? (profile?.is_active ?? true) : true)
  const [chosen, setChosen] = useState<Set<string>>(new Set(profile?.permissions ?? []))
  const [touched, setTouched] = useState(false)

  const grantable = useMemo(() => new Set(catalogue.grantable), [catalogue.grantable])

  // Permissions this profile already grants that the editor cannot. They stay
  // checked and locked; the server refuses an edit that removes them.
  const protectedCodes = useMemo(
    () => new Set((mode === 'edit' ? (profile?.permissions ?? []) : []).filter((code) => !grantable.has(code))),
    [mode, profile, grantable],
  )

  const toggle = (permission: string) => {
    setChosen((previous) => {
      const next = new Set(previous)
      if (next.has(permission)) next.delete(permission)
      else next.add(permission)
      return next
    })
  }

  const nameError = touched && name.trim() === '' ? 'Give the profile a name.' : null
  const permissionError =
    touched && chosen.size === 0 ? 'A profile with no permissions grants nothing. Choose at least one.' : null

  const save = () => {
    setTouched(true)
    if (name.trim() === '' || chosen.size === 0) return

    onSave({
      profile_id: mode === 'edit' ? profile?.profile_id : undefined,
      profile_name: name.trim(),
      description: description.trim() || undefined,
      permissions: [...chosen],
      is_active: active,
    })
  }

  const title = mode === 'edit' ? `Edit ${profile?.profile_name ?? 'profile'}` : mode === 'duplicate' ? 'Duplicate profile' : 'New profile'

  return (
    <AccessDialog
      open={open}
      title={title}
      description="A profile is a set of permissions. Assigning it to somebody gives them exactly these and nothing else."
      onClose={onClose}
      busy={busy}
      wide
      footer={
        <>
          <span className="access-dialog__footer-note">
            {chosen.size} permission{chosen.size === 1 ? '' : 's'} selected
          </span>
          <button type="button" className="access-btn access-btn--secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="access-btn access-btn--primary" onClick={save} disabled={busy}>
            <ShieldCheck size={15} aria-hidden />
            {busy ? 'Saving…' : mode === 'edit' ? 'Save profile' : 'Create profile'}
          </button>
        </>
      }
    >
      {error && (
        <div className="access-notice access-notice--danger" role="alert" style={{ marginBottom: 14 }}>
          <ShieldCheck size={15} aria-hidden />
          <div>
            <strong>Not saved</strong>
            {error}
          </div>
        </div>
      )}

      {!catalogue.is_owner && (
        <div className="access-notice access-notice--info" style={{ marginBottom: 14 }}>
          <Info size={15} aria-hidden />
          <div>
            <strong>You can grant what you hold</strong>
            You hold {catalogue.granted.length} permission{catalogue.granted.length === 1 ? '' : 's'} and can
            give those out. The rest are listed but cannot be selected — a company owner can grant them.
          </div>
        </div>
      )}

      <div className="access-form__grid" style={{ gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1.4fr)' }}>
        <div className="access-field">
          <label htmlFor="profile-name">Name</label>
          <input
            id="profile-name"
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="e.g. Accounts payable"
            aria-invalid={nameError ? true : undefined}
            aria-describedby={nameError ? 'profile-name-error' : undefined}
          />
          {nameError && (
            <span className="access-field__error" id="profile-name-error">
              {nameError}
            </span>
          )}
        </div>

        <div className="access-field">
          <label htmlFor="profile-description">Description (optional)</label>
          <input
            id="profile-description"
            type="text"
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            placeholder="What this profile is for."
          />
        </div>
      </div>

      <label className="access-perm" style={{ marginBottom: 14 }}>
        <input type="checkbox" checked={active} onChange={() => setActive((was) => !was)} />
        <span>
          Active
          <span className="access-field__hint" style={{ display: 'block', marginTop: 2 }}>
            A disabled profile grants nothing, and cannot be assigned to anybody new.
          </span>
        </span>
      </label>

      {permissionError && (
        <div className="access-notice access-notice--danger" role="alert" style={{ marginBottom: 12 }}>
          <Layers size={15} aria-hidden />
          <div>{permissionError}</div>
        </div>
      )}

      {Object.entries(catalogue.catalog).map(([group, permissions]) => {
        const codes = Object.keys(permissions)
        const selectable = codes.filter((code) => grantable.has(code))
        const selectedHere = codes.filter((code) => chosen.has(code)).length
        const allSelected = selectable.length > 0 && selectable.every((code) => chosen.has(code))

        return (
          // A `<legend>` is only a legend as the first child of its own
          // `<fieldset>`, and this header is a flex row with a button in it.
          // `role="group"` plus `aria-labelledby` groups the checkboxes just as
          // well and stays valid HTML.
          <div
            className="access-permgroup"
            key={group}
            role="group"
            aria-labelledby={`permgroup-${group.replace(/\W+/g, '-')}`}
          >
            <div className="access-permgroup__head">
              <span id={`permgroup-${group.replace(/\W+/g, '-')}`}>{group}</span>
              <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span className="access-permgroup__count">
                  {selectedHere}/{codes.length}
                </span>
                {selectable.length > 0 && (
                  <button
                    type="button"
                    className="access-textbtn"
                    onClick={() =>
                      setChosen((previous) => {
                        const next = new Set(previous)
                        for (const code of selectable) {
                          if (allSelected) next.delete(code)
                          else next.add(code)
                        }
                        return next
                      })
                    }
                  >
                    {allSelected ? 'Clear group' : 'Select all'}
                  </button>
                )}
              </span>
            </div>

            <div className="access-permlist">
              {Object.entries(permissions).map(([code, label]) => {
                const locked = protectedCodes.has(code)
                const allowed = grantable.has(code) && !locked
                const reason = locked
                  ? `${code} — this profile already grants it and you do not hold it, so it cannot be removed`
                  : allowed
                    ? code
                    : `${code} — you do not hold this, so you cannot grant it`

                return (
                  <label
                    key={code}
                    className={allowed ? 'access-perm' : 'access-perm is-locked'}
                    title={reason}
                  >
                    <input
                      type="checkbox"
                      checked={chosen.has(code)}
                      disabled={!allowed || busy}
                      onChange={() => toggle(code)}
                    />
                    <span>
                      {label}
                      <code>{code}</code>
                    </span>
                    {/* Only worth marking for a delegate, where "I hold this"
                        is information. An owner holds the whole catalogue, so
                        a tick beside all twenty-seven says nothing. */}
                    {!catalogue.is_owner && catalogue.granted.includes(code) && (
                      <ShieldCheck size={13} aria-hidden className="access-perm__held" />
                    )}
                  </label>
                )
              })}
            </div>
          </div>
        )
      })}
    </AccessDialog>
  )
}
