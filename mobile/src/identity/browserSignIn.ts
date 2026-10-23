import * as Linking from 'expo-linking';
import * as SecureStore from 'expo-secure-store';
import * as WebBrowser from 'expo-web-browser';
import { ENV, PRODUCT_KEY } from '../config/env';
import { newPkcePair } from './pkce';
import { identity, type SignInResult } from './portal';

/**
 * Google sign-in, run by the portal in the system browser (no Google client id lives in this
 * app). The portal returns `?sso_code=…&state=…` to this app's scheme; the code is redeemed with
 * the PKCE verifier. A return carrying a bare `auth_token` is refused: this app always sends a
 * challenge, so a token in a URL means something other than the portal produced it.
 *
 * The pending attempt is kept in SecureStore for ten minutes so a cold start on the callback
 * link (the OS killed the app while the browser was open) can still finish it.
 */

const PENDING_KEY = 'purchases.browser_signin';
const PENDING_TTL_MS = 10 * 60 * 1000;

interface Pending {
  state: string;
  verifier: string;
  redirectUri: string;
  at: number;
}

export type CallbackReading =
  | { kind: 'code'; code: string }
  | { kind: 'error'; message: string }
  | { kind: 'ignored' };

/** What a callback URL says, checked against the state this app sent. Pure. */
export function readCallback(url: string, expectedState: string | null): CallbackReading {
  const query = url.includes('?') ? url.slice(url.indexOf('?') + 1).split('#')[0] : '';
  const params = new Map<string, string>();
  for (const part of query.split('&')) {
    if (!part) continue;
    const [k, v = ''] = part.split('=');
    try {
      params.set(decodeURIComponent(k), decodeURIComponent(v.replace(/\+/g, ' ')));
    } catch {
      params.set(k, v);
    }
  }
  const state = params.get('state') ?? '';
  // Anything in a reply with the wrong state is not ours to act on.
  if (!expectedState || state !== expectedState) return { kind: 'ignored' };
  if (params.has('auth_token') && !params.has('sso_code')) {
    return { kind: 'error', message: 'The sign-in came back in a form this app does not accept. Try again, or use your email and password.' };
  }
  const error = params.get('sso_error');
  if (error) {
    return {
      kind: 'error',
      message: error === 'temporarily_unavailable' ? 'Google sign-in is unavailable right now. Try again in a moment.' : 'Google sign-in did not complete. Try again.',
    };
  }
  const code = params.get('sso_code') ?? '';
  return /^[A-Za-z0-9_-]{16,256}$/.test(code) ? { kind: 'code', code } : { kind: 'error', message: 'Google sign-in did not complete. Try again.' };
}

export function startUrl(provider: 'google', pair: { state: string; challenge: string }, redirectUri: string, portal = ENV.portalLogin): string {
  const q = [
    ['jumpto', PRODUCT_KEY],
    ['returnUrl', redirectUri],
    ['state', pair.state],
    ['response_type', 'code'],
    ['code_challenge', pair.challenge],
    ['code_challenge_method', 'S256'],
  ]
    .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
    .join('&');
  return `${portal}/login/social_start/${provider}?${q}`;
}

async function savePending(p: Pending) {
  await SecureStore.setItemAsync(PENDING_KEY, JSON.stringify(p));
}

async function takePending(): Promise<Pending | null> {
  try {
    const raw = await SecureStore.getItemAsync(PENDING_KEY);
    await SecureStore.deleteItemAsync(PENDING_KEY);
    if (!raw) return null;
    const p = JSON.parse(raw) as Pending;
    return Date.now() - p.at < PENDING_TTL_MS ? p : null;
  } catch {
    return null;
  }
}

async function redeem(url: string, pending: Pending | null): Promise<SignInResult> {
  const reading = readCallback(url, pending?.state ?? null);
  if (reading.kind === 'ignored' || !pending) return { ok: false, message: 'That sign-in was started somewhere else. Start it again from this screen.' };
  if (reading.kind === 'error') return { ok: false, message: reading.message };
  return identity.redeemBrowserCode(reading.code, pending.verifier, pending.redirectUri);
}

/** The whole browser round trip. Resolves with a token, a sentence, or `cancelled`. */
export async function signInWithBrowser(provider: 'google'): Promise<SignInResult & { cancelled?: boolean }> {
  const pair = await newPkcePair();
  const redirectUri = Linking.createURL('/auth/callback');
  await savePending({ state: pair.state, verifier: pair.verifier, redirectUri, at: Date.now() });
  const result = await WebBrowser.openAuthSessionAsync(startUrl(provider, pair, redirectUri), redirectUri);
  if (result.type !== 'success') {
    await takePending();
    return { ok: false, cancelled: true };
  }
  return redeem(result.url, await takePending());
}

/** The cold-start landing (app/auth/callback): finish an attempt the OS interrupted. */
export async function finishFromLink(url: string): Promise<SignInResult> {
  return redeem(url, await takePending());
}
