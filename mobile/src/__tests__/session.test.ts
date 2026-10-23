import * as SecureStore from 'expo-secure-store';
import {
  currentSessionKey,
  forgetCredentials,
  IdentityUnreachableError,
  readSignInToken,
  resetSessionMemory,
  SessionEndedError,
  setSessionTransport,
  storeSignInToken,
} from '../core/session';

const json = (status: number, body: unknown) => ({ ok: status >= 200 && status < 300, status, json: async () => body }) as unknown as Response;

describe('session keys', () => {
  beforeEach(async () => {
    resetSessionMemory();
    await forgetCredentials();
  });
  afterAll(() => setSessionTransport(null));

  it('keeps the sign-in token in SecureStore and the session key only in memory', async () => {
    const calls: string[] = [];
    setSessionTransport(async (url, init) => {
      calls.push(`${String(url)} ${(init?.headers as Record<string, string>).Authorization}`);
      return json(200, { ses_key: 'ses-1', expires_in: 900 });
    });
    await storeSignInToken('tok-A');
    expect(await SecureStore.getItemAsync('purchases.signin_token')).toBe('tok-A');
    expect(await currentSessionKey()).toBe('ses-1');
    expect(await currentSessionKey()).toBe('ses-1');
    expect(calls).toEqual(['https://my.aicountly.com/api/seskey Bearer tok-A']);
    const stored = Array.from(((SecureStore as unknown as { __store: Map<string, string> }).__store).values());
    expect(stored).not.toContain('ses-1');
  });

  it('mints once for concurrent callers', async () => {
    let n = 0;
    setSessionTransport(async () => {
      n += 1;
      return json(200, { ses_key: `ses-${n}` });
    });
    await storeSignInToken('tok');
    const [a, b, c] = await Promise.all([currentSessionKey(), currentSessionKey(), currentSessionKey()]);
    expect([a, b, c]).toEqual(['ses-1', 'ses-1', 'ses-1']);
    expect(n).toBe(1);
  });

  it('refreshes at /api/seskey/refresh, falling back to a fresh mint when refused', async () => {
    const paths: string[] = [];
    setSessionTransport(async (url) => {
      const path = String(url).replace('https://my.aicountly.com', '');
      paths.push(path);
      if (path === '/api/seskey/refresh') return json(401, {});
      return json(200, { ses_key: `k${paths.length}` });
    });
    await storeSignInToken('tok');
    await currentSessionKey();
    expect(await currentSessionKey(true)).toBe('k3');
    expect(paths).toEqual(['/api/seskey', '/api/seskey/refresh', '/api/seskey']);
  });

  it('a refused token ends the session; no answer is "unreachable", not signed out', async () => {
    await storeSignInToken('tok');
    setSessionTransport(async () => json(401, { message: 'Auth expired' }));
    await expect(currentSessionKey()).rejects.toBeInstanceOf(SessionEndedError);
    setSessionTransport(async () => {
      throw new TypeError('Network request failed');
    });
    await expect(currentSessionKey()).rejects.toBeInstanceOf(IdentityUnreachableError);
    setSessionTransport(async () => json(503, {}));
    await expect(currentSessionKey()).rejects.toBeInstanceOf(IdentityUnreachableError);
  });

  it('no token means signed out', async () => {
    await expect(currentSessionKey()).rejects.toBeInstanceOf(SessionEndedError);
    expect(await readSignInToken()).toBeNull();
  });

  it('a key minted for a session that signed out meanwhile is dropped', async () => {
    let release: (r: Response) => void = () => undefined;
    setSessionTransport(() => new Promise<Response>((resolve) => (release = resolve)));
    await storeSignInToken('tok');
    const pending = currentSessionKey();
    await forgetCredentials();
    release(json(200, { ses_key: 'late' }));
    await expect(pending).rejects.toBeInstanceOf(SessionEndedError);
  });
});
