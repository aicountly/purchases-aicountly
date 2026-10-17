/**
 * The filter bar above a register-style list: the "Advanced" button (amount range and supplier),
 * a line naming what is in force, the GST chips the list can answer, and — when the server could
 * not apply a GST key the URL asked for — a short notice saying so instead of a list that quietly
 * ignored it.
 */

import { useEffect, useRef, useState, type FormEvent } from 'react'
import { SlidersHorizontal, X } from 'lucide-react'
import { SupplierPicker } from '../components/LivePicker'
import { Button, Input, Notice } from '../ui'
import type { CatalogSupplier } from '../services/types'
import {
  type GstKey,
  type QuickFilterGroup,
  advancedActiveCount,
  describeAmountRange,
  gstUnavailableMessage,
  normalizeAmountBound,
  quickFilterGroups,
} from './registerFilters'
import type { useRegisterFilters } from './useRegisterFilters'
import './registers.css'

type RegisterFilters = ReturnType<typeof useRegisterFilters>

export function RegisterFilterBar({
  filters,
  gstKeys = [],
  withSupplier = false,
  supplierLabel,
  amountHint,
  gstUnavailable = [],
}: {
  filters: RegisterFilters
  /** The GST keys this list answers (BILL_GST_KEYS for bills); none shows no chips. */
  gstKeys?: readonly GstKey[]
  withSupplier?: boolean
  /** The selected supplier's name, when the list knows it. */
  supplierLabel?: string | null
  /** Which figure the amount range compares against, in the list's own words. */
  amountHint: string
  /** `meta.filters.gst_unavailable` from the list response. */
  gstUnavailable?: readonly string[]
}) {
  const groups = quickFilterGroups(gstKeys)
  const amount = describeAmountRange(filters.amountMin, filters.amountMax)
  const supplier = withSupplier && filters.supplierId ? `Supplier: ${supplierLabel || `Ledger #${filters.supplierId}`}` : ''
  const summary = [amount, supplier].filter(Boolean).join(' · ')
  const unavailable = gstUnavailableMessage(gstUnavailable)

  return (
    <>
      <div className="register-filter-bar">
        <RegisterAdvancedFilters
          amountMin={filters.amountMin}
          amountMax={filters.amountMax}
          supplierId={withSupplier ? filters.supplierId : ''}
          supplierLabel={supplierLabel}
          withSupplier={withSupplier}
          amountHint={amountHint}
          onApply={filters.applyAdvanced}
          onClear={filters.clearAdvanced}
        />
        {groups.length > 0 && (
          <RegisterQuickFilterChips groups={groups} selected={filters.gst} onToggle={filters.toggleGst} onClear={() => filters.clearGst(gstKeys)} />
        )}
        {summary && <span className="register-filter-summary">{summary}</span>}
      </div>
      {unavailable && (
        <div style={{ marginBottom: '0.85rem' }}>
          <Notice tone="warning" action={<Button onClick={() => filters.clearGst(gstUnavailable)}>Remove</Button>}>
            {unavailable}
          </Notice>
        </div>
      )}
    </>
  )
}

/** One row of toggle chips per group. Chips in a group are alternatives; groups narrow each other. */
export function RegisterQuickFilterChips({
  groups,
  selected,
  onToggle,
  onClear,
}: {
  groups: QuickFilterGroup[]
  selected: readonly GstKey[]
  onToggle: (key: GstKey) => void
  onClear: () => void
}) {
  const offered = groups.flatMap((group) => group.options.map((option) => option.value))
  const active = selected.filter((key) => offered.includes(key)).length

  return (
    <div className="register-chips" role="group" aria-label="GST quick filters">
      {groups.map((group) => (
        <div key={group.key} className="register-chip-group">
          <span className="register-chip-group-label">{group.label}</span>
          {group.options.map((option) => (
            <button
              key={option.value}
              type="button"
              className="register-chip"
              title={option.title}
              aria-pressed={selected.includes(option.value)}
              onClick={() => onToggle(option.value)}
            >
              {option.label}
            </button>
          ))}
        </div>
      ))}
      {active > 0 && (
        <button type="button" className="register-chip-clear" onClick={onClear} aria-label="Clear GST quick filters">
          <X size={11} aria-hidden style={{ verticalAlign: '-1px' }} /> Clear
        </button>
      )}
    </div>
  )
}

/**
 * The "Advanced" button and its panel. Edits are held in the panel until Apply, so typing
 * "50000" does not reload the list five times.
 */
