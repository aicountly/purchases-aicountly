import { useRouter } from 'expo-router';
import { useMemo, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { type Problem } from '../../core/problems';
import { useResource } from '../../core/useResource';
import { useOnline } from '../../net/NetworkProvider';
import { purchasing } from '../../purchasing/api';
import { day, money, relativeDay } from '../../purchasing/format';
import { isOverdue, RECEIPT_LABEL } from '../../purchasing/status';
import type { ApprovalRow, PoListRow } from '../../purchasing/types';
import { localToday } from '../../scope/companies';
import { useScope } from '../../scope/ScopeProvider';
import { Icon, type IconName } from '../../ui/Icon';
import { Banner, Docket, Stamp, StatePanel, T } from '../../ui/kit';
import { palette, radius, space } from '../../ui/theme';

type Queue = 'approve' | 'receive';

/**
 * The desk: the two queues this app exists for. "To approve" holds the purchase orders and
 * requisitions waiting for this person's decision; "To receive" holds the orders whose goods
 * are still expected. Tracking and suppliers sit on the dock underneath.
 */
export default function Desk() {
  const router = useRouter();
  const online = useOnline();
  const { scope, seat, can, restricted, reloadSeat } = useScope();
  const approver = can('po.approve') || can('requisition.approve');
  const receiver = can('receipt.request');
  const [chosen, setChosen] = useState<Queue | null>(null);
  const queue: Queue = chosen ?? (approver || !receiver ? 'approve' : 'receive');
  const today = localToday();

  const approvals = useResource(() => (approver ? purchasing.approvals() : Promise.resolve({ rows: [], total: 0 })), [scope?.cmpId, scope?.fyId, scope?.boId, approver]);
  const receiving = useResource(() => (receiver ? purchasing.toReceive() : Promise.resolve({ rows: [], total: 0 })), [scope?.cmpId, scope?.fyId, scope?.boId, receiver]);
  const active = queue === 'approve' ? approvals : receiving;
  const reload = () => {
    approvals.reload();
    receiving.reload();
  };

  const header = (
    <SafeAreaView edges={['top']} style={{ backgroundColor: palette.ink }}>
      <View style={st.head}>
        <Pressable accessibilityRole="button" accessibilityLabel={`Company ${scope?.companyName}. Change company`} onPress={() => router.push('/company')} style={{ flex: 1 }}>
          <T v="label" color={palette.signal}>
            Purchases desk
          </T>
          <T v="title" color={palette.onInk} numberOfLines={1}>
            {scope?.companyName}
          </T>
          <T v="small" color={palette.onInkMuted}>
            {scope?.yearLabel} · {scope?.branchName} ▾
          </T>
        </Pressable>
        <Pressable accessibilityRole="button" accessibilityLabel="Account" onPress={() => router.push('/account')} hitSlop={10} style={st.person}>
          <Icon name="person" color={palette.onInk} />
        </Pressable>
      </View>
      <View style={st.switch} accessibilityRole="tablist">
        <QueueTab label="To approve" icon="stamp" count={approver ? approvals.data?.total : undefined} on={queue === 'approve'} onPress={() => setChosen('approve')} />
        <QueueTab label="To receive" icon="crate" count={receiver ? receiving.data?.total : undefined} on={queue === 'receive'} onPress={() => setChosen('receive')} />
      </View>
    </SafeAreaView>
  );

  const banners = (
    <>
      {!online ? <Banner tone="go" icon="offline" text="You're offline. You can still count a delivery; recording it needs a connection." /> : null}
      {restricted ? <Banner tone="late" icon="alert" text="This company is read-only in Purchases right now. Contact your company administrator." /> : null}
    </>
  );

  const body = useMemo(() => {
    if (seat.kind === 'loading' || seat.kind === 'idle') return <StatePanel kind="loading" />;
    if (seat.kind === 'failed') {
      return (
        <View style={{ padding: space.l }}>
          <Banner tone="stop" icon="alert" text={seat.message} />
          {seat.noAccess ? (
            <StatePanel kind="empty" icon="building" title="Can't open this company here" text="Choose another company, or ask its owner for access to Purchases." action={{ label: 'Choose a company', onPress: () => router.push('/company') }} />
          ) : (
            <StatePanel kind="empty" icon="refresh" title="This company did not open" text="Check your connection and try again." action={{ label: 'Try again', onPress: reloadSeat }} />
          )}
        </View>
      );
    }
    if (queue === 'approve' && !approver) {
      return <StatePanel kind="empty" icon="stamp" title="You don't approve here" text="Approvals in this company are decided by others. Track orders or look up suppliers below." />;
    }
    if (queue === 'receive' && !receiver) {
      return <StatePanel kind="empty" icon="crate" title="You don't receive goods here" text="Your profile in this company does not record goods receipts. Track orders below." />;
    }
    return null;
  }, [seat, queue, approver, receiver, router, reloadSeat]);

  const failure = active.phase === 'failed' ? (active.error as Problem) : null;

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      {header}
      {body ?? (
        <FlatList<ApprovalRow | PoListRow>
          data={(active.data?.rows ?? []) as (ApprovalRow | PoListRow)[]}
          keyExtractor={(r) => ('approval_id' in r ? `a${r.approval_id}` : `p${r.po_id}`)}
          contentContainerStyle={{ padding: space.l, paddingBottom: 120 }}
          refreshControl={<RefreshControl refreshing={active.refreshing} onRefresh={reload} tintColor={palette.signalDeep} />}
          ListHeaderComponent={
            <>
              {banners}
              {failure && active.data ? <Banner tone="late" icon={failure.kind === 'offline' ? 'offline' : 'alert'} text={`Showing what was loaded earlier. ${failure.message}`} /> : null}
            </>
          }
          ListEmptyComponent={
            active.phase === 'loading' ? (
              <StatePanel kind="loading" />
            ) : failure ? (
              <StatePanel kind="error" problem={failure} onRetry={reload} />
            ) : queue === 'approve' ? (
              <StatePanel kind="empty" icon="check" title="Nothing waiting for you" text="Purchase orders and requisitions that need your decision appear here." />
            ) : (
              <StatePanel kind="empty" icon="crate" title="No deliveries expected" text="Approved and issued orders with goods still to come appear here, soonest first." />
            )
          }
          renderItem={({ item }) =>
            'approval_id' in item ? (
              <ApprovalCard row={item} today={today} onPress={() => router.push({ pathname: '/approval/[kind]/[id]', params: { kind: item.entity_type === 'requisition' ? 'requisition' : 'order', id: String(item.entity_id) } })} />
            ) : (
              <ReceiveCard row={item} today={today} onPress={() => router.push({ pathname: '/receive/[poId]', params: { poId: String(item.po_id) } })} />
            )
          }
        />
      )}
      <SafeAreaView edges={['bottom']} style={st.dockWrap} pointerEvents="box-none">
        <View style={st.dock}>
          <DockButton icon="route" label="Track orders" onPress={() => router.push('/orders')} />
          <View style={st.dockRule} />
          <DockButton icon="ledger" label="Suppliers" onPress={() => router.push('/suppliers')} />
        </View>
      </SafeAreaView>
    </View>
  );
}

