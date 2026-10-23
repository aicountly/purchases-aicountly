import { day, daysBetween, money, qty, relativeDay } from '../purchasing/format';
import {
  applyScan,
  collectSerialsFor,
  draftFrom,
  itemForCode,
  payloadOf,
  problemsIn,
  removeSerial,
  setBatch,
  setCounted,
  setRejected,
  summarise,
} from '../purchasing/receiving';
import { isOverdue, routeOf, statusLabel } from '../purchasing/status';
import { order } from '../testing/fixtures';

const TODAY = '2026-10-23';
const angle = { item_id: 501, item_name: 'MS Angle 40x40', item_upc: '8901234567890', item_sku: 'MSA-40' };
const rods = { item_id: 502, item_name: 'Welding rods', item_upc: '8900000000011', track_batch: 1, track_expiry: 1 };

describe('counting a delivery', () => {
  it('starts from the stock lines still expected; services and finished lines are left out', () => {
    const d = draftFrom(order(), 'tok', TODAY);
    expect(d.lines.map((l) => [l.lineId, l.outstanding])).toEqual([
      [1, 6],
      [2, 20],
    ]);
    expect(d.receivedAt).toBe(TODAY);
    expect(d.token).toBe('tok');
  });

  it('matches a scanned code exactly on UPC or SKU, never a prefix', () => {
    expect(itemForCode('8901234567890', [angle])).toBe(angle);
    expect(itemForCode('msa-40', [angle])).toBe(angle);
    expect(itemForCode('890123', [angle])).toBeNull();
  });

  it('each scan counts one unit on the line that still wants it, and says when it goes over', () => {
    let d = draftFrom(order(), 'tok', TODAY);
    for (let i = 0; i < 6; i += 1) d = applyScan(d, '8901234567890', angle).draft;
    expect(d.lines[0].counted).toBe(6);
    const over = applyScan(d, '8901234567890', angle);
    expect(over.outcome).toMatchObject({ kind: 'counted', counted: 7, over: true });
    expect(over.draft.codes['8901234567890']).toBe(501);
  });

  it('a code that is not an item, or an item not on this order, counts nothing', () => {
    const d = draftFrom(order(), 'tok', TODAY);
    expect(applyScan(d, '000', null).outcome).toEqual({ kind: 'unknown', code: '000' });
    const other = applyScan(d, '777', { item_id: 999, item_name: 'Paint' });
    expect(other.outcome).toEqual({ kind: 'not-on-order', code: '777', name: 'Paint' });
    expect(other.draft.lines.every((l) => l.counted === 0)).toBe(true);
  });

  it('learns batch and expiry tracking from the scanned item and asks for them', () => {
    let d = applyScan(draftFrom(order(), 'tok', TODAY), '8900000000011', rods).draft;
    expect(d.lines[1].tracks).toEqual({ batch: true, serial: false, expiry: true });
    expect(problemsIn(d, TODAY).map((p) => p.message)).toEqual(['Line 2: give the batch number.', 'Line 2: give the batch expiry date (YYYY-MM-DD).']);
    d = setBatch(d, 2, 'B-77', '2027-04-30');
    expect(problemsIn(d, TODAY)).toEqual([]);
    expect(payloadOf(d).lines).toEqual([{ line_id: 2, qty: 1, batch_no: 'B-77', expiry_date: '2027-04-30' }]);
  });

  it('a serial-tracked item collects one serial label per unit', () => {
    const serialItem = { item_id: 501, item_name: 'Angle', item_upc: '8901234567890', track_serial: true };
    let d = applyScan(draftFrom(order(), 'tok', TODAY), '8901234567890', serialItem).draft;
    expect(d.serialLineId).toBe(1);
    expect(d.lines[0].counted).toBe(0);
    d = applyScan(d, 'SN-1', null).draft;
    d = applyScan(d, 'SN-2', null).draft;
    expect(applyScan(d, 'SN-2', null).outcome.kind).toBe('serial-repeat');
    expect(d.lines[0]).toMatchObject({ counted: 2, serials: ['SN-1', 'SN-2'] });
    d = removeSerial(d, 1, 'SN-1');
    expect(d.lines[0]).toMatchObject({ counted: 1, serials: ['SN-2'] });
    d = collectSerialsFor(d, null);
    expect(payloadOf(d).lines).toEqual([{ line_id: 1, qty: 1, serials: ['SN-2'] }]);
  });

  it('rejected goods need a reason and do not reduce what is still owed', () => {
    let d = setCounted(draftFrom(order(), 'tok', TODAY), 1, 4);
    d = setRejected(d, 1, 2, '');
    expect(problemsIn(d, TODAY)[0].message).toMatch(/say why 2 was rejected/);
    d = setRejected(d, 1, 2, 'Bent in transit');
    const s = summarise(d);
    expect(s.lines[0]).toEqual({ lineId: 1, verdict: 'short', stillOwed: 2, rejected: 2 });
    expect(s).toMatchObject({ accepted: 4, rejected: 2, shortLines: 1, overLines: 0 });
    expect(payloadOf(d).lines).toEqual([{ line_id: 1, qty: 4, rejected_qty: 2, rejection_reason: 'Bent in transit' }]);
  });

  it('nothing counted, a future date or a malformed date blocks recording', () => {
    const d = draftFrom(order(), 'tok', TODAY);
    expect(problemsIn(d, TODAY).map((p) => p.message)).toContain('Count at least one item, or record what was rejected.');
    const future = { ...setCounted(d, 1, 1), receivedAt: '2026-10-24' };
    expect(problemsIn(future, TODAY).map((p) => p.message)).toContain('The date received must be a real date, not after today.');
    const badDc = { ...setCounted(d, 1, 1), dcDate: '23/10/2026' };
    expect(problemsIn(badDc, TODAY).map((p) => p.message)).toContain('Write the challan date as YYYY-MM-DD.');
  });

  it('the body carries only counted lines, the challan, and the reasons the server asked for', () => {
    let d = setCounted(draftFrom(order(), 'tok', TODAY), 2, 20);
    d = { ...d, dcNo: ' DC-88 ', vehicleNo: 'MH12AB1234', note: '' };
    expect(payloadOf(d)).toEqual({ lines: [{ line_id: 2, qty: 20 }], received_at: TODAY, supplier_dc_no: 'DC-88', vehicle_no: 'MH12AB1234' });
    expect(payloadOf(d, { overReason: 'Full carton', duplicateReason: 'Second truck, same challan' })).toMatchObject({
      over_receipt_reason: 'Full carton',
      duplicate_dc_reason: 'Second truck, same challan',
      confirm_duplicate_dc: true,
    });
  });

  it('negative and nonsense counts become zero', () => {
    const d = setCounted(setCounted(draftFrom(order(), 'tok', TODAY), 1, -3), 2, Number.NaN);
    expect(d.lines.map((l) => l.counted)).toEqual([0, 0]);
  });
});

