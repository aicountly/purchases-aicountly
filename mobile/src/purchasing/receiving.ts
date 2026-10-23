import { toNumber } from './format';
import type { CatalogItem, PurchaseOrder } from './types';

/**
 * Counting a delivery at the gate, before it is recorded. Pure: the screen holds a draft, every
 * scan and tap returns a new one, and `payloadOf` turns it into the body of
 * POST v1/purchase-orders/{id}/receive.
 *
 *   counted    accepted into stock; counts against what the order still wants
 *   rejected   turned away at the gate (damaged, wrong, expired) with a reason; the supplier
 *              still owes it, so it does not reduce what is outstanding
 *   short      outstanding − counted: simply not delivered yet; the order stays open for it
 *
 * A serial-tracked line takes one serial per unit; a batch-tracked line takes a batch number
 * and, for an expiry-tracked item, its expiry date. Whether an item tracks either is learnt
 * from Inventory when its barcode is scanned; the server checks again either way.
 */

export interface DraftLine {
  lineId: number;
  lineNo: number;
  itemId: number;
  label: string;
  ordered: number;
  outstanding: number;
  counted: number;
  rejected: number;
  rejectionReason: string;
  batchNo: string;
  expiryDate: string;
  serials: string[];
  tracks: { batch: boolean; serial: boolean; expiry: boolean } | null;
}

export interface ReceiveDraft {
  poId: number;
  poNo: string;
  supplier: string;
  token: string;
  receivedAt: string;
  dcNo: string;
  dcDate: string;
  vehicleNo: string;
  note: string;
  lines: DraftLine[];
  /** Codes already resolved this session: barcode → item id (null = not an item we know). */
  codes: Record<string, number | null>;
  /** The line whose serial labels the scanner is collecting, if any. */
  serialLineId: number | null;
  updatedAt: number;
}

const truthy = (v: unknown) => v === true || v === 1 || v === '1' || v === 't';
const isService = (v: unknown) => truthy(v);
const round = (n: number) => Math.round(n * 10000) / 10000;

export function draftFrom(po: PurchaseOrder, token: string, today: string): ReceiveDraft {
  const progress = new Map((po.progress?.lines ?? []).map((l) => [l.line_id, l]));
  const lines: DraftLine[] = [];
  for (const line of po.lines ?? []) {
    const p = progress.get(line.line_id);
    if (isService(line.is_service) || line.item_id === null || (p && !p.is_stock)) continue;
    const outstanding = round(p ? p.to_receive_qty : toNumber(line.ordered_qty) - toNumber(line.received_qty) - toNumber(line.short_closed_qty));
    if (outstanding <= 0) continue;
    lines.push({
      lineId: line.line_id,
      lineNo: line.line_no,
      itemId: line.item_id,
      label: line.description?.trim() || `Item ${line.item_id}`,
      ordered: toNumber(line.ordered_qty),
      outstanding,
      counted: 0,
      rejected: 0,
      rejectionReason: '',
      batchNo: '',
      expiryDate: '',
      serials: [],
      tracks: null,
    });
  }
  return {
    poId: po.po_id,
    poNo: po.po_no,
    supplier: po.supplier_name_snapshot ?? '',
    token,
    receivedAt: today,
    dcNo: '',
    dcDate: '',
    vehicleNo: '',
    note: '',
    lines,
    codes: {},
    serialLineId: null,
    updatedAt: Date.now(),
  };
}

const touch = (d: ReceiveDraft, lines: DraftLine[], extra: Partial<ReceiveDraft> = {}): ReceiveDraft => ({ ...d, ...extra, lines, updatedAt: Date.now() });

function patchLine(d: ReceiveDraft, lineId: number, patch: (l: DraftLine) => DraftLine): ReceiveDraft {
  return touch(d, d.lines.map((l) => (l.lineId === lineId ? patch(l) : l)));
}

export function setCounted(d: ReceiveDraft, lineId: number, value: number): ReceiveDraft {
  return patchLine(d, lineId, (l) => ({ ...l, counted: Math.max(0, round(Number.isFinite(value) ? value : 0)) }));
}

export function setRejected(d: ReceiveDraft, lineId: number, value: number, reason?: string): ReceiveDraft {
  return patchLine(d, lineId, (l) => ({
    ...l,
    rejected: Math.max(0, round(Number.isFinite(value) ? value : 0)),
    rejectionReason: reason ?? l.rejectionReason,
  }));
}

