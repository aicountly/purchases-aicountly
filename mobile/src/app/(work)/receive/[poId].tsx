import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Modal, Pressable, ScrollView, StyleSheet, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { submissionToken } from '../../../core/http';
import { isProblem, messageOf, type Problem } from '../../../core/problems';
import { useResource } from '../../../core/useResource';
import { useOnline } from '../../../net/NetworkProvider';
import { purchasing, RECEIVABLE } from '../../../purchasing/api';
import { dropDraft, loadDraft, saveDraft } from '../../../purchasing/drafts';
import { day, qty } from '../../../purchasing/format';
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
  setHeader,
  setRejected,
  summarise,
  type DraftLine,
  type ReceiveDraft,
} from '../../../purchasing/receiving';
import type { CatalogItem, PurchaseOrder } from '../../../purchasing/types';
import { localToday } from '../../../scope/companies';
import { useScope } from '../../../scope/ScopeProvider';
import { Icon } from '../../../ui/Icon';
import { Banner, Bar, Button, Docket, Field, KeyValue, ReasonSheet, Rule, StatePanel, T } from '../../../ui/kit';
import { ScannerSheet, type ScanFeedback } from '../../../ui/ScannerSheet';
import { font, palette, radius, space } from '../../../ui/theme';

type Outcome =
  | { kind: 'recorded'; receiptNo: string; po: PurchaseOrder }
  | { kind: 'pending'; requestId: number; message: string; uncertain: boolean }
  | { kind: 'refused'; message: string };

/**
 * Receiving goods against one order, at the gate: scan or tap to count, reject what is damaged
 * with a reason, note the challan, review, and record the goods receipt. Inventory records the
 * receipt as its own document; Purchases records it exactly once under this count's token.
 */