describe('order status and route', () => {
  it('labels statuses, and says what it does not know in words', () => {
    expect(statusLabel('APPROVAL_PENDING')).toEqual({ label: 'Awaiting approval', tone: 'waiting' });
    expect(statusLabel('SOMETHING_NEW')).toEqual({ label: 'Something new', tone: 'neutral' });
  });

  it('a part-received order is approved, issued, and receiving now; acknowledgement was skipped', () => {
    const r = routeOf(order());
    expect(r.map((s) => [s.key, s.state])).toEqual([
      ['approved', 'done'],
      ['issued', 'done'],
      ['acknowledged', 'skipped'],
      ['received', 'current'],
      ['billed', 'ahead'],
      ['closed', 'ahead'],
    ]);
    expect(r[3].label).toBe('Part received');
  });

  it('an order awaiting approval has approval as its current stage', () => {
    const r = routeOf(order({ status: 'APPROVAL_PENDING', approved_at: null, issued_at: null, receipts: [], receipt_status: 'NOT_STARTED', progress: { receipt_status: 'NOT_STARTED', billing_status: 'NOT_BILLED', lines: [] } }));
    expect(r[0].state).toBe('current');
    expect(r.slice(1).every((s) => s.state === 'ahead')).toBe(true);
  });

  it('a cancelled order shows what never happened as skipped, with the reason', () => {
    const r = routeOf(order({ status: 'CANCELLED', issued_at: null, cancel_reason: 'Supplier withdrew', receipts: [], progress: { receipt_status: 'NOT_STARTED', billing_status: 'NOT_BILLED', lines: [] } }));
    expect(r[0].state).toBe('done');
    expect(r[5]).toMatchObject({ state: 'skipped', note: 'Supplier withdrew' });
  });

  it('overdue means a passed promise while goods are still owed', () => {
    expect(isOverdue('2026-10-20', 'ISSUED', TODAY)).toBe(true);
    expect(isOverdue('2026-10-20', 'RECEIVED', TODAY)).toBe(false);
    expect(isOverdue('2026-10-23', 'ISSUED', TODAY)).toBe(false);
    expect(isOverdue(null, 'ISSUED', TODAY)).toBe(false);
  });
});

describe('formatting', () => {
  it('money in Indian grouping from the API\'s decimal strings, rounding to paise', () => {
    expect(money('120000.5000')).toBe('₹1,20,000.50');
    expect(money('1234567.995')).toBe('₹12,34,568.00');
    expect(money('999.999')).toBe('₹1,000.00');
    expect(money('-50')).toBe('−₹50.00');
    expect(money('2500', 'USD')).toBe('$2,500.00');
    expect(money(null)).toBe('—');
    expect(money('abc')).toBe('—');
  });

  it('quantities and dates', () => {
    expect(qty('10.0000')).toBe('10');
    expect(qty('2.5')).toBe('2.5');
    expect(day('2026-10-23T10:00:00Z')).toBe('23 Oct 2026');
    expect(day(null)).toBe('—');
    expect(daysBetween('2026-10-20', '2026-10-23')).toBe(3);
    expect(relativeDay('2026-10-20', TODAY)).toBe('3 days ago');
    expect(relativeDay('2026-10-24', TODAY)).toBe('tomorrow');
  });
});
