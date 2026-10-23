import AsyncStorage from '@react-native-async-storage/async-storage';
import * as AppleAuthentication from 'expo-apple-authentication';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { Platform } from 'react-native';
import { client } from '../core/http';
import { currentSessionKey, forgetCredentials, readSignInToken, SessionEndedError, storeSignInToken } from '../core/session';
import { signInWithBrowser } from './browserSignIn';
import type { ClosedAccount } from './closure';
import { identity, type SignInResult } from './portal';

/**
 * Who is signed in, and every way in and out. Each sign-in method ends at `adopt()`: the token
 * is stored, a session key is minted with it, and only then is the person signed in. If the key
 * cannot be minted the token is thrown away again, so a half-finished sign-in never leaves a
 * credential behind.
 */

export type IdentityStatus = 'loading' | 'signedOut' | 'signedIn';

export interface Person {
  name?: string;
  email?: string;
}

export interface Outcome {
  ok: boolean;
  message?: string;
  closed?: ClosedAccount;
  cancelled?: boolean;
}

interface IdentityValue {
  status: IdentityStatus;
  person: Person | null;
  appleAvailable: boolean;
  signInWithPassword(email: string, password: string, reopen?: boolean): Promise<Outcome>;
  signInWithApple(reopen?: boolean): Promise<Outcome>;
  signInWithGoogle(): Promise<Outcome>;
  adoptToken(token: string, person?: Person): Promise<Outcome>;
  signOut(): Promise<void>;
  /** After the account is closed: forget everything on the phone without telling the service. */
  forgetLocally(): Promise<void>;
}

const Ctx = createContext<IdentityValue | null>(null);
const PERSON_KEY = 'purchases.person';

/** Keys that belong to the signed-in person and go when they do. */
export const PERSONAL_KEYS = [PERSON_KEY, 'purchases.scope', 'purchases.onboarded.signin'];

async function clearPersonalData() {
  const all = await AsyncStorage.getAllKeys().catch(() => [] as readonly string[]);
  const drafts = all.filter((k) => k.startsWith('purchases.draft.'));
  await AsyncStorage.multiRemove([...PERSONAL_KEYS.filter((k) => k !== 'purchases.onboarded.signin'), ...drafts]).catch(() => undefined);
}

export function IdentityProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<IdentityStatus>('loading');
  const [person, setPerson] = useState<Person | null>(null);
  const [appleAvailable, setAppleAvailable] = useState(false);
  // The last Apple credential, so "Reopen my account" does not show Apple's sheet twice.
  const [lastApple, setLastApple] = useState<{ token: string; name?: string; code?: string | null } | null>(null);

  const finishSignOut = useCallback(async () => {
    await forgetCredentials();
    await clearPersonalData();
    client.setScope(null);
    setPerson(null);
    setStatus('signedOut');
  }, []);

  useEffect(() => {
    client.onSessionEnded(() => {
      void finishSignOut();
    });
    return () => client.onSessionEnded(null);
  }, [finishSignOut]);

  useEffect(() => {
    let alive = true;
    (async () => {
      if (Platform.OS === 'ios') {
        setAppleAvailable(await AppleAuthentication.isAvailableAsync().catch(() => false));
      }
      const token = await readSignInToken();
      if (!token) {
        if (alive) setStatus('signedOut');
        return;
      }
      const saved = await AsyncStorage.getItem(PERSON_KEY).catch(() => null);
      try {
        await currentSessionKey();
      } catch (err) {
        // A refused token ends the session; an unreachable service (offline) does not.
        if (err instanceof SessionEndedError) {
          await finishSignOut();
          return;
        }
      }
      if (!alive) return;
      setPerson(saved ? (JSON.parse(saved) as Person) : null);
      setStatus('signedIn');
    })();
    return () => {
      alive = false;
    };
  }, [finishSignOut]);

  const adoptToken = useCallback(async (token: string, who?: Person): Promise<Outcome> => {
    await storeSignInToken(token);
    try {
      await currentSessionKey();
    } catch (err) {
      await forgetCredentials();
      return { ok: false, message: err instanceof Error ? err.message : 'Could not start your session. Try again.' };
    }
    const next = who && (who.name || who.email) ? who : null;
    if (next) await AsyncStorage.setItem(PERSON_KEY, JSON.stringify(next)).catch(() => undefined);
    setPerson(next);
    setStatus('signedIn');
    return { ok: true };
  }, []);

  const fromResult = useCallback(
    async (result: SignInResult, who?: Person): Promise<Outcome> => {
      if (!result.ok || !result.token) return { ok: false, message: result.message, closed: result.closed };
      return adoptToken(result.token, { name: result.name ?? who?.name, email: result.email ?? who?.email });
    },
    [adoptToken],
  );

  const signInWithPassword = useCallback(
    async (email: string, password: string, reopen = false) => fromResult(await identity.passwordSignIn(email, password, reopen), { email }),
    [fromResult],
  );

  const signInWithApple = useCallback(
    async (reopen = false): Promise<Outcome> => {
      let credential = reopen ? lastApple : null;
      if (!credential) {
        try {
          const c = await AppleAuthentication.signInAsync({
            requestedScopes: [AppleAuthentication.AppleAuthenticationScope.FULL_NAME, AppleAuthentication.AppleAuthenticationScope.EMAIL],
          });
          if (!c.identityToken) return { ok: false, message: 'Apple did not return a sign-in. Try again.' };
          const name = [c.fullName?.givenName, c.fullName?.familyName].filter(Boolean).join(' ') || undefined;
          credential = { token: c.identityToken, name, code: c.authorizationCode };
          setLastApple(credential);
        } catch (err) {
          const code = (err as { code?: string })?.code;
          if (code === 'ERR_REQUEST_CANCELED' || code === 'ERR_CANCELED') return { ok: false, cancelled: true };
          return { ok: false, message: 'Sign in with Apple did not complete. Try again.' };
        }
      }
      const result = await identity.appleSignIn(credential.token, { fullName: credential.name, authorizationCode: credential.code, reopen });
      return fromResult(result, { name: credential.name });
    },
    [fromResult, lastApple],
  );

  const signInWithGoogle = useCallback(async (): Promise<Outcome> => {
    const result = await signInWithBrowser('google');
    if (result.cancelled) return { ok: false, cancelled: true };
    return fromResult(result);
  }, [fromResult]);

  const signOut = useCallback(async () => {
    const token = await readSignInToken();
    await identity.signOut(token).catch(() => undefined);
    await finishSignOut();
  }, [finishSignOut]);

  const value = useMemo<IdentityValue>(
    () => ({ status, person, appleAvailable, signInWithPassword, signInWithApple, signInWithGoogle, adoptToken, signOut, forgetLocally: finishSignOut }),
    [status, person, appleAvailable, signInWithPassword, signInWithApple, signInWithGoogle, adoptToken, signOut, finishSignOut],
  );
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useIdentity(): IdentityValue {
  const v = useContext(Ctx);
  if (!v) throw new Error('useIdentity outside IdentityProvider');
  return v;
}
