import type { PurchaseOrder } from './types';

/**
 * What a purchase order's state means to someone tracking it, and the route it takes:
 * approved → issued → acknowledged → received → billed → closed. Pure.
 */

export type Tone = 'neutral' | 'waiting' | 'go' | 'stop' | 'late' | 'done';

const LABELS: Record<string, [string, Tone]> = {
  DRAFT: ['Draft', 'neutral'],
  APPROVAL_PENDING: ['Awaiting approval', 'waiting'],
  APPROVED: ['Approved', 'go'],
  ISSUED: ['Issued', 'go'],
  ACKNOWLEDGED: ['Acknowledged', 'go'],
  PARTIALLY_RECEIVED: ['Part received', 'waiting'],
  RECEIVED: ['Received', 'done'],
  CLOSED: ['Closed', 'done'],
  CANCELLED: ['Cancelled', 'stop'],
  // Requisitions share the approval states and add their own.
  SUBMITTED: ['Submitted', 'waiting'],
  REJECTED: ['Rejected', 'stop'],
  CONVERTED: ['Ordered', 'done'],
};

export function statusLabel(status: string): { label: string; tone: Tone } {
  const hit = LABELS[status];
  if (hit) return { label: hit[0], tone: hit[1] };
  const words = status.toLowerCase().replace(/_/g, ' ');
  return { label: words.charAt(0).toUpperCase() + words.slice(1), tone: 'neutral' };
}

export const RECEIPT_LABEL: Record<string, string> = {
  NOT_STARTED: 'Nothing received',
  PARTIAL: 'Part received',
  COMPLETE: 'Fully received',
};

export const BILLING_LABEL: Record<string, string> = {
  NOT_BILLED: 'Not billed',
  PARTIAL: 'Part billed',
  COMPLETE: 'Fully billed',
};

export interface Stage {
  key: 'approved' | 'issued' | 'acknowledged' | 'received' | 'billed' | 'closed';
  label: string;
  state: 'done' | 'current' | 'ahead' | 'skipped';
  when: string | null;
  note?: string;
}

/** The order's route, each stage done, current, still ahead, or skipped (cancelled). */
export function routeOf(po: Pick<PurchaseOrder, 'status' | 'approved_at' | 'issued_at' | 'acknowledged_at' | 'receipts' | 'progress' | 'receipt_status' | 'billing_status' | 'cancel_reason' | 'closure_reason'>): Stage[] {
  const receipt = po.progress?.receipt_status ?? po.receipt_status ?? 'NOT_STARTED';
  const billing = po.progress?.billing_status ?? po.billing_status ?? 'NOT_BILLED';
  const applied = (po.receipts ?? []).filter((r) => r.applied_at).map((r) => r.received_at ?? r.applied_at ?? null);
  const lastReceipt = applied.sort().slice(-1)[0] ?? null;
  const before = ['DRAFT', 'APPROVAL_PENDING'].includes(po.status);
  const cancelled = po.status === 'CANCELLED';

  const raw: Omit<Stage, 'state'>[] = [
    { key: 'approved', label: 'Approved', when: po.approved_at },
    { key: 'issued', label: 'Issued to supplier', when: po.issued_at },
    { key: 'acknowledged', label: 'Supplier acknowledged', when: po.acknowledged_at ?? null },
    { key: 'received', label: RECEIPT_LABEL[receipt] ?? 'Received', when: lastReceipt },
    { key: 'billed', label: BILLING_LABEL[billing] ?? 'Billed', when: null },
    { key: 'closed', label: 'Closed', when: null, note: po.closure_reason ?? undefined },
  ];
  const done: Record<Stage['key'], boolean> = {
    approved: !before && !!po.approved_at,
    issued: !!po.issued_at,
    acknowledged: !!po.acknowledged_at,
    received: receipt === 'COMPLETE',
    billed: billing === 'COMPLETE',
    closed: po.status === 'CLOSED',
  };
  let currentSet = false;
  return raw.map((s) => {
    if (done[s.key]) return { ...s, state: 'done' as const };
    if (cancelled) return { ...s, state: 'skipped' as const, note: s.key === 'closed' ? po.cancel_reason ?? 'Cancelled' : s.note };
    // A supplier who never acknowledged does not hold up receiving: that stage is optional.
    if (s.key === 'acknowledged' && (done.received || receipt === 'PARTIAL')) return { ...s, state: 'skipped' as const };
    if (!currentSet) {
      currentSet = true;
      const partial = (s.key === 'received' && receipt === 'PARTIAL') || (s.key === 'billed' && billing === 'PARTIAL');
      return { ...s, state: 'current' as const, when: partial ? s.when : null };
    }
    return { ...s, state: 'ahead' as const };
  });
}

/** True when a promised date has passed and goods are still owed. */
export function isOverdue(promised: string | null | undefined, status: string, today: string): boolean {
  if (!promised) return false;
  return promised.slice(0, 10) < today && ['ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'APPROVED'].includes(status);
}
