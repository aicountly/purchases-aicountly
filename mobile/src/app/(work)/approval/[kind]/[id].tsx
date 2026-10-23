import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Alert, RefreshControl, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { isProblem, messageOf } from '../../../../core/problems';
import { useResource } from '../../../../core/useResource';
import { useOnline } from '../../../../net/NetworkProvider';
import { purchasing } from '../../../../purchasing/api';
import { day, money, qty, toNumber } from '../../../../purchasing/format';
import { statusLabel } from '../../../../purchasing/status';
import type { EntityKind, PurchaseOrder, Requisition } from '../../../../purchasing/types';
import { useScope } from '../../../../scope/ScopeProvider';
import { Banner, Bar, Button, Docket, KeyValue, Page, ReasonSheet, Rule, Stamp, StatePanel, T } from '../../../../ui/kit';
import { palette, space } from '../../../../ui/theme';

type Doc = { kind: 'purchase_order'; po: PurchaseOrder } | { kind: 'requisition'; req: Requisition };

type Ask = null | 'reject' | 'own' | 'vendor';

/** One document waiting for a decision: read it in full, then approve, or reject with a reason. */
export default function ApprovalScreen() {
  const { kind: kindParam, id } = useLocalSearchParams<{ kind: string; id: string }>();
  const router = useRouter();
  const online = useOnline();
  const { can } = useScope();
  const kind: EntityKind = kindParam === 'requisition' ? 'requisition' : 'purchase_order';
  const docId = Number(id);
  const doc = useResource<Doc>(
    async () => (kind === 'purchase_order' ? { kind, po: await purchasing.order(docId) } : { kind, req: await purchasing.requisition(docId) }),
    [kind, docId],
  );
  const [ask, setAsk] = useState<Ask>(null);
  const [pending, setPending] = useState<{ action: 'approve' | 'reject'; note?: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<'approved' | 'rejected' | null>(null);

  const mayDecide = can(kind === 'purchase_order' ? 'po.approve' : 'requisition.approve');
  const status = doc.data ? (doc.data.kind === 'purchase_order' ? doc.data.po.status : doc.data.req.status) : '';
  const order = doc.data?.kind === 'purchase_order' ? doc.data.po : null;
  const amendment =
    order && order.status !== 'APPROVAL_PENDING' && order.amendment_pending_id
      ? order.amendments?.find((a) => a.amendment_id === order.amendment_pending_id) ?? order.amendments?.find((a) => a.status === 'PENDING_APPROVAL') ?? null
      : null;
  const waiting = status === 'APPROVAL_PENDING' || !!amendment;
  const number = doc.data ? (doc.data.kind === 'purchase_order' ? doc.data.po.po_no : doc.data.req.requisition_no) : '';

  const decide = async (action: 'approve' | 'reject', note?: string, vendorOverride?: string) => {
    setBusy(true);
    setError(null);
    try {
      await purchasing.decide(kind, docId, action, note, vendorOverride);
      setAsk(null);
      setDone(action === 'approve' ? 'approved' : 'rejected');
    } catch (err) {
      if (isProblem(err) && err.code === 'sod_reason_required') {
        setPending({ action, note });
        setAsk('own');
      } else if (isProblem(err) && err.code === 'vendor_not_approved' && err.details.override && can('supplier.approve')) {
        setPending({ action, note });
        setAsk('vendor');
      } else {
        setAsk(null);
        setError(messageOf(err));
      }
    } finally {
      setBusy(false);
    }
  };

  const confirmApprove = () => {
    const total = doc.data?.kind === 'purchase_order' ? money(amendment ? amendment.value_after : doc.data.po.total_amount, doc.data.po.currency_code) : doc.data ? money(doc.data.req.estimated_value) : '';
    Alert.alert(`Approve ${number}?`, `${total}. Your approval is recorded with your name and the time.`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Approve', onPress: () => void decide('approve') },
    ]);
  };

  if (done) {
    return (
      <View style={{ flex: 1, backgroundColor: palette.paper }}>
        <Bar title={number} back={false} />
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl }}>
          <View style={[st.bigStamp, { borderColor: done === 'approved' ? palette.approve : palette.reject }]}>
            <T v="title" color={done === 'approved' ? palette.approve : palette.reject} style={{ letterSpacing: 3 }}>
              {done === 'approved' ? 'APPROVED' : 'REJECTED'}
            </T>
          </View>
          <T v="body" color={palette.textSoft} style={{ marginTop: space.l, textAlign: 'center' }}>
            {done === 'approved'
              ? `${number} is approved. The buyer can issue it to the supplier.`
              : `${number} went back to its author as a draft, with your reason.`}
          </T>
          <Button label="Back to the desk" kind="ink" onPress={() => router.replace('/desk')} style={{ marginTop: space.xl, alignSelf: 'stretch' }} />
        </View>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title={number || (kind === 'purchase_order' ? 'Purchase order' : 'Requisition')} subtitle={kind === 'purchase_order' ? 'Purchase order' : 'Requisition'} />
      {doc.phase === 'loading' && !doc.data ? (
        <StatePanel kind="loading" />
      ) : doc.phase === 'failed' && !doc.data ? (
        <StatePanel kind="error" problem={doc.error} onRetry={doc.reload} />
      ) : doc.data ? (
        <>
          <Page refresh={<RefreshControl refreshing={doc.refreshing} onRefresh={doc.reload} />}>
            {!online ? <Banner tone="go" icon="offline" text="You're offline. You can read this, but deciding needs a connection." /> : null}
            {error ? <Banner tone="stop" icon="alert" text={error} /> : null}
            {doc.data.kind === 'purchase_order' ? <OrderBody po={doc.data.po} amendment={amendment} /> : <RequisitionBody req={doc.data.req} />}
            {!waiting ? <Banner tone="go" icon="check" text={`This ${kind === 'purchase_order' ? 'order' : 'requisition'} is no longer waiting for a decision.`} /> : null}
            {waiting && !mayDecide ? <Banner tone="neutral" icon="stamp" text="You can read this, but your profile does not decide on it." /> : null}
          </Page>
          {waiting && mayDecide ? (
            <SafeAreaView edges={['bottom']} style={st.actions}>
              <Button label="Reject" kind="reject" icon="cross" disabled={!online || busy} onPress={() => setAsk('reject')} style={{ flex: 1 }} testID="reject" />
              <Button label="Approve" kind="approve" icon="check" busy={busy && ask === null} disabled={!online || busy} onPress={confirmApprove} style={{ flex: 1.4 }} testID="approve" />
            </SafeAreaView>
          ) : null}
        </>
      ) : null}
      <ReasonSheet
        visible={ask === 'reject'}
        title={`Reject ${number}`}
        text="Say why. The reason goes back to whoever raised it, and stays on the record."
        placeholder="e.g. Rate is above the agreed contract price"
        confirmLabel="Reject"
        busy={busy}
        onClose={() => setAsk(null)}
        onConfirm={(reason) => void decide('reject', reason)}
      />
      <ReasonSheet
        visible={ask === 'own'}
        title="You raised this one"
        text="As the company owner you may decide on your own document only by saying why. The reason is recorded."
        placeholder="Why are you deciding on it yourself?"
        confirmLabel={pending?.action === 'reject' ? 'Reject' : 'Approve'}
        confirmKind={pending?.action === 'reject' ? 'reject' : 'approve'}
        busy={busy}
        onClose={() => setAsk(null)}
        onConfirm={(reason) => void decide(pending?.action ?? 'approve', pending?.note ? `${pending.note} — ${reason}` : reason)}
      />
      <ReasonSheet
        visible={ask === 'vendor'}
        title="Supplier on hold"
        text="This supplier is suspended or blacklisted in Purchases. You may approve this one order anyway by saying why; the override is recorded."
        placeholder="Why order from them this time?"
        confirmLabel="Approve anyway"
        confirmKind="approve"
        minLength={10}
        busy={busy}
        onClose={() => setAsk(null)}
        onConfirm={(reason) => void decide('approve', pending?.note, reason)}
      />
    </View>
  );
}

