import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { RefreshControl, StyleSheet, View } from 'react-native';
import { messageOf } from '../../../core/problems';
import { useResource } from '../../../core/useResource';
import { useOnline } from '../../../net/NetworkProvider';
import { purchasing, RECEIVABLE } from '../../../purchasing/api';
import { day, money, qty, toNumber } from '../../../purchasing/format';
import { BILLING_LABEL, isOverdue, RECEIPT_LABEL, routeOf, statusLabel, type Stage } from '../../../purchasing/status';
import { localToday } from '../../../scope/companies';
import { useScope } from '../../../scope/ScopeProvider';
import { Icon } from '../../../ui/Icon';
import { Banner, Bar, Button, Docket, KeyValue, Page, Rule, Stamp, StatePanel, T } from '../../../ui/kit';
import { palette, space } from '../../../ui/theme';

/** One purchase order's route and progress: approved, issued, received, billed — and what is stuck. */
export default function OrderScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const poId = Number(id);
  const router = useRouter();
  const online = useOnline();
  const { can } = useScope();
  const order = useResource(() => purchasing.order(poId), [poId]);
  const [busy, setBusy] = useState<number | null>(null);
  const [note, setNote] = useState<{ tone: 'done' | 'stop'; text: string } | null>(null);
  const today = localToday();

  const follow = async (requestId: number, action: 'retry' | 'reconcile') => {
    setBusy(requestId);
    setNote(null);
    try {
      const po = action === 'retry' ? await purchasing.retryReceipt(requestId) : await purchasing.reconcileReceipt(requestId);
      const r = po.receipts?.find((x) => x.request_id === requestId);
      setNote(r?.applied_at ? { tone: 'done', text: `Receipt ${r.receipt_no} is now recorded in Inventory.` } : { tone: 'stop', text: r?.last_error ?? 'Inventory has not recorded it yet.' });
      order.reload();
    } catch (err) {
      setNote({ tone: 'stop', text: messageOf(err) });
    } finally {
      setBusy(null);
    }
  };

  const po = order.data;
  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title={po?.po_no ?? 'Purchase order'} subtitle={po?.supplier_name_snapshot ?? undefined} />
      {order.phase === 'loading' && !po ? (
        <StatePanel kind="loading" />
      ) : order.phase === 'failed' && !po ? (
        <StatePanel kind="error" problem={order.error} onRetry={order.reload} />
      ) : po ? (
        <Page refresh={<RefreshControl refreshing={order.refreshing} onRefresh={order.reload} />}>
          {order.phase === 'failed' ? <Banner tone="late" icon="offline" text={`Showing what was loaded earlier. ${order.error.message}`} /> : null}
          {note ? <Banner tone={note.tone} icon={note.tone === 'done' ? 'check' : 'alert'} text={note.text} /> : null}
          <Docket>
            <View style={st.between}>
              <Stamp {...(isOverdue(po.promised_date, po.status, today) ? { label: 'Overdue', tone: 'late' as const } : statusLabel(po.status))} />
              <T v="small" color={palette.textMuted}>
                {day(po.po_date)}
              </T>
            </View>
            <T v="figure" style={{ marginTop: space.m }}>
              {money(po.total_amount, po.currency_code)}
            </T>
            <KeyValue k="Promised by" v={day(po.promised_date)} />
            <KeyValue k="Receiving" v={RECEIPT_LABEL[po.progress?.receipt_status ?? po.receipt_status] ?? '—'} />
            <KeyValue k="Billing" v={BILLING_LABEL[po.progress?.billing_status ?? po.billing_status] ?? '—'} />
            {po.cancel_reason ? <KeyValue k="Cancelled because" v={po.cancel_reason} /> : null}
          </Docket>

          {RECEIVABLE.includes(po.status) && po.progress?.receipt_status !== 'COMPLETE' && can('receipt.request') ? (
            <Button label="Receive goods" icon="scan" onPress={() => router.push({ pathname: '/receive/[poId]', params: { poId: String(po.po_id) } })} style={{ marginBottom: space.l }} />
          ) : null}
          {(po.status === 'APPROVAL_PENDING' || po.amendment_pending_id) && can('po.approve') ? (
            <Button label="Decide on this order" kind="ink" icon="stamp" onPress={() => router.push({ pathname: '/approval/[kind]/[id]', params: { kind: 'order', id: String(po.po_id) } })} style={{ marginBottom: space.l }} />
          ) : null}

          <T v="label" color={palette.textSoft} style={{ marginBottom: space.s }}>
            Route
          </T>
          <Docket>
            {routeOf(po).map((s, i, all) => (
              <RouteStop key={s.key} stage={s} last={i === all.length - 1} />
            ))}
          </Docket>

          <T v="label" color={palette.textSoft} style={{ marginVertical: space.s }}>
            Lines
          </T>
          {po.lines.map((l) => {
            const ordered = toNumber(l.ordered_qty) - toNumber(l.short_closed_qty);
            const received = toNumber(l.received_qty) - toNumber(l.returned_qty);
            const pct = ordered > 0 ? Math.min(1, Math.max(0, received / ordered)) : 0;
            return (
              <View key={l.line_id} style={st.line}>
                <T v="bodyStrong" numberOfLines={2}>
                  {l.description ?? `Item ${l.item_id ?? ''}`}
                </T>
                <View style={st.bar}>
                  <View style={[st.barFill, { width: `${pct * 100}%` }]} />
                </View>
                <T v="small" color={palette.textSoft}>
                  {qty(received)} of {qty(ordered)} received · {qty(l.billed_qty)} billed
                  {toNumber(l.rejected_qty) > 0 ? ` · ${qty(l.rejected_qty)} rejected` : ''}
                </T>
              </View>
            );
          })}

          <T v="label" color={palette.textSoft} style={{ marginVertical: space.s }}>
            Goods receipts
          </T>
          {po.receipts.length === 0 ? (
            <T v="small" color={palette.textMuted}>
              Nothing received against this order yet.
            </T>
          ) : (
            po.receipts.map((r) => (
              <Docket key={r.request_id}>
                <View style={st.between}>
                  <T v="docNo">{r.receipt_no}</T>
                  <Stamp label={r.applied_at ? 'In Inventory' : r.status === 'CANCELLED' ? 'Withdrawn' : r.status === 'BLOCKED' ? 'Refused' : 'Not confirmed'} tone={r.applied_at ? 'done' : r.status === 'BLOCKED' ? 'stop' : r.status === 'CANCELLED' ? 'neutral' : 'late'} />
                </View>
                <T v="small" color={palette.textSoft}>
                  {day(r.received_at)}
                  {r.inventory_document_no ? ` · ${r.inventory_document_no}` : ''}
                  {r.supplier_dc_no ? ` · challan ${r.supplier_dc_no}` : ''}
                </T>
                {r.last_error && !r.applied_at ? (
                  <T v="small" color={palette.reject}>
                    {r.last_error}
                  </T>
                ) : null}
                {r.recoverable && can('receipt.request') ? (
                  <View style={{ flexDirection: 'row', gap: space.m, marginTop: space.s }}>
                    <Button label="Check" kind="quiet" busy={busy === r.request_id} disabled={!online || busy !== null} onPress={() => void follow(r.request_id, 'reconcile')} style={{ flex: 1 }} />
                    <Button label="Retry" kind="ink" busy={busy === r.request_id} disabled={!online || busy !== null} onPress={() => void follow(r.request_id, 'retry')} style={{ flex: 1 }} />
                  </View>
                ) : null}
              </Docket>
            ))
          )}
          <Rule />
          <T v="small" color={palette.textMuted}>
            Editing, issuing, amending, closing and billing this order are done on the web.
          </T>
        </Page>
      ) : null}
    </View>
  );
}