export function setBatch(d: ReceiveDraft, lineId: number, batchNo: string, expiryDate: string): ReceiveDraft {
  return patchLine(d, lineId, (l) => ({ ...l, batchNo, expiryDate }));
}

export function removeSerial(d: ReceiveDraft, lineId: number, serial: string): ReceiveDraft {
  return patchLine(d, lineId, (l) => {
    const serials = l.serials.filter((s) => s !== serial);
    return { ...l, serials, counted: serials.length };
  });
}

export function collectSerialsFor(d: ReceiveDraft, lineId: number | null): ReceiveDraft {
  return { ...d, serialLineId: lineId };
}

export function setHeader(d: ReceiveDraft, patch: Partial<Pick<ReceiveDraft, 'dcNo' | 'dcDate' | 'vehicleNo' | 'note' | 'receivedAt'>>): ReceiveDraft {
  return touch(d, d.lines, patch);
}

export type ScanOutcome =
  | { kind: 'counted'; lineId: number; label: string; counted: number; outstanding: number; over: boolean }
  | { kind: 'serial'; lineId: number; label: string; serial: string; counted: number; outstanding: number }
  | { kind: 'serial-repeat'; lineId: number; serial: string }
  | { kind: 'not-on-order'; code: string; name?: string }
  | { kind: 'unknown'; code: string };

/** The item a scanned code names, from Inventory's search hits: exact UPC or SKU, never a prefix. */
export function itemForCode(code: string, hits: CatalogItem[]): CatalogItem | null {
  const needle = code.trim().toLowerCase();
  if (!needle) return null;
  return hits.find((h) => (h.item_upc ?? '').trim().toLowerCase() === needle || (h.item_sku ?? '').trim().toLowerCase() === needle) ?? null;
}

/**
 * Apply one scan. With a serial line chosen, the code is that line's next serial label;
 * otherwise it names an item (`item`, resolved by the caller, or null when nothing matched),
 * and one unit is counted on the order line that still wants it most.
 */
export function applyScan(d: ReceiveDraft, code: string, item: CatalogItem | null): { draft: ReceiveDraft; outcome: ScanOutcome } {
  const value = code.trim();
  const codes = { ...d.codes, [value]: item ? item.item_id : null };

  if (d.serialLineId !== null) {
    const line = d.lines.find((l) => l.lineId === d.serialLineId);
    if (line) {
      if (line.serials.includes(value)) return { draft: d, outcome: { kind: 'serial-repeat', lineId: line.lineId, serial: value } };
      // An item barcode scanned while collecting serials is the item, not a serial.
      if (!item || item.item_id !== line.itemId) {
        const serials = [...line.serials, value];
        const next = patchLine(d, line.lineId, (l) => ({ ...l, serials, counted: serials.length }));
        return { draft: next, outcome: { kind: 'serial', lineId: line.lineId, label: line.label, serial: value, counted: serials.length, outstanding: line.outstanding } };
      }
    }
  }

  if (!item) return { draft: { ...d, codes }, outcome: { kind: 'unknown', code: value } };
  const candidates = d.lines.filter((l) => l.itemId === item.item_id);
  if (candidates.length === 0) return { draft: { ...d, codes }, outcome: { kind: 'not-on-order', code: value, name: item.item_name } };
  const target = candidates.find((l) => l.counted + l.rejected < l.outstanding) ?? candidates[0];
  const tracks = {
    batch: truthy(item.track_batch) || truthy(item.track_expiry),
    serial: truthy(item.track_serial),
    expiry: truthy(item.track_expiry),
  };
  const counted = round(target.counted + 1);
  const next = touch(
    { ...d, codes },
    d.lines.map((l) => (l.lineId === target.lineId ? { ...l, counted: tracks.serial ? l.counted : counted, tracks } : l)),
    // A serial-tracked item: start collecting its serial labels rather than counting the barcode.
    tracks.serial ? { serialLineId: target.lineId } : {},
  );
  if (tracks.serial) {
    return { draft: next, outcome: { kind: 'counted', lineId: target.lineId, label: target.label, counted: target.counted, outstanding: target.outstanding, over: false } };
  }
  return { draft: next, outcome: { kind: 'counted', lineId: target.lineId, label: target.label, counted, outstanding: target.outstanding, over: counted > target.outstanding } };
}

export type LineVerdict = 'untouched' | 'complete' | 'short' | 'over';

export interface LineSummary {
  lineId: number;
  verdict: LineVerdict;
  /** Still owed after this delivery (outstanding − accepted). */
  stillOwed: number;
  rejected: number;
}

