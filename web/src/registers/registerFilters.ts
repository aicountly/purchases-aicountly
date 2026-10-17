/**
 * Quick filters and advanced filters for the register-style lists — the client half of
 * RegisterFilter on the server, which owns what each key means and which a list can answer.
 *
 *   ?gst=rcm                  GST quick filters (bills)
 *   ?amount_min=&amount_max=  the amount the list shows for a row
 *   ?supplier_id=42           the supplier (the API calls it supplier_account_id; useUrlId)
 *
 * The keys are Books' Purchase-register keys, so a link means the same thing in both products.
 * Chips in one group are alternatives, groups narrow each other. Only the chips a list can
 * answer from Purchase's own rows are offered (see BILL_GST_KEYS); any other key in the URL is
 * still sent, and the server reports it back as unavailable so the screen can say so.
 *
 * Pure functions only — no React — so node --test can run them.
 */

export type GstKey =
  | 'local'
  | 'interstate'
  | 'overseas'
  | 'registered'
  | 'unregistered'
  | 'composition'
  | 'sez'
  | 'exempt'
  | 'rcm'

export interface QuickFilterOption {
  value: GstKey
  label: string
  title: string
}

export interface QuickFilterGroup {
  key: 'region' | 'party' | 'tax'
  label: string
  options: QuickFilterOption[]
}

/** Books' inward groups, in its order. What this product offers is a subset (quickFilterGroups). */
const ALL_GROUPS: QuickFilterGroup[] = [
  {
    key: 'region',
    label: 'Supply',
    options: [
      { value: 'local', label: 'Local', title: 'Place of supply in your own state (CGST + SGST)' },
      { value: 'interstate', label: 'Interstate', title: 'Place of supply in another state, or from an SEZ (IGST)' },
      { value: 'overseas', label: 'Imports', title: 'Bought from outside India — overseas supplier or place of supply 96 / 97' },
    ],
  },
  {
    key: 'party',
    label: 'Supplier',
    options: [
      { value: 'registered', label: 'Registered', title: 'Regular GST-registered supplier with a valid GSTIN' },
      { value: 'unregistered', label: 'Unregistered', title: 'Supplier with no GSTIN' },
      { value: 'composition', label: 'Composition', title: 'Purchases from composition dealers' },
      { value: 'sez', label: 'SEZ', title: 'Purchases from an SEZ unit or developer' },
    ],
  },
  {
    key: 'tax',
    label: 'Tax',
    options: [
      { value: 'exempt', label: 'Exempt / Nil / Non-GST', title: 'Nil-rated, exempt or non-GST supplies' },
      { value: 'rcm', label: 'Reverse charge', title: 'Bills marked reverse charge: the GST is payable by you, not the supplier' },
    ],
  },
]

/** Every key a URL may carry, in display order. */
export const GST_KEYS: GstKey[] = ALL_GROUPS.flatMap((group) => group.options.map((option) => option.value))

/**
 * The keys the bills list answers from its own rows — BillService::GST_PREDICATES. Reverse charge
 * is marked on the bill. The supplier's GST registration and the place of supply are read from
 * the supplier's Books ledger when a bill is posted and are not kept here, and a line's exempt /
 * nil nature is its Books tax category's: those chips are not offered rather than guessed.
 */
export const BILL_GST_KEYS: GstKey[] = ['rcm']

/** The groups a list offers, holding only its keys; a group left empty is not shown. */
export function quickFilterGroups(offered: readonly GstKey[]): QuickFilterGroup[] {
  return ALL_GROUPS.map((group) => ({ ...group, options: group.options.filter((option) => offered.includes(option.value)) })).filter(
    (group) => group.options.length > 0,
  )
}

export function gstLabel(key: string): string {
  for (const group of ALL_GROUPS) {
    const option = group.options.find((o) => o.value === key)
    if (option) return option.label
  }
  return key
}

/**
 * `?gst=` → the recognised keys, in display order, each once. Mirrors RegisterFilter::parseKeys(),
 * so a stale URL cannot light a chip the server ignores; a typo is dropped.
 */
export function parseGstKeys(raw: string | null | undefined, allowed: readonly GstKey[] = GST_KEYS): GstKey[] {
  const picked = new Set(
    String(raw ?? '')
      .toLowerCase()
      .split(',')
      .map((part) => part.trim())
      .filter(Boolean),
  )
  return allowed.filter((key) => picked.has(key))
}

export function toggleGstKey(keys: readonly GstKey[], key: GstKey): GstKey[] {
  const next = keys.includes(key) ? keys.filter((k) => k !== key) : [...keys, key]
  return GST_KEYS.filter((k) => next.includes(k))
}

/**
 * An amount bound as the URL carries it: digits with an optional decimal part, commas and spaces
 * ignored ("1,50,000" is fine). Anything else — blank, negative, text — is no bound.
 */