export function RegisterAdvancedFilters({
  amountMin,
  amountMax,
  supplierId,
  supplierLabel,
  withSupplier,
  amountHint,
  onApply,
  onClear,
}: {
  amountMin: string
  amountMax: string
  supplierId: string
  supplierLabel?: string | null
  withSupplier: boolean
  amountHint: string
  onApply: (next: { amountMin: string; amountMax: string; supplierId: string }) => void
  onClear: () => void
}) {
  const [open, setOpen] = useState(false)
  const [draftMin, setDraftMin] = useState(amountMin)
  const [draftMax, setDraftMax] = useState(amountMax)
  const [draftSupplier, setDraftSupplier] = useState<{ id: string; label: string }>({ id: supplierId, label: supplierLabel ?? '' })
  const boxRef = useRef<HTMLDivElement>(null)

  const activeCount = advancedActiveCount({ amountMin, amountMax, supplierId })

  // Opening starts from what is applied, not from an abandoned draft. Only on opening: the list
  // loading underneath (and naming the supplier) must not wipe what is being typed.
  useEffect(() => {
    if (!open) return
    setDraftMin(amountMin)
    setDraftMax(amountMax)
    setDraftSupplier({ id: supplierId, label: supplierLabel ?? '' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  useEffect(() => {
    if (!open) return undefined
    const onDown = (event: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  // Digits, a decimal point and grouping commas only; the rest is checked on Apply.
  const clean = (raw: string) => raw.replace(/[^\d.,]/g, '')

  function apply(event: FormEvent) {
    event.preventDefault()
    onApply({
      amountMin: normalizeAmountBound(draftMin),
      amountMax: normalizeAmountBound(draftMax),
      supplierId: withSupplier ? draftSupplier.id : '',
    })
    setOpen(false)
  }

  return (
    <div ref={boxRef} className="register-advanced">
      <button
        type="button"
        className="register-advanced-button"
        data-active={activeCount > 0}
        aria-expanded={open}
        aria-haspopup="dialog"
        onClick={() => setOpen((value) => !value)}
        title={withSupplier ? 'Amount range and supplier' : 'Amount range'}
      >
        <SlidersHorizontal size={14} aria-hidden />
        Advanced
        {activeCount > 0 && <span className="register-advanced-badge">{activeCount}</span>}
      </button>

      {open && (
        <form className="register-advanced-panel" role="dialog" aria-label="Advanced filters" onSubmit={apply}>
          <div>
            <span style={{ display: 'block', fontSize: '0.8rem', color: 'var(--muted)', marginBottom: '0.25rem' }}>Amount</span>
            <div className="register-amount-row">
              <Input
                type="text"
                inputMode="decimal"
                placeholder="From"
                aria-label="Minimum amount"
                autoFocus
                value={draftMin}
                onChange={(e) => setDraftMin(clean(e.target.value))}
              />
              <span style={{ color: 'var(--muted)', fontSize: '0.8rem', flexShrink: 0 }}>to</span>
              <Input
                type="text"
                inputMode="decimal"
                placeholder="To"
                aria-label="Maximum amount"
                value={draftMax}
                onChange={(e) => setDraftMax(clean(e.target.value))}
              />
            </div>
            <span style={{ display: 'block', fontSize: '0.75rem', color: 'var(--muted)', marginTop: '0.25rem' }}>{amountHint}</span>
          </div>

          {withSupplier && (
            <div>
              <SupplierPicker
                selectedLabel={draftSupplier.id ? draftSupplier.label || `Ledger #${draftSupplier.id}` : 'Any supplier'}
                onPick={(supplier: CatalogSupplier) => setDraftSupplier({ id: String(supplier.acc_id), label: supplier.acc_name })}
              />
              {draftSupplier.id && (
                <button
                  type="button"
                  onClick={() => setDraftSupplier({ id: '', label: '' })}
                  style={{ marginTop: '0.3rem', background: 'none', border: 'none', padding: 0, color: 'var(--muted)', cursor: 'pointer', fontSize: '0.78rem' }}
                >
                  Remove supplier ({draftSupplier.label || `Ledger #${draftSupplier.id}`})
                </button>
              )}
            </div>
          )}

          <div className="register-advanced-actions">
            <Button
              tone="ghost"
              onClick={() => {
                onClear()
                setOpen(false)
              }}
            >
              Clear
            </Button>
            <Button tone="primary" type="submit">Apply</Button>
          </div>
        </form>
      )}
    </div>
  )
}
