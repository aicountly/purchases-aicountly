import { Redirect, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Alert, Linking, Pressable, StyleSheet, View } from 'react-native';
import Constants from 'expo-constants';
import { PRIVACY_URL, SUPPORT_EMAIL, TERMS_URL, webAppUrl } from '../config/env';
import { onTheWeb } from '../config/mobileScope';
import { useIdentity } from '../identity/IdentityProvider';
import { useScope } from '../scope/ScopeProvider';
import { Icon, type IconName } from '../ui/Icon';
import { Bar, Docket, Page, Rule, T } from '../ui/kit';
import { palette, space } from '../ui/theme';

/** Who is signed in, where, what stays on the web, and the ways out. */
export default function Account() {
  const router = useRouter();
  const { status, person, signOut } = useIdentity();
  const { scope, seat } = useScope();
  if (status === 'signedOut') return <Redirect href="/sign-in" />;

  const name = (seat.kind === 'ready' && seat.seat.displayName) || person?.name || person?.email || 'Signed in';

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title="Account" />
      <Page>
        <Docket>
          <T v="heading">{name}</T>
          {person?.email && person.email !== name ? (
            <T v="small" color={palette.textSoft}>
              {person.email}
            </T>
          ) : null}
          {scope ? (
            <>
              <Rule />
              <T v="label" color={palette.textSoft}>
                Working in
              </T>
              <T v="bodyStrong">{scope.companyName}</T>
              <T v="small" color={palette.textSoft}>
                {scope.yearLabel} · {scope.branchName}
                {seat.kind === 'ready' && seat.seat.isOwner ? ' · owner' : ''}
              </T>
            </>
          ) : null}
        </Docket>

        <Row icon="building" label="Change company, year or branch" onPress={() => router.push('/company')} />

        <T v="label" color={palette.textSoft} style={{ marginTop: space.l, marginBottom: space.s }}>
          On the web
        </T>
        <Docket>
          <T v="small" color={palette.textSoft} style={{ marginBottom: space.s }}>
            This app is for approving, receiving and looking things up. These stay on the web, where there is room for them:
          </T>
          {onTheWeb.map((m) => (
            <View key={m.module} style={{ flexDirection: 'row', gap: space.s, marginTop: space.xs }}>
              <T v="small" color={palette.signalDeep}>
                ▪
              </T>
              <T v="small" style={{ flex: 1 }}>
                {m.module}
              </T>
            </View>
          ))}
          <Pressable accessibilityRole="link" onPress={() => void WebBrowser.openBrowserAsync(webAppUrl())} style={{ marginTop: space.m }}>
            <T v="bodyStrong" color={palette.signalDeep}>
              Open Purchases on the web
            </T>
          </Pressable>
        </Docket>

        <T v="label" color={palette.textSoft} style={{ marginTop: space.l, marginBottom: space.s }}>
          Help and policies
        </T>
        <Row icon="person" label={`Write to ${SUPPORT_EMAIL}`} onPress={() => void Linking.openURL(`mailto:${SUPPORT_EMAIL}?subject=${encodeURIComponent('Purchases app')}`)} />
        <Row icon="ledger" label="Privacy policy" onPress={() => void WebBrowser.openBrowserAsync(PRIVACY_URL)} />
        <Row icon="ledger" label="Terms" onPress={() => void WebBrowser.openBrowserAsync(TERMS_URL)} />

        <T v="label" color={palette.textSoft} style={{ marginTop: space.l, marginBottom: space.s }}>
          Sign out
        </T>
        <Row
          icon="exit"
          label="Sign out of this phone"
          onPress={() =>
            Alert.alert('Sign out?', 'Counts saved on this phone that were not recorded are cleared.', [
              { text: 'Cancel', style: 'cancel' },
              { text: 'Sign out', style: 'destructive', onPress: () => void signOut().then(() => router.replace('/sign-in')) },
            ])
          }
        />
        <Row icon="bin" label="Delete my AICOUNTLY account" danger onPress={() => router.push('/delete-account')} />
        <T v="small" color={palette.textMuted} style={{ marginTop: space.l, textAlign: 'center' }}>
          Purchases {Constants.expoConfig?.version ?? ''}
        </T>
      </Page>
    </View>
  );
}

function Row({ icon, label, onPress, danger }: { icon: IconName; label: string; onPress: () => void; danger?: boolean }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={onPress} style={({ pressed }) => [st.row, { opacity: pressed ? 0.75 : 1 }]}>
      <Icon name={icon} size={20} color={danger ? palette.reject : palette.textSoft} />
      <T v="body" color={danger ? palette.reject : palette.text} style={{ flex: 1 }}>
        {label}
      </T>
      <Icon name="next" size={16} color={palette.textMuted} />
    </Pressable>
  );
}

const st = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space.m, backgroundColor: palette.sheet, paddingVertical: space.m, paddingHorizontal: space.l, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: palette.rule },
});