export default function ReceiveScreen() {
  const { poId: param } = useLocalSearchParams<{ poId: string }>();
  const poId = Number(param);
  const router = useRouter();
  const online = useOnline();
  const { scope, can } = useScope();
  const today = localToday();
  const order = useResource(() => purchasing.order(poId), [poId]);
  const [draft, setDraft] = useState<ReceiveDraft | null>(null);
  const [scanning, setScanning] = useState(false);
  const [feedback, setFeedback] = useState<ScanFeedback | null>(null);
  const [rejectLine, setRejectLine] = useState<DraftLine | null>(null);
  const [reviewing, setReviewing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ask, setAsk] = useState<null | 'over' | 'duplicate'>(null);
  const [extras, setExtras] = useState<{ overReason?: string; duplicateReason?: string }>({});
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const items = useRef(new Map<number, CatalogItem>());

  const po = order.data;
  const cmpId = scope?.cmpId ?? 0;

  // Start from a count saved on this phone for this order, else a fresh one.
  useEffect(() => {
    if (!po || draft) return;
    let alive = true;
    loadDraft(cmpId, po.po_id).then((saved) => {
      if (!alive) return;
      setDraft(saved ?? draftFrom(po, submissionToken(`grn-${po.po_id}`), today));
    });
    return () => {
      alive = false;
    };
  }, [po, draft, cmpId, today]);

  useEffect(() => {
    if (draft && !outcome) void saveDraft(cmpId, draft);
  }, [draft, cmpId, outcome]);

  const summary = useMemo(() => (draft ? summarise(draft) : null), [draft]);

  const onCode = async (code: string): Promise<ScanFeedback> => {
    if (!draft) return { tone: 'bad', text: 'Still loading the order.' };
    let item: CatalogItem | null = null;
    const known = draft.codes[code];
    if (known !== undefined) {
      item = known === null ? null : items.current.get(known) ?? { item_id: known, item_name: '' };
    } else if (draft.serialLineId === null) {
      try {
        item = itemForCode(code, await purchasing.searchItems(code));
        if (item) items.current.set(item.item_id, item);
      } catch (err) {
        const p = err as Problem;
        return { tone: 'bad', text: isProblem(p) && p.kind === 'offline' ? "Offline: can't look up a new barcode. Tap + on the line instead." : messageOf(err) };
      }
    }
    const { draft: next, outcome: o } = applyScan(draft, code, item);
    setDraft(next);
    const fb: ScanFeedback =
      o.kind === 'counted'
        ? next.serialLineId === o.lineId && next.lines.find((l) => l.lineId === o.lineId)?.tracks?.serial
          ? { tone: 'warn', text: `${o.label} tracks serial numbers — now scan each unit's serial label.` }
          : { tone: o.over ? 'warn' : 'good', text: `${o.label}: ${qty(o.counted)} of ${qty(o.outstanding)}${o.over ? ' — more than the order still wants' : ''}` }
        : o.kind === 'serial'
          ? { tone: 'good', text: `Serial ${o.serial} · ${o.label}: ${o.counted} of ${qty(o.outstanding)}` }
          : o.kind === 'serial-repeat'
            ? { tone: 'warn', text: `Serial ${o.serial} is already counted.` }
            : o.kind === 'not-on-order'
              ? { tone: 'bad', text: `${o.name || o.code} is not on ${draft.poNo}.` }
              : { tone: 'bad', text: `No item has barcode ${o.code}.` };
    setFeedback(fb);
    return fb;
  };

  const submit = async (more: { overReason?: string; duplicateReason?: string } = extras) => {
    if (!draft || !po) return;
    setBusy(true);
    setError(null);
    try {
      const result = await purchasing.receive(po.po_id, payloadOf(draft, more), draft.token);
      await dropDraft(cmpId, po.po_id);
      setReviewing(false);
      setAsk(null);
      setOutcome({ kind: 'recorded', receiptNo: result.receipt?.receipt_no ?? 'recorded', po: result });
    } catch (err) {
      const p = isProblem(err) ? err : null;
      const requestId = Number(p?.details.request_id ?? 0);
      if (p && p.kind === 'validation' && String(p.details.needs ?? '').includes('over_receipt_reason') && can('receipt.over_tolerance')) {
        setAsk('over');
      } else if (p && p.code === 'duplicate_supplier_dc') {
        setError(p.message);
        setAsk('duplicate');
      } else if (p && requestId > 0 && ['inventory_unavailable', 'inventory_uncertain', 'receipt_in_progress'].includes(p.code)) {
        // Recorded in Purchases, not yet in Inventory: from here it is Retry / Check, never a second count.
        await dropDraft(cmpId, po.po_id);
        setReviewing(false);
        setOutcome({ kind: 'pending', requestId, message: p.message, uncertain: p.code !== 'inventory_unavailable' });
      } else if (p && requestId > 0 && p.code === 'inventory_refused') {
        await dropDraft(cmpId, po.po_id);
        setReviewing(false);
        setOutcome({ kind: 'refused', message: p.message });
      } else if (p && p.status === 0) {
        setAsk(null);
        setError('No answer came back, so it is not certain whether this delivery was recorded. Press Record again: the same count can never be recorded twice.');
      } else {
        setAsk(null);
        setError(messageOf(err));
      }
    } finally {
      setBusy(false);
    }
  };

  const followUp = async (action: 'retry' | 'reconcile', requestId: number) => {
    setBusy(true);
    setError(null);
    try {
      const result = action === 'retry' ? await purchasing.retryReceipt(requestId) : await purchasing.reconcileReceipt(requestId);
      const receipt = result.receipts?.find((r) => r.request_id === requestId);
      if (receipt?.applied_at) setOutcome({ kind: 'recorded', receiptNo: receipt.receipt_no, po: result });
      else setError(receipt?.last_error ?? 'Inventory has not recorded it yet. Try again in a moment.');
    } catch (err) {
      setError(messageOf(err));
    } finally {
      setBusy(false);
    }
  };

  const discard = () =>
    Alert.alert('Discard this count?', 'The quantities counted on this phone for this order are cleared. Nothing has been recorded.', [
      { text: 'Keep counting', style: 'cancel' },
      {
        text: 'Discard',
        style: 'destructive',
        onPress: async () => {
          if (!po) return;
          await dropDraft(cmpId, po.po_id);
          setDraft(draftFrom(po, submissionToken(`grn-${po.po_id}`), today));
        },
      },
    ]);

  // ---------------------------------------------------------------- states

  if (outcome) return <OutcomeView outcome={outcome} busy={busy} error={error} onFollowUp={followUp} onDone={() => router.replace('/desk')} onOrder={() => router.replace({ pathname: '/order/[id]', params: { id: String(poId) } })} />;

  if (order.phase === 'loading' && !po) return <Shell><StatePanel kind="loading" text="Loading the order…" /></Shell>;
  if (order.phase === 'failed' && !po) return <Shell><StatePanel kind="error" problem={order.error} onRetry={order.reload} /></Shell>;
  if (!po || !draft) return <Shell><StatePanel kind="loading" /></Shell>;
  if (!can('receipt.request')) {
    return <Shell title={po.po_no}><StatePanel kind="empty" icon="crate" title="You don't record receipts here" text="Your profile in this company does not record goods receipts. Someone at the receiving desk can." /></Shell>;
  }
  if (!RECEIVABLE.includes(po.status) || draft.lines.length === 0) {
    return (
      <Shell title={po.po_no}>
        <StatePanel kind="empty" icon="check" title="Nothing to receive" text={po.status === 'APPROVAL_PENDING' || po.status === 'DRAFT' ? 'This order has not been approved yet.' : 'Every goods line on this order has been received or closed.'} action={{ label: 'See the order', onPress: () => router.replace({ pathname: '/order/[id]', params: { id: String(poId) } }) }} />
      </Shell>
    );
  }

  const problems = problemsIn(draft, today);
  const counting = draft.serialLineId !== null ? draft.lines.find((l) => l.lineId === draft.serialLineId) : null;

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title={`Receive ${po.po_no}`} subtitle={po.supplier_name_snapshot ?? undefined} right={<Pressable accessibilityRole="button" onPress={discard} hitSlop={10}><T v="label" color={palette.onInkMuted}>Reset</T></Pressable>} />
      <ScrollView contentContainerStyle={{ padding: space.l, paddingBottom: 180 }} keyboardShouldPersistTaps="handled">
        {!online ? <Banner tone="go" icon="offline" text="Offline. Keep counting — it is saved on this phone. Recording needs a connection." /> : null}
        {error ? <Banner tone="stop" icon="alert" text={error} /> : null}
        <Banner
          tone="neutral"
          icon="crate"
          text={po.grn_stock_effect === 'challan_only' ? 'This company records deliveries as challans: stock is added when the bill is posted.' : 'Recording this puts the accepted goods into stock now, at the order rate.'}
        />
        <Pressable accessibilityRole="button" onPress={() => { setFeedback(null); setDraft(collectSerialsFor(draft, null)); setScanning(true); }} style={st.scanHero}>
          <Icon name="scan" size={28} color={palette.white} />
          <View style={{ flex: 1 }}>
            <T v="heading" color={palette.white}>
              Scan items
            </T>
            <T v="small" color={palette.plumWash}>
              Each barcode counts one unit on its order line.
            </T>
          </View>
          <Icon name="next" color={palette.white} />
        </Pressable>

        {draft.lines.map((line) => (
          <LineCard
            key={line.lineId}
            line={line}
            verdict={summary?.lines.find((s) => s.lineId === line.lineId)?.verdict ?? 'untouched'}
            problem={problems.find((p) => p.lineId === line.lineId)?.message}
            onCount={(n) => setDraft(setCounted(draft, line.lineId, n))}
            onReject={() => setRejectLine(line)}
            onBatch={(b, e) => setDraft(setBatch(draft, line.lineId, b, e))}
            onSerials={() => { setFeedback(null); setDraft(collectSerialsFor(draft, line.lineId)); setScanning(true); }}
            onRemoveSerial={(s) => setDraft(removeSerial(draft, line.lineId, s))}
          />
        ))}

        <Docket>
          <T v="label" color={palette.textSoft} style={{ marginBottom: space.m }}>
            Delivery
          </T>
          <Field label="Supplier challan no." value={draft.dcNo} onChangeText={(v) => setDraft(setHeader(draft, { dcNo: v }))} placeholder="As printed on the delivery challan" autoCapitalize="characters" />
          <Field label="Challan date" value={draft.dcDate} onChangeText={(v) => setDraft(setHeader(draft, { dcDate: v }))} placeholder="YYYY-MM-DD" keyboardType="numbers-and-punctuation" />
          <Field label="Vehicle no." value={draft.vehicleNo} onChangeText={(v) => setDraft(setHeader(draft, { vehicleNo: v }))} placeholder="e.g. MH12AB1234" autoCapitalize="characters" />
          <Field label="Received on" value={draft.receivedAt} onChangeText={(v) => setDraft(setHeader(draft, { receivedAt: v }))} hint="Not after today, and within the financial year you are in." keyboardType="numbers-and-punctuation" />
          <Field label="Inspection note" value={draft.note} onChangeText={(v) => setDraft(setHeader(draft, { note: v }))} placeholder="Seal intact, cartons dry…" multiline />
          <T v="small" color={palette.textMuted}>
            Photos are not attached from the phone: Purchases keeps no attachments yet. Describe damage in the rejection reason and here.
          </T>
        </Docket>
      </ScrollView>

      <SafeAreaView edges={['bottom']} style={st.footer}>
        <View style={{ flex: 1 }}>
          <T v="bodyStrong">
            {qty(summary?.accepted ?? 0)} accepted{summary && summary.rejected > 0 ? ` · ${qty(summary.rejected)} rejected` : ''}
          </T>
          <T v="small" color={summary && summary.shortLines > 0 ? palette.late : palette.textSoft}>
            {summary && summary.shortLines > 0 ? `${summary.shortLines} ${summary.shortLines === 1 ? 'line' : 'lines'} short` : 'Nothing short'}
            {summary && summary.overLines > 0 ? ` · ${summary.overLines} over` : ''}
          </T>
        </View>
        <Button label="Review" icon="check" disabled={!summary || (summary.accepted === 0 && summary.rejected === 0)} onPress={() => setReviewing(true)} testID="review" />
      </SafeAreaView>

      <ScannerSheet
        visible={scanning}
        title={counting ? `Serials · ${counting.label}` : `Scanning for ${po.po_no}`}
        feedback={feedback}
        onCode={onCode}
        onClose={() => {
          setScanning(false);
          setDraft((d) => (d ? collectSerialsFor(d, null) : d));
        }}
      />
      <RejectSheet line={rejectLine} onClose={() => setRejectLine(null)} onSave={(n, reason) => { if (rejectLine) setDraft(setRejected(draft, rejectLine.lineId, n, reason)); setRejectLine(null); }} />
      <Modal visible={reviewing} animationType="slide" onRequestClose={() => setReviewing(false)}>
        <View style={{ flex: 1, backgroundColor: palette.paper }}>
          <Bar title="Review the delivery" subtitle={po.po_no} back={false} right={<Pressable accessibilityRole="button" onPress={() => setReviewing(false)} hitSlop={10}><Icon name="cross" color={palette.onInk} /></Pressable>} />
          <ScrollView contentContainerStyle={{ padding: space.l }}>
            {error ? <Banner tone="stop" icon="alert" text={error} /> : null}
            {problems.map((p, i) => (
              <Banner key={i} tone="stop" icon="alert" text={p.message} />
            ))}
            {draft.lines.map((l) => {
              const s = summary?.lines.find((x) => x.lineId === l.lineId);
              if (!s || s.verdict === 'untouched') return null;
              return (
                <View key={l.lineId} style={st.reviewRow}>
                  <T v="bodyStrong" style={{ flex: 1 }}>
                    {l.label}
                  </T>
                  <T v="docNo">{qty(l.counted)}</T>
                  <T v="small" color={s.verdict === 'short' ? palette.late : s.verdict === 'over' ? palette.reject : palette.approve}>
                    {s.verdict === 'complete' ? 'Complete' : s.verdict === 'over' ? `Over by ${qty(l.counted - l.outstanding)}` : `${qty(s.stillOwed)} still owed`}
                    {l.rejected > 0 ? ` · ${qty(l.rejected)} rejected: ${l.rejectionReason}` : ''}
                  </T>
                </View>
              );
            })}
            {draft.lines.some((l) => l.counted === 0 && l.rejected === 0) ? (
              <T v="small" color={palette.textSoft} style={{ marginTop: space.m }}>
                Lines you did not count stay outstanding on the order. Nothing is recorded for them.
              </T>
            ) : null}
            <Rule />
            <KeyValue k="Received on" v={day(draft.receivedAt)} />
            <KeyValue k="Challan" v={draft.dcNo || '—'} mono={!!draft.dcNo} />
            <KeyValue k="Vehicle" v={draft.vehicleNo || '—'} />
          </ScrollView>
          <SafeAreaView edges={['bottom']} style={{ padding: space.l, backgroundColor: palette.sheet }}>
            {!online ? <Banner tone="go" icon="offline" text="Recording needs a connection. Your count is saved on this phone." /> : null}
            <Button label="Record goods receipt" kind="approve" icon="check" busy={busy} disabled={problems.length > 0 || !online} onPress={() => void submit()} testID="record" />
          </SafeAreaView>
        </View>
        <ReasonSheet
          visible={ask === 'over'}
          title="More than the order wants"
          text="Receiving this much goes beyond what the order and its tolerance allow. You may accept it by saying why; the reason is recorded."
          placeholder="e.g. Supplier sent a full carton; agreed with buyer"
          confirmLabel="Record anyway"
          confirmKind="approve"
          busy={busy}
          onClose={() => setAsk(null)}
          onConfirm={(reason) => {
            const next = { ...extras, overReason: reason };
            setExtras(next);
            void submit(next);
          }}
        />
        <ReasonSheet
          visible={ask === 'duplicate'}
          title="This challan is already recorded"
          text={error ?? 'A receipt on this order already carries this challan number. If the supplier really delivered twice under one challan, say why (10 characters or more).'}
          placeholder="Why record the same challan again?"
          confirmLabel="Record again"
          confirmKind="approve"
          minLength={10}
          busy={busy}
          onClose={() => setAsk(null)}
          onConfirm={(reason) => {
            const next = { ...extras, duplicateReason: reason };
            setExtras(next);
            void submit(next);
          }}
        />
      </Modal>
    </View>
  );
}

