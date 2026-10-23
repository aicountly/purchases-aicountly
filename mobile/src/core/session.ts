import * as SecureStore from 'expo-secure-store';
import { IDENTITY_ORIGIN } from '../config/env';

/**
 * The two credentials, kept apart on purpose:
 *
 *   sign-in token   long-lived, from the identity service; SecureStore (Keychain / Keystore)
 *   session key     ~15 minutes, minted from the token at /api/seskey; memory only
 *
 * A generation counter guards the race where a mint started before sign-out lands after it:
 * a key minted for an older generation is dropped instead of reviving a signed-out session.
 */

const TOKEN_KEY = 'purchases.signin_token';
const SAFETY_MARGIN_MS = 60_000;
const MINT_TIMEOUT_MS = 15_000;

let sessionKey: { value: string; expiresAt: number } | null = null;
let generation = 0;
let cachedToken: string | null | undefined;

export async function readSignInToken(): Promise<string | null> {
  if (cachedToken !== undefined) return cachedToken;
  try {
    cachedToken = (await SecureStore.getItemAsync(TOKEN_KEY)) ?? null;
  } catch {
    cachedToken = null;
  }
  return cachedToken;
}

export async function storeSignInToken(token: string): Promise<void> {
  generation += 1;
  sessionKey = null;
  cachedToken = token;
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function forgetCredentials(): Promise<void> {
  generation += 1;
  sessionKey = null;
  cachedToken = null;
  try {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
  } catch {
    // Nothing stored is the state we wanted.
  }
}

export class SessionEndedError extends Error {
  constructor(message = 'Your sign-in has ended. Sign in again to continue.') {
    super(message);
    this.name = 'SessionEndedError';
  }
}

export class IdentityUnreachableError extends Error {
  constructor() {
    super('Could not reach AICOUNTLY sign-in. Check your connection and try again.');
    this.name = 'IdentityUnreachableError';
  }
}

type Fetch = typeof fetch;
let fetchImpl: Fetch = (...args) => fetch(...args);

/** Tests replace the transport; the app never does. */
export function setSessionTransport(next: Fetch | null): void {
  fetchImpl = next ?? ((...args) => fetch(...args));
}

async function mint(path: '/api/seskey' | '/api/seskey/refresh'): Promise<string> {
  const token = await readSignInToken();
  if (!token) throw new SessionEndedError();
  const started = generation;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), MINT_TIMEOUT_MS);
  let res: Response;
  try {
    res = await fetchImpl(`${IDENTITY_ORIGIN}${path}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      signal: controller.signal,
    });
  } catch {
    throw new IdentityUnreachableError();
  } finally {
    clearTimeout(timer);
  }
  if (res.status === 401 || res.status === 403) throw new SessionEndedError();
  if (!res.ok) throw new IdentityUnreachableError();
  const body = (await res.json().catch(() => null)) as Record<string, unknown> | null;
  const key = body?.ses_key ?? body?.sesKey;
  if (typeof key !== 'string' || key === '') throw new SessionEndedError();
  const ttl = Number(body?.expires_in ?? 900);
  if (started !== generation) throw new SessionEndedError();
  sessionKey = { value: key, expiresAt: Date.now() + (Number.isFinite(ttl) && ttl > 0 ? ttl : 900) * 1000 };
  return key;
}

let inflight: Promise<string> | null = null;

/** A live session key, minted once however many requests ask at the same moment. */
export function currentSessionKey(forceRefresh = false): Promise<string> {
  if (!forceRefresh && sessionKey && sessionKey.expiresAt - SAFETY_MARGIN_MS > Date.now()) {
    return Promise.resolve(sessionKey.value);
  }
  if (!inflight) {
    const path = forceRefresh && sessionKey ? '/api/seskey/refresh' : '/api/seskey';
    inflight = mint(path)
      .catch((err) => {
        // A refresh the identity service refuses is retried once as a fresh mint.
        if (path === '/api/seskey/refresh' && err instanceof SessionEndedError) return mint('/api/seskey');
        throw err;
      })
      .finally(() => {
        inflight = null;
      });
  }
  return inflight;
}

/** For tests only: forget the in-memory state without touching storage. */
export function resetSessionMemory(): void {
  sessionKey = null;
  cachedToken = undefined;
  inflight = null;
  generation += 1;
}