function QueueTab({ label, icon, count, on, onPress }: { label: string; icon: IconName; count?: number; on: boolean; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="tab" accessibilityState={{ selected: on }} accessibilityLabel={count !== undefined ? `${label}, ${count}` : label} onPress={onPress} style={[st.tab, on && st.tabOn]}>
      <Icon name={icon} size={18} color={on ? palette.ink : palette.onInkMuted} />
      <T v="bodyStrong" color={on ? palette.ink : palette.onInk}>
        {label}
      </T>
      {count !== undefined ? (
        <View style={[st.count, on && { backgroundColor: palette.ink }]}>
          <T v="label" color={on ? palette.signal : palette.ink}>
            {count > 99 ? '99+' : String(count)}
          </T>
        </View>
      ) : null}
    </Pressable>
  );
}

function DockButton({ icon, label, onPress }: { icon: IconName; label: string; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={onPress} style={({ pressed }) => [st.dockButton, { opacity: pressed ? 0.7 : 1 }]}>
      <Icon name={icon} size={20} color={palette.onInk} />
      <T v="bodyStrong" color={palette.onInk}>
        {label}
      </T>
    </Pressable>
  );
}

function ApprovalCard({ row, today, onPress }: { row: ApprovalRow; today: string; onPress: () => void }) {
  const isOrder = row.entity_type !== 'requisition';
  const number = (isOrder ? row.po_no : row.requisition_no) ?? `#${row.entity_id}`;
  const value = isOrder ? row.po_value : row.requisition_value;
  return (
    <Docket onPress={onPress} accessibilityLabel={`${isOrder ? 'Purchase order' : 'Requisition'} ${number}, waiting for your decision`}>
      <View style={st.cardTop}>
        <T v="label" color={palette.textMuted}>
          {isOrder ? 'Purchase order' : 'Requisition'}
        </T>
        <T v="small" color={palette.textMuted}>
          waiting {relativeDay(row.created_at, today).replace(' ago', '')}
        </T>
      </View>
      <T v="docNo" style={{ marginTop: 2 }}>
        {number}
      </T>
      {isOrder && row.supplier_name_snapshot ? (
        <T v="bodyStrong" numberOfLines={1}>
          {row.supplier_name_snapshot}
        </T>
      ) : null}
      <View style={st.cardBottom}>
        <T v="figure" style={{ fontSize: 18 }}>
          {value !== null && value !== undefined ? money(value) : 'Value hidden'}
        </T>
        <Icon name="next" size={18} color={palette.textMuted} />
      </View>
      {row.reason_detail ? (
        <T v="small" color={palette.textSoft} numberOfLines={2}>
          {row.reason_detail}
        </T>
      ) : null}
    </Docket>
  );
}