function Shell({ children, title = 'Receive goods' }: { children: React.ReactNode; title?: string }) {
  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title={title} />
      {children}
    </View>
  );
}

function LineCard({
  line,
  verdict,
  problem,
  onCount,
  onReject,
  onBatch,
  onSerials,
  onRemoveSerial,
}: {
  line: DraftLine;
  verdict: string;
  problem?: string;
  onCount: (n: number) => void;
  onReject: () => void;
  onBatch: (batchNo: string, expiry: string) => void;
  onSerials: () => void;
  onRemoveSerial: (serial: string) => void;
}) {
  const [typing, setTyping] = useState<string | null>(null);
  const [showBatch, setShowBatch] = useState(!!line.batchNo || !!line.tracks?.batch);
  const serialLine = !!line.tracks?.serial || line.serials.length > 0;
  const accent = verdict === 'complete' ? palette.approve : verdict === 'over' ? palette.reject : verdict === 'short' ? palette.plum : palette.ruleStrong;
  return (
    <View style={[st.lineCard, { borderLeftColor: accent }]} testID={`line-${line.lineId}`}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: space.s }}>
        <T v="bodyStrong" style={{ flex: 1 }} numberOfLines={2}>
          {line.label}
        </T>
        <T v="small" color={palette.textMuted}>
          line {line.lineNo}
        </T>
      </View>
      <T v="small" color={palette.textSoft}>
        {qty(line.outstanding)} still expected of {qty(line.ordered)} ordered
      </T>
      <View style={st.counterRow}>
        {serialLine ? (
          <Button label={`Scan serials (${line.serials.length})`} kind="ink" icon="tag" onPress={onSerials} style={{ flex: 1 }} />
        ) : (
          <>
            <Pressable accessibilityRole="button" accessibilityLabel={`One less ${line.label}`} onPress={() => onCount(line.counted - 1)} style={st.step}>
              <Icon name="minus" />
            </Pressable>
            {typing !== null ? (
              <TextInput
                autoFocus
                value={typing}
                onChangeText={setTyping}
                keyboardType="decimal-pad"
                onBlur={() => {
                  onCount(Number(typing.replace(',', '.')));
                  setTyping(null);
                }}
                onSubmitEditing={() => {
                  onCount(Number(typing.replace(',', '.')));
                  setTyping(null);
                }}
                style={st.countInput}
                accessibilityLabel={`Quantity received of ${line.label}`}
              />
            ) : (
              <Pressable accessibilityRole="button" accessibilityLabel={`${qty(line.counted)} counted. Tap to type`} onPress={() => setTyping(line.counted ? String(line.counted) : '')} style={st.count}>
                <T v="figure">{qty(line.counted)}</T>
              </Pressable>
            )}
            <Pressable accessibilityRole="button" accessibilityLabel={`One more ${line.label}`} onPress={() => onCount(line.counted + 1)} style={[st.step, { backgroundColor: palette.plum, borderColor: palette.plum }]}>
              <Icon name="plus" color={palette.white} />
            </Pressable>
            <Pressable accessibilityRole="button" accessibilityLabel={`All ${qty(line.outstanding)} arrived`} onPress={() => onCount(line.outstanding)} style={st.all}>
              <T v="label">All</T>
            </Pressable>
          </>
        )}
      </View>
      {line.serials.length > 0 ? (
        <View style={st.serials}>
          {line.serials.map((s) => (
            <Pressable key={s} accessibilityRole="button" accessibilityLabel={`Remove serial ${s}`} onPress={() => onRemoveSerial(s)} style={st.serialChip}>
              <T v="small">{s}</T>
              <Icon name="cross" size={12} />
            </Pressable>
          ))}
        </View>
      ) : null}
      <View style={{ flexDirection: 'row', gap: space.l, marginTop: space.s }}>
        <Pressable accessibilityRole="button" onPress={onReject} hitSlop={6}>
          <T v="small" color={palette.reject}>
            {line.rejected > 0 ? `${qty(line.rejected)} rejected · edit` : 'Reject damaged…'}
          </T>
        </Pressable>
        {!showBatch ? (
          <Pressable accessibilityRole="button" onPress={() => setShowBatch(true)} hitSlop={6}>
            <T v="small" color={palette.plumDeep}>
              Add batch
            </T>
          </Pressable>
        ) : null}
      </View>
      {showBatch ? (
        <View style={{ flexDirection: 'row', gap: space.s, marginTop: space.s }}>
          <View style={{ flex: 1 }}>
            <Field label="Batch no." value={line.batchNo} onChangeText={(v) => onBatch(v, line.expiryDate)} autoCapitalize="characters" />
          </View>
          <View style={{ flex: 1 }}>
            <Field label={line.tracks?.expiry ? 'Expiry (required)' : 'Expiry'} value={line.expiryDate} onChangeText={(v) => onBatch(line.batchNo, v)} placeholder="YYYY-MM-DD" keyboardType="numbers-and-punctuation" />
          </View>
        </View>
      ) : null}
      {problem ? (
        <T v="small" color={palette.reject} style={{ marginTop: space.xs }}>
          {problem}
        </T>
      ) : null}
    </View>
  );
}

