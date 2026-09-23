/**
 * Who could quote for this.
 *
 * The list is Books' party ledgers, read through this product's own relay, with
 * the procurement profile THIS company recorded beside each one. Nothing is
 * copied here and nothing is stored: a supplier master inside Purchases would
 * be a second answer to who a supplier is, and it would be wrong from the first
 * time somebody corrected the name in Contacts.
 *
 * "Qualified" and "Preferred" mean what this company decided in its own
 * supplier profiles. There is no platform-wide verification behind them, so the
 * panel does not use the word "verified".
 */

import { useEffect, useState } from 'react'
import { Search, ShieldCheck, Star } from 'lucide-react'
import { api } from '../../../services/api'
import type { CatalogSupplier } from '../../../services/types'
import { SourcingDrawer } from './parts'

export function SupplierDiscovery({
  open,
  onClose,
  onFilterBySupplier,
}: {
  open: boolean
  onClose: () => void
  onFilterBySupplier: (supplier: CatalogSupplier) => void
}) {
  const [term, setTerm] = useState('')
  const [rows, setRows] = useState<CatalogSupplier[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return

    const controller = new AbortController()
    const timer = setTimeout(() => {
      setBusy(true)
      setError(null)
      api
        .list<CatalogSupplier>('v1/catalog/suppliers', { q: term.trim() || undefined, limit: 25 }, controller.signal)
        .then((response) => setRows(response.data))
        .catch((err: Error) => {
          if (!controller.signal.aborted) setError(err.message)
        })
        .finally(() => {
          if (!controller.signal.aborted) setBusy(false)
        })
    }, term === '' ? 0 : 300)

    return () => {
      clearTimeout(timer)
      controller.abort()
    }
  }, [open, term])

  return (
    <SourcingDrawer
      open={open}
      title="Find suppliers"
      subtitle="Read live from Books, with this company’s procurement profile beside each one."
      onClose={onClose}
    >
      <div className="sq-search sq-search--inline">
        <Search size={16} aria-hidden />
        <input
          type="search"
          value={term}
          onChange={(event) => setTerm(event.target.value)}
          placeholder="Search by name or GSTIN…"
          aria-label="Search suppliers"
        />
      </div>

      {error && (
        <div className="sq-drawer__error" role="alert">
          <strong>Could not reach the supplier list.</strong>
          {/* The upstream message verbatim: "Books is unreachable" and "Books
              refused this" need different things done about them, and a
              rewritten message hides which one happened. */}
          <span>{error}</span>
        </div>
      )}

      {busy && <p className="sq-drawer__note">Searching…</p>}

      {!busy && !error && rows.length === 0 && (
        <p className="sq-drawer__note">
          {term === ''
            ? 'No supplier ledgers came back for this company.'
            : 'No supplier matches that.'}
        </p>
      )}

      <ul className="sq-supplier-list">
        {rows.map((supplier) => {
          const profile = supplier.procurement_profile
          const qualification = profile?.qualification_status ?? null

          return (
            <li key={supplier.acc_id} className="sq-supplier">
              <div className="sq-supplier__who">
                <strong>{supplier.acc_name}</strong>
                <span>{supplier.gstin ?? 'No GSTIN on the ledger'}</span>
                <div className="sq-supplier__tags">
                  {profile?.is_preferred && (
                    <span className="sq-chip sq-chip--good">
                      <Star size={11} aria-hidden /> Preferred
                    </span>
                  )}
                  {qualification === 'approved' ? (
                    <span className="sq-chip sq-chip--good">
                      <ShieldCheck size={11} aria-hidden /> Approved
                    </span>
                  ) : qualification ? (
                    <span className="sq-chip">{qualification.replace(/_/g, ' ')}</span>
                  ) : (
                    <span className="sq-chip sq-chip--quiet">No procurement profile</span>
                  )}
                  {profile?.operational_lead_days !== null && profile?.operational_lead_days !== undefined && (
                    <span className="sq-chip sq-chip--quiet">{profile.operational_lead_days} day lead</span>
                  )}
                  {profile?.risk_flag && <span className="sq-chip sq-chip--risk">{profile.risk_flag}</span>}
                </div>
              </div>

              <button
                type="button"
                className="sq-button sq-button--tiny"
                onClick={() => onFilterBySupplier(supplier)}
              >
                Show their enquiries
              </button>
            </li>
          )
        })}
      </ul>

      <p className="sq-drawer__footnote">
        Inviting a supplier to a specific enquiry happens on that enquiry, where the invitation and its portal
        token are recorded.
      </p>
    </SourcingDrawer>
  )
}
