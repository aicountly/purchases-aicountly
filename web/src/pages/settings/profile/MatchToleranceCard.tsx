/**
 * Section 4 — how much a supplier's bill may differ from the order and the
 * receipt before somebody has to look at it.
 *
 * Three documents in three products: the purchase order is ours, the goods
 * receipt is Inventory's and the bill is what the buyer entered on its way to
 * Books. This card configures the tolerance the engine compares them with; it
 * does not hold any of the three.
 *
 * NO POLICY IS A VALID STATE and the empty state says so rather than nagging.
 * Exact matching is the safe default, and a company that wants it should not
 * be told its setup is unfinished.
 */

import { useState } from 'react'
import { AlertTriangle, ArrowLeftRight, Pencil, Plus, Star, Trash2 } from 'lucide-react'
import type { MatchPolicy } from '../../../services/types'
import type { MatchPolicyPayload } from '../../../services/purchaseProfile'
import { money, qty } from '../../../ui'
import { PpButton, PpCard, PpDialog, PpEmpty, PpField, PpStrip } from './ProfileUi'

interface Draft {
  policy_id?: number
  policy_name: string
  qty_tolerance_pc: string
  rate_tolerance_pc: string
  value_tolerance_amt: string
  freight_tolerance_amt: string
  auto_match_below_amt: string
  is_default: boolean
}

const BLANK: Draft = {
  policy_name: '',
  qty_tolerance_pc: '0',
  rate_tolerance_pc: '0',
  value_tolerance_amt: '0',
  freight_tolerance_amt: '0',
  auto_match_below_amt: '0',
  is_default: false,
}

function draftFrom(policy: MatchPolicy): Draft {
  const trim = (value: string | number) => String(Number(Number(value).toFixed(4)))
  return {
    policy_id: policy.policy_id,
    policy_name: policy.policy_name,
    qty_tolerance_pc: trim(policy.qty_tolerance_pc),
    rate_tolerance_pc: trim(policy.rate_tolerance_pc),
    value_tolerance_amt: trim(policy.value_tolerance_amt),
    freight_tolerance_amt: trim(policy.freight_tolerance_amt),
    auto_match_below_amt: trim(policy.auto_match_below_amt),
    is_default: policy.is_default,
  }
}

const DECIMAL = /^\d*(\.\d{1,4})?$/

function draftErrors(draft: Draft): Record<string, string> {
  const errors: Record<string, string> = {}
  if (draft.policy_name.trim() === '') errors.policy_name = 'A policy needs a name.'
  else if (draft.policy_name.trim().length > 80) errors.policy_name = '80 characters at most.'

  for (const key of ['qty_tolerance_pc', 'rate_tolerance_pc'] as const) {
    const raw = draft[key].trim()
    if (raw === '') continue
    if (!DECIMAL.test(raw)) errors[key] = 'A number, four decimals at most.'
    else if (Number(raw) > 100) errors[key] = 'A percentage cannot exceed 100.'
  }
  for (const key of ['value_tolerance_amt', 'freight_tolerance_amt', 'auto_match_below_amt'] as const) {
    const raw = draft[key].trim()
    if (raw !== '' && !DECIMAL.test(raw)) errors[key] = 'A number, four decimals at most.'
  }

  return errors
}

function toPayload(draft: Draft): MatchPolicyPayload {
  const number = (value: string) => (value.trim() === '' ? 0 : Number(value))
  return {
    ...(draft.policy_id ? { policy_id: draft.policy_id } : {}),
    policy_name: draft.policy_name.trim(),
    qty_tolerance_pc: number(draft.qty_tolerance_pc),
    rate_tolerance_pc: number(draft.rate_tolerance_pc),
    value_tolerance_amt: number(draft.value_tolerance_amt),
    freight_tolerance_amt: number(draft.freight_tolerance_amt),
    auto_match_below_amt: number(draft.auto_match_below_amt),
    is_default: draft.is_default,
  }
}

