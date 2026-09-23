/**
 * What the returns API answers with.
 *
 * Note what is NOT here: no item, no warehouse, no supplier ledger and no
 * accounting figure. Where one of those appears it is an id, a reference or a
 * label the server snapshotted, and the body behind it comes from a live call
 * to the product that owns it.
 *
 * Money arrives as an exact decimal STRING and stays one until something draws
 * it. The formatted form travels beside it, produced once on the server, so the
 * card, the table and the export cannot disagree at the last paisa.
 */

export type ReturnStatus = 'DRAFT' | 'APPROVED' | 'DISPATCHED' | 'DEBITED' | 'CLOSED' | 'CANCELLED'

export type CreditStatus = 'PENDING' | 'RECEIVED' | 'NOT_REQUIRED'

/** What this product knows: whether it holds a reference. Not what the other product thinks. */
export type LinkStatus = 'PENDING' | 'POSTED' | 'NOT_REQUIRED'

export interface ReturnSource {
  type: 'PURCHASE_ORDER'
  id: number
  number: string | null
}

export interface PurchaseReturnRow {
  return_id: number
  return_uuid: string
  return_no: string
  return_date: string
  expected_pickup_date: string | null
  status: ReturnStatus
  status_label: string
  supplier_account_id: number
  supplier_name: string | null
  source: ReturnSource | null
  po_id: number | null
  item_count: number
  total_value: string
  total_value_formatted: string
  currency: string
  reason: { code: string; label: string } | null
  reason_note: string | null
  cancel_reason: string | null
  supplier_credit: {
    status: CreditStatus
    label: string
    reference: string | null
    date: string | null
    amount: string | null
  }
  inventory: { status: LinkStatus; reference: string | null }
  books: { status: LinkStatus; reference: string | null }
  created_by: string | null
  created_at: string | null
  updated_at: string | null
}

export interface PurchaseReturnLine {
  line_id: number
  line_no: number
  po_line_id: number | null
  item_id: number | null
  unit_id: number | null
  warehouse_id: number | null
  batch_id: number | null
  return_qty: string
  rate: string
  line_amount: string
  reason_code: string | null
  /**
   * The description on the purchase order line this was raised against.
   *
   * A field Purchases owns, joined at read time. The ITEM is Inventory's and
   * stays an id here; a screen that needs its name asks Inventory for it.
   */
  source_description?: string | null
  source_ordered_qty?: string | null
  source_received_qty?: string | null
  source_returned_qty?: string | null
  source_rate?: string | null
}

/** A return with its lines and the cross-app requests raised for it. */
export interface PurchaseReturnDetail extends PurchaseReturnRow {
  lines: PurchaseReturnLine[]
  commands: import('../../services/types').IntegrationCommand[]
  inventory_document_uuid: string | null
  books_debit_note_uuid: string | null
}

// ---------------------------------------------------------------------------
// Analytics
// ---------------------------------------------------------------------------

export interface ReturnTotals {
  returns: number
  return_value: string
  return_value_formatted: string
  return_value_compact: string
  credits_received: number
  credits_pending: number
  credits_pending_value: string
  credits_pending_formatted: string
  drafts: number
  inventory_pending: number
  books_pending: number
}

/**
 * Change against the window before.
 *
 * `direction: 'new'` with no percentage is a rise from zero, which has no
 * percentage — reporting one would be reporting infinity as a number.
 */
export interface ReturnDelta {
  direction: 'up' | 'down' | 'flat' | 'new'
  percent: string
  from: string
  to: string
}

export interface ReturnTrendPoint {
  month: string
  label: string
  short_label: string
  count: number
  value: string
  value_formatted: string
  value_compact: string
}

export interface ReturnReasonSlice {
  reason_code: string
  label: string
  count: number
  value: string
  value_formatted: string
  /** A share of the filtered set, 0–100, as a string. */
  percentage: string
}

export interface ReturnInsight {
  id: string
  severity: 'info' | 'positive' | 'warning' | 'critical'
  message: string
  /** Where the workspace should go when this insight is acted on. */
  filters: Record<string, string>
  action: { label: string; action_type: string } | null
  basis: string
}

export interface ReturnSummary {
  period: {
    from: string | null
    to: string | null
    comparable: boolean
    previous: { from: string; to: string } | null
  }
  currency: string
  totals: ReturnTotals
  previous: ReturnTotals | null
  deltas: {
    returns: ReturnDelta
    return_value: ReturnDelta
    credits_received: ReturnDelta
    credits_pending: ReturnDelta
  } | null
  trend: ReturnTrendPoint[]
  reasons: ReturnReasonSlice[]
  insights: ReturnInsight[]
}

export interface ReturnOption {
  value: string
  label: string
}

export interface ReturnOptions {
  statuses: ReturnOption[]
  credit_statuses: ReturnOption[]
  reasons: ReturnOption[]
  can: { create: boolean; approve: boolean; export: boolean }
}

// ---------------------------------------------------------------------------
// Live integration status
// ---------------------------------------------------------------------------

/**
 * What Inventory and Books say, asked at the moment somebody looks.
 *
 * `available: false` is NOT "nothing posted". It means the product could not be
 * reached, and a screen that draws the second when it means the first is lying
 * about the company's stock and its books.
 */
export interface IntegrationLeg<T> {
  status: 'NOT_SENT' | 'NOT_RAISED' | 'POSTED' | 'UNKNOWN'
  available: boolean
  reason: string | null
  reference: string | null
  document?: T | null
  voucher?: T | null
}

export interface ReturnIntegration {
  return_id: number
  inventory: IntegrationLeg<Record<string, unknown>>
  books: IntegrationLeg<Record<string, unknown>>
}

// ---------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------

export interface ImportRow {
  row: number
  ok: boolean
  errors: string[]
  cells: Record<string, string>
  return_date: string
  supplier_account_id: number | null
  supplier_name: string | null
  po_id: number | null
  po_no: string | null
  item_id: number | null
  warehouse_id: number | null
  return_qty: string
  rate: string
  reason_code: string | null
  notes: string | null
}

export interface ImportDraft {
  supplier_account_id: number
  supplier_name: string | null
  po_id: number | null
  po_no: string | null
  return_date: string
  reason_code: string | null
  reason_note: string | null
  lines: Array<{
    item_id: number | null
    warehouse_id: number | null
    return_qty: number
    rate: number
    reason_code: string | null
  }>
}

export interface ImportPreview {
  file: { name: string; kind: string; readable: boolean }
  mapping?: {
    header_row: number
    headers: string[]
    columns: Record<string, number | null>
    unmapped: string[]
  }
  notes: string[]
  summary: { rows: number; valid: number; errors: number; returns: number }
  rows: ImportRow[]
  returns: ImportDraft[]
  guidance: string
  retention?: string
}

export interface ImportResult {
  created: PurchaseReturnRow[]
  created_count: number
  failed: Array<{ index: number; message: string }>
}
