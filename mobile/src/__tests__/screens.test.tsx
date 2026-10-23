/**
 * Every screen in its states — loading, empty, failed, offline, and loaded — with the API,
 * the mockRouter and the providers replaced by test doubles.
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { Alert } from 'react-native';
import { Problem } from '../core/problems';
import { order } from '../testing/fixtures';

// ------------------------------------------------------------------ doubles

const mockRouter = { push: jest.fn(), replace: jest.fn(), back: jest.fn(), canGoBack: jest.fn(() => true) };
let mockParams: Record<string, string> = {};
jest.mock('expo-router', () => {
  const { Text } = jest.requireActual('react-native');
  return {
    useRouter: () => mockRouter,
    useLocalSearchParams: () => mockParams,
    Redirect: ({ href }: { href: string }) => <Text testID="redirect">{String(href)}</Text>,
    Stack: () => null,
  };
});

let mockOnline = true;
jest.mock('../net/NetworkProvider', () => ({ useOnline: () => mockOnline }));

type Seat = { kind: 'ready'; seat: { displayName: string | null; isOwner: boolean; permissions: string[] } } | { kind: 'loading' } | { kind: 'failed'; message: string; noAccess: boolean };
let mockSeat: Seat = { kind: 'ready', seat: { displayName: 'Asha Rao', isOwner: false, permissions: [] } };
const mockScopeValue = () => ({
  ready: true,
  scope: { cmpId: 88, companyName: 'Rao Traders', fyId: 6, yearLabel: 'FY 2026–27', boId: 0, branchName: 'All branches' },
  seat: mockSeat,
  restricted: false,
  choose: jest.fn(async () => undefined),
  clear: jest.fn(),
  reloadSeat: jest.fn(),
  can: (p: string) => mockSeat.kind === 'ready' && (mockSeat.seat.isOwner || mockSeat.seat.permissions.includes(p)),
});
jest.mock('../scope/ScopeProvider', () => ({ useScope: () => mockScopeValue() }));

const mockIdentity = {
  status: 'signedIn' as 'signedIn' | 'signedOut' | 'loading',
  person: { name: 'Asha Rao', email: 'asha@rao.in' },
  appleAvailable: false,
  signInWithPassword: jest.fn(),
  signInWithApple: jest.fn(),
  signInWithGoogle: jest.fn(),
  adoptToken: jest.fn(),
  signOut: jest.fn(async () => undefined),
  forgetLocally: jest.fn(async () => undefined),
};
jest.mock('../identity/IdentityProvider', () => ({ useIdentity: () => mockIdentity }));

jest.mock('../purchasing/api', () => ({
  RECEIVABLE: ['APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED'],
  purchasing: {
    approvals: jest.fn(),
    toReceive: jest.fn(),
    orders: jest.fn(),
    order: jest.fn(),
    requisition: jest.fn(),
    decide: jest.fn(),
    receive: jest.fn(),
    reconcileReceipt: jest.fn(),
    retryReceipt: jest.fn(),
    searchItems: jest.fn(),
    searchSuppliers: jest.fn(),
    supplierPosition: jest.fn(),
  },
}));
jest.mock('../scope/companies', () => ({
  ...jest.requireActual('../scope/companies'),
  fetchCompanies: jest.fn(),
  fetchCompanyDetail: jest.fn(),
  localToday: () => '2026-10-23',
}));
jest.mock('../identity/portal', () => ({ identity: { closureSummary: jest.fn(), closeAccount: jest.fn() } }));
jest.mock('../core/session', () => ({ readSignInToken: jest.fn(async () => 'tok') }));
jest.mock('expo-web-browser', () => ({ openBrowserAsync: jest.fn() }));
jest.mock('expo-camera', () => ({ CameraView: () => null, useCameraPermissions: () => [{ granted: true, canAskAgain: true }, jest.fn()] }));
jest.mock('expo-apple-authentication', () => ({ AppleAuthenticationButton: () => null, AppleAuthenticationButtonType: {}, AppleAuthenticationButtonStyle: {} }));
jest.mock('expo-constants', () => ({ expoConfig: { version: '1.0.0' } }));

import { purchasing } from '../purchasing/api';
import { fetchCompanies, fetchCompanyDetail } from '../scope/companies';
import { identity } from '../identity/portal';
import Desk from '../app/(work)/desk';
import ApprovalScreen from '../app/(work)/approval/[kind]/[id]';
import ReceiveScreen from '../app/(work)/receive/[poId]';
import Orders from '../app/(work)/orders';
import OrderScreen from '../app/(work)/order/[id]';
import Suppliers from '../app/(work)/suppliers';
import SupplierScreen from '../app/(work)/supplier/[id]';
import CompanyScreen from '../app/company';
import Account from '../app/account';
import DeleteAccount from '../app/delete-account';
import SignIn from '../app/sign-in';

const api = purchasing as unknown as Record<string, jest.Mock>;
const offline = () => new Problem('offline', 0, 'offline', "You're offline.");
const broken = () => new Problem('unavailable', 503, 'upstream_unavailable', 'Could not reach the app that owns this information. Please retry.', { retryable: true });
const never = () => new Promise(() => undefined);

function as(...granted: string[]) {
  mockSeat = { kind: 'ready', seat: { displayName: 'Asha Rao', isOwner: false, permissions: granted } };
}

beforeEach(() => {
  jest.clearAllMocks();
  mockOnline = true;
  mockParams = {};
  mockIdentity.status = 'signedIn';
  as('po.approve', 'requisition.approve', 'receipt.request', 'po.view', 'supplier.view', 'cost.view');
});

// ------------------------------------------------------------------ desk

describe('Desk', () => {
  const approval = { approval_id: 1, entity_type: 'purchase_order', entity_id: 41, reason_kind: 'value', reason_detail: 'Order value ₹1,18,000.00 is above the ₹50,000.00 approval threshold.', requested_by: 'u', created_at: '2026-10-21T09:00:00Z', requisition_no: null, requisition_value: null, po_no: 'PO/6/0041', supplier_name_snapshot: 'Rao Steel Traders', po_value: '118000.0000' };

  it('loading', () => {
    api.approvals.mockReturnValue(never());
    api.toReceive.mockReturnValue(never());
    render(<Desk />);
    expect(screen.getByTestId('state-loading')).toBeTruthy();
  });

  it('opens on the approvals queue for an approver, with the waiting documents', async () => {
    api.approvals.mockResolvedValue({ rows: [approval], total: 1 });
    api.toReceive.mockResolvedValue({ rows: [], total: 0 });
    render(<Desk />);
    expect(await screen.findByText('PO/6/0041')).toBeTruthy();
    expect(screen.getByText('₹1,18,000.00')).toBeTruthy();
    fireEvent.press(screen.getByLabelText('Purchase order PO/6/0041, waiting for your decision'));
    expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/approval/[kind]/[id]', params: { kind: 'order', id: '41' } });
  });

  it('empty queues say what would appear there', async () => {
    api.approvals.mockResolvedValue({ rows: [], total: 0 });
    api.toReceive.mockResolvedValue({ rows: [], total: 0 });
    render(<Desk />);
    expect(await screen.findByText('Nothing waiting for you')).toBeTruthy();
    fireEvent.press(screen.getByRole('tab', { name: /To receive/ }));
    expect(await screen.findByText('No deliveries expected')).toBeTruthy();
  });

  it('a receiver who does not approve opens on deliveries', async () => {
    as('receipt.request', 'po.view');
    api.toReceive.mockResolvedValue({ rows: [{ po_id: 41, po_no: 'PO/6/0041', po_date: '2026-10-01', supplier_account_id: 601, supplier_name_snapshot: 'Rao Steel Traders', status: 'ISSUED', promised_date: '2026-10-20', total_amount: '1', currency_code: 'INR', receipt_status: 'NOT_STARTED' }], total: 1 });
    render(<Desk />);
    expect(await screen.findByText(/3 days ago/)).toBeTruthy();
    expect(api.approvals).not.toHaveBeenCalled();
    fireEvent.press(screen.getByLabelText('Receive against PO/6/0041'));
    expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/receive/[poId]', params: { poId: '41' } });
  });

  it('failed, and offline', async () => {
    api.approvals.mockRejectedValueOnce(broken());
    api.toReceive.mockResolvedValue({ rows: [], total: 0 });
    const { unmount } = render(<Desk />);
    expect(await screen.findByTestId('state-error')).toBeTruthy();
    unmount();
    mockOnline = false;
    api.approvals.mockRejectedValueOnce(offline());
    render(<Desk />);
    expect(await screen.findByTestId('state-offline')).toBeTruthy();
    expect(screen.getByText(/You're offline. You can still count a delivery/)).toBeTruthy();
  });

  it('someone with neither job is told so, not shown an empty list', async () => {
    as('po.view');
    render(<Desk />);
    expect(await screen.findByText("You don't approve here")).toBeTruthy();
  });

  it('a person without Purchases access in this company is told, with a way to change company', async () => {
    mockSeat = { kind: 'failed', message: 'You have no Purchases access in this company yet.', noAccess: true };
    render(<Desk />);
    expect(await screen.findByText('You have no Purchases access in this company yet.')).toBeTruthy();
    fireEvent.press(screen.getByLabelText('Choose a company'));
    expect(mockRouter.push).toHaveBeenCalledWith('/company');
  });
});

// ------------------------------------------------------------------ approval

describe('Approval', () => {
  const pending = () => order({ status: 'APPROVAL_PENDING', approved_at: null, issued_at: null, approvals: [{ approval_id: 1, status: 'PENDING', reason_detail: 'Order value is above the approval threshold.', decided_by: null, decided_at: null, decision_note: null, created_at: '2026-10-21' }] });

  beforeEach(() => {
    mockParams = { kind: 'order', id: '41' };
  });

  it('loading, and failed', async () => {
    api.order.mockReturnValueOnce(never());
    const { unmount } = render(<ApprovalScreen />);
    expect(screen.getByTestId('state-loading')).toBeTruthy();
    unmount();
    api.order.mockRejectedValueOnce(new Problem('not_found', 404, 'not_found', 'That purchase order does not exist.'));
    render(<ApprovalScreen />);
    expect(await screen.findByText('That purchase order does not exist.')).toBeTruthy();
  });

  it('shows the lines and why it needs you; reject asks for a reason first', async () => {
    api.order.mockResolvedValue(pending());
    api.decide.mockResolvedValue(undefined);
    render(<ApprovalScreen />);
    expect(await screen.findByText('Order value is above the approval threshold.')).toBeTruthy();
    expect(screen.getByText('MS Angle 40x40')).toBeTruthy();
    fireEvent.press(screen.getByTestId('reject'));
    expect(screen.getByTestId('reason-confirm').props.accessibilityState.disabled).toBe(true);
    fireEvent.changeText(screen.getByTestId('reason-input'), 'Rate above contract');
    await act(async () => fireEvent.press(screen.getByTestId('reason-confirm')));
    expect(api.decide).toHaveBeenCalledWith('purchase_order', 41, 'reject', 'Rate above contract', undefined);
    expect(await screen.findByText('REJECTED')).toBeTruthy();
  });

  it('the owner deciding on their own order is asked why, and the same decision is sent again with it', async () => {
    api.order.mockResolvedValue(pending());
    api.decide.mockRejectedValueOnce(new Problem('forbidden', 403, 'sod_reason_required', 'You raised this purchase order.')).mockResolvedValueOnce(undefined);
    const alert = jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => buttons?.[1]?.onPress?.());
    render(<ApprovalScreen />);
    await screen.findByText('MS Angle 40x40');
    await act(async () => fireEvent.press(screen.getByTestId('approve')));
    expect(await screen.findByText('You raised this one')).toBeTruthy();
    fireEvent.changeText(screen.getByTestId('reason-input'), 'Buyer is on leave');
    await act(async () => fireEvent.press(screen.getByTestId('reason-confirm')));
    expect(api.decide).toHaveBeenLastCalledWith('purchase_order', 41, 'approve', 'Buyer is on leave', undefined);
    expect(await screen.findByText('APPROVED')).toBeTruthy();
    alert.mockRestore();
  });

  it('offline: readable, but the decision buttons are off', async () => {
    mockOnline = false;
    api.order.mockResolvedValue(pending());
    render(<ApprovalScreen />);
    await screen.findByText('MS Angle 40x40');
    expect(screen.getByTestId('approve').props.accessibilityState.disabled).toBe(true);
  });

  it('a plan-limit refusal shows only the plan-limit sentence', async () => {
    api.order.mockResolvedValue(pending());
    api.decide.mockRejectedValueOnce(new Problem('plan_limit', 402, 'PLAN_LIMIT_REACHED', "This company's plan limit is reached. Contact your company administrator."));
    const alert = jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => buttons?.[1]?.onPress?.());
    render(<ApprovalScreen />);
    await screen.findByText('MS Angle 40x40');
    await act(async () => fireEvent.press(screen.getByTestId('approve')));
    expect(await screen.findByText("This company's plan limit is reached. Contact your company administrator.")).toBeTruthy();
    alert.mockRestore();
  });

  it('a requisition is read and decided as a requisition', async () => {
    mockParams = { kind: 'requisition', id: '9' };
    api.requisition.mockResolvedValue({ requisition_id: 9, requisition_no: 'PR/6/0009', requisition_date: '2026-10-20', status: 'APPROVAL_PENDING', requester_uuid: 'u', department: 'Stores', required_by: '2026-11-01', priority: 'high', justification: 'Line 2 maintenance', estimated_value: '42000', lines: [{ line_id: 1, line_no: 1, item_id: 5, description: 'Bearings', required_qty: '12', estimated_rate: '3500', required_by: null }], approvals: [] });
    render(<ApprovalScreen />);
    expect((await screen.findAllByText('PR/6/0009')).length).toBeGreaterThan(0);
    expect(screen.getByText('Line 2 maintenance')).toBeTruthy();
  });
});

// ------------------------------------------------------------------ receiving

describe('Receive', () => {
  beforeEach(() => {
    mockParams = { poId: '41' };
  });

  it('loading, failed, and nothing to receive', async () => {
    api.order.mockReturnValueOnce(never());
    const a = render(<ReceiveScreen />);
    expect(screen.getByTestId('state-loading')).toBeTruthy();
    a.unmount();
    api.order.mockRejectedValueOnce(offline());
    const b = render(<ReceiveScreen />);
    expect(await screen.findByTestId('state-offline')).toBeTruthy();
    b.unmount();
    api.order.mockResolvedValueOnce(order({ status: 'APPROVAL_PENDING' }));
    render(<ReceiveScreen />);
    expect(await screen.findByText('Nothing to receive')).toBeTruthy();
  });

  it('counts by tap, reviews, and records once under the count\'s token', async () => {
    api.order.mockResolvedValue(order());
    api.receive.mockResolvedValue({ ...order(), receipt: { request_id: 8, receipt_no: 'GRN/6/0008', status: 'APPLIED', received_at: '2026-10-23', applied_at: '2026-10-23' } });
    render(<ReceiveScreen />);
    await screen.findByText('MS Angle 40x40');
    fireEvent.press(screen.getByLabelText('All 6 arrived'));
    fireEvent.press(screen.getByLabelText('One more Welding rods (box)'));
    expect(screen.getByText('7 accepted')).toBeTruthy();
    expect(screen.getByText('1 line short')).toBeTruthy();
    fireEvent.press(screen.getByTestId('review'));
    await act(async () => fireEvent.press(screen.getByTestId('record')));
    const [poId, body, token] = api.receive.mock.calls[0];
    expect(poId).toBe(41);
    expect(body.lines).toEqual([
      { line_id: 1, qty: 6 },
      { line_id: 2, qty: 1 },
    ]);
    expect(token).toMatch(/^grn-41-/);
    expect(await screen.findByText('Receipt GRN/6/0008 recorded')).toBeTruthy();
  });

  it('Inventory not answering leaves the receipt recorded here, with Check and Retry instead of a recount', async () => {
    api.order.mockResolvedValue(order());
    api.receive.mockRejectedValueOnce(new Problem('unavailable', 502, 'inventory_unavailable', 'Inventory did not record this receipt (timeout). Nothing has been received — press Retry.', { request_id: 8, retryable: true }));
    api.retryReceipt.mockResolvedValue({ ...order(), receipts: [{ request_id: 8, receipt_no: 'GRN/6/0008', status: 'APPLIED', received_at: '2026-10-23', applied_at: '2026-10-23' }] });
    render(<ReceiveScreen />);
    await screen.findByText('MS Angle 40x40');
    fireEvent.press(screen.getByLabelText('One more MS Angle 40x40'));
    fireEvent.press(screen.getByTestId('review'));
    await act(async () => fireEvent.press(screen.getByTestId('record')));
    expect(await screen.findByText('Saved, not yet in Inventory')).toBeTruthy();
    await act(async () => fireEvent.press(screen.getByLabelText('Retry')));
    expect(api.retryReceipt).toHaveBeenCalledWith(8);
    expect(await screen.findByText('Receipt GRN/6/0008 recorded')).toBeTruthy();
  });

  it('offline: counting works, recording is off', async () => {
    mockOnline = false;
    api.order.mockResolvedValue(order());
    render(<ReceiveScreen />);
    await screen.findByText('MS Angle 40x40');
    fireEvent.press(screen.getByLabelText('One more MS Angle 40x40'));
    fireEvent.press(screen.getByTestId('review'));
    expect(screen.getByTestId('record').props.accessibilityState.disabled).toBe(true);
    expect(api.receive).not.toHaveBeenCalled();
  });

  it('someone who does not receive goods is told so', async () => {
    as('po.view');
    api.order.mockResolvedValue(order());
    render(<ReceiveScreen />);
    expect(await screen.findByText("You don't record receipts here")).toBeTruthy();
  });
});

// ------------------------------------------------------------------ tracking

describe('Track orders and the order route', () => {
  const row = { po_id: 41, po_no: 'PO/6/0041', po_date: '2026-10-01', supplier_account_id: 601, supplier_name_snapshot: 'Rao Steel Traders', status: 'ISSUED', promised_date: '2026-10-20', total_amount: '118000', currency_code: 'INR', receipt_status: 'PARTIAL' };

  it('lists, filters and searches orders', async () => {
    api.orders.mockResolvedValue({ rows: [row], total: 1 });
    render(<Orders />);
    expect(await screen.findByText('PO/6/0041')).toBeTruthy();
    expect(screen.getByLabelText('Status: Overdue')).toBeTruthy();
    fireEvent.press(screen.getByRole('tab', { name: 'Awaiting approval' }));
    await waitFor(() => expect(api.orders).toHaveBeenLastCalledWith({ q: '', view: 'pending' }));
  });

  it('empty, failed, offline', async () => {
    api.orders.mockResolvedValueOnce({ rows: [], total: 0 });
    const a = render(<Orders />);
    expect(await screen.findByText('No orders here')).toBeTruthy();
    a.unmount();
    api.orders.mockRejectedValueOnce(broken());
    const b = render(<Orders />);
    expect(await screen.findByTestId('state-error')).toBeTruthy();
    b.unmount();
    api.orders.mockRejectedValueOnce(offline());
    render(<Orders />);
    expect(await screen.findByTestId('state-offline')).toBeTruthy();
  });

  it('the order shows its route, its lines and a stuck receipt\'s Check / Retry', async () => {
    mockParams = { id: '41' };
    api.order.mockResolvedValue(order({ receipts: [{ request_id: 9, receipt_no: 'GRN/6/0009', status: 'UNCERTAIN', received_at: '2026-10-22', applied_at: null, last_error: 'Inventory did not answer.', recoverable: true }] }));
    api.reconcileReceipt.mockResolvedValue(order({ receipts: [{ request_id: 9, receipt_no: 'GRN/6/0009', status: 'APPLIED', received_at: '2026-10-22', applied_at: '2026-10-22' }] }));
    render(<OrderScreen />);
    expect(await screen.findByText('Issued to supplier')).toBeTruthy();
    expect(screen.getByText('4 of 10 received · 0 billed')).toBeTruthy();
    await act(async () => fireEvent.press(screen.getByLabelText('Check')));
    expect(api.reconcileReceipt).toHaveBeenCalledWith(9);
    expect(await screen.findByText('Receipt GRN/6/0009 is now recorded in Inventory.')).toBeTruthy();
  });

  it('order: loading and failed', async () => {
    mockParams = { id: '41' };
    api.order.mockReturnValueOnce(never());
    const a = render(<OrderScreen />);
    expect(screen.getByTestId('state-loading')).toBeTruthy();
    a.unmount();
    api.order.mockRejectedValueOnce(broken());
    render(<OrderScreen />);
    expect(await screen.findByTestId('state-error')).toBeTruthy();
  });
});

// ------------------------------------------------------------------ suppliers

describe('Suppliers', () => {
  it('searches and opens a supplier', async () => {
    api.searchSuppliers.mockResolvedValue([{ acc_id: 601, acc_name: 'Rao Steel Traders', gstin: '27ABCDE1234F1Z5', procurement_profile: { qualification_status: 'approved' } }]);
    render(<Suppliers />);
    expect(await screen.findByText('Rao Steel Traders')).toBeTruthy();
    expect(screen.getByText('Approved')).toBeTruthy();
    fireEvent.press(screen.getByLabelText('Supplier Rao Steel Traders'));
    expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/supplier/[id]', params: { id: '601', name: 'Rao Steel Traders' } });
  });

  it('empty, failed, offline, and not allowed', async () => {
    api.searchSuppliers.mockResolvedValueOnce([]);
    const a = render(<Suppliers />);
    expect(await screen.findByText('No suppliers yet')).toBeTruthy();
    a.unmount();
    api.searchSuppliers.mockRejectedValueOnce(broken());
    const b = render(<Suppliers />);
    expect(await screen.findByTestId('state-error')).toBeTruthy();
    b.unmount();
    api.searchSuppliers.mockRejectedValueOnce(offline());
    const c = render(<Suppliers />);
    expect(await screen.findByTestId('state-offline')).toBeTruthy();
    c.unmount();
    as('po.view');
    api.searchSuppliers.mockResolvedValueOnce([]);
    render(<Suppliers />);
    expect(screen.getByText('Supplier details are not shown to you')).toBeTruthy();
  });

  it('a supplier: dues from the books, overdue, and open orders', async () => {
    mockParams = { id: '601' };
    api.supplierPosition.mockResolvedValue({
      supplier_account_id: 601,
      supplier_name: 'Rao Steel Traders',
      profile: { qualification_status: 'approved', is_preferred: true, operational_lead_days: 7, payment_terms: '30 days', risk_flag: null },
      open_orders: [{ po_id: 41, po_no: 'PO/6/0041', po_date: '2026-10-01', status: 'ISSUED', receipt_status: 'PARTIAL', promised_date: '2026-10-20', overdue: true, currency_code: 'INR', total_amount: '118000' }],
      open_order_count: 1,
      withheld: [],
      dues: { available: true, as_of: '2026-10-23', net_payable: '175000.75', overdue: '120000.5', bills: [{ bill_ref: 'INV/0001', bill_date: '2026-08-01', due_date: '2026-08-31', pending_amount: '120000.5', days_overdue: 53 }], bill_count: 1, credits_total: '0', basis: 'Open items from Smart Books today.' },
    });
    render(<SupplierScreen />);
    expect(await screen.findByText('₹1,75,000.75')).toBeTruthy();
    expect(screen.getByText('₹1,20,000.50 overdue')).toBeTruthy();
    expect(screen.getByText('53 days overdue')).toBeTruthy();
    fireEvent.press(screen.getByLabelText('Order PO/6/0041'));
    expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/order/[id]', params: { id: '41' } });
  });

  it('a supplier: Books unreachable and withheld values are said, not shown as zero', async () => {
    mockParams = { id: '601' };
    api.supplierPosition.mockResolvedValueOnce({ supplier_account_id: 601, supplier_name: 'Rao', profile: null, open_orders: null, open_order_count: null, withheld: ['po.view'], dues: { available: false, reason: 'Smart Books is unreachable.', retryable: true } });
    const a = render(<SupplierScreen />);
    expect(await screen.findByText(/Smart Books did not answer/)).toBeTruthy();
    expect(screen.getByText('Your profile does not show purchase orders.')).toBeTruthy();
    a.unmount();
    api.supplierPosition.mockRejectedValueOnce(offline());
    render(<SupplierScreen />);
    expect(await screen.findByTestId('state-offline')).toBeTruthy();
  });
});

// ------------------------------------------------------------------ company, account, sign-in

describe('Company, account and sign-in', () => {
  it('company: lists what can be opened, marks what cannot, and opens straight in when there is one year', async () => {
    (fetchCompanies as jest.Mock).mockResolvedValue([
      { cmpId: 88, name: 'Rao Traders', openable: true },
      { cmpId: 89, name: 'Desai Mills', openable: false },
    ]);
    (fetchCompanyDetail as jest.Mock).mockResolvedValue({ cmpId: 88, name: 'Rao Traders', years: [{ fyId: 6, start: '2026-04-01', end: '2027-03-31', label: 'FY 2026–27' }], branches: [], hoId: null });
    render(<CompanyScreen />);
    expect(await screen.findByText('No Purchases access — ask the company owner')).toBeTruthy();
    await act(async () => fireEvent.press(screen.getByLabelText('Open Rao Traders')));
    expect(mockRouter.replace).toHaveBeenCalledWith('/desk');
  });

  it('company: empty, failed and offline', async () => {
    (fetchCompanies as jest.Mock).mockResolvedValueOnce([]);
    const a = render(<CompanyScreen />);
    expect(await screen.findByText('No companies yet')).toBeTruthy();
    a.unmount();
    (fetchCompanies as jest.Mock).mockRejectedValueOnce(offline());
    const b = render(<CompanyScreen />);
    expect(await screen.findByTestId('state-offline')).toBeTruthy();
    b.unmount();
    (fetchCompanies as jest.Mock).mockRejectedValueOnce(broken());
    render(<CompanyScreen />);
    expect(await screen.findByTestId('state-error')).toBeTruthy();
  });

  it('account lists what stays on the web and reaches account closure', () => {
    render(<Account />);
    expect(screen.getByText('On the web')).toBeTruthy();
    expect(screen.getByText('Writing purchase orders')).toBeTruthy();
    fireEvent.press(screen.getByLabelText('Delete my AICOUNTLY account'));
    expect(mockRouter.push).toHaveBeenCalledWith('/delete-account');
  });

  it('closing the account needs the confirmation word and the company acknowledgement', async () => {
    (identity.closureSummary as jest.Mock).mockResolvedValue({ ok: true, summary: { name: 'Asha', email: 'a@x', ownedCompanies: ['Rao Traders'], sharedCount: 0, purgeAfterDays: 45, willDelete: ['Your sign-in'], willKeep: [] } });
    (identity.closeAccount as jest.Mock).mockResolvedValue({ ok: true, purgeAfter: '2026-12-07 00:00:00' });
    const alert = jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => buttons?.[buttons.length - 1]?.onPress?.());
    render(<DeleteAccount />);
    expect(await screen.findByText('You own a company')).toBeTruthy();
    fireEvent.changeText(screen.getByTestId('confirm-word'), 'close');
    expect(screen.getByTestId('close-account').props.accessibilityState.disabled).toBe(true);
    fireEvent.press(screen.getByRole('checkbox'));
    await act(async () => fireEvent.press(screen.getByTestId('close-account')));
    expect(identity.closeAccount).toHaveBeenCalledWith('tok', true);
    await waitFor(() => expect(mockIdentity.forgetLocally).toHaveBeenCalled());
    alert.mockRestore();
  });

  it('closing the account: an older service offers the web page', async () => {
    (identity.closureSummary as jest.Mock).mockResolvedValue({ ok: false, unsupported: true });
    (identity.closeAccount as jest.Mock).mockResolvedValue({ ok: false, useWeb: true });
    const alert = jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => buttons?.[buttons.length - 1]?.onPress?.());
    render(<DeleteAccount />);
    fireEvent.changeText(await screen.findByTestId('confirm-word'), 'CLOSE');
    await act(async () => fireEvent.press(screen.getByTestId('close-account')));
    expect(await screen.findByText('Open the account page')).toBeTruthy();
    alert.mockRestore();
  });

  it('sign-in: shows the service\'s answer, and offers to reopen a closed account', async () => {
    mockIdentity.status = 'signedOut';
    mockIdentity.signInWithPassword.mockResolvedValueOnce({ ok: false, message: 'Invalid email/username or password' });
    render(<SignIn />);
    fireEvent.changeText(screen.getByTestId('email'), 'asha@rao.in');
    fireEvent.changeText(screen.getByTestId('password'), 'x');
    await act(async () => fireEvent.press(screen.getByTestId('sign-in')));
    expect(await screen.findByText('Invalid email/username or password')).toBeTruthy();

    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    mockIdentity.signInWithPassword.mockResolvedValueOnce({ ok: false, closed: { message: 'This account was closed.' } });
    await act(async () => fireEvent.press(screen.getByTestId('sign-in')));
    expect(alert).toHaveBeenCalledWith('This account is closed', 'This account was closed.', expect.arrayContaining([expect.objectContaining({ text: 'Reopen my account' })]));
    alert.mockRestore();
  });

  it('sign-in: offline turns the buttons off and says why', () => {
    mockIdentity.status = 'signedOut';
    mockOnline = false;
    render(<SignIn />);
    expect(screen.getByText("You're offline. Signing in needs a connection.")).toBeTruthy();
    expect(screen.getByTestId('sign-in').props.accessibilityState.disabled).toBe(true);
  });
});
