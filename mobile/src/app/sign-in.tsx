import * as AppleAuthentication from 'expo-apple-authentication';
import { useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { IDENTITY_ORIGIN, PRIVACY_URL, TERMS_URL } from '../config/env';
import { useIdentity, type Outcome } from '../identity/IdentityProvider';
import { useOnline } from '../net/NetworkProvider';
import { Icon } from '../ui/Icon';
import { Banner, Button, Docket, Field, T } from '../ui/kit';
import { palette, space } from '../ui/theme';

type Method = 'password' | 'apple' | 'google';

export default function SignIn() {
  const router = useRouter();
  const online = useOnline();
  const { signInWithPassword, signInWithApple, signInWithGoogle, appleAvailable } = useIdentity();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState<Method | null>(null);
  const [error, setError] = useState<string | null>(null);

  const run = async (method: Method, attempt: (reopen: boolean) => Promise<Outcome>, reopen = false) => {
    setBusy(method);
    setError(null);
    try {
      const outcome = await attempt(reopen);
      if (outcome.ok) return router.replace('/');
      if (outcome.cancelled) return;
      if (outcome.closed) {
        Alert.alert('This account is closed', outcome.closed.message, [
          { text: 'Not now', style: 'cancel' },
          { text: 'Reopen my account', onPress: () => void run(method, attempt, true) },
        ]);
        return;
      }
      setError(outcome.message ?? 'Sign-in did not complete. Try again.');
    } finally {
      setBusy(null);
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: palette.paper }}>
      <SafeAreaView edges={['top']} style={{ backgroundColor: palette.ink }}>
        <View style={st.hero}>
          <View style={st.mark}>
            <Icon name="stamp" size={26} color={palette.white} />
          </View>
          <T v="display" color={palette.onInk} style={{ marginTop: space.l }}>
            Purchases
          </T>
          <T v="body" color={palette.onInkMuted}>
            Approvals and receiving, wherever the work is.
          </T>
        </View>
      </SafeAreaView>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={{ padding: space.l, paddingBottom: space.xxl }} keyboardShouldPersistTaps="handled">
          {!online ? <Banner tone="go" icon="offline" text="You're offline. Signing in needs a connection." /> : null}
          <Docket>
            <T v="label" color={palette.textSoft} style={{ marginBottom: space.m }}>
              Sign in with your AICOUNTLY account
            </T>
            <Field label="Email" value={email} onChangeText={setEmail} autoCapitalize="none" autoComplete="email" keyboardType="email-address" textContentType="username" placeholder="you@company.com" testID="email" />
            <Field label="Password" value={password} onChangeText={setPassword} secureTextEntry autoComplete="password" textContentType="password" placeholder="Your password" testID="password" />
            {error ? <Banner tone="stop" icon="alert" text={error} /> : null}
            <Button label="Sign in" busy={busy === 'password'} disabled={!online || busy !== null} onPress={() => run('password', (reopen) => signInWithPassword(email, password, reopen))} testID="sign-in" />
            <Pressable accessibilityRole="link" onPress={() => void WebBrowser.openBrowserAsync(`${IDENTITY_ORIGIN}/forgot_password`)} style={{ alignSelf: 'center', marginTop: space.m }}>
              <T v="small" color={palette.plumDeep}>
                Forgot your password?
              </T>
            </Pressable>
          </Docket>

          <View style={st.or}>
            <View style={st.orLine} />
            <T v="label" color={palette.textMuted}>
              or
            </T>
            <View style={st.orLine} />
          </View>

          {Platform.OS === 'ios' && appleAvailable ? (
            <AppleAuthentication.AppleAuthenticationButton
              buttonType={AppleAuthentication.AppleAuthenticationButtonType.SIGN_IN}
              buttonStyle={AppleAuthentication.AppleAuthenticationButtonStyle.BLACK}
              cornerRadius={6}
              style={{ height: 48, marginBottom: space.m }}
              onPress={() => {
                if (busy || !online) return;
                void run('apple', (reopen) => signInWithApple(reopen));
              }}
            />
          ) : null}
          <Button kind="quiet" label="Continue with Google" busy={busy === 'google'} disabled={!online || busy !== null} onPress={() => run('google', () => signInWithGoogle())} />

          <T v="small" color={palette.textSoft} style={{ marginTop: space.xl }}>
            Purchases opens the companies your administrator has given you access to. New to AICOUNTLY? Ask your company administrator to add you.
          </T>
          <View style={{ flexDirection: 'row', gap: space.l, marginTop: space.m }}>
            <Pressable accessibilityRole="link" onPress={() => void WebBrowser.openBrowserAsync(PRIVACY_URL)}>
              <T v="small" color={palette.plumDeep}>
                Privacy policy
              </T>
            </Pressable>
            <Pressable accessibilityRole="link" onPress={() => void WebBrowser.openBrowserAsync(TERMS_URL)}>
              <T v="small" color={palette.plumDeep}>
                Terms
              </T>
            </Pressable>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const st = StyleSheet.create({
  hero: { paddingHorizontal: space.xl, paddingTop: space.xl, paddingBottom: space.xxl },
  mark: { width: 48, height: 48, backgroundColor: palette.plum, alignItems: 'center', justifyContent: 'center', borderRadius: 4 },
  or: { flexDirection: 'row', alignItems: 'center', gap: space.m, marginVertical: space.l },
  orLine: { flex: 1, height: StyleSheet.hairlineWidth, backgroundColor: palette.ruleStrong },
});