export function summarise(d: ReceiveDraft): { lines: LineSummary[]; accepted: number; rejected: number; shortLines: number; overLines: number } {
  const lines = d.lines.map<LineSummary>((l) => {
    const verdict: LineVerdict =
      l.counted === 0 && l.rejected === 0 ? 'untouched' : l.counted > l.outstanding ? 'over' : l.counted === l.outstanding ? 'complete' : 'short';
    return { lineId: l.lineId, verdict, stillOwed: round(Math.max(0, l.outstanding - l.counted)), rejected: l.rejected };
  });
  return {
    lines,
    accepted: round(d.lines.reduce((s, l) => s + l.counted, 0)),
    rejected: round(d.lines.reduce((s, l) => s + l.rejected, 0)),
    shortLines: lines.filter((l) => l.verdict === 'short').length,
    overLines: lines.filter((l) => l.verdict === 'over').length,
  };
}

export interface DraftProblem {
  lineId: number | null;
  message: string;
}

const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** What must be fixed before the delivery can be sent. Over-receipt is the server's call (tolerance). */
export function problemsIn(d: ReceiveDraft, today: string): DraftProblem[] {
  const out: DraftProblem[] = [];
  if (!d.lines.some((l) => l.counted > 0 || l.rejected > 0)) out.push({ lineId: null, message: 'Count at least one item, or record what was rejected.' });
  if (!DATE.test(d.receivedAt) || d.receivedAt > today) out.push({ lineId: null, message: 'The date received must be a real date, not after today.' });
  if (d.dcDate && !DATE.test(d.dcDate)) out.push({ lineId: null, message: 'Write the challan date as YYYY-MM-DD.' });
  for (const l of d.lines) {
    if (l.rejected > 0 && l.rejectionReason.trim().length < 3) out.push({ lineId: l.lineId, message: `Line ${l.lineNo}: say why ${l.rejected} was rejected.` });
    if (l.serials.length > 0 && l.serials.length !== l.counted) out.push({ lineId: l.lineId, message: `Line ${l.lineNo}: ${l.counted} counted but ${l.serials.length} serial numbers scanned.` });
    if (l.tracks?.serial && l.counted > 0 && l.serials.length === 0) out.push({ lineId: l.lineId, message: `Line ${l.lineNo}: scan one serial number per unit received.` });
    if (l.tracks?.batch && l.counted > 0 && !l.batchNo.trim()) out.push({ lineId: l.lineId, message: `Line ${l.lineNo}: give the batch number.` });
    if (l.tracks?.expiry && l.counted > 0 && !DATE.test(l.expiryDate)) out.push({ lineId: l.lineId, message: `Line ${l.lineNo}: give the batch expiry date (YYYY-MM-DD).` });
    if (l.expiryDate && !DATE.test(l.expiryDate)) out.push({ lineId: l.lineId, message: `Line ${l.lineNo}: write the expiry date as YYYY-MM-DD.` });
  }
  return out;
}

/** The receive request body (without the submission token, which the API layer adds). */
export function payloadOf(d: ReceiveDraft, extra: { overReason?: string; duplicateReason?: string } = {}): Record<string, unknown> {
  const lines = d.lines
    .filter((l) => l.counted > 0 || l.rejected > 0)
    .map((l) => ({
      line_id: l.lineId,
      qty: l.counted,
      ...(l.rejected > 0 ? { rejected_qty: l.rejected, rejection_reason: l.rejectionReason.trim() } : {}),
      ...(l.batchNo.trim() ? { batch_no: l.batchNo.trim() } : {}),
      ...(l.expiryDate.trim() ? { expiry_date: l.expiryDate.trim() } : {}),
      ...(l.serials.length > 0 ? { serials: l.serials } : {}),
    }));
  const text = (v: string) => (v.trim() ? v.trim() : undefined);
  const body: Record<string, unknown> = { lines, received_at: d.receivedAt };
  const header: Record<string, string | undefined> = {
    supplier_dc_no: text(d.dcNo),
    supplier_dc_date: text(d.dcDate),
    vehicle_no: text(d.vehicleNo),
    inspection_note: text(d.note),
    over_receipt_reason: extra.overReason ? text(extra.overReason) : undefined,
    duplicate_dc_reason: extra.duplicateReason ? text(extra.duplicateReason) : undefined,
  };
  for (const [k, v] of Object.entries(header)) if (v !== undefined) body[k] = v;
  if (extra.duplicateReason) body.confirm_duplicate_dc = true;
  return body;
}
