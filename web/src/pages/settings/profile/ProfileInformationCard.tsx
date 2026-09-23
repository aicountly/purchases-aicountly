/**
 * Section 1 — what this profile is called and what it is for.
 *
 * The profile type list comes from the server (`meta.profile_types`), so a
 * deployment that gains a sixth kind of procurement gains it here without a
 * front-end release, and this screen can never offer a value the API would
 * refuse.
 */

import { Building2 } from 'lucide-react'
import type { ProfileTypeOption } from '../../../services/types'
import { PpCard, PpField } from './ProfileUi'
import { CODE_LIMIT, DESCRIPTION_LIMIT, NAME_LIMIT, type ProfileErrors, type ProfileForm } from './profileModel'

export function ProfileInformationCard({
  form,
  errors,
  types,
  editable,
  onChange,
}: {
  form: ProfileForm
  errors: ProfileErrors
  types: ProfileTypeOption[]
  editable: boolean
  onChange: (patch: Partial<ProfileForm>) => void
}) {
  const used = form.description.length
  const over = used > DESCRIPTION_LIMIT

  return (
    <PpCard
      id="basic-details"
      icon={<Building2 size={19} aria-hidden />}
      tone="indigo"
      title="Profile Information"
      subtitle="Define the basic details for this purchase profile."
      aside={
        <span className="pp-required-note">
          <b aria-hidden>*</b> Required fields
        </span>
      }
    >
      <div className="pp-grid pp-grid--3">
        <PpField
          id="code"
          label="Profile Code"
          required
          hint="Unique code for this profile"
          error={errors.code}
        >
          {(props) => (
            <input
              {...props}
              value={form.code}
              disabled={!editable}
              maxLength={CODE_LIMIT}
              autoComplete="off"
              spellCheck={false}
              placeholder="PR"
              // Upper-cased as it is typed, which is how it is stored. Doing it
              // only on save means the field disagrees with the database for as
              // long as the form is open.
              onChange={(event) => onChange({ code: event.target.value.toUpperCase() })}
            />
          )}
        </PpField>

        <PpField
          id="name"
          label="Profile Name"
          required
          hint="Display name for easy identification"
          error={errors.name}
        >
          {(props) => (
            <input
              {...props}
              value={form.name}
              disabled={!editable}
              maxLength={NAME_LIMIT}
              placeholder="Procurement - Regular"
              onChange={(event) => onChange({ name: event.target.value })}
            />
          )}
        </PpField>

        <PpField id="type" label="Profile Type" hint="Select the type of profile" error={errors.type}>
          {(props) => (
            <select
              {...props}
              value={form.type}
              disabled={!editable || types.length === 0}
              onChange={(event) => onChange({ type: event.target.value })}
            >
              {/* A saved value this deployment no longer offers still shows,
                  rather than the select silently snapping to its first option
                  and saving a change nobody made. */}
              {!types.some((type) => type.value === form.type) && (
                <option value={form.type}>{form.type || 'Standard Procurement'}</option>
              )}
              {types.map((type) => (
                <option key={type.value} value={type.value}>
                  {type.label}
                </option>
              ))}
            </select>
          )}
        </PpField>
      </div>

      <div className="pp-field--wide">
        <PpField id="description" label="Description" error={errors.description}>
          {(props) => (
            <textarea
              {...props}
              value={form.description}
              disabled={!editable}
              maxLength={DESCRIPTION_LIMIT}
              rows={3}
              placeholder="What this profile covers, and when to use it."
              onChange={(event) => onChange({ description: event.target.value })}
            />
          )}
        </PpField>
        <div className={over ? 'pp-counter is-over' : 'pp-counter'} aria-live="polite">
          {used} / {DESCRIPTION_LIMIT}
        </div>
      </div>
    </PpCard>
  )
}
