/**
 * The supplier and material-centre filters.
 *
 * These were two numeric text boxes. Asking a buyer for a supplier's account id
 * is asking them to know a number the product never shows them, so in practice
 * both fields stayed empty and the screen could not be narrowed at all.
 *
 * WHAT IT DOES NOT DO. It does not hold a local copy of anyone's supplier list.
 * Suppliers are Books' party ledgers and centres are Inventory's warehouses;
 * both are asked for through this product's existing read-through endpoints, on
 * demand, and what is kept is the id — plus the label, in the URL, so a
 * reloaded page can say "Northern Distributors" rather than "Supplier 501".
 */

import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { Check, ChevronDown, Search, X } from 'lucide-react'
import { api } from '../services/api'
import type { CatalogSupplier } from '../services/types'

export interface ComboOption {
  id: string
  label: string
  hint?: string
}

function useDebounced<T>(value: T, ms: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), ms)
    return () => clearTimeout(timer)
  }, [value, ms])
  return debounced
}

export function FilterCombo({
  label,
  allLabel,
  value,
  valueLabel,
  load,
  onChange,
  minimumTerm = 0,
}: {
  label: string
  /** What the control says when nothing is chosen: "All suppliers". */
  allLabel: string
  value: string | null
  valueLabel: string | null
  /** Asks the owning product. Called debounced, and cancelled on every change. */
  load: (term: string, signal: AbortSignal) => Promise<ComboOption[]>
  onChange: (option: ComboOption | null) => void
  /** Above zero, nothing is asked for until the term is this long. */
  minimumTerm?: number
}) {
  const [open, setOpen] = useState(false)
  const [term, setTerm] = useState('')
  const [options, setOptions] = useState<ComboOption[]>([])
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)
  const debounced = useDebounced(term, 300)
  const boxRef = useRef<HTMLDivElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)
  const listId = useId()

  useEffect(() => {
    if (!open) return

    const trimmed = debounced.trim()
    if (trimmed.length < minimumTerm) {
      setOptions([])
      return
    }

    const controller = new AbortController()
    setBusy(true)
    setFailed(false)

    load(trimmed, controller.signal)
      .then((rows) => {
        if (!controller.signal.aborted) setOptions(rows)
      })
      .catch(() => {
        if (!controller.signal.aborted) setFailed(true)
      })
      .finally(() => {
        if (!controller.signal.aborted) setBusy(false)
      })

    return () => controller.abort()
  }, [open, debounced, minimumTerm, load])

  // Opening puts the cursor in the search box: the whole point of the control
  // is that you type a name.
  useEffect(() => {
    if (open) searchRef.current?.focus()
  }, [open])

  useEffect(() => {
    if (!open) return

    function onClickAway(event: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) setOpen(false)
    }
    function onEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onClickAway)
    document.addEventListener('keydown', onEscape)
    return () => {
      document.removeEventListener('mousedown', onClickAway)
      document.removeEventListener('keydown', onEscape)
    }
  }, [open])

  const choose = (option: ComboOption | null) => {
    onChange(option)
    setOpen(false)
    setTerm('')
  }

  return (
    <div className="purchase-field purchase-combo" ref={boxRef}>
      <span id={`${listId}-label`}>{label}</span>

      <button
        type="button"
        className="purchase-combo__button"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-labelledby={`${listId}-label`}
        onClick={() => setOpen((was) => !was)}
      >
        <span className={value === null ? 'purchase-combo__placeholder' : undefined}>
          {value === null ? allLabel : (valueLabel ?? `#${value}`)}
        </span>
        <ChevronDown size={14} aria-hidden />
      </button>

      {open && (
        <div className="purchase-combo__panel">
          <div className="purchase-combo__search">
            <Search size={14} aria-hidden />
            <input
              ref={searchRef}
              type="text"
              value={term}
              placeholder={minimumTerm > 0 ? `Type ${minimumTerm} characters to search…` : 'Search…'}
              onChange={(event) => setTerm(event.target.value)}
              aria-label={`Search ${label.toLowerCase()}`}
            />
            {term !== '' && (
              <button type="button" onClick={() => setTerm('')} aria-label="Clear the search">
                <X size={13} aria-hidden />
              </button>
            )}
          </div>

          <ul className="purchase-combo__list" role="listbox" id={listId} aria-labelledby={`${listId}-label`}>
            <li>
              <button
                type="button"
                role="option"
                aria-selected={value === null}
                className="purchase-combo__option"
                onClick={() => choose(null)}
              >
                <span>{allLabel}</span>
                {value === null && <Check size={13} aria-hidden />}
              </button>
            </li>

            {options.map((option) => (
              <li key={option.id}>
                <button
                  type="button"
                  role="option"
                  aria-selected={option.id === value}
                  className="purchase-combo__option"
                  onClick={() => choose(option)}
                >
                  <span>
                    {option.label}
                    {option.hint !== undefined && <small>{option.hint}</small>}
                  </span>
                  {option.id === value && <Check size={13} aria-hidden />}
                </button>
              </li>
            ))}

            {!busy && !failed && options.length === 0 && (
              <li className="purchase-combo__note">
                {debounced.trim().length < minimumTerm
                  ? `Type at least ${minimumTerm} characters.`
                  : 'Nothing matched that.'}
              </li>
            )}
            {busy && <li className="purchase-combo__note">Searching…</li>}
            {failed && <li className="purchase-combo__note">That list could not be loaded. Try again.</li>}
          </ul>
        </div>
      )}
    </div>
  )
}

/** Books' party ledgers, searched live. Two characters, because every keystroke is a call. */
export function useSupplierOptions() {
  return useMemo(
    () => async (term: string, signal: AbortSignal): Promise<ComboOption[]> => {
      const response = await api.list<CatalogSupplier>('v1/catalog/suppliers', { q: term }, signal)
      return response.data.map((supplier) => ({
        id: String(supplier.acc_id),
        label: supplier.acc_name,
        hint: supplier.procurement_profile?.qualification_status?.replace(/_/g, ' '),
      }))
    },
    [],
  )
}

/** Inventory's warehouses. A short list that arrives whole, so it is filtered here. */
export function useCentreOptions() {
  return useMemo(
    () => async (term: string, signal: AbortSignal): Promise<ComboOption[]> => {
      const response = await api.list<Record<string, unknown>>('v1/catalog/warehouses', undefined, signal)
      const needle = term.trim().toLowerCase()

      return response.data
        .map((row) => ({
          id: String(row.warehouse_id ?? row.id ?? ''),
          label: String(row.warehouse_name ?? row.name ?? row.label ?? `Centre ${row.warehouse_id ?? ''}`),
        }))
        .filter((option) => option.id !== '' && (needle === '' || option.label.toLowerCase().includes(needle)))
    },
    [],
  )
}