export function MatchToleranceCard({
  policies,
  loading,
  error,
  canView,
  editable,
  busy,
  onSave,
  onDelete,
}: {
  policies: MatchPolicy[]
  loading: boolean
  error: string | null
  canView: boolean
  editable: boolean
  busy: boolean
  onSave: (payload: MatchPolicyPayload) => Promise<boolean>
  onDelete: (policyId: number) => Promise<boolean>
}) {
  const [draft, setDraft] = useState<Draft | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [confirming, setConfirming] = useState<MatchPolicy | null>(null)

  const set = (patch: Partial<Draft>) => setDraft((current) => (current ? { ...current, ...patch } : current))

  async function save() {
    if (!draft) return
    const found = draftErrors(draft)
    setErrors(found)
    if (Object.keys(found).length > 0) return
    if (await onSave(toPayload(draft))) {
      setDraft(null)
      setErrors({})
    }
  }

  const body = () => {
    if (!canView) {
      return (
        <PpStrip tone="muted">
          Viewing match tolerances needs the <code>match.view</code> permission. The rules still apply to your
          documents — you just cannot see how they are configured.
        </PpStrip>
      )
    }
    if (loading) {
      return (
        <div className="pp-policies" aria-busy="true">
          <div className="pp-skeleton" style={{ height: 62 }} />
          <div className="pp-skeleton" style={{ height: 62 }} />
        </div>
      )
    }
    if (error) {
      return (
        <PpStrip tone="danger" icon={<AlertTriangle size={14} aria-hidden />}>
          {error}
        </PpStrip>
      )
    }

    return (
      <>
        {policies.length === 0 ? (
          <PpEmpty icon={<ArrowLeftRight size={18} aria-hidden />} title="No tolerance policy yet">
            Matching remains exact until a tolerance policy is created, which is the safe default.
          </PpEmpty>
        ) : (
          <div className="pp-policies">
            {policies.map((policy) => (
              <article className="pp-policy" key={policy.policy_id}>
                <div className="pp-policy__name">
                  <strong>{policy.policy_name}</strong>
                  <span style={{ display: 'inline-flex', gap: 6, marginTop: 4 }}>
                    {policy.is_default && (
                      <span className="pp-badge pp-badge--default">
                        <Star size={9} aria-hidden /> Default
                      </span>
                    )}
                    <span className={policy.is_active ? 'pp-badge pp-badge--active' : 'pp-badge pp-badge--inactive'}>
                      {policy.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </span>
                </div>

                <dl className="pp-policy__figures">
                  <div className="pp-figure">
                    <dt>Quantity</dt>
                    <dd>{qty(policy.qty_tolerance_pc)}%</dd>
                  </div>
                  <div className="pp-figure">
                    <dt>Rate</dt>
                    <dd>{qty(policy.rate_tolerance_pc)}%</dd>
                  </div>
                  <div className="pp-figure">
                    <dt>Value</dt>
                    <dd>{money(policy.value_tolerance_amt)}</dd>
                  </div>
                  <div className="pp-figure">
                    <dt>Freight</dt>
                    <dd>{money(policy.freight_tolerance_amt)}</dd>
                  </div>
                  <div className="pp-figure">
                    <dt>Auto-match below</dt>
                    <dd>{money(policy.auto_match_below_amt)}</dd>
                  </div>
                </dl>

                {editable && (
                  <div className="pp-policy__actions">
                    <PpButton
                      small
                      tone="quiet"
                      ariaLabel={`Edit ${policy.policy_name}`}
                      onClick={() => {
                        setErrors({})
                        setDraft(draftFrom(policy))
                      }}
                    >
                      <Pencil size={13} aria-hidden /> Edit
                    </PpButton>
                    <PpButton
                      small
                      tone="quiet"
                      ariaLabel={`Delete ${policy.policy_name}`}
                      onClick={() => setConfirming(policy)}
                    >
                      <Trash2 size={13} aria-hidden /> Delete
                    </PpButton>
                  </div>
                )}
              </article>
            ))}
          </div>
        )}

        {draft && (
          <div className="pp-editor">
            <h3>{draft.policy_id ? `Edit “${draft.policy_name || 'policy'}”` : 'New tolerance policy'}</h3>

            <div className="pp-grid pp-grid--3">
              <PpField id="policy_name" label="Policy Name" required error={errors.policy_name}>
                {(props) => (
                  <input
                    {...props}
                    value={draft.policy_name}
                    maxLength={80}
                    placeholder="Standard"
                    onChange={(event) => set({ policy_name: event.target.value })}
                  />
                )}
              </PpField>

              <PpField
                id="qty_tolerance_pc"
                label="Quantity Variance %"
                error={errors.qty_tolerance_pc}
                hint="Receipt against order"
              >
                {(props) => (
                  <input
                    {...props}
                    value={draft.qty_tolerance_pc}
                    inputMode="decimal"
                    onChange={(event) => set({ qty_tolerance_pc: event.target.value })}
                  />
                )}
              </PpField>

              <PpField
                id="rate_tolerance_pc"
                label="Rate Variance %"
                error={errors.rate_tolerance_pc}
                hint="Bill against agreed rate"
              >
                {(props) => (
                  <input
                    {...props}
                    value={draft.rate_tolerance_pc}
                    inputMode="decimal"
                    onChange={(event) => set({ rate_tolerance_pc: event.target.value })}
                  />
                )}
              </PpField>

              <PpField
                id="value_tolerance_amt"
                label="Value Variance (₹)"
                error={errors.value_tolerance_amt}
                hint="Absolute, on the line total"
              >
                {(props) => (
                  <input
                    {...props}
                    value={draft.value_tolerance_amt}
                    inputMode="decimal"
                    onChange={(event) => set({ value_tolerance_amt: event.target.value })}
                  />
                )}
              </PpField>

              <PpField
                id="freight_tolerance_amt"
                label="Freight Variance (₹)"
                error={errors.freight_tolerance_amt}
                hint="Absolute, on freight"
              >
                {(props) => (
                  <input
                    {...props}
                    value={draft.freight_tolerance_amt}
                    inputMode="decimal"
                    onChange={(event) => set({ freight_tolerance_amt: event.target.value })}
                  />
                )}
              </PpField>

              <PpField
                id="auto_match_below_amt"
                label="Auto-match Below (₹)"
                error={errors.auto_match_below_amt}
                hint="Bills under this skip the match"
              >
                {(props) => (
                  <input
                    {...props}
                    value={draft.auto_match_below_amt}
                    inputMode="decimal"
                    onChange={(event) => set({ auto_match_below_amt: event.target.value })}
                  />
                )}
              </PpField>
            </div>

            <label className="pp-check" style={{ background: '#fff' }}>
              <input
                type="checkbox"
                checked={draft.is_default}
                onChange={(event) => set({ is_default: event.target.checked })}
              />
              <span>
                <strong>Use this policy by default</strong>
                <small>A company has exactly one default — setting this one clears the other.</small>
              </span>
            </label>

            <div className="pp-editor__actions">
              <PpButton tone="primary" small disabled={busy} onClick={() => void save()}>
                {busy ? 'Saving…' : 'Save Policy'}
              </PpButton>
              <PpButton
                small
                disabled={busy}
                onClick={() => {
                  setDraft(null)
                  setErrors({})
                }}
              >
                Cancel
              </PpButton>
            </div>
          </div>
        )}

        <PpStrip tone="muted">
          A supplier billing <strong>less</strong> than agreed is never an exception. Only a variance in their
          favour, beyond the tolerance, blocks a bill.
        </PpStrip>
      </>
    )
  }

  return (
    <>
      <PpCard
        id="matching"
        icon={<ArrowLeftRight size={19} aria-hidden />}
        tone="orange"
        title="Three-Way Match Tolerances"
        subtitle="Configure acceptable PO, receipt and supplier-bill variances."
        aside={
          canView &&
          editable &&
          !draft && (
            <PpButton
              small
              onClick={() => {
                setErrors({})
                setDraft({ ...BLANK, is_default: policies.length === 0 })
              }}
            >
              <Plus size={13} aria-hidden /> Add Policy
            </PpButton>
          )
        }
      >
        {body()}
      </PpCard>

      <PpDialog
        open={confirming !== null}
        size="narrow"
        title="Delete this tolerance policy?"
        subtitle={confirming ? `“${confirming.policy_name}” will no longer be available to the match engine.` : undefined}
        onClose={() => setConfirming(null)}
        footer={
          <>
            <PpButton onClick={() => setConfirming(null)} disabled={busy}>
              Cancel
            </PpButton>
            <PpButton
              tone="danger"
              disabled={busy}
              onClick={() => {
                const target = confirming
                if (!target) return
                void onDelete(target.policy_id).then((ok) => {
                  if (ok) setConfirming(null)
                })
              }}
            >
              {busy ? 'Deleting…' : 'Delete Policy'}
            </PpButton>
          </>
        }
      >
        <p style={{ margin: 0 }}>
          Bills already matched under this policy keep their verdict — what was decided then was decided under the
          rules in force then. They simply stop naming a policy that no longer exists.
        </p>
      </PpDialog>
    </>
  )
}
