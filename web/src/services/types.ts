/**
 * Shapes the Purchases API returns.
 *
 * Note what is NOT here: no Item, Supplier, GRN or Invoice type with a full
 * body. Where one of those appears it is an id or a uuid, and the display
 * fields beside it come from a live call to the product that owns it.
 */

export interface RequisitionLine {
  line_id: number
  line_no: number
  item_id: number | null
  unit_id: number | null
  warehouse_id: number | null
  is_service: boolean
  description: string | null
  required_qty: string
  estimated_rate: string
  ordered_qty: string
  required_by: string | null
  notes: string | null
}

export interface ApprovalRequest {
  approval_id: number
  entity_type: string
  entity_id: number
  reason_kind: string
  reason_detail: string | null
  threshold_value: string | null
  actual_value: string | null
  status: string
  requested_by: string
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string
}

export interface Requisition {
  requisition_id: number
  requisition_uuid: string
  requisition_no: string
  requisition_date: string
  status: string
  requester_uuid: string
  department: string | null
  required_by: string | null
  priority: string
  justification: string | null
  exception_flags: string[] | string
  estimated_value: string
  approved_by: string | null
  created_at: string
  lines: RequisitionLine[]
  approvals: ApprovalRequest[]
}

export interface RfqLine {
  line_id: number
  line_no: number
  item_id: number | null
  unit_id: number | null
  is_service: boolean
  description: string | null
  required_qty: string
  required_by: string | null
  specification: string | null
}

export interface RfqInvitation {
  invitation_id: number
  supplier_account_id: number
  status: string
  invited_at: string
  responded_at: string | null
}

export interface Quote {
  quote_id: number
  rfq_id: number
  supplier_account_id: number
  quote_ref: string | null
  quote_date: string | null
  revision_no: number
  status: string
  currency_code: string
  payment_terms: string | null
  delivery_days: number | null
  warranty_terms: string | null
  freight_amount: string
  other_charges: string
  valid_until: string | null
  notes: string | null
}

export interface BidAward {
  award_id: number
  rfq_line_id: number | null
  quote_id: number
  awarded_qty: string
  awarded_rate: string
  rationale: string | null
  decided_by: string
  decided_at: string
}

export interface Rfq {
  rfq_id: number
  rfq_uuid: string
  rfq_no: string
  rfq_date: string
  title: string | null
  status: string
  response_deadline: string | null
  required_by: string | null
  currency_code: string
  created_by: string
  lines: RfqLine[]
  invitations: RfqInvitation[]
  quotes: Quote[]
  awards: BidAward[]
}

export interface ComparisonColumn {
  quote_id: number
  supplier_account_id: number
  revision_no: number
  currency_code: string
  payment_terms: string | null
  delivery_days: number | null
  warranty_terms: string | null
  valid_until: string | null
  line_total: number
  freight_amount: number
  other_charges: number
  /** Lines plus freight and other charges — the number that should decide it. */
  estimated_landed_total: number
  lines: Array<{
    rfq_line_id: number | null
    item_id: number | null
    quoted_qty: number
    quoted_rate: number
    discount_pc: number
    line_amount: number
    moq: number | null
    lead_days: number | null
  }>
}

export interface Comparison {
  rfq: Rfq
  quotes: ComparisonColumn[]
  best_by_line: Record<string, { quote_id: number; supplier_account_id: number; quoted_rate: number }>
  lowest_landed: number | null
  note: string
}

export interface PurchaseOrderLine {
  line_id: number
  line_no: number
  item_id: number | null
  unit_id: number | null
  warehouse_id: number | null
  is_service: boolean
  description: string | null
  ordered_qty: string
  received_qty: string
  rejected_qty: string
  billed_qty: string
  returned_qty: string
  agreed_rate: string
  discount_pc: string
  estimated_tax_pc: string
  line_amount: string
  promised_date: string | null
  hsn_sac: string | null
}

/** A cross-service command and where it got to. Replaces a reconciliation job. */
export interface IntegrationCommand {
  command_id: number
  target_service: string
  command_type: string
  status: 'PENDING' | 'POSTING' | 'COMPLETED' | 'FAILED' | 'BLOCKED'
  attempts: number
  last_error: string | null
  external_reference: Record<string, unknown> | string | null
  last_attempt_at: string | null
  completed_at: string | null
}

export interface ReceiptRequest {
  request_id: number
  status: string
  received_at: string | null
  supplier_dc_no: string | null
  vehicle_no: string | null
  inventory_document_uuid: string | null
  inventory_document_no: string | null
  last_error: string | null
  created_at: string
}

export interface BillRequestSummary {
  request_id: number
  supplier_invoice_no: string | null
  supplier_invoice_date: string | null
  status: string
  books_voucher_id: number | null
  books_voucher_no: string | null
  last_error: string | null
  created_at: string
}

export interface PurchaseOrder {
  po_id: number
  po_uuid: string
  po_no: string
  po_date: string
  requisition_id: number | null
  rfq_id: number | null
  supplier_account_id: number
  supplier_name_snapshot: string | null
  status: string
  delivery_warehouse_id: number | null
  promised_date: string | null
  payment_terms: string | null
  incoterm: string | null
  currency_code: string
  subtotal_amount: string
  discount_amount: string
  freight_amount: string
  other_charges: string
  estimated_tax_amount: string
  total_amount: string
  notes: string | null
  created_by: string
  approved_by: string | null
  cancel_reason: string | null
  created_at: string
  lines: PurchaseOrderLine[]
  receipts: ReceiptRequest[]
  bills: BillRequestSummary[]
  commands: IntegrationCommand[]
  approvals: ApprovalRequest[]
}

