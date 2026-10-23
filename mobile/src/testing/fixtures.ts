import type { PurchaseOrder } from '../purchasing/types';

/** A purchase order as `GET v1/purchase-orders/{id}` answers it (PurchaseOrderService::find). */
export function order(overrides: Partial<PurchaseOrder> = {}): PurchaseOrder {
  return {
    po_id: 41,
    po_no: 'PO/6/0041',
    po_date: '2026-10-01',
    supplier_account_id: 601,
    supplier_name_snapshot: 'Rao Steel Traders',
    status: 'ISSUED',
    delivery_warehouse_id: 3,
    promised_date: '2026-10-20',
    payment_terms: '30 days',
    currency_code: 'INR',
    subtotal_amount: '100000.0000',
    estimated_tax_amount: '18000.0000',
    total_amount: '118000.0000',
    notes: null,
    created_by: 'user-buyer',
    approved_at: '2026-10-02T10:00:00Z',
    issued_at: '2026-10-02T11:00:00Z',
    acknowledged_at: null,
    cancel_reason: null,
    receipt_status: 'PARTIAL',
    billing_status: 'NOT_BILLED',
    lines: [
      { line_id: 1, line_no: 1, item_id: 501, unit_id: 1, is_service: false, description: 'MS Angle 40x40', ordered_qty: '10.0000', received_qty: '4.0000', rejected_qty: '0', billed_qty: '0', returned_qty: '0', short_closed_qty: '0', agreed_rate: '5000', discount_pc: '0', line_amount: '50000', promised_date: null },
      { line_id: 2, line_no: 2, item_id: 502, unit_id: 1, is_service: false, description: 'Welding rods (box)', ordered_qty: '20.0000', received_qty: '0', rejected_qty: '0', billed_qty: '0', returned_qty: '0', short_closed_qty: '0', agreed_rate: '2500', discount_pc: '0', line_amount: '50000', promised_date: null },
      { line_id: 3, line_no: 3, item_id: null, unit_id: null, is_service: true, description: 'Unloading', ordered_qty: '1', received_qty: '0', rejected_qty: '0', billed_qty: '0', returned_qty: '0', short_closed_qty: '0', agreed_rate: '0', discount_pc: '0', line_amount: '0', promised_date: null },
      { line_id: 4, line_no: 4, item_id: 503, unit_id: 1, is_service: false, description: 'Done line', ordered_qty: '5', received_qty: '5', rejected_qty: '0', billed_qty: '0', returned_qty: '0', short_closed_qty: '0', agreed_rate: '10', discount_pc: '0', line_amount: '50', promised_date: null },
    ],
    receipts: [{ request_id: 7, receipt_no: 'GRN/6/0007', status: 'APPLIED', received_at: '2026-10-10', applied_at: '2026-10-10T09:00:00Z' }],
    bills: [],
    approvals: [],
    progress: {
      receipt_status: 'PARTIAL',
      billing_status: 'NOT_BILLED',
      lines: [
        { line_id: 1, line_no: 1, is_stock: true, wanted_qty: 10, net_received_qty: 4, net_billed_qty: 0, to_receive_qty: 6, to_bill_qty: 4 },
        { line_id: 2, line_no: 2, is_stock: true, wanted_qty: 20, net_received_qty: 0, net_billed_qty: 0, to_receive_qty: 20, to_bill_qty: 0 },
        { line_id: 3, line_no: 3, is_stock: false, wanted_qty: 1, net_received_qty: 0, net_billed_qty: 0, to_receive_qty: 0, to_bill_qty: 1 },
        { line_id: 4, line_no: 4, is_stock: true, wanted_qty: 5, net_received_qty: 5, net_billed_qty: 0, to_receive_qty: 0, to_bill_qty: 5 },
      ],
    },
    grn_stock_effect: 'physical',
    ...overrides,
  };
}
