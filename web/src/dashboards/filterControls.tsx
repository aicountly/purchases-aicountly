/**
 * The command bar's three narrowing controls.
 *
 * All three read their options LIVE from the product that owns them — suppliers
 * from Books' party ledgers, material centres from Inventory, branches from
 * Manage — through this application's own read-through endpoints. None of them
 * is a list kept here, and none of them is prefetched on page load: a control
 * nobody has opened costs nothing.
 *
 * They replaced three numeric text boxes. Asking a buyer to type account id
 * 601 is asking them to know something the computer already knows.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, Loader2, Search, X } from 'lucide-react'
import { api } from '../services/api'
import { fetchCompanyInfo } from '../services/manage'
import { usePurchases } from '../context/PurchasesContext'
import type { CatalogSupplier } from '../services/types'

// ---------------------------------------------------------------------------
// Supplier — a type-ahead over Books' creditor ledgers
// ---------------------------------------------------------------------------

function useDebounced<T>(value: T, ms: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), ms)
    return () => clearTimeout(timer)
  }, [value, ms])
  return debounced
}

export function SupplierFilter({
  value,
  onChange,
}: {
  /** The account id in the URL, or null for every supplier. */
  value: string | null
  onChange: (supplierId: string | null, label: string | null) => void
}) {
  const [term, setTerm] = useState('')
  // The name is remembered WITH the id it names. Storing the name alone meant
  // that going Back from one supplier to another left the previous supplier's
  // name sitting over the new supplier's id.
  const [chosen, setChosen] = useState<{ id: string; name: string } | null>(null)
  const [options, setOptions] = useState<CatalogSupplier[]>([])
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)
  const debounced = useDebounced(term, 250)
  const boxRef = useRef<HTMLLabelElement>(null)

  // A link arriving with supplier_id=601 and no name in it: the control shows
  // the id rather than pretending the filter is not applied.
  useEffect(() => {
    setChosen((remembered) => (remembered !== null && remembered.id === value ? remembered : null))
  }, [value])

  useEffect(() => {
    // Two characters, debounced: the cost of asking here is a call to Books,
    // so it is not made per keystroke.
    if (debounced.trim().length < 2) {
      setOptions([])
      return
    }

    const controller = new AbortController()
    setBusy(true)
    setFailed(null)

    api
      .list<CatalogSupplier>('v1/catalog/suppliers', { q: debounced.trim(), limit: 12 }, controller.signal)
      .then((response) => setOptions(response.data))
      .catch(() => {
        if (!controller.signal.aborted) setFailed('Could not reach the app that owns this list.')
      })
      .finally(() => {
        if (!controller.signal.aborted) setBusy(false)
      })

    return () => controller.abort()
  }, [debounced])

  useEffect(() => {
    function onClickAway(event: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickAway)
    return () => document.removeEventListener('mousedown', onClickAway)
  }, [])

  const applied = value !== null && value !== ''
  const shown = chosen?.name ?? (applied ? `Account ${value}` : null)

  return (
    <label className="purchase-filterbar__picker" ref={boxRef}>
      <span>Supplier account</span>

      <span className="purchase-picker">
        <Search size={14} aria-hidden className="purchase-picker__icon" />
        <input
          type="search"
          value={term}
          placeholder={shown ?? 'All suppliers'}
          aria-label="Filter by supplier account"
          onChange={(event) => {
            setTerm(event.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
        />
        {applied && (
          <button
            type="button"
            className="purchase-picker__clear"
            onClick={() => {
              setChosen(null)
              setTerm('')
              onChange(null, null)
            }}
          >
            <X size={13} aria-hidden />
            <span className="purchase-sr-only">Clear the supplier filter</span>
          </button>
        )}
      </span>

      {open && term.trim().length >= 2 && (
        <div className="purchase-picker__menu" role="listbox">
          {busy && <p className="purchase-picker__note">Searching…</p>}
          {failed && <p className="purchase-picker__note is-danger">{failed}</p>}
          {!busy && !failed && options.length === 0 && <p className="purchase-picker__note">No matches.</p>}
          {options.map((option) => (
            <button
              key={option.acc_id}
              type="button"
              role="option"
              aria-selected={String(option.acc_id) === value}
              onClick={() => {
                setChosen({ id: String(option.acc_id), name: option.acc_name })
                setTerm('')
                setOpen(false)
                onChange(String(option.acc_id), option.acc_name)
              }}
            >
              {option.acc_name}
              {option.gstin && <span className="purchase-table__sub">{option.gstin}</span>}
            </button>
          ))}
        </div>
      )}
    </label>
  )
}

// ---------------------------------------------------------------------------
// Material centre — Inventory's warehouses
// ---------------------------------------------------------------------------

interface CentreOption {
  id: number
  name: string
}

/**
 * Inventory's warehouse list, normalised defensively.
 *
 * This is relayed straight from Inventory, and its row shape is Inventory's to
 * change. Reading several plausible key names costs nothing and means a rename
 * upstream degrades to "the list is empty", not to a crashed dashboard.
 */
function parseCentres(body: unknown): CentreOption[] {
  const payload = body as { data?: unknown }
  const raw = Array.isArray(payload?.data)
    ? payload.data
    : Array.isArray((payload?.data as { rows?: unknown })?.rows)
      ? ((payload.data as { rows: unknown[] }).rows)
      : []

  const out: CentreOption[] = []
  for (const row of raw) {
    if (typeof row !== 'object' || row === null) continue
    const record = row as Record<string, unknown>
    const id = Number(record.warehouse_id ?? record.wh_id ?? record.id)
    const name = record.warehouse_name ?? record.name ?? record.wh_name
    if (!Number.isFinite(id) || id <= 0) continue
    out.push({ id, name: typeof name === 'string' && name.trim() !== '' ? name.trim() : `Centre ${id}` })
  }

  return out
}

export function CentreFilter({
  value,
  onChange,
}: {
  value: string | null
  onChange: (warehouseId: string | null) => void
}) {
  const [centres, setCentres] = useState<CentreOption[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [loading, setLoading] = useState(false)

  // Fetched when the control is first opened or focused, not on page load: a
  // dashboard nobody has filtered should not be calling Inventory.
  const load = useCallback(() => {
    if (centres !== null || loading) return
    const controller = new AbortController()
    setLoading(true)
    // `get`, not `unscoped`: this endpoint is company-scoped, and an unscoped
    // call would arrive at the API without a company to read warehouses for.
    api
      .get<unknown>('v1/catalog/warehouses', undefined, controller.signal)
      .then((body) => setCentres(parseCentres(body)))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false))
  }, [centres, loading])

  if (failed) {
    // Inventory declined. The filter still works — by id — rather than
    // disappearing and taking the ability to narrow with it.
    return (
      <label>
        <span>Material centre</span>
        <input
          type="text"
          inputMode="numeric"
          defaultValue={value ?? ''}
          placeholder="Centre id"
          title="Inventory could not be reached for the list of centres, so this takes a centre id."
          onBlur={(event) => onChange(event.target.value === '' ? null : event.target.value)}
        />
      </label>
    )
  }

  return (
    <label>
      <span>Material centre</span>
      <span className="purchase-select">
        <select
          value={value ?? ''}
          onFocus={load}
          onMouseDown={load}
          onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
        >
          <option value="">All centres</option>
          {/* A chosen centre stays listed even before the list loads, so the
              select never shows "All centres" while a filter is applied. */}
          {centres === null && value !== null && <option value={value}>{`Centre ${value}`}</option>}
          {(centres ?? []).map((centre) => (
            <option key={centre.id} value={String(centre.id)}>
              {centre.name}
            </option>
          ))}
        </select>
        {loading ? <Loader2 size={13} aria-hidden className="purchase-select__icon" /> : <ChevronDown size={13} aria-hidden className="purchase-select__icon" />}
      </span>
    </label>
  )
}

// ---------------------------------------------------------------------------
// Branch — Manage's, and the application's actual scope
// ---------------------------------------------------------------------------

/**
 * The branch control changes the APP SCOPE, not a dashboard filter.
 *
 * `bo_id` travels on every request this product makes, and the server scopes
 * its own tables and its Books and Inventory reads by it. A second, dashboard-
 * only idea of "branch" would be a screen whose figures disagreed with the
 * list it drills into, so this writes the real one.
 */
export function BranchFilter() {
  const { scope, setCompanyScope } = usePurchases()
  const [branches, setBranches] = useState<{ boId: number; name: string }[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [loading, setLoading] = useState(false)

  const cmpId = scope?.cmp_id ?? null

  const load = useCallback(() => {
    if (cmpId === null || branches !== null || loading) return
    const controller = new AbortController()
    setLoading(true)
    fetchCompanyInfo(cmpId, controller.signal)
      .then((info) => setBranches(info.branches.map((branch) => ({ boId: branch.boId, name: branch.name }))))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false))
  }, [cmpId, branches, loading])

  const current = scope?.bo_id ?? 0
  const label = useMemo(() => {
    if (current === 0) return 'All branches'
    const match = branches?.find((branch) => branch.boId === current)
    return match?.name ?? `Branch ${current}`
  }, [branches, current])

  if (scope === null || failed) {
    return null
  }

  return (
    <label>
      <span>Branch</span>
      <span className="purchase-select">
        <select
          value={String(current)}
          aria-label="Branch"
          onFocus={load}
          onMouseDown={load}
          onChange={(event) => setCompanyScope({ ...scope, bo_id: Number(event.target.value) })}
        >
          <option value="0">All branches</option>
          {branches === null && current !== 0 && <option value={String(current)}>{label}</option>}
          {(branches ?? []).map((branch) => (
            <option key={branch.boId} value={String(branch.boId)}>
              {branch.name}
            </option>
          ))}
        </select>
        {loading ? <Loader2 size={13} aria-hidden className="purchase-select__icon" /> : <ChevronDown size={13} aria-hidden className="purchase-select__icon" />}
      </span>
    </label>
  )
}
