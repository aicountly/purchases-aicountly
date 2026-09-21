/**
 * Section 3 — thresholds, and the two controls that refuse things.
 *
 * Checkboxes, not switches, and on purpose. Both of these map to a boolean the
 * services read before they refuse a purchase order or a bill; a checkbox is
 * the control HTML already has for "this is on", it arrives in a form with its
 * label, and a keyboard user already knows what Space does to it. Painting it
 * to look like a switch would gain nothing and lose all of that.
 */

import { CheckCircle2 } from 'lucide-react'
import { money } from '../../../ui'
import { PpCard, PpField } from './ProfileUi'
import type { ProfileApprovals, ProfileErrors, ProfileForm } from './profileModel'

function thresholdHint(raw: string, noun: string): string {
  const value = Number(String(raw).trim() || 0)
  if (!Number.isFinite(value) || value <= 0) return '0 means always approve'
  return `${noun} above ${money(value)} need approval`
}

export function ApprovalControlsCard({
  form,
  errors,
  editable,
  onChange,
}: {
  form: ProfileForm
  errors: ProfileErrors
  editable: boolean
  onChange: (approvals: ProfileApprovals) => void
}) {
  const set = (patch: Partial<ProfileApprovals>) => onChange({ ...form.approvals, ...patch })

  return (
    <PpCard
      id="approvals"
      icon={<CheckCircle2 size={19} aria-hidden />}
      tone="indigo"
      title="Approvals and Controls"
      subtitle="Set approval limits and additional controls."
    >
      <div className="pp-grid pp-grid--2">
        <PpField
          id="requisitionLimit"
          label="Requisition Approval Limit (₹)"
          error={errors.requisitionLimit}
          hint={thresholdHint(form.approvals.requisitionLimit, 'Requisitions')}
        >
          {(props) => (
            <input
              {...props}
              value={form.approvals.requisitionLimit}
              disabled={!editable}
              inputMode="decimal"
              autoComplete="off"
              onChange={(event) => set({ requisitionLimit: event.target.value })}
            />
          )}
        </PpField>

        <PpField
          id="purchaseOrderLimit"
          label="Purchase Order Approval Limit (₹)"
          error={errors.purchaseOrderLimit}
          hint={thresholdHint(form.approvals.purchaseOrderLimit, 'Orders')}
        >
          {(props) => (
            <input
              {...props}
              value={form.approvals.purchaseOrderLimit}
              disabled={!editable}
              inputMode="decimal"
              autoComplete="off"
              onChange={(event) => set({ purchaseOrderLimit: event.target.value })}
            />
          )}
        </PpField>
      </div>

      <label className="pp-check">
        <input
          type="checkbox"
          disabled={!editable}
          checked={form.approvals.approvedSuppliersOnly}
          onChange={(event) => set({ approvedSuppliersOnly: event.target.checked })}
        />
        <span>
          <strong>Only allow purchase orders to approved suppliers</strong>
          <small>Restrict PO creation to approved suppliers only</small>
        </span>
      </label>

      <label className="pp-check">
        <input
          type="checkbox"
          disabled={!editable}
          checked={form.approvals.blockBillOnMatchException}
          onChange={(event) => set({ blockBillOnMatchException: event.target.checked })}
        />
        <span>
          <strong>Refuse to post a bill while a match exception is open</strong>
          <small>Prevent posting bills with unresolved 3-way match exceptions</small>
        </span>
      </label>
    </PpCard>
  )
}
