/**
 * Section 2 — the prefix on every document number this product issues.
 *
 * The example under each field is the REAL format. NumberSeries composes
 * `{prefix}/{fy_id}/0001`, so that is what is previewed, with the financial
 * year this session is actually working in. A tidier-looking "PR0001" would be
 * a number this product has never issued and nobody would ever find.
 *
 * The boundary note below the fields is not decoration. A vendor bill number is
 * the supplier's and the voucher number is Smart Books', from its own statutory
 * series — this product numbers neither, and the one place somebody might
 * assume otherwise is a screen headed "Document numbering".
 */

import { Hash, Info, RotateCcw } from 'lucide-react'
import { PpButton, PpCard, PpField, PpStrip } from './ProfileUi'
import {
  DEFAULT_PROFILE,
  PREFIX_FIELDS,
  PREFIX_LIMIT,
  numberExample,
  type ProfileErrors,
  type ProfileForm,
  type ProfileNumbering,
} from './profileModel'

export function DocumentNumberingCard({
  form,
  errors,
  editable,
  fyId,
  onChange,
}: {
  form: ProfileForm
  errors: ProfileErrors
  editable: boolean
  fyId: number | null
  onChange: (numbering: ProfileNumbering) => void
}) {
  return (
    <PpCard
      id="numbering"
      icon={<Hash size={19} aria-hidden />}
      tone="green"
      title="Document Numbering"
      subtitle="Define the prefix for different purchase documents."
      aside={
        editable && (
          <PpButton
            small
            onClick={() => onChange({ ...DEFAULT_PROFILE.numbering })}
            title="Restore the prefixes this company's profile started with"
          >
            <RotateCcw size={13} aria-hidden /> Use Company Defaults
          </PpButton>
        )
      }
    >
      <div className="pp-grid pp-grid--4 pp-grid--aligned">
        {PREFIX_FIELDS.map((field) => {
          const value = form.numbering[field.key]
          return (
            <PpField
              key={field.key}
              id={field.key}
              label={field.label}
              error={errors[field.key]}
              hint={
                <>
                  Next: <b className="pp-hint--mono">{numberExample(value, fyId)}</b>
                </>
              }
            >
              {(props) => (
                <input
                  {...props}
                  value={value}
                  disabled={!editable}
                  maxLength={PREFIX_LIMIT}
                  autoComplete="off"
                  spellCheck={false}
                  onChange={(event) => onChange({ ...form.numbering, [field.key]: event.target.value })}
                />
              )}
            </PpField>
          )
        })}
      </div>

      <PpStrip tone="info" icon={<Info size={14} aria-hidden />}>
        Numbers are issued in order, per financial year, as
        {' '}
        <b>{'{prefix}'}/{fyId ?? '{year}'}/0001</b>. Changing a prefix affects new documents only — the ones
        already numbered keep the numbers they were issued.
      </PpStrip>

      <PpStrip tone="muted">
        Vendor bill numbers are the supplier's, and the voucher number is assigned by Smart Books from its own
        statutory series. Neither is set here.
      </PpStrip>
    </PpCard>
  )
}
