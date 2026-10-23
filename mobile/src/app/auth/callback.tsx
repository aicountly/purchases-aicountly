import { useLocalSearchParams, useRouter } from 'expo-router';
import * as Linking from 'expo-linking';
import { useEffect, useState } from 'react';
import { View } from 'react-native';
import { finishFromLink } from '../../identity/browserSignIn';
import { useIdentity } from '../../identity/IdentityProvider';
import { Button, StatePanel, T } from '../../ui/kit';
import { palette, space } from '../../ui/theme';

/**
 * Where the browser sign-in lands when the OS had to start the app for it. The in-app round
 * trip normally finishes before this screen is reached; then there is nothing pending and this
 * screen simply moves on.
 */
export default function BrowserCallback() {
  const router = useRouter();
  const params = useLocalSearchParams<{ sso_code?: string; state?: string; sso_error?: string }>();
  const { status, adoptToken } = useIdentity();
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (status === 'loading') return;
    if (status === 'signedIn') {
      router.replace('/');
      return;
    }
    const query = Object.entries(params)
      .filter(([, v]) => typeof v === 'string')
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
      .join('&');
    (async () => {
      const result = await finishFromLink(`${Linking.createURL('/auth/callback')}?${query}`);
      if (result.ok && result.token) {
        const outcome = await adoptToken(result.token);
        if (outcome.ok) return router.replace('/');
        return setMessage(outcome.message ?? 'Sign-in did not complete.');
      }
      setMessage(result.message ?? 'Sign-in did not complete.');
    })();
    // Run once per arrival.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status]);

  if (!message) return <StatePanel kind="loading" text="Finishing sign-in…" />;
  return (
    <View style={{ flex: 1, backgroundColor: palette.paper, padding: space.xl, justifyContent: 'center' }}>
      <T v="title">Sign-in did not finish</T>
      <T v="body" color={palette.textSoft} style={{ marginTop: space.s }}>
        {message}
      </T>
      <Button label="Back to sign in" onPress={() => router.replace('/sign-in')} style={{ marginTop: space.xl }} />
    </View>
  );
}