export interface MatchException {
  exception_id: number
  match_id: number
  exception_kind: string
  detail: string | null
  po_value: string | null
  receipt_value: string | null
  bill_value: string | null
  variance_value: string | null
  status: string
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string
}

export interface MatchResult {
  match_id: number
  po_id: number | null
  bill_request_id: number | null
  verdict: 'MATCHED' | 'WITHIN_TOLERANCE' | 'REVIEW_REQUIRED' | 'BLOCKED'
  variances: unknown
  compared_references: unknown
  matched_at: string
  matched_by: string
  exceptions: MatchException[]
}

export interface BillRequest extends BillRequestSummary {
  po_id: number | null
  supplier_account_id: number
  requested_lines: unknown
  receipt_references: unknown
  matches: MatchResult[]
  commands: IntegrationCommand[]
  /** Present on the response to entering or re-matching a bill. */
  match?: {
    verdict: string
    variances: unknown[]
    exceptions: Array<{ exception_kind: string; detail?: string }>
    receipts_reachable: boolean
    match_id?: number
  }
}

export interface PurchaseReturn {
  return_id: number
  return_uuid: string
  return_no: string
  return_date: string
  po_id: number | null
  supplier_account_id: number
  status: string
  reason_code: string | null
  reason_note: string | null
  inventory_document_uuid: string | null
  books_debit_note_uuid: string | null
  lines: Array<{
    line_id: number
    line_no: number
    item_id: number | null
    return_qty: string
    rate: string
    line_amount: string
    reason_code: string | null
  }>
  commands: IntegrationCommand[]
}

export interface Claim {
  claim_id: number
  claim_no: string
  claim_date: string
  supplier_account_id: number
  po_id: number | null
  claim_kind: string
  status: string
  claimed_amount: string
  settled_amount: string
  description: string | null
  supplier_response: string | null
  settled_at: string | null
  created_at: string
}

export interface SupplierProfile {
  profile_id: number
  supplier_account_id: number
  qualification_status: string
  is_preferred: boolean
  approved_categories: unknown
  operational_lead_days: number | null
  payment_terms: string | null
  incoterm: string | null
  risk_flag: string | null
  notes: string | null
  approved_by: string | null
  approved_at: string | null
}

export interface Scorecard {
  supplier_account_id: number
  period_start: string
  period_end: string
  on_time_delivery_pc: number | null
  quality_rejection_pc: number | null
  po_acknowledgement_hours: number | null
  fulfilment_pc: number | null
  claim_count: number
  overall_score: number | null
  basis_summary: { po_count: number; ordered_value: number; receipt_count: number; note: string }
}

export interface DashboardSummary {
  period: { from: string; to: string }
  requisitions: { total: number; awaiting_approval: number; approved: number; estimated_value: number }
  rfqs: { total: number; awaiting_response: number; evaluating: number }
  orders: {
    total: number
    awaiting_approval: number
    awaiting_acknowledgement: number
    open: number
    overdue: number
    ordered_value: number
  }
  pipeline: { awaiting_receipt_value: number; awaiting_bill_value: number }
  attention: { match_exceptions: number; pending_approvals: number; stuck_commands: number; open_claims: number }
  top_suppliers: Array<{
    supplier_account_id: number
    supplier_name_snapshot: string | null
    po_count: string
    ordered_value: string
  }>
  /** Books, live. `available: false` means Books did not answer — not zero payables. */
  financial: {
    available: boolean
    reason: string | null
    payable_total?: number
    payable_overdue?: number
    payable_count?: number
  }
}

/** An item as Inventory describes it. Rendered, never stored. */
export interface CatalogItem {
  item_id: number
  item_name: string
  item_sku: string | null
  unit_id: number | null
  hsn_sac: string | null
  is_active: boolean
}

/** A supplier as Books describes it, with our procurement profile beside it. */
export interface CatalogSupplier {
  acc_id: number
  acc_name: string
  gstin?: string | null
  procurement_profile: SupplierProfile | null
}

export interface PurchaseSettings {
  cmp_id: number
  requisition_prefix: string
  rfq_prefix: string
  po_prefix: string
  return_prefix: string
  claim_prefix: string
  po_approval_above_amount: string
  requisition_approval_above_amount: string
  enforce_approved_vendors: boolean
  block_bill_on_match_failure: boolean
  default_warehouse_id: number | null
}

export interface MatchPolicy {
  policy_id: number
  policy_name: string
  qty_tolerance_pc: string
  rate_tolerance_pc: string
  value_tolerance_amt: string
  freight_tolerance_amt: string
  auto_match_below_amt: string
  is_default: boolean
  is_active: boolean
}

// ---------------------------------------------------------------------------
// Access administration
//
// The catalogue is the authority on what permissions exist and what each one is
// called; nothing in the UI may invent a key or rename one. `grantable` is the
// subset this caller may hand out, which is the whole catalogue for a company
// owner and only their own grants for anybody else.
// ---------------------------------------------------------------------------

export interface AccessCatalogue {
  /** Group title → permission key → human label, in the order to display them. */
  catalog: Record<string, Record<string, string>>
  granted: string[]
  grantable: string[]
  is_owner: boolean
  my_uuid: string
  owner_note: string
}

export interface AccessProfile {
  profile_id: number
  profile_name: string
  description: string | null
  permissions: string[]
  permission_count: number
  is_active: boolean
  system_key: string | null
  member_count: number
  updated_at: string | null
}

export interface AccessAssignment {
  assignment_id: number
  profile_id: number
  profile_name: string
  is_active: boolean
  note: string | null
  assigned_at: string
}

export interface AccessMember {
  user_uuid: string
  label: string | null
  is_you: boolean
  permission_count: number
  permissions: string[]
  assignments: AccessAssignment[]
}

export interface AccessCandidate {
  user_uuid: string
  actions: number
  last_seen: string
  is_you: boolean
}
