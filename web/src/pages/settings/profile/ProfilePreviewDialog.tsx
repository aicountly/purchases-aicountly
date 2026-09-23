/**
 * The profile as it will read once it is saved.
 *
 * Human-readable, not JSON. The point of a preview is that somebody who is
 * about to commit a numbering change can see the numbers, and somebody about to
 * commit an approval threshold can see the money — neither of which a dump of
 * the payload would tell them. The payload is what Export is for.
 */

import type { MatchPolicy, ProfileTypeOption } from '../../../services/types'
import { money, qty } from '../../../ui'
import { PpButton, PpDialog, PpDl, PpStrip, PpSummary } from './ProfileUi'
import { PREFIX_FIELDS, numberExample, type ProfileForm } from './profileModel'

export function ProfilePreviewDialog({
  open,
  form,
  policies,
  types,
  fyId,
  permissionSummary,
  onClose,
  onSave,
  saving,
  canSave,
}: {
  open: boolean
  form: ProfileForm
  policies: MatchPolicy[]
  types: ProfileTypeOption[]
  fyId: number | null
  permissionSummary: string
  onClose: () => void
  onSave: () => void
  saving: boolean
  canSave: boolean
}) {
  const typeLabel = types.find((type) => type.value === form.type)?.label ?? form.type
  const threshold = (raw: string, noun: string) => {
    const value = Number(String(raw).trim() || 0)
    return Number.isFinite(value) && value > 0
      ? `${noun} above ${money(value)} need approval`
      : `${noun} are auto-approved, whatever the value`
  }

  return (
    <PpDialog
      open={open}
      size="wide"
      title="Preview profile"
      subtitle="Everything this profile will apply once it is saved."
      onClose={onClose}
      footer={
        <>
          <span className="pp-dialog__note">Nothing is saved until you choose Save Profile.</span>
          <PpButton onClick={onClose}>Keep editing</PpButton>
          {canSave && (
            <PpButton tone="primary" disabled={saving} onClick={onSave}>
              {saving ? 'Saving…' : 'Save Profile'}
            </PpButton>
          )}
        </>
      }
    >
      <PpSummary title="Basic details">
        <PpDl
          rows={[
            { label: 'Profile code', value: form.code.trim().toUpperCase() || '—', muted: form.code.trim() === '' },
            { label: 'Profile name', value: form.name.trim() || '—', muted: form.name.trim() === '' },
            { label: 'Profile type', value: typeLabel },
            {
              label: 'Status',
              value: form.active ? 'Active — available for new documents' : 'Inactive — no new documents',
            },
          ]}
        />
        {form.description.trim() !== '' && (
          <p style={{ margin: '12px 0 0', color: 'var(--pp-text-secondary)', fontSize: 13 }}>
            {form.description.trim()}
          </p>
        )}
      </PpSummary>

      <PpSummary title="Document numbering">
        <PpDl
          rows={PREFIX_FIELDS.map((field) => ({
            label: field.label.replace(' Prefix', ''),
            value: numberExample(form.numbering[field.key], fyId),
          }))}
        />
      </PpSummary>

      <PpSummary title="Approval rules">
        <PpDl
          rows={[
            {
              label: 'Requisitions',
              value: threshold(form.approvals.requisitionLimit, 'Requisitions'),
            },
            {
              label: 'Purchase orders',
              value: threshold(form.approvals.purchaseOrderLimit, 'Orders'),
            },
            {
              label: 'Approved suppliers',
              value: form.approvals.approvedSuppliersOnly
                ? 'Orders only to approved suppliers'
                : 'Orders may go to any supplier',
            },
            {
              label: 'Match exceptions',
              value: form.approvals.blockBillOnMatchException
                ? 'A bill cannot be posted while an exception is open'
                : 'Bills may be posted with an exception open',
            },
          ]}
        />
      </PpSummary>

      <PpSummary title="Three-way matching">
        {policies.length === 0 ? (
          <PpStrip tone="muted">No tolerance policy, so matching is exact — the safe default.</PpStrip>
        ) : (
          <ul className="pp-list">
            {policies.map((policy) => (
              <li key={policy.policy_id}>
                <strong>{policy.policy_name}</strong>
                {policy.is_default ? ' (default)' : ''} — quantity {qty(policy.qty_tolerance_pc)}%, rate{' '}
                {qty(policy.rate_tolerance_pc)}%, value {money(policy.value_tolerance_amt)}, freight{' '}
                {money(policy.freight_tolerance_amt)}, auto-match below {money(policy.auto_match_below_amt)}
              </li>
            ))}
          </ul>
        )}
        <PpStrip tone="muted">
          Tolerance policies are saved as you edit them and are not part of this form's unsaved changes.
        </PpStrip>
      </PpSummary>

      <PpSummary title="Permissions">
        <PpStrip tone="muted">{permissionSummary}</PpStrip>
      </PpSummary>
    </PpDialog>
  )
}