function RejectSheet({ line, onClose, onSave }: { line: DraftLine | null; onClose: () => void; onSave: (n: number, reason: string) => void }) {
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  useEffect(() => {
    if (line) {
      setAmount(line.rejected ? String(line.rejected) : '');
      setReason(line.rejectionReason);
    }
  }, [line]);
  const n = Number(amount.replace(',', '.'));
  return (
    <Modal visible={!!line} transparent animationType="slide" onRequestClose={onClose}>
      <View style={{ flex: 1, backgroundColor: 'rgba(38,40,45,0.45)', justifyContent: 'flex-end' }}>
        <SafeAreaView edges={['bottom']} style={{ backgroundColor: palette.paper, padding: space.xl, borderTopLeftRadius: radius.l, borderTopRightRadius: radius.l }}>
          <T v="title">Reject at the gate</T>
          <T v="small" color={palette.textSoft} style={{ marginBottom: space.l }}>
            {line?.label}. Rejected goods go back; the supplier still owes them, so the order stays open for them.
          </T>
          <Field label="Quantity rejected" value={amount} onChangeText={setAmount} keyboardType="decimal-pad" placeholder="0" />
          <Field label="Reason" value={reason} onChangeText={setReason} placeholder="Damaged in transit, wrong size, expired…" multiline />
          <View style={{ flexDirection: 'row', gap: space.m }}>
            <Button label="Cancel" kind="quiet" onPress={onClose} style={{ flex: 1 }} />
            <Button label={n > 0 ? 'Save' : 'Clear'} kind="reject" disabled={n > 0 && reason.trim().length < 3} onPress={() => onSave(Number.isFinite(n) && n > 0 ? n : 0, n > 0 ? reason : '')} style={{ flex: 1 }} />
          </View>
        </SafeAreaView>
      </View>
    </Modal>
  );
}

