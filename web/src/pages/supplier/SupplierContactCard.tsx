import { useState } from 'react'
import { api, ApiError } from '../../services/api'
import type { SupplierContact, SupplierContactCandidate, SupplierContactCandidates, SupplierContactLink } from '../../services/types'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'
import { Button, Card, Field, Input, Notice } from '../../ui'

const SEARCHED_BY: Record<string, string> = {
  tax_id: 'GSTIN / PAN',
  email: 'e-mail',
  phone: 'phone number',
  name: 'name',
}

/**
 * The company contact Aicountly Contacts links to this supplier's Books ledger — read live, never
 * copied here. Someone with supplier.manage can link one (Contacts decides whether the ledger is
 * already someone else's), remove the link, or move it to the contact a merged one survived as.
 * An archived or merged contact is shown as such, never as the supplier's live contact; when
 * Contacts cannot answer, the card says so instead of claiming there is no contact.
 */
export function SupplierContactCard({ accId }: { accId: number }) {
  const { scope, can } = usePurchases()
  const mayManage = can('supplier.manage')
  const [term, setTerm] = useState('')
  const [searched, setSearched] = useState<SupplierContactCandidates | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const linked = useApi(
    (signal) => api.one<SupplierContactLink>(`v1/suppliers/${accId}/contact`, undefined, signal),
    [accId, scope?.cmp_id],
    Boolean(scope),
  )

  async function run(action: () => Promise<unknown>) {
    setBusy(true)
    setError(null)
    try {
      await action()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  const search = (page = 1) =>
    run(async () => {
      setSearched(await api.get<SupplierContactCandidates>('v1/suppliers/contact-candidates', { q: term.trim(), page, per_page: 10 }))
    })

  const link = (contact: Pick<SupplierContactCandidate, 'id'>) =>
    run(async () => {
      await api.post(`v1/suppliers/${accId}/contact`, { contact_id: contact.id })
      setSearched(null)
      setTerm('')
      linked.reload()
    })

  const unlink = () =>
    run(async () => {
      await api.del(`v1/suppliers/${accId}/contact`)
      linked.reload()
    })

  const current = linked.data?.data
  const contact = current?.contact ?? null
  const live = contact !== null && current?.state === 'active'

  return (
    <Card title="Contact at this supplier">
      {linked.error && <Notice tone="warning">{linked.error}</Notice>}
      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}
      {!linked.error && linked.loading && <p style={{ color: 'var(--muted)', margin: 0 }}>Asking Aicountly Contacts…</p>}

      {contact && <ContactDetails contact={contact} muted={!live} />}
      {contact && !live && current?.message && (
        <Notice tone="warning" title={current.state === 'merged' ? 'Merged in Contacts' : current.state === 'deleted' ? 'Deleted in Contacts' : 'Archived in Contacts'}>
          {current.message}
        </Notice>
      )}
      {current?.survivor && (
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: '0.5rem', alignItems: 'center', marginTop: '0.5rem' }}>
          <span>
            Merged into <strong>{current.survivor.display_name || current.survivor.organization_name || 'another contact'}</strong>
          </span>
          {mayManage && (
            <Button tone="primary" disabled={busy} onClick={() => void link(current.survivor as SupplierContact)}>
              Link this one instead
            </Button>
          )}
        </div>
      )}
      {contact && mayManage && (
        <div style={{ marginTop: '0.5rem' }}>
          <Button tone="ghost" disabled={busy} onClick={() => void unlink()} title="Removes the link in Aicountly Contacts; the contact itself stays">
            Remove link
          </Button>
        </div>
      )}

      {current && !contact && (
        <p style={{ color: 'var(--muted)', margin: 0 }}>No company contact is linked to this supplier's Books ledger yet.</p>
      )}
      {current && !live && mayManage && (
        <div style={{ display: 'grid', gap: '0.5rem', marginTop: '0.75rem' }}>
          <Field label={contact ? 'Link another company contact' : 'Find a company contact'}>
            <form
              style={{ display: 'flex', gap: '0.5rem' }}
              onSubmit={(e) => {
                e.preventDefault()
                void search(1)
              }}
            >
              <Input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Name, e-mail, phone or GSTIN" />
              <Button type="submit" disabled={busy || term.trim() === ''}>Search</Button>
            </form>
          </Field>
          {searched && (
            <span style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
              {searched.meta.total === 0
                ? `No company contact matches that ${SEARCHED_BY[searched.meta.searched_by] ?? searched.meta.searched_by}.`
                : `${searched.meta.total} found by ${SEARCHED_BY[searched.meta.searched_by] ?? searched.meta.searched_by}.`}
            </span>
          )}
          {searched?.data.map((candidate) => (
            <div key={candidate.id} style={{ display: 'flex', justifyContent: 'space-between', gap: '0.5rem', alignItems: 'center' }}>
              <span>
                {candidate.display_name || candidate.organization_name}
                {[candidate.email_hint, candidate.phone_hint, ...candidate.tax_ids.map((tax) => `${tax.type.toUpperCase()} ${tax.value}`)]
                  .filter(Boolean)
                  .map((hint) => (
                    <span key={hint} style={{ color: 'var(--muted)' }}> · {hint}</span>
                  ))}
              </span>
              <Button tone="primary" disabled={busy || candidate.state !== 'active'} title={candidate.state !== 'active' ? `This contact is ${candidate.state} in Contacts` : undefined} onClick={() => void link(candidate)}>
                Link
              </Button>
            </div>
          ))}
          {searched && searched.meta.total_pages > 1 && (
            <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
              <Button tone="ghost" disabled={busy || searched.meta.page <= 1} onClick={() => void search(searched.meta.page - 1)}>Previous</Button>
              <span style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>Page {searched.meta.page} of {searched.meta.total_pages}</span>
              <Button tone="ghost" disabled={busy || searched.meta.page >= searched.meta.total_pages} onClick={() => void search(searched.meta.page + 1)}>Next</Button>
            </div>
          )}
        </div>
      )}
    </Card>
  )
}

function ContactDetails({ contact, muted }: { contact: SupplierContact; muted: boolean }) {
  return (
    <div style={{ display: 'grid', gap: '0.25rem', opacity: muted ? 0.65 : 1 }}>
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
  )
}
