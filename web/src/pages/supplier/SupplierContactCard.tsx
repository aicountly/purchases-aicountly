import { useState } from 'react'
import { api, ApiError } from '../../services/api'
import type { SupplierContact } from '../../services/types'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'
import { Button, Card, Field, Input, Notice } from '../../ui'

/**
 * The company contact Aicountly Contacts links to this supplier's Books ledger — read live, never
 * copied here. A person with supplier.manage can ask Contacts to make the link; Contacts decides
 * whether the ledger is already someone else's.
 */
export function SupplierContactCard({ accId }: { accId: number }) {
  const { scope, can } = usePurchases()
  const [term, setTerm] = useState('')
  const [searched, setSearched] = useState<SupplierContact[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const linked = useApi(
    (signal) => api.one<{ linked: boolean; contact: SupplierContact | null }>(`v1/suppliers/${accId}/contact`, undefined, signal),
    [accId, scope?.cmp_id],
    Boolean(scope),
  )

  async function search() {
    setBusy(true)
    setError(null)
    try {
      const response = await api.get<{ data: SupplierContact[] }>('v1/suppliers/contact-candidates', { q: term.trim() })
      setSearched(response.data)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function link(contact: SupplierContact) {
    setBusy(true)
    setError(null)
    try {
      await api.post(`v1/suppliers/${accId}/contact`, { contact_id: contact.id })
      setSearched(null)
      setTerm('')
      linked.reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  const current = linked.data?.data
  const contact = current?.contact ?? null

  return (
    <Card title="Contact at this supplier">
      {linked.error && <Notice tone="warning">{linked.error}</Notice>}
      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}
      {!linked.error && linked.loading && <p style={{ color: 'var(--muted)', margin: 0 }}>Asking Aicountly Contacts…</p>}
      {contact && (
        <div style={{ display: 'grid', gap: '0.25rem' }}>
          <strong>{contact.display_name || contact.organization_name || 'Unnamed contact'}</strong>
          {contact.organization_name && contact.organization_name !== contact.display_name && (
            <span style={{ color: 'var(--muted)' }}>{contact.organization_name}</span>
          )}
          {contact.emails.length > 0 && <span>{contact.emails.join(', ')}</span>}
          {contact.phones.length > 0 && <span>{contact.phones.join(', ')}</span>}
          {contact.tax_ids.length > 0 && (
            <span style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>
              {contact.tax_ids.map((tax) => `${tax.type.toUpperCase()} ${tax.value}`).join(' · ')}
            </span>
          )}
          <span style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>From Aicountly Contacts, linked to this supplier's Books ledger.</span>
        </div>
      )}
      {current && !contact && (
        <p style={{ color: 'var(--muted)', margin: 0 }}>No company contact is linked to this supplier's Books ledger yet.</p>
      )}
      {current && !contact && can('supplier.manage') && (
        <div style={{ display: 'grid', gap: '0.5rem', marginTop: '0.75rem' }}>
          <Field label="Find a company contact">
            <div style={{ display: 'flex', gap: '0.5rem' }}>
              <Input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Name, email or GSTIN" />
              <Button onClick={() => void search()} disabled={busy}>Search</Button>
            </div>
          </Field>
          {searched && searched.length === 0 && <p style={{ color: 'var(--muted)', margin: 0 }}>No company contact matches.</p>}
          {searched?.map((candidate) => (
            <div key={candidate.id} style={{ display: 'flex', justifyContent: 'space-between', gap: '0.5rem', alignItems: 'center' }}>
              <span>
                {candidate.display_name || candidate.organization_name}
                {candidate.emails[0] && <span style={{ color: 'var(--muted)' }}> · {candidate.emails[0]}</span>}
              </span>
              <Button tone="primary" disabled={busy} onClick={() => void link(candidate)}>Link</Button>
            </div>
          ))}
        </div>
      )}
    </Card>
  )
}
