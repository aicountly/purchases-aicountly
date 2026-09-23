/**
 * Everything the list can be narrowed by that does not earn a place in the
 * toolbar — and nothing it cannot.
 *
 * Each control here maps to a condition the API actually applies. That is the
 * whole rule for this panel: a filter the backend cannot honour would return an
 * unfiltered list under a label that says it was filtered, which is worse than
 * having no filter at all.
 */

import { SupplierPicker } from '../../../components/LivePicker'
import type { CatalogSupplier } from '../../../services/types'
import type { RfqFilters } from '../useSourcing'
import { PAGE_SIZES } from '../useSourcing'
import { SourcingDrawer } from './parts'

export function AdvancedFilters({
  open,
  filters,
  supplierName,
  onClose,
  onChange,
  onClear,
  onSupplierPicked,
}: {
  open: boolean
  filters: RfqFilters
  supplierName: string | null
  onClose: () => void
  onChange: (patch: Partial<RfqFilters>) => void
  onClear: () => void
  onSupplierPicked: (supplier: CatalogSupplier | null) => void
}) {
  return (
    <SourcingDrawer
      open={open}
      title="Filter enquiries"
      subtitle="Every filter here is applied by the API, not in the browser."
      onClose={onClose}
      footer={
        <>
          <button type="button" className="sq-button sq-button--quiet" onClick={onClear}>
            Clear filters
          </button>
          <button type="button" className="sq-button sq-button--primary" onClick={onClose}>
            Show results
          </button>
        </>
      }
    >
      <div className="sq-form">
        <div className="sq-form__row">
          <div className="sq-form__field">
            <SupplierPicker
              selectedLabel={supplierName}
              onPick={(supplier) => onSupplierPicked(supplier)}
            />
            <p className="sq-form__hint">
              Shows the enquiries this supplier was invited to. Suppliers are read live from Books.
            </p>
            {filters.supplierId !== null && (
              <button type="button" className="sq-link-button" onClick={() => onSupplierPicked(null)}>
                Remove the supplier filter
              </button>
            )}
          </div>
        </div>

        <fieldset className="sq-form__group">
          <legend>Responses</legend>
          <label className="sq-form__label" htmlFor="sq-filter-quotes">
            Quotations received
          </label>
          <select
            id="sq-filter-quotes"
            value={filters.quotes}
            onChange={(event) => onChange({ quotes: event.target.value as RfqFilters['quotes'] })}
          >
            <option value="">Any</option>
            <option value="none">Nobody has quoted</option>
            <option value="any">At least one quotation</option>
            <option value="comparable">Two or more — comparable</option>
          </select>

          <label className="sq-form__label" htmlFor="sq-filter-deadline">
            Response deadline
          </label>
          <select
            id="sq-filter-deadline"
            value={filters.deadline}
            onChange={(event) => onChange({ deadline: event.target.value as RfqFilters['deadline'] })}
          >
            <option value="">Any deadline</option>
            <option value="overdue">Past its deadline, still open</option>
            <option value="due_soon">Closing within seven days</option>
          </select>
        </fieldset>

        <fieldset className="sq-form__group">
          <legend>Raised</legend>
          <div className="sq-form__row">
            <div className="sq-form__field">
              <label className="sq-form__label" htmlFor="sq-filter-from">
                On or after
              </label>
              <input
                id="sq-filter-from"
                type="date"
                value={filters.from}
                max={filters.to || undefined}
                onChange={(event) => onChange({ from: event.target.value })}
              />
            </div>
            <div className="sq-form__field">
              <label className="sq-form__label" htmlFor="sq-filter-to">
                On or before
              </label>
              <input
                id="sq-filter-to"
                type="date"
                value={filters.to}
                min={filters.from || undefined}
                onChange={(event) => onChange({ to: event.target.value })}
              />
            </div>
          </div>

          <label className="sq-form__check">
            <input
              type="checkbox"
              checked={filters.mine}
              onChange={(event) => onChange({ mine: event.target.checked })}
            />
            <span>Only enquiries I raised</span>
          </label>
        </fieldset>

        <fieldset className="sq-form__group">
          <legend>Page</legend>
          <label className="sq-form__label" htmlFor="sq-filter-size">
            Rows per page
          </label>
          <select
            id="sq-filter-size"
            value={filters.size}
            onChange={(event) => onChange({ size: Number.parseInt(event.target.value, 10) })}
          >
            {PAGE_SIZES.map((size) => (
              <option key={size} value={size}>
                {size}
              </option>
            ))}
          </select>
        </fieldset>
      </div>
    </SourcingDrawer>
  )
}
