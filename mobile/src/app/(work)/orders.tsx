import { useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, TextInput, View } from 'react-native';
import { useResource } from '../../core/useResource';
import { purchasing } from '../../purchasing/api';
import { day, money } from '../../purchasing/format';
import { isOverdue, RECEIPT_LABEL, statusLabel } from '../../purchasing/status';
import { localToday } from '../../scope/companies';
import { useScope } from '../../scope/ScopeProvider';
import { Icon } from '../../ui/Icon';
import { Banner, Bar, Docket, Stamp, StatePanel, T } from '../../ui/kit';
import { font, palette, radius, space } from '../../ui/theme';

type View_ = 'open' | 'overdue' | 'pending' | 'all';
const VIEWS: { key: View_; label: string }[] = [
  { key: 'open', label: 'Open' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'pending', label: 'Awaiting approval' },
  { key: 'all', label: 'All' },
];

/** Find a purchase order by number or supplier, and see where it stands. */
export default function Orders() {
  const router = useRouter();
  const { scope, can, seat } = useScope();
  const [view, setView] = useState<View_>('open');
  const [typed, setTyped] = useState('');
  const [q, setQ] = useState('');
  const today = localToday();

  useEffect(() => {
    const t = setTimeout(() => setQ(typed), 350);
    return () => clearTimeout(t);
  }, [typed]);

  const list = useResource(() => purchasing.orders({ q, view }), [q, view, scope?.cmpId, scope?.fyId, scope?.boId]);
  const rows = list.data?.rows ?? [];

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title="Track orders" subtitle={scope?.companyName} />
      <View style={st.search}>
        <Icon name="search" size={18} color={palette.textMuted} />
        <TextInput value={typed} onChangeText={setTyped} placeholder="PO number or supplier" placeholderTextColor={palette.textMuted} style={st.searchInput} autoCapitalize="characters" autoCorrect={false} accessibilityLabel="Search orders" returnKeyType="search" />
      </View>
      <View style={st.views} accessibilityRole="tablist">
        {VIEWS.map((v) => (
          <Pressable key={v.key} accessibilityRole="tab" accessibilityState={{ selected: v.key === view }} onPress={() => setView(v.key)} style={[st.view, v.key === view && st.viewOn]}>
            <T v="small" color={v.key === view ? palette.onInk : palette.text}>
              {v.label}
            </T>
          </Pressable>
        ))}
      </View>
      {seat.kind === 'ready' && !can('po.view') ? (
        <StatePanel kind="empty" icon="route" title="Orders are not shown to you" text="Your profile in this company does not include viewing purchase orders." />
      ) : (
        <FlatList
          data={rows}
          keyExtractor={(r) => String(r.po_id)}
          contentContainerStyle={{ padding: space.l, paddingTop: space.s }}
          refreshControl={<RefreshControl refreshing={list.refreshing} onRefresh={list.reload} />}
          ListHeaderComponent={list.phase === 'failed' && rows.length > 0 ? <Banner tone="late" icon="offline" text={`Showing earlier results. ${list.error.message}`} /> : null}
          ListEmptyComponent={
            list.phase === 'loading' ? (
              <StatePanel kind="loading" />
            ) : list.phase === 'failed' ? (
              <StatePanel kind="error" problem={list.error} onRetry={list.reload} />
            ) : (
              <StatePanel kind="empty" icon="search" title={q ? `No orders match "${q}"` : 'No orders here'} text={view === 'overdue' ? 'Nothing is past its promised date.' : 'Try another view, or search by number or supplier.'} />
            )
          }
          renderItem={({ item }) => {
            const s = statusLabel(item.status);
            const late = isOverdue(item.promised_date, item.status, today);
            return (
              <Docket onPress={() => router.push({ pathname: '/order/[id]', params: { id: String(item.po_id) } })} accessibilityLabel={`${item.po_no}, ${s.label}`}>
                <View style={st.rowTop}>
                  <T v="docNo">{item.po_no}</T>
                  <Stamp label={late ? 'Overdue' : s.label} tone={late ? 'late' : s.tone} />
                </View>
                <T v="bodyStrong" numberOfLines={1}>
                  {item.supplier_name_snapshot ?? 'Supplier'}
                </T>
                <View style={st.rowTop}>
                  <T v="small" color={palette.textSoft}>
                    {day(item.po_date)} · {RECEIPT_LABEL[item.receipt_status ?? ''] ?? '—'}
                  </T>
                  <T v="small">{money(item.total_amount, item.currency_code)}</T>
                </View>
              </Docket>
            );
          }}
        />
      )}
    </View>
  );
}

const st = StyleSheet.create({
  search: { flexDirection: 'row', alignItems: 'center', gap: space.s, margin: space.l, marginBottom: space.s, paddingHorizontal: space.m, backgroundColor: palette.sheet, borderRadius: radius.m, borderWidth: 1, borderColor: palette.ruleStrong },
  searchInput: { flex: 1, paddingVertical: space.m, fontFamily: font.mono, fontSize: 16, color: palette.text },
  views: { flexDirection: 'row', flexWrap: 'wrap', gap: space.s, paddingHorizontal: space.l, paddingBottom: space.s },
  view: { paddingHorizontal: space.m, paddingVertical: 6, borderRadius: radius.s, borderWidth: 1, borderColor: palette.ruleStrong, backgroundColor: palette.sheet },
  viewOn: { backgroundColor: palette.ink, borderColor: palette.ink },
  rowTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 2 },
});