function OutcomeView({ outcome, busy, error, onFollowUp, onDone, onOrder }: { outcome: Outcome; busy: boolean; error: string | null; onFollowUp: (a: 'retry' | 'reconcile', id: number) => void; onDone: () => void; onOrder: () => void }) {
  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title="Goods receipt" back={false} />
      <View style={{ flex: 1, justifyContent: 'center', padding: space.xl }}>
        {outcome.kind === 'recorded' ? (
          <>
            <Icon name="check" size={48} color={palette.approve} />
            <T v="title" style={{ marginTop: space.l }}>
              Receipt {outcome.receiptNo} recorded
            </T>
            <T v="body" color={palette.textSoft} style={{ marginTop: space.s }}>
              Inventory has the delivery against {outcome.po.po_no}. What was short stays expected on the order.
            </T>
          </>
        ) : outcome.kind === 'pending' ? (
          <>
            <Icon name="clock" size={48} color={palette.late} />
            <T v="title" style={{ marginTop: space.l }}>
              {outcome.uncertain ? 'Waiting for Inventory to confirm' : 'Saved, not yet in Inventory'}
            </T>
            <T v="body" color={palette.textSoft} style={{ marginTop: space.s }}>
              {outcome.message}
            </T>
            {error ? <Banner tone="stop" icon="alert" text={error} /> : null}
            <View style={{ flexDirection: 'row', gap: space.m, marginTop: space.l }}>
              <Button label="Check" kind="quiet" busy={busy} onPress={() => onFollowUp('reconcile', outcome.requestId)} style={{ flex: 1 }} />
              <Button label="Retry" kind="ink" busy={busy} onPress={() => onFollowUp('retry', outcome.requestId)} style={{ flex: 1 }} />
            </View>
            <T v="small" color={palette.textMuted} style={{ marginTop: space.s }}>
              Neither can record the delivery twice.
            </T>
          </>
        ) : (
          <>
            <Icon name="alert" size={48} color={palette.reject} />
            <T v="title" style={{ marginTop: space.l }}>
              Inventory refused this receipt
            </T>
            <T v="body" color={palette.textSoft} style={{ marginTop: space.s }}>
              {outcome.message} Sort it out on the web, where the order&rsquo;s receipts can be withdrawn and recorded again.
            </T>
          </>
        )}
        <Button label="Back to the desk" onPress={onDone} style={{ marginTop: space.xl }} />
        <Button label="See the order" kind="quiet" onPress={onOrder} style={{ marginTop: space.m }} />
      </View>
    </View>
  );
}