function RouteStop({ stage, last }: { stage: Stage; last: boolean }) {
  const color = stage.state === 'done' ? palette.approve : stage.state === 'current' ? palette.signalDeep : palette.ruleStrong;
  return (
    <View style={{ flexDirection: 'row', gap: space.m }}>
      <View style={{ alignItems: 'center', width: 22 }}>
        <View style={[st.dot, { borderColor: color, backgroundColor: stage.state === 'done' ? color : palette.sheet }]}>
          {stage.state === 'done' ? <Icon name="check" size={12} color={palette.white} strokeWidth={3} /> : null}
          {stage.state === 'skipped' ? <Icon name="minus" size={12} color={palette.textMuted} strokeWidth={3} /> : null}
        </View>
        {!last ? <View style={[st.stem, { backgroundColor: stage.state === 'done' ? palette.approve : palette.rule }]} /> : null}
      </View>
      <View style={{ flex: 1, paddingBottom: last ? 0 : space.m }}>
        <T v={stage.state === 'current' ? 'bodyStrong' : 'body'} color={stage.state === 'ahead' || stage.state === 'skipped' ? palette.textMuted : palette.text}>
          {stage.label}
        </T>
        {stage.when ? (
          <T v="small" color={palette.textSoft}>
            {day(stage.when)}
          </T>
        ) : stage.note ? (
          <T v="small" color={palette.textSoft}>
            {stage.note}
          </T>
        ) : null}
      </View>
    </View>
  );
}

const st = StyleSheet.create({
  between: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  line: { backgroundColor: palette.sheet, padding: space.m, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: palette.rule, gap: 4 },
  bar: { height: 6, backgroundColor: palette.rule, borderRadius: 3, overflow: 'hidden' },
  barFill: { height: 6, backgroundColor: palette.approve },
  dot: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, alignItems: 'center', justifyContent: 'center' },
  stem: { width: 2, flex: 1, minHeight: 14 },
});
