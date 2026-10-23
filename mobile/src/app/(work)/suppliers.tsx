import { useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { FlatList, StyleSheet, TextInput, View } from 'react-native';
import { useResource } from '../../core/useResource';
import { purchasing } from '../../purchasing/api';
import { useScope } from '../../scope/ScopeProvider';
import { Icon } from '../../ui/Icon';
import { Bar, Docket, Stamp, StatePanel, T } from '../../ui/kit';
import { font, palette, radius, space } from '../../ui/theme';

const QUALIFICATION: Record<string, { label: string; tone: 'done' | 'waiting' | 'stop' | 'neutral' }> = {
  approved: { label: 'Approved', tone: 'done' },
  pending_approval: { label: 'Pending', tone: 'waiting' },
  draft: { label: 'Not qualified', tone: 'neutral' },
  suspended: { label: 'Suspended', tone: 'stop' },
  blacklisted: { label: 'Blacklisted', tone: 'stop' },
};

/** Look a supplier up by name (Smart Books' creditor ledgers), with their standing in Purchases. */
export default function Suppliers() {
  const router = useRouter();
  const { scope, can, seat } = useScope();
  const [typed, setTyped] = useState('');
  const [q, setQ] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setQ(typed.trim()), 350);
    return () => clearTimeout(t);
  }, [typed]);
  const hits = useResource(() => purchasing.searchSuppliers(q), [q, scope?.cmpId]);
  const rows = hits.data ?? [];

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title="Suppliers" subtitle="Open orders and what you owe" />
      <View style={st.search}>
        <Icon name="search" size={18} color={palette.textMuted} />
        <TextInput value={typed} onChangeText={setTyped} placeholder="Supplier name" placeholderTextColor={palette.textMuted} style={st.input} autoCorrect={false} accessibilityLabel="Search suppliers" returnKeyType="search" />
      </View>
      {seat.kind === 'ready' && !can('supplier.view') ? (
        <StatePanel kind="empty" icon="ledger" title="Supplier details are not shown to you" text="Your profile in this company does not include viewing suppliers." />
      ) : (
        <FlatList
          data={rows}
          keyExtractor={(r) => String(r.acc_id)}
          contentContainerStyle={{ padding: space.l, paddingTop: space.s }}
          ListEmptyComponent={
            hits.phase === 'loading' ? (
              <StatePanel kind="loading" />
            ) : hits.phase === 'failed' ? (
              <StatePanel kind="error" problem={hits.error} onRetry={hits.reload} />
            ) : (
              <StatePanel kind="empty" icon="ledger" title={q ? `No supplier matches "${q}"` : 'No suppliers yet'} text="Suppliers are the creditor ledgers in this company's books." />
            )
          }
          renderItem={({ item }) => {
            const status = item.procurement_profile?.qualification_status;
            const q2 = status ? QUALIFICATION[status] : null;
            return (
              <Docket onPress={() => router.push({ pathname: '/supplier/[id]', params: { id: String(item.acc_id), name: item.acc_name } })} accessibilityLabel={`Supplier ${item.acc_name}`}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: space.s }}>
                  <T v="bodyStrong" style={{ flex: 1 }} numberOfLines={1}>
                    {item.acc_name}
                  </T>
                  {q2 ? <Stamp label={q2.label} tone={q2.tone} /> : null}
                </View>
                {item.gstin ? (
                  <T v="small" color={palette.textSoft}>
                    GSTIN {item.gstin}
                  </T>
                ) : null}
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
  input: { flex: 1, paddingVertical: space.m, fontFamily: font.regular, fontSize: 16, color: palette.text },
});
