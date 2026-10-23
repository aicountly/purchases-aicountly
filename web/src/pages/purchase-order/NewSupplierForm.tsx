import { useState } from 'react'
import { api, ApiError } from '../../services/api'
import { Button, Field, Input, Notice, Select } from '../../ui'
import { GST_STATES, newIdempotencyKey, newSupplierBody, stateOfGstin } from './newSupplier'

export interface CreatedSupplier {
  acc_id: number
  acc_name: string
}

/**
 * "New supplier" in the purchase order editor (launch-readiness P1-P8). A supplier is a creditor ledger
 * in Smart Books, so this asks Purchases to create one there (POST v1/suppliers/ledger, one
 * Idempotency-Key per opened form) and hands the new ledger back to the editor. If Smart Books already
 * has a supplier of that name, it offers that one instead of a second.
 */
export function NewSupplierForm({ onCreated, onCancel }: { onCreated: (supplier: CreatedSupplier) => void; onCancel: () => void }) {
  const [key] = useState(() => newIdempotencyKey())
  const [name, setName] = useState('')
  const [gstin, setGstin] = useState('')
  const [stateCode, setStateCode] = useState('')
  const [city, setCity] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [existing, setExisting] = useState<CreatedSupplier | null>(null)

  async function save() {
    const checked = newSupplierBody({ name, gstin, stateCode, city })
    if ('problem' in checked) {
      setError(checked.problem)
      return
    }
    setSaving(true)
    setError(null)
    setExisting(null)
    try {
      const response = await api.postOnce<CreatedSupplier>('v1/suppliers/ledger', checked.body, key)
      onCreated(response.data)
    } catch (err) {
      if (err instanceof ApiError && err.code === 'supplier_exists' && typeof err.details.acc_id === 'number') {
        setExisting({ acc_id: err.details.acc_id, acc_name: String(err.details.acc_name ?? name) })
      }
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', padding: '0.85rem', display: 'grid', gap: '0.7rem', marginTop: '0.85rem' }}>
      <div style={{ fontWeight: 600 }}>New supplier</div>
      <p style={{ margin: 0, fontSize: '0.82rem', color: 'var(--muted)' }}>
        Created in Smart Books as a creditor ledger, so bills and payments to them are booked there. Add their bank and contact details in Smart Books later.
      </p>
      {error && (
        <Notice tone={existing ? 'warning' : 'danger'} action={existing ? <Button onClick={() => onCreated(existing)}>Use {existing.acc_name}</Button> : undefined}>
          {error}
        </Notice>
      )}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.7rem' }}>
        <Field label="Name"><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="As on their invoices" autoFocus /></Field>
        <Field label="GSTIN" hint="Optional; decides the state">
          <Input
            value={gstin}
            onChange={(e) => {
              setGstin(e.target.value)
              const s = stateOfGstin(e.target.value)
              if (s) setStateCode(s)
            }}
            placeholder="27AAPFU0939F1ZV"
          />
        </Field>
        <Field label="State">
          <Select value={stateCode} onChange={(e) => setStateCode(e.target.value)}>
            <option value="">Not known</option>
            {GST_STATES.map((s) => (
              <option key={s.code} value={s.code}>
                {s.name} ({s.code})
              </option>
            ))}
          </Select>
        </Field>
        <Field label="City"><Input value={city} onChange={(e) => setCity(e.target.value)} /></Field>
      </div>
      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={onCancel}>Cancel</Button>
        <Button tone="primary" disabled={saving} onClick={save}>{saving ? 'Adding…' : 'Add supplier'}</Button>
      </div>
    </div>
  )
}