const st = StyleSheet.create({
  scanHero: { flexDirection: 'row', alignItems: 'center', gap: space.m, backgroundColor: palette.plum, padding: space.l, borderRadius: radius.m, marginBottom: space.l },
  lineCard: { backgroundColor: palette.sheet, borderRadius: radius.m, padding: space.l, marginBottom: space.m, borderLeftWidth: 5, borderWidth: StyleSheet.hairlineWidth, borderColor: palette.ruleStrong },
  counterRow: { flexDirection: 'row', alignItems: 'center', gap: space.s, marginTop: space.m },
  step: { width: 48, height: 48, borderRadius: radius.m, borderWidth: 1.5, borderColor: palette.ruleStrong, alignItems: 'center', justifyContent: 'center' },
  count: { flex: 1, height: 48, alignItems: 'center', justifyContent: 'center', backgroundColor: palette.paper, borderRadius: radius.m },
  countInput: { flex: 1, height: 48, textAlign: 'center', fontFamily: font.monoBold, fontSize: 22, backgroundColor: palette.paper, borderRadius: radius.m, color: palette.text },
  all: { height: 48, paddingHorizontal: space.m, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: palette.ruleStrong, borderRadius: radius.m },
  serials: { flexDirection: 'row', flexWrap: 'wrap', gap: space.xs, marginTop: space.s },
  serialChip: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: space.s, paddingVertical: 2, backgroundColor: palette.paper, borderRadius: radius.s, borderWidth: 1, borderColor: palette.rule },
  footer: { position: 'absolute', left: 0, right: 0, bottom: 0, flexDirection: 'row', alignItems: 'center', gap: space.m, padding: space.l, backgroundColor: palette.sheet, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: palette.ruleStrong },
  reviewRow: { backgroundColor: palette.sheet, padding: space.m, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: palette.rule, gap: 2 },
});
