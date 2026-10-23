import { Redirect, useRouter } from 'expo-router';
import { useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, View } from 'react-native';
import { messageOf } from '../core/problems';
import { useResource } from '../core/useResource';
import { useIdentity } from '../identity/IdentityProvider';
import { useScope } from '../scope/ScopeProvider';
import { fetchCompanies, fetchCompanyDetail, localToday, pickBranch, pickYear, type Company, type CompanyDetail } from '../scope/companies';
import { Icon } from '../ui/Icon';
import { Banner, Bar, Button, StatePanel, T } from '../ui/kit';
import { palette, radius, space } from '../ui/theme';

/** Choose the company (then its year and branch) to work in. */
export default function CompanyScreen() {
  const router = useRouter();
  const { status } = useIdentity();
  const { scope, choose } = useScope();
  const companies = useResource(fetchCompanies, []);
  const [opening, setOpening] = useState<number | null>(null);
  const [detail, setDetail] = useState<CompanyDetail | null>(null);
  const [fyId, setFyId] = useState<number | null>(null);
  const [boId, setBoId] = useState<number>(0);
  const [error, setError] = useState<string | null>(null);

  if (status === 'signedOut') return <Redirect href="/sign-in" />;

  const open = async (c: Company) => {
    setOpening(c.cmpId);
    setError(null);
    try {
      const d = await fetchCompanyDetail(c.cmpId);
      const year = pickYear(d.years, localToday());
      if (!year) {
        setError(`${c.name} has no financial year in AICOUNTLY yet. Its owner sets one up on the web.`);
        return;
      }
      const named = { ...d, name: d.name || c.name };
      if (d.years.length === 1 && d.branches.length <= 1) {
        await commit(named, year.fyId, pickBranch(d));
        return;
      }
      setDetail(named);
      setFyId(year.fyId);
      setBoId(pickBranch(d));
    } catch (err) {
      setError(messageOf(err));
    } finally {
      setOpening(null);
    }
  };

  const commit = async (d: CompanyDetail, fy: number, bo: number) => {
    const year = d.years.find((y) => y.fyId === fy);
    const branch = d.branches.find((b) => b.boId === bo);
    await choose({ cmpId: d.cmpId, companyName: d.name, fyId: fy, yearLabel: year?.label ?? `Year ${fy}`, boId: bo, branchName: bo === 0 ? 'All branches' : branch?.name ?? `Branch ${bo}` });
    router.replace('/desk');
  };

  if (detail && fyId) {
    return (
      <View style={{ flex: 1, backgroundColor: palette.paper }}>
        <Bar title={detail.name} subtitle="Year and branch" back={false} right={<Pressable accessibilityRole="button" onPress={() => setDetail(null)}><T v="label" color={palette.onInk}>Change</T></Pressable>} />
        <View style={{ padding: space.l }}>
          <T v="label" color={palette.textSoft}>
            Financial year
          </T>
          <View style={st.chips}>
            {detail.years.map((y) => (
              <Chip key={y.fyId} label={y.label} on={y.fyId === fyId} onPress={() => setFyId(y.fyId)} />
            ))}
          </View>
          {detail.branches.length > 1 ? (
            <>
              <T v="label" color={palette.textSoft} style={{ marginTop: space.l }}>
                Branch
              </T>
              <View style={st.chips}>
                <Chip label="All branches" on={boId === 0} onPress={() => setBoId(0)} />
                {detail.branches.map((b) => (
                  <Chip key={b.boId} label={b.name} on={b.boId === boId} onPress={() => setBoId(b.boId)} />
                ))}
              </View>
            </>
          ) : null}
          <Button label={`Open ${detail.name}`} onPress={() => void commit(detail, fyId, boId)} style={{ marginTop: space.xl }} />
        </View>
      </View>
    );
  }

  const list = companies.data ?? [];
  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title="Choose a company" subtitle="Where you approve and receive" back={!!scope} />
      {error ? (
        <View style={{ paddingHorizontal: space.l, paddingTop: space.l }}>
          <Banner tone="stop" icon="alert" text={error} />
        </View>
      ) : null}
      {companies.phase === 'loading' ? (
        <StatePanel kind="loading" text="Finding your companies…" />
      ) : companies.phase === 'failed' && list.length === 0 ? (
        <StatePanel kind="error" problem={companies.error} onRetry={companies.reload} />
      ) : (
        <FlatList
          data={list}
          keyExtractor={(c) => String(c.cmpId)}
          contentContainerStyle={{ padding: space.l }}
          refreshControl={<RefreshControl refreshing={companies.refreshing} onRefresh={companies.reload} />}
          ListEmptyComponent={
            <StatePanel kind="empty" icon="building" title="No companies yet" text="Purchases works inside a company your administrator has shared with you. Ask them to add you, then pull to refresh." />
          }
          renderItem={({ item }) => (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={item.openable ? `Open ${item.name}` : `${item.name}, no Purchases access`}
              disabled={!item.openable || opening !== null}
              onPress={() => void open(item)}
              style={({ pressed }) => [st.row, { opacity: !item.openable ? 0.55 : pressed ? 0.8 : 1 }]}
            >
              <Icon name="building" color={palette.textSoft} />
              <View style={{ flex: 1 }}>
                <T v="bodyStrong">{item.name}</T>
                {!item.openable ? (
                  <T v="small" color={palette.textMuted}>
                    No Purchases access — ask the company owner
                  </T>
                ) : null}
              </View>
              {opening === item.cmpId ? <T v="small">Opening…</T> : item.openable ? <Icon name="next" size={18} color={palette.textMuted} /> : null}
            </Pressable>
          )}
        />
      )}
    </View>
  );
}

function Chip({ label, on, onPress }: { label: string; on: boolean; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="radio" accessibilityState={{ selected: on }} onPress={onPress} style={[st.chip, on && { backgroundColor: palette.ink, borderColor: palette.ink }]}>
      <T v="small" color={on ? palette.onInk : palette.text}>
        {label}
      </T>
    </Pressable>
  );
}

const st = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space.m, backgroundColor: palette.sheet, padding: space.l, borderRadius: radius.m, borderWidth: StyleSheet.hairlineWidth, borderColor: palette.ruleStrong, marginBottom: space.s },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: space.s, marginTop: space.s },
  chip: { paddingHorizontal: space.m, paddingVertical: space.s, borderRadius: radius.s, borderWidth: 1, borderColor: palette.ruleStrong, backgroundColor: palette.sheet },
});
