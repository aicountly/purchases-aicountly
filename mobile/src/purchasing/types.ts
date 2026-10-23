/**
 * The Purchases API's shapes, as far as the phone reads them. Money arrives as decimal strings
 * and stays a string until it is formatted (format.ts); nothing on the phone adds money up.
 */

export type EntityKind = 'purchase_order' | 'requisition';

export interface ApprovalRow {
  approval_id: number;
  entity_type: EntityKind | string;
  entity_id: number;
  reason_kind: string | null;
  reason_detail: string | null;
  requested_by: string | null;
  created_at: string;
  requisition_no: string | null;
  requisition_value: string | null;
  po_no: string | null;
  supplier_name_snapshot: string | null;
  po_value: string | null;
  /** The permission whose absence hid the values, when they were hidden. */
  values_withheld?: string | null;
}

export interface PoListRow {
  po_id: number;
  po_no: string;
  po_date: string;
  supplier_account_id: number;
  supplier_name_snapshot: string | null;
  status: string;
  promised_date: string | null;
  total_amount: string;
  currency_code: string;
  receipt_status?: string | null;
  billing_status?: string | null;
}

export interface PoLine {
  line_id: number;
  line_no: number;
  item_id: number | null;
  unit_id: number | null;
  is_service: boolean | string;
  description: string | null;
  ordered_qty: string;
  received_qty: string;
  rejected_qty: string;
  billed_qty: string;
  returned_qty: string;
  short_closed_qty: string;
  agreed_rate: string;
  discount_pc: string;
  line_amount: string;
  promised_date: string | null;
}

export interface ProgressLine {
  line_id: number;
  line_no: number;
  is_stock: boolean;
  wanted_qty: number;
  net_received_qty: number;
  net_billed_qty: number;
  to_receive_qty: number;
  to_bill_qty: number;
}

export interface Receipt {
  request_id: number;
  receipt_no: string;
  status: string;
  received_at: string | null;
  applied_at: string | null;
  inventory_document_no?: string | null;
  supplier_dc_no?: string | null;
  last_error?: string | null;
  recoverable?: boolean;
}

export interface ApprovalRecord {
  approval_id: number;
  status: string;
  reason_detail: string | null;
  decided_by: string | null;
  decided_at: string | null;
  decision_note: string | null;
  created_at: string;
}

export interface Amendment {
  amendment_id: number;
  status: string;
  reason: string;
  value_before: string;
  value_after: string;
}

export interface PurchaseOrder {
  po_id: number;
  po_no: string;
  po_date: string;
  supplier_account_id: number;
  supplier_name_snapshot: string | null;
  status: string;
  delivery_warehouse_id: number | null;
  promised_date: string | null;
  payment_terms: string | null;
  currency_code: string;
  subtotal_amount: string;
  estimated_tax_amount: string;
  total_amount: string;
  notes: string | null;
  created_by: string;
  approved_at: string | null;
  issued_at: string | null;
  sent_at?: string | null;
  acknowledged_at?: string | null;
  cancel_reason: string | null;
  closure_reason?: string | null;
  receipt_status: string;
  billing_status: string;
  lines: PoLine[];
  receipts: Receipt[];
  bills: { request_id: number; status: string; supplier_invoice_no?: string | null }[];
  approvals: ApprovalRecord[];
  progress: { receipt_status: string; billing_status: string; lines: ProgressLine[] };
  grn_stock_effect?: 'physical' | 'challan_only';
  amendment_pending_id?: number | null;
  amendments?: Amendment[];
  /** Present on the answer to a receipt. */
  receipt?: Receipt | null;
}

export interface RequisitionLine {
  line_id: number;
  line_no: number;
  item_id: number | null;
  description: string | null;
  required_qty: string;
  estimated_rate: string;
  required_by: string | null;
}

export interface Requisition {
  requisition_id: number;
  requisition_no: string;
  requisition_date: string;
  status: string;
  requester_uuid: string;
  department: string | null;
  required_by: string | null;
  priority: string;
  justification: string | null;
  estimated_value: string;
  lines: RequisitionLine[];
  approvals: ApprovalRecord[];
}

export interface CatalogItem {
  item_id: number;
  item_name: string;
  item_sku?: string | null;
  item_upc?: string | null;
  track_batch?: boolean | number | null;
  track_serial?: boolean | number | null;
  track_expiry?: boolean | number | null;
}

export interface SupplierHit {
  acc_id: number;
  acc_name: string;
  gstin?: string | null;
  procurement_profile: { qualification_status?: string; is_preferred?: boolean | string } | null;
}

export interface DueBill {
  bill_ref: string | null;
  bill_date: string | null;
  due_date: string | null;
  pending_amount: string;
  days_overdue: number | null;
}

export interface SupplierPosition {
  supplier_account_id: number;
  supplier_name: string | null;
  profile: {
    qualification_status: string;
    is_preferred: boolean;
    operational_lead_days: number | null;
    payment_terms: string | null;
    risk_flag: string | null;
  } | null;
  open_orders: {
    po_id: number;
    po_no: string;
    po_date: string;
    status: string;
    receipt_status: string;
    promised_date: string | null;
    overdue: boolean;
    currency_code: string;
    total_amount: string | null;
  }[] | null;
  open_order_count: number | null;
  withheld: string[];
  dues:
    | { available: true; as_of: string; net_payable: string; overdue: string; bills: DueBill[]; bill_count: number; credits_total: string; basis: string }
    | { available: false; reason: string; retryable?: boolean };
}