function OrderBody({ po, amendment }: { po: PurchaseOrder; amendment: { reason: string; value_before: string; value_after: string } | null }) {
  const s = statusLabel(po.status);
  const pendingApproval = [...(po.approvals ?? [])].reverse().find((a) => a.status === 'PENDING');
  return (
    <>
      <Docket>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
          <T v="docNo">{po.po_no}</T>
          <Stamp label={amendment ? 'Change to approve' : s.label} tone={amendment ? 'waiting' : s.tone} />
        </View>
        <T v="heading" style={{ marginTop: space.s }}>
          {po.supplier_name_snapshot ?? 'Supplier'}
        </T>
        <T v="figure" style={{ marginTop: space.m }}>
          {money(amendment ? amendment.value_after : po.total_amount, po.currency_code)}
        </T>
        {amendment ? (
          <T v="small" color={palette.textSoft}>
            was {money(amendment.value_before, po.currency_code)} · {amendment.reason}
          </T>
        ) : (
          <T v="small" color={palette.textSoft}>
            {money(po.subtotal_amount, po.currency_code)} before an estimated {money(po.estimated_tax_amount, po.currency_code)} tax
          </T>
        )}
        {pendingApproval?.reason_detail ? (
          <View style={st.why}>
            <T v="label" color={palette.plumDeep}>
              Why it needs you
            </T>
            <T v="small">{pendingApproval.reason_detail}</T>
          </View>
        ) : null}
        <Rule />
        <KeyValue k="Order date" v={day(po.po_date)} />
        <KeyValue k="Promised by" v={day(po.promised_date)} />
        <KeyValue k="Payment terms" v={po.payment_terms ?? '—'} />
      </Docket>
      <T v="label" color={palette.textSoft} style={{ marginVertical: space.s }}>
        {po.lines.length} {po.lines.length === 1 ? 'line' : 'lines'}
      </T>
      {po.lines.map((l) => (
        <View key={l.line_id} style={st.line}>
          <T v="bodyStrong" style={{ flex: 1 }} numberOfLines={2}>
            {l.description ?? `Item ${l.item_id ?? ''}`}
          </T>
          <T v="small" color={palette.textSoft}>
            {qty(l.ordered_qty)} × {money(l.agreed_rate, po.currency_code)}
            {toNumber(l.discount_pc) > 0 ? ` less ${qty(l.discount_pc)}%` : ''}
          </T>
          <T v="docNo" style={{ textAlign: 'right' }}>
            {money(l.line_amount, po.currency_code)}
          </T>
        </View>
      ))}
      {po.notes ? (
        <Docket style={{ marginTop: space.m }}>
          <T v="label" color={palette.textSoft}>
            Notes
          </T>
          <T v="small">{po.notes}</T>
        </Docket>
      ) : null}
    </>
  );
}