function ReceiveCard({ row, today, onPress }: { row: PoListRow; today: string; onPress: () => void }) {
  const late = isOverdue(row.promised_date, row.status, today);
  return (
    <Docket onPress={onPress} accessibilityLabel={`Receive against ${row.po_no}`}>
      <View style={st.cardTop}>
        <T v="docNo">{row.po_no}</T>
        <Stamp label={RECEIPT_LABEL[row.receipt_status ?? 'NOT_STARTED'] ?? 'Expected'} tone={row.receipt_status === 'PARTIAL' ? 'waiting' : 'go'} />
      </View>
      <T v="bodyStrong" numberOfLines={1} style={{ marginTop: 2 }}>
        {row.supplier_name_snapshot ?? 'Supplier'}
      </T>
      <View style={st.cardBottom}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
          <Icon name="clock" size={16} color={late ? palette.late : palette.textMuted} />
          <T v="small" color={late ? palette.late : palette.textSoft}>
            {row.promised_date ? `Promised ${day(row.promised_date)} (${relativeDay(row.promised_date, today)})` : 'No promised date'}
          </T>
        </View>
        <Icon name="scan" size={18} color={palette.signalDeep} />
      </View>
    </Docket>
  );
}

const st = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.l, paddingTop: space.m, paddingBottom: space.m },
  person: { width: 40, height: 40, borderRadius: 20, borderWidth: 1, borderColor: palette.inkLine, alignItems: 'center', justifyContent: 'center' },
  switch: { flexDirection: 'row', paddingHorizontal: space.l, gap: space.s, paddingBottom: space.m },
  tab: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: space.s, paddingVertical: space.m, paddingHorizontal: space.m, borderRadius: radius.m, borderWidth: 1, borderColor: palette.inkLine },
  tabOn: { backgroundColor: palette.signal, borderColor: palette.signal },
  count: { marginLeft: 'auto', minWidth: 24, paddingHorizontal: 6, paddingVertical: 2, borderRadius: radius.s, backgroundColor: palette.onInk, alignItems: 'center' },
  cardTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  cardBottom: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: space.s },
  dockWrap: { position: 'absolute', left: 0, right: 0, bottom: 0 },
  dock: { flexDirection: 'row', marginHorizontal: space.l, marginBottom: space.m, backgroundColor: palette.ink, borderRadius: radius.l, overflow: 'hidden' },
  dockButton: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: space.s, paddingVertical: space.l },
  dockRule: { width: 1, backgroundColor: palette.inkLine, marginVertical: space.m },
});
