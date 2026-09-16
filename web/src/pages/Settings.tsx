import { useEffect, useState } from 'react'
import { api, ApiError } from '../services/api'
import type { MatchPolicy, PurchaseSettings } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { Button, Card, DataTable, Field, Input, money, Notice, qty } from '../ui'

export default function Settings() {
  const { scope, can, session } = usePurchases()
  const [form, setForm] = useState<Partial<PurchaseSettings>>({})
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [policy, setPolicy] = useState({ policy_name: '', qty_tolerance_pc: '0', rate_tolerance_pc: '0', auto_match_below_amt: '0', is_default: true })

  const settings = useApi(
    (signal) => api.one<PurchaseSettings>('v1/settings', undefined, signal),
    [scope?.cmp_id],
    Boolean(scope),
  )

  const policies = useApi(
    (signal) => api.get<{ data: MatchPolicy[] }>('v1/settings/match-policies', undefined, signal),
    [scope?.cmp_id],
    Boolean(scope && can('match.view')),
  )

  useEffect(() => {
    if (settings.data?.data) setForm(settings.data.data)
  }, [settings.data])

  const editable = can('settings.manage')

  async function save() {
    setBusy(true)
    setError(null)
    setSaved(false)
    try {
      await api.put('v1/settings', form)
      setSaved(true)
      settings.reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function savePolicy() {
    setBusy(true)
    setError(null)
    try {
      await api.post('v1/settings/match-policies', {
        policy_name: policy.policy_name.trim(),
        qty_tolerance_pc: Number(policy.qty_tolerance_pc),
        rate_tolerance_pc: Number(policy.rate_tolerance_pc),
        auto_match_below_amt: Number(policy.auto_match_below_amt),
        is_default: policy.is_default,
      })
      setPolicy({ policy_name: '', qty_tolerance_pc: '0', rate_tolerance_pc: '0', auto_match_below_amt: '0', is_default: true })
      policies.reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (settings.loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '52rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Settings</h1>

      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}
      {saved && <Notice tone="success">Saved.</Notice>}
      {!editable && <Notice tone="info">You can see these settings but not change them.</Notice>}

      <Card title="Document numbering">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(10rem, 1fr))', gap: '0.85rem' }}>
          {([
            ['requisition_prefix', 'Requisition prefix'],
            ['rfq_prefix', 'RFQ prefix'],
            ['po_prefix', 'Purchase order prefix'],
            ['return_prefix', 'Return prefix'],
            ['claim_prefix', 'Claim prefix'],
          ] as const).map(([key, label]) => (
            <Field key={key} label={label}>
              <Input disabled={!editable} value={(form[key] as string) ?? ''} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
            </Field>
          ))}
        </div>
        <p style={{ color: 'var(--muted)', fontSize: '0.8rem', marginBottom: 0 }}>
          Vendor bill numbers are the supplier's, and the voucher number is assigned by Smart Books from its own
          statutory series.
        </p>
      </Card>

      <Card title="Approvals and controls">
        <div style={{ display: 'grid', gap: '0.85rem' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(13rem, 1fr))', gap: '0.85rem' }}>
            <Field label="Requisition needs approval above" hint="0 means always approve">
              <Input
                disabled={!editable}
                inputMode="decimal"
                value={String(form.requisition_approval_above_amount ?? '')}
                onChange={(e) => setForm({ ...form, requisition_approval_above_amount: e.target.value })}
              />
            </Field>
            <Field label="Purchase order needs approval above">
              <Input
                disabled={!editable}
                inputMode="decimal"
                value={String(form.po_approval_above_amount ?? '')}
                onChange={(e) => setForm({ ...form, po_approval_above_amount: e.target.value })}
              />
            </Field>
          </div>
          <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <input
              type="checkbox"
              disabled={!editable}
              checked={Boolean(form.enforce_approved_vendors)}
              onChange={(e) => setForm({ ...form, enforce_approved_vendors: e.target.checked })}
            />
            Only allow purchase orders to approved suppliers
          </label>
          <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <input
              type="checkbox"
              disabled={!editable}
              checked={Boolean(form.block_bill_on_match_failure)}
              onChange={(e) => setForm({ ...form, block_bill_on_match_failure: e.target.checked })}
            />
            Refuse to post a bill while a match exception is open
          </label>
        </div>
      </Card>

      {can('match.view') && (
        <Card title="Three-way match tolerances">
          <DataTable
            loading={policies.loading}
            rows={policies.data?.data ?? []}
            rowKey={(row) => row.policy_id}
            empty="No policy yet — matching is exact, which is the safe default."
            columns={[
              { key: 'name', header: 'Policy', render: (row) => row.policy_name },
              { key: 'qty', header: 'Quantity', numeric: true, render: (row) => `${qty(row.qty_tolerance_pc)}%` },
              { key: 'rate', header: 'Rate', numeric: true, render: (row) => `${qty(row.rate_tolerance_pc)}%` },
              { key: 'auto', header: 'Auto-match below', numeric: true, render: (row) => money(row.auto_match_below_amt) },
              { key: 'default', header: 'Default', render: (row) => (row.is_default ? 'Yes' : '—') },
            ]}
          />

          {editable && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(10rem, 1fr))', gap: '0.6rem', alignItems: 'end', marginTop: '0.85rem' }}>
              <Field label="Name"><Input value={policy.policy_name} onChange={(e) => setPolicy({ ...policy, policy_name: e.target.value })} /></Field>
              <Field label="Quantity %"><Input value={policy.qty_tolerance_pc} inputMode="decimal" onChange={(e) => setPolicy({ ...policy, qty_tolerance_pc: e.target.value })} /></Field>
              <Field label="Rate %"><Input value={policy.rate_tolerance_pc} inputMode="decimal" onChange={(e) => setPolicy({ ...policy, rate_tolerance_pc: e.target.value })} /></Field>
              <Field label="Auto-match below"><Input value={policy.auto_match_below_amt} inputMode="decimal" onChange={(e) => setPolicy({ ...policy, auto_match_below_amt: e.target.value })} /></Field>
              <Button tone="primary" disabled={busy || policy.policy_name.trim() === ''} onClick={savePolicy}>Save policy</Button>
            </div>
          )}

          <p style={{ color: 'var(--muted)', fontSize: '0.8rem', marginTop: '0.85rem', marginBottom: 0 }}>
            A supplier billing LESS than agreed is never an exception. Only a variance in their favour, beyond the
            tolerance, blocks a bill.
          </p>
        </Card>
      )}

      {session && (
        <Card title="Your permissions in Purchases">
          {session.is_owner ? (
            <p style={{ margin: 0 }}>You own this company, so you hold every Purchases permission.</p>
          ) : (
            <ul style={{ margin: 0, paddingLeft: '1.1rem', columns: 2 }}>
              {session.permissions.map((permission) => <li key={permission} style={{ fontSize: '0.85rem' }}>{permission}</li>)}
            </ul>
          )}
        </Card>
      )}

      {editable && (
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <Button tone="primary" disabled={busy} onClick={save}>{busy ? 'Saving…' : 'Save settings'}</Button>
        </div>
      )}
    </div>
  )
}
