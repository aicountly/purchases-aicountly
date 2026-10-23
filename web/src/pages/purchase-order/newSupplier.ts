/**
 * The "New supplier" form in the purchase order editor (launch-readiness P1-P8), kept pure for
 * node:test: GST states, the state a GSTIN names, and what the form may send. The server checks all of
 * it again (SuppliersController::createLedger, PlaceOfSupply::normaliseGstin) — this only saves a trip.
 */

export const GST_STATES: ReadonlyArray<{ code: string; name: string }> = [
  ['01', 'Jammu and Kashmir'], ['02', 'Himachal Pradesh'], ['03', 'Punjab'], ['04', 'Chandigarh'], ['05', 'Uttarakhand'],
  ['06', 'Haryana'], ['07', 'Delhi'], ['08', 'Rajasthan'], ['09', 'Uttar Pradesh'], ['10', 'Bihar'], ['11', 'Sikkim'],
  ['12', 'Arunachal Pradesh'], ['13', 'Nagaland'], ['14', 'Manipur'], ['15', 'Mizoram'], ['16', 'Tripura'], ['17', 'Meghalaya'],
  ['18', 'Assam'], ['19', 'West Bengal'], ['20', 'Jharkhand'], ['21', 'Odisha'], ['22', 'Chhattisgarh'], ['23', 'Madhya Pradesh'],
  ['24', 'Gujarat'], ['26', 'Dadra and Nagar Haveli and Daman and Diu'], ['27', 'Maharashtra'], ['29', 'Karnataka'], ['30', 'Goa'],
  ['31', 'Lakshadweep'], ['32', 'Kerala'], ['33', 'Tamil Nadu'], ['34', 'Puducherry'], ['35', 'Andaman and Nicobar Islands'],
  ['36', 'Telangana'], ['37', 'Andhra Pradesh'], ['38', 'Ladakh'], ['97', 'Other Territory'],
].map(([code, name]) => ({ code, name }))

const GSTIN = /^(\d{2})[0-9A-Z]{13}$/

/** The two-digit state a GSTIN was issued in, or null when it is not shaped like one. */
export function stateOfGstin(value: string): string | null {
  const match = GSTIN.exec(value.trim().toUpperCase())
  if (!match) return null
  return GST_STATES.some((s) => s.code === match[1]) ? match[1] : null
}

export interface NewSupplierDraft {
  name: string
  gstin: string
  stateCode: string
  city: string
}

/** The body to send, or the first thing to fix. */
export function newSupplierBody(draft: NewSupplierDraft): { body: Record<string, string> } | { problem: string; field: keyof NewSupplierDraft } {
  const name = draft.name.trim().replace(/\s+/g, ' ')
  if (name === '') return { problem: 'Enter the supplier\'s name as it appears on their invoices.', field: 'name' }
  if (name.length > 150) return { problem: 'The name is at most 150 characters.', field: 'name' }
  const gstin = draft.gstin.trim().toUpperCase()
  const fromGstin = gstin === '' ? null : stateOfGstin(gstin)
  if (gstin !== '' && fromGstin === null) return { problem: 'That is not a GSTIN: 15 characters, starting with the two-digit state code.', field: 'gstin' }
  const state = draft.stateCode.trim() || fromGstin || ''
  if (fromGstin !== null && state !== fromGstin) return { problem: `The GSTIN is registered in state ${fromGstin}; choose that state or leave it blank.`, field: 'stateCode' }
  const body: Record<string, string> = { name }
  if (gstin !== '') body.gstin = gstin
  if (state !== '') body.state_code = state
  if (draft.city.trim() !== '') body.city = draft.city.trim()
  return { body }
}

/** One key per opened form: a retry after a lost answer is Books' replay of the same ledger. */
export function newIdempotencyKey(random: () => string = () => globalThis.crypto.randomUUID()): string {
  return `supplier-${random().replace(/[^A-Za-z0-9-]/g, '')}`.slice(0, 64)
}
