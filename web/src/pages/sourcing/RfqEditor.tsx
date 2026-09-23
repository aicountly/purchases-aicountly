/**
 * A new enquiry.
 *
 * Moved here from `pages/Sourcing.tsx` when sourcing became a folder, with one
 * addition: it accepts a draft handed to it in navigation state by the guided
 * brief on the sourcing screen. Nothing else changed, and nothing is saved
 * until the buyer presses Save.
 */

import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { api, ApiError } from '../../services/api'
import type { CatalogSupplier, Rfq } from '../../services/types'
import { ItemPicker, SupplierPicker } from '../../components/LivePicker'
import { Button, Card, Field, Input, Notice, Textarea } from '../../ui'

/** What the guided brief hands over. Every field is optional and editable here. */
interface RfqDraft {
  title?: string
  deadline?: string
  requiredBy?: string
  terms?: string
  lineLabel?: string
  quantity?: string
}

export function RfqEditor() {
  const navigate = useNavigate()
  // A draft from the guided brief, if that is how the buyer arrived. It is
  // navigation state rather than a saved record: nothing has been written yet,
  // and refreshing the page is meant to lose it.
  const draft = (useLocation().state as { draft?: RfqDraft } | null)?.draft

  const [title, setTitle] = useState(draft?.title ?? '')
  const [deadline, setDeadline] = useState(draft?.deadline ?? '')
  const [requiredBy, setRequiredBy] = useState(draft?.requiredBy ?? '')
  const [terms, setTerms] = useState(draft?.terms ?? '')
  const [suppliers, setSuppliers] = useState<Array<{ id: number; name: string }>>([])
  const [lines, setLines] = useState<Array<{ key: string; item_id: number | null; label: string; qty: string }>>([
    // The brief names what is being bought; the ITEM is still chosen here,
    // because only Inventory knows which item that is.
    { key: 'a', item_id: null, label: '', qty: draft?.quantity || '1' },
  ])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function save() {
    setSaving(true)
    setError(null)
    try {
      const response = await api.post<Rfq>('v1/rfqs', {
        title: title || undefined,
        response_deadline: deadline || undefined,
        required_by: requiredBy || undefined,
        commercial_terms: terms || undefined,
        supplier_account_ids: suppliers.map((s) => s.id),
        lines: lines.filter((l) => l.item_id).map((l) => ({ item_id: l.item_id, required_qty: Number(l.qty || 0), description: l.label })),
      })
      navigate(`/rfqs/${response.data.rfq_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '56rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>New RFQ</h1>
      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}
      {draft && (
        <Notice tone="info" title="Filled in from your brief">
          {draft.lineLabel ? `You said you were buying: ${draft.lineLabel}. ` : ''}
          Pick the items from Inventory and the suppliers to invite, then check every field — nothing has been
          saved yet.
        </Notice>
      )}

      <Card title="Enquiry">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.85rem' }}>
          <Field label="Title"><Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Q3 steel" /></Field>
          <Field label="Responses by"><Input type="date" value={deadline} onChange={(e) => setDeadline(e.target.value)} /></Field>
          <Field label="Delivery needed by"><Input type="date" value={requiredBy} onChange={(e) => setRequiredBy(e.target.value)} /></Field>
        </div>
        <div style={{ marginTop: '0.85rem' }}>
          <Field label="Commercial terms"><Textarea value={terms} onChange={(e) => setTerms(e.target.value)} /></Field>
        </div>
      </Card>

      <Card title="Suppliers to invite">
        <SupplierPicker
          onPick={(supplier: CatalogSupplier) =>
            setSuppliers((current) =>
              current.some((s) => s.id === supplier.acc_id) ? current : [...current, { id: supplier.acc_id, name: supplier.acc_name }],
            )
          }
        />
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginTop: '0.75rem' }}>
          {suppliers.map((supplier) => (
            <span
              key={supplier.id}
              style={{ padding: '0.2rem 0.6rem', borderRadius: '999px', background: 'var(--surface-2)', border: '1px solid var(--border)', fontSize: '0.85rem' }}
            >
              {supplier.name}
              <button
                type="button"
                onClick={() => setSuppliers((c) => c.filter((s) => s.id !== supplier.id))}
                style={{ marginLeft: '0.4rem', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--muted)' }}
                aria-label={`Remove ${supplier.name}`}
              >
                ×
              </button>
            </span>
          ))}
          {suppliers.length === 0 && <span style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>None yet.</span>}
        </div>
      </Card>

      <Card
        title="Lines"
        action={<Button onClick={() => setLines((c) => [...c, { key: Math.random().toString(36).slice(2), item_id: null, label: '', qty: '1' }])}>Add line</Button>}
      >
        <div style={{ display: 'grid', gap: '0.75rem' }}>
          {lines.map((line) => (
            <div key={line.key} style={{ display: 'grid', gridTemplateColumns: '3fr 1fr auto', gap: '0.6rem', alignItems: 'end' }}>
              <ItemPicker
                selectedLabel={line.label || null}
                onPick={(item) =>
                  setLines((c) => c.map((l) => (l.key === line.key ? { ...l, item_id: item.item_id, label: item.item_name } : l)))
                }
              />
              <Field label="Quantity">
                <Input value={line.qty} inputMode="decimal" onChange={(e) => setLines((c) => c.map((l) => (l.key === line.key ? { ...l, qty: e.target.value } : l)))} />
              </Field>
              <Button tone="ghost" onClick={() => setLines((c) => (c.length > 1 ? c.filter((l) => l.key !== line.key) : c))}>Remove</Button>
            </div>
          ))}
        </div>
      </Card>

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button tone="primary" disabled={saving} onClick={save}>{saving ? 'Saving…' : 'Save RFQ'}</Button>
      </div>
    </div>
  )
}