export function normalizeAmountBound(raw: string | null | undefined): string {
  const value = String(raw ?? '').replace(/[,\s]/g, '')
  return /^\d+(\.\d+)?$/.test(value) ? value : ''
}

/** Both bounds normalised, swapped when entered the wrong way round — a range, not an empty list. */
export function orderedAmountRange(rawMin: string | null | undefined, rawMax: string | null | undefined): [string, string] {
  const min = normalizeAmountBound(rawMin)
  const max = normalizeAmountBound(rawMax)
  return min !== '' && max !== '' && Number(min) > Number(max) ? [max, min] : [min, max]
}

/** A positive whole-number id, or ''. Same rule as useUrlId. */
export function normalizeSupplierId(raw: string | null | undefined): string {
  const value = String(raw ?? '').trim()
  return /^\d+$/.test(value) && Number(value) > 0 ? String(Number(value)) : ''
}

export interface RegisterFilterState {
  gst: GstKey[]
  amountMin: string
  amountMax: string
  supplierId: string
}

export function readRegisterFilters(params: URLSearchParams): RegisterFilterState {
  const [amountMin, amountMax] = orderedAmountRange(params.get('amount_min'), params.get('amount_max'))
  return {
    gst: parseGstKeys(params.get('gst')),
    amountMin,
    amountMax,
    supplierId: normalizeSupplierId(params.get('supplier_id')),
  }
}

/** The list endpoint's query for these filters; an unset filter is left out. */
export function registerQuery(state: RegisterFilterState): Record<string, string | undefined> {
  return {
    gst: state.gst.length > 0 ? state.gst.join(',') : undefined,
    amount_min: state.amountMin || undefined,
    amount_max: state.amountMax || undefined,
    supplier_account_id: state.supplierId || undefined,
  }
}

function setOrDelete(params: URLSearchParams, name: string, value: string): void {
  if (value === '') params.delete(name)
  else params.set(name, value)
}

/** The URL with these GST keys (every other parameter kept). */
export function withGst(params: URLSearchParams, keys: readonly GstKey[]): URLSearchParams {
  const next = new URLSearchParams(params)
  setOrDelete(next, 'gst', GST_KEYS.filter((k) => keys.includes(k)).join(','))
  return next
}

/** The URL with the advanced filters applied: normalised, swapped, empty ones removed. */
export function withAdvanced(
  params: URLSearchParams,
  advanced: { amountMin: string; amountMax: string; supplierId: string },
): URLSearchParams {
  const next = new URLSearchParams(params)
  const [min, max] = orderedAmountRange(advanced.amountMin, advanced.amountMax)
  setOrDelete(next, 'amount_min', min)
  setOrDelete(next, 'amount_max', max)
  setOrDelete(next, 'supplier_id', normalizeSupplierId(advanced.supplierId))
  return next
}

/** The badge on the Advanced button: the amount range counts once, the supplier once. */
export function advancedActiveCount(state: Pick<RegisterFilterState, 'amountMin' | 'amountMax' | 'supplierId'>): number {
  return (state.amountMin || state.amountMax ? 1 : 0) + (state.supplierId ? 1 : 0)
}

function formatBound(value: string): string {
  const n = Number(value)
  return Number.isFinite(n) ? n.toLocaleString('en-IN', { maximumFractionDigits: 2 }) : value
}

export function describeAmountRange(amountMin: string, amountMax: string): string {
  if (amountMin && amountMax) return `Amount ${formatBound(amountMin)} – ${formatBound(amountMax)}`
  if (amountMin) return `Amount ≥ ${formatBound(amountMin)}`
  if (amountMax) return `Amount ≤ ${formatBound(amountMax)}`
  return ''
}

/** What the server says it applied and could not, from the list's `meta.filters`. */
export function readFilterMeta(meta: unknown): { gst: string[]; gstUnavailable: string[] } {
  const filters = meta && typeof meta === 'object' ? (meta as Record<string, unknown>).filters : undefined
  const list = (value: unknown): string[] => (Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : [])
  if (!filters || typeof filters !== 'object') return { gst: [], gstUnavailable: [] }
  const f = filters as Record<string, unknown>
  return { gst: list(f.gst), gstUnavailable: list(f.gst_unavailable) }
}

/** The notice for GST keys the server could not apply, or '' when there are none. */
export function gstUnavailableMessage(keys: readonly string[]): string {
  if (keys.length === 0) return ''
  const names = keys.map(gstLabel).join(', ')
  return (
    `${names} cannot be filtered here, so ${keys.length === 1 ? 'it was' : 'they were'} not applied. ` +
    'The place of supply and the supplier’s GST registration are read from their Smart Books ledger when a bill is posted, ' +
    'and an exempt or nil rate is the Smart Books tax category’s — Purchase keeps no copy to filter on. ' +
    'Use the Purchase register in Smart Books for those.'
  )
}