function RequisitionBody({ req }: { req: Requisition }) {
  const s = statusLabel(req.status);
  const pendingApproval = [...(req.approvals ?? [])].reverse().find((a) => a.status === 'PENDING');
  return (
    <>
      <Docket>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
          <T v="docNo">{req.requisition_no}</T>
          <Stamp label={s.label} tone={s.tone} />
        </View>
        <T v="figure" style={{ marginTop: space.m }}>
          {money(req.estimated_value)}
        </T>
        <T v="small" color={palette.textSoft}>
          estimated
        </T>
        {pendingApproval?.reason_detail ? (
          <View style={st.why}>
            <T v="label" color={palette.plumDeep}>
              Why it needs you
            </T>
            <T v="small">{pendingApproval.reason_detail}</T>
          </View>
        ) : null}
        <Rule />
        <KeyValue k="Department" v={req.department ?? '—'} />
        <KeyValue k="Priority" v={req.priority ?? '—'} />
        <KeyValue k="Needed by" v={day(req.required_by)} />
        {req.justification ? (
          <>
            <Rule />
            <T v="label" color={palette.textSoft}>
              Justification
            </T>
            <T v="small">{req.justification}</T>
          </>
        ) : null}
      </Docket>
      {req.lines.map((l) => (
        <View key={l.line_id} style={st.line}>
          <T v="bodyStrong" style={{ flex: 1 }} numberOfLines={2}>
            {l.description ?? `Item ${l.item_id ?? ''}`}
          </T>
          <T v="small" color={palette.textSoft}>
            {qty(l.required_qty)} at about {money(l.estimated_rate)}
          </T>
        </View>
      ))}
    </>
  );
}

const st = StyleSheet.create({
  actions: { flexDirection: 'row', gap: space.m, padding: space.l, backgroundColor: palette.sheet, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: palette.ruleStrong },
  line: { backgroundColor: palette.sheet, padding: space.m, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: palette.rule, gap: 2 },
  why: { marginTop: space.m, padding: space.m, backgroundColor: palette.plumWash, borderLeftWidth: 3, borderLeftColor: palette.plum },
  bigStamp: { borderWidth: 4, paddingHorizontal: space.xl, paddingVertical: space.m, transform: [{ rotate: '-6deg' }] },
});
