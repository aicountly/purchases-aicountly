jest.mock('expo-apple-authentication', () => ({ isAvailableAsync: jest.fn(async () => false), signInAsync: jest.fn(), AppleAuthenticationScope: { FULL_NAME: 0, EMAIL: 1 } }));
jest.mock('expo-web-browser', () => ({ openAuthSessionAsync: jest.fn() }));
jest.mock('expo-linking', () => ({ createURL: (p: string) => `aicountlypurchases://${p}` }));

import AsyncStorage from '@react-native-async-storage/async-storage';
import { act, render, waitFor } from '@testing-library/react-native';
import * as SecureStore from 'expo-secure-store';
import { useEffect } from 'react';
import { Text } from 'react-native';
import { forgetCredentials, resetSessionMemory, setSessionTransport, storeSignInToken } from '../core/session';
import { IdentityProvider, useIdentity } from '../identity/IdentityProvider';
import { setIdentityTransport } from '../identity/portal';

const res = (status: number, body: unknown) => ({ status, ok: status < 300, json: async () => body }) as unknown as Response;
const probe: { current: ReturnType<typeof useIdentity> | null } = { current: null };
function Probe() {
  const value = useIdentity();
  useEffect(() => {
    probe.current = value;
  });
  return <Text testID="status">{value.status}</Text>;
}
const mount = () => render(<IdentityProvider><Probe /></IdentityProvider>);

let identityCalls: string[] = [];
beforeEach(async () => {
  resetSessionMemory();
  await forgetCredentials();
  await AsyncStorage.clear();
  identityCalls = [];
  probe.current = null;
});
afterAll(() => {
  setSessionTransport(null);
  setIdentityTransport(null);
});

describe('signing in and out', () => {
  it('password sign-in stores the token only once a session key is minted', async () => {
    setIdentityTransport(async (url) => {
      identityCalls.push(String(url));
      return res(200, { success: '1', aic_auth_token: 'tok-1', full_name: 'Asha Rao' });
    });
    setSessionTransport(async () => res(200, { ses_key: 'ses' }));
    const view = mount();
    await waitFor(() => expect(view.getByTestId('status').props.children).toBe('signedOut'));
    let outcome;
    await act(async () => {
      outcome = await probe.current!.signInWithPassword('asha@rao.in', 'pw');
    });
    expect(outcome).toEqual({ ok: true });
    expect(view.getByTestId('status').props.children).toBe('signedIn');
    expect(await SecureStore.getItemAsync('purchases.signin_token')).toBe('tok-1');
    expect(probe.current!.person).toEqual({ name: 'Asha Rao', email: 'asha@rao.in' });
  });

  it('a token that cannot start a session is thrown away again', async () => {
    setIdentityTransport(async () => res(200, { success: '1', aic_auth_token: 'tok-bad' }));
    setSessionTransport(async () => res(401, {}));
    const view = mount();
    await waitFor(() => expect(view.getByTestId('status').props.children).toBe('signedOut'));
    let outcome: { ok: boolean } | undefined;
    await act(async () => {
      outcome = await probe.current!.signInWithPassword('a@b.c', 'pw');
    });
    expect(outcome!.ok).toBe(false);
    expect(await SecureStore.getItemAsync('purchases.signin_token')).toBeNull();
    expect(view.getByTestId('status').props.children).toBe('signedOut');
  });

  it('a closed account comes back as such, for the screen to offer reopening', async () => {
    setIdentityTransport(async () => res(200, { success: '0', pending_deletion: '1' }));
    const view = mount();
    await waitFor(() => expect(view.getByTestId('status').props.children).toBe('signedOut'));
    let outcome: { closed?: unknown } | undefined;
    await act(async () => {
      outcome = await probe.current!.signInWithPassword('a@b.c', 'pw');
    });
    expect(outcome!.closed).toBeTruthy();
  });

  it('opens signed in from a stored token, even offline; a refused token signs out', async () => {
    await storeSignInToken('tok-stored');
    resetSessionMemory();
    setSessionTransport(async () => {
      throw new TypeError('Network request failed');
    });
    const a = mount();
    await waitFor(() => expect(a.getByTestId('status').props.children).toBe('signedIn'));
    a.unmount();
    resetSessionMemory();
    setSessionTransport(async () => res(401, {}));
    const b = mount();
    await waitFor(() => expect(b.getByTestId('status').props.children).toBe('signedOut'));
    expect(await SecureStore.getItemAsync('purchases.signin_token')).toBeNull();
  });

  it('sign-out tells the identity service, forgets the token and clears counts saved on the phone', async () => {
    await storeSignInToken('tok-out');
    resetSessionMemory();
    await AsyncStorage.setItem('purchases.draft.88.41', '{}');
    await AsyncStorage.setItem('purchases.welcomed', '1');
    setSessionTransport(async () => res(200, { ses_key: 'ses' }));
    setIdentityTransport(async (url, init) => {
      identityCalls.push(`${String(url)} ${(init?.headers as Record<string, string>)?.Authorization}`);
      return res(200, { success: '1' });
    });
    const view = mount();
    await waitFor(() => expect(view.getByTestId('status').props.children).toBe('signedIn'));
    await act(async () => {
      await probe.current!.signOut();
    });
    expect(identityCalls).toEqual(['https://my.aicountly.com/api/logout Bearer tok-out']);
    expect(view.getByTestId('status').props.children).toBe('signedOut');
    expect(await SecureStore.getItemAsync('purchases.signin_token')).toBeNull();
    expect(await AsyncStorage.getItem('purchases.draft.88.41')).toBeNull();
    expect(await AsyncStorage.getItem('purchases.welcomed')).toBe('1');
  });
});
