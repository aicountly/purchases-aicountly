import { Redirect, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, Pressable, View } from 'react-native';
import { ACCOUNT_DELETION_PAGE } from '../config/env';
import { readSignInToken } from '../core/session';
import { useResource } from '../core/useResource';
import { CLOSURE_TERMS, shortDate } from '../identity/closure';
import { identity } from '../identity/portal';
import { useIdentity } from '../identity/IdentityProvider';
import { useOnline } from '../net/NetworkProvider';
import { Icon } from '../ui/Icon';
import { Banner, Bar, Button, Docket, Field, Page, StatePanel, T } from '../ui/kit';
import { palette, space } from '../ui/theme';

const CONFIRM_WORD = 'CLOSE';

/**
 * Close the AICOUNTLY account from the app (App Store 5.1.1(v)). The identity service closes it at
 * once and erases it after 45 days; signing in again within that time can reopen it. When the
 * service cannot do it from the app, the web page that finishes the job is offered instead.
 */
export default function DeleteAccount() {
  const router = useRouter();
  const online = useOnline();
  const { status, forgetLocally } = useIdentity();
  const summary = useResource(async () => {
    const token = await readSignInToken();
    if (!token) return { ok: false, unsupported: false, message: 'You are signed out.' } as Awaited<ReturnType<typeof identity.closureSummary>>;
    return identity.closureSummary(token);
  }, []);
  const [ownedOk, setOwnedOk] = useState(false);
  const [typed, setTyped] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [useWeb, setUseWeb] = useState(false);
  const [owned, setOwned] = useState<string[]>([]);

  if (status === 'signedOut') return <Redirect href="/sign-in" />;

  const s = summary.data?.summary;
  const ownedCompanies = owned.length ? owned : s?.ownedCompanies ?? [];
  const ready = typed.trim().toUpperCase() === CONFIRM_WORD && (ownedCompanies.length === 0 || ownedOk) && online && !busy;

  const close = async () => {
    setBusy(true);
    setError(null);
    try {
      const token = await readSignInToken();
      if (!token) return;
      const result = await identity.closeAccount(token, ownedOk);
      if (result.ok) {
        const until = shortDate(result.purgeAfter);
        Alert.alert(
          'Your account is closed',
          `You have been signed out everywhere. You can reopen it by signing in again ${until ? `until ${until}` : 'within 45 days'}; after that it and its data are erased.`,
          [{ text: 'OK', onPress: () => void forgetLocally().then(() => router.replace('/sign-in')) }],
        );
        return;
      }
      if (result.useWeb) return setUseWeb(true);
      if (result.ownedCompanies?.length) {
        setOwned(result.ownedCompanies);
        setOwnedOk(false);
      }
      setError(result.message ?? 'Your account could not be closed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <Bar title="Delete my account" />
      {summary.phase === 'loading' ? (
        <StatePanel kind="loading" />
      ) : (
        <Page>
          {!online ? <Banner tone="go" icon="offline" text="You're offline. Closing an account needs a connection." /> : null}
          <Docket>
            <Icon name="alert" size={28} color={palette.reject} />
            <T v="title" style={{ marginTop: space.s }}>
              Delete your AICOUNTLY account
            </T>
            <T v="body" color={palette.textSoft} style={{ marginTop: space.s }}>
              {CLOSURE_TERMS}
            </T>
            <T v="small" color={palette.textSoft} style={{ marginTop: space.s }}>
              This closes your whole AICOUNTLY sign-in, not only this app. Purchase orders and receipts you recorded stay with the companies they belong to.
            </T>
          </Docket>
          {s?.willDelete.length ? (
            <Docket>
              <T v="label" color={palette.textSoft}>
                Erased
              </T>
              {s.willDelete.map((w) => (
                <T key={w} v="small">
                  ▪ {w}
                </T>
              ))}
              {s.willKeep.length ? (
                <T v="label" color={palette.textSoft} style={{ marginTop: space.m }}>
                  Kept, as the law requires
                </T>
              ) : null}
              {s.willKeep.map((w) => (
                <T key={w} v="small">
                  ▪ {w}
                </T>
              ))}
            </Docket>
          ) : null}
          {ownedCompanies.length ? (
            <Docket>
              <T v="bodyStrong">You own {ownedCompanies.length === 1 ? 'a company' : `${ownedCompanies.length} companies`}</T>
              {ownedCompanies.map((c) => (
                <T key={c} v="small">
                  ▪ {c}
                </T>
              ))}
              <T v="small" color={palette.textSoft} style={{ marginTop: space.s }}>
                Closing your account closes them too, for everyone in them, unless you hand them over first on the web.
              </T>
              <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: ownedOk }} onPress={() => setOwnedOk(!ownedOk)} style={{ flexDirection: 'row', gap: space.s, marginTop: space.m, alignItems: 'center' }}>
                <View style={{ width: 22, height: 22, borderWidth: 2, borderColor: palette.ink, alignItems: 'center', justifyContent: 'center', backgroundColor: ownedOk ? palette.ink : palette.sheet }}>
                  {ownedOk ? <Icon name="check" size={14} color={palette.white} strokeWidth={3} /> : null}
                </View>
                <T v="small" style={{ flex: 1 }}>
                  I understand these companies close with my account.
                </T>
              </Pressable>
            </Docket>
          ) : null}
          {useWeb ? (
            <Docket>
              <T v="body">Your account can&rsquo;t be closed from the app right now. Finish it on the web page, which does the same thing.</T>
              <Button label="Open the account page" kind="ink" onPress={() => void WebBrowser.openBrowserAsync(ACCOUNT_DELETION_PAGE)} style={{ marginTop: space.m }} />
            </Docket>
          ) : (
            <>
              {error ? <Banner tone="stop" icon="alert" text={error} /> : null}
              <Field label={`Type ${CONFIRM_WORD} to confirm`} value={typed} onChangeText={setTyped} autoCapitalize="characters" autoCorrect={false} testID="confirm-word" />
              <Button
                label="Delete my account"
                kind="reject"
                busy={busy}
                disabled={!ready}
                onPress={() =>
                  Alert.alert('Delete your account?', CLOSURE_TERMS, [
                    { text: 'Keep it', style: 'cancel' },
                    { text: 'Delete account', style: 'destructive', onPress: () => void close() },
                  ])
                }
                testID="close-account"
              />
            </>
          )}
        </Page>
      )}
    </View>
  );
}
