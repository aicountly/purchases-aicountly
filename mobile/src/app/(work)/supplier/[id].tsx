import { useLocalSearchParams, useRouter } from 'expo-router';
import { RefreshControl, StyleSheet, View } from 'react-native';
import { useResource } from '../../../core/useResource';
import { purchasing } from '../../../purchasing/api';
import { day, money } from '../../../purchasing/format';
import { statusLabel } from '../../../purchasing/status';
import { Banner, Bar, Docket, KeyValue, Page, Rule, Stamp, StatePanel, T } from '../../../ui/kit';
import { palette, space } from '../../../ui/theme';

/** One supplier: their standing, the orders still open with them, and what Smart Books says is owed. */
export default function SupplierScreen() {
  const { id, name } = useLocalSearchParams<{ id: string; name?: string }>();
  const router = useRouter();
  const position = useResource(() => purchasing.supplierPosition(Number(id)), [id]);
  const p = position.data;
  const title = p?.supplier_name ?? name ?? 'Supplier';

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title={title} subtitle="Supplier" />
      {position.phase === 'loading' && !p ? (
        <StatePanel kind="loading" />
      ) : position.phase === 'failed' && !p ? (
        <StatePanel kind="error" problem={position.error} onRetry={position.reload} />
      ) : p ? (
        <Page refresh={<RefreshControl refreshing={position.refreshing} onRefresh={position.reload} />}>
          {position.phase === 'failed' ? <Banner tone="late" icon="offline" text={`Showing what was loaded earlier. ${position.error.message}`} /> : null}
          <Docket>
            <T v="label" color={palette.textSoft}>
              Owed to {title}
            </T>
            {p.dues.available ? (
              <>
                <T v="figure" style={{ marginTop: space.s }}>
                  {money(p.dues.net_payable)}
                </T>
                {Number(p.dues.overdue) > 0 ? (
                  <T v="bodyStrong" color={palette.late}>
                    {money(p.dues.overdue)} overdue
                  </T>
                ) : (
                  <T v="small" color={palette.approve}>
                    Nothing overdue
                  </T>
                )}
                <T v="small" color={palette.textMuted} style={{ marginTop: space.s }}>
                  {p.dues.basis}
                </T>
                {p.dues.bills.length > 0 ? <Rule /> : null}
                {p.dues.bills.map((b, i) => (
                  <View key={`${b.bill_ref}-${i}`} style={st.bill}>
                    <View style={{ flex: 1 }}>
                      <T v="docNo">{b.bill_ref ?? 'Bill'}</T>
                      <T v="small" color={b.days_overdue && b.days_overdue > 0 ? palette.late : palette.textSoft}>
                        {b.due_date ? (b.days_overdue && b.days_overdue > 0 ? `${b.days_overdue} days overdue` : `Due ${day(b.due_date)}`) : 'No due date in the books'}
                      </T>
                    </View>
                    <T v="bodyStrong">{money(b.pending_amount)}</T>
                  </View>
                ))}
              </>
            ) : (
              <T v="small" color={palette.textSoft} style={{ marginTop: space.s }}>
                {p.dues.retryable ? `Smart Books did not answer: ${p.dues.reason} Pull down to try again.` : 'Your profile does not show balances owed to suppliers.'}
              </T>
            )}
          </Docket>

          {p.profile ? (
            <Docket>
              <T v="label" color={palette.textSoft}>
                Standing in Purchases
              </T>
              <KeyValue k="Qualification" v={p.profile.qualification_status.replace(/_/g, ' ')} />
              <KeyValue k="Preferred" v={p.profile.is_preferred ? 'Yes' : 'No'} />
              {p.profile.operational_lead_days !== null ? <KeyValue k="Lead time" v={`${p.profile.operational_lead_days} days`} /> : null}
              {p.profile.payment_terms ? <KeyValue k="Payment terms" v={p.profile.payment_terms} /> : null}
              {p.profile.risk_flag ? <KeyValue k="Risk" v={p.profile.risk_flag} /> : null}
            </Docket>
          ) : null}

          <T v="label" color={palette.textSoft} style={{ marginVertical: space.s }}>
            {p.open_orders === null ? 'Open orders' : `Open orders (${p.open_order_count ?? p.open_orders.length})`}
          </T>
          {p.open_orders === null ? (
            <T v="small" color={palette.textMuted}>
              Your profile does not show purchase orders.
            </T>
          ) : p.open_orders.length === 0 ? (
            <StatePanel kind="empty" icon="check" title="No open orders" text="Nothing is on order with this supplier in this year." />
          ) : (
            p.open_orders.map((o) => {
              const s = statusLabel(o.status);
              return (
                <Docket key={o.po_id} onPress={() => router.push({ pathname: '/order/[id]', params: { id: String(o.po_id) } })} accessibilityLabel={`Order ${o.po_no}`}>
                  <View style={st.between}>
                    <T v="docNo">{o.po_no}</T>
                    <Stamp label={o.overdue ? 'Overdue' : s.label} tone={o.overdue ? 'late' : s.tone} />
                  </View>
                  <View style={st.between}>
                    <T v="small" color={palette.textSoft}>
                      {o.promised_date ? `Promised ${day(o.promised_date)}` : `Ordered ${day(o.po_date)}`}
                    </T>
                    <T v="small">{o.total_amount !== null ? money(o.total_amount, o.currency_code) : ''}</T>
                  </View>
                </Docket>
              );
            })
          )}
        </Page>
      ) : null}
    </View>
  );
}

const st = StyleSheet.create({
  between: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 2 },
  bill: { flexDirection: 'row', alignItems: 'center', paddingVertical: space.s, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: palette.rule },
});
