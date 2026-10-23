jest.mock('expo-web-browser', () => ({ openAuthSessionAsync: jest.fn(), openBrowserAsync: jest.fn() }));
jest.mock('expo-linking', () => ({ createURL: (p: string) => `aicountlypurchases://${p}` }));

import { createHash } from 'crypto';
import * as WebBrowser from 'expo-web-browser';
import { readCallback, signInWithBrowser, startUrl, finishFromLink } from '../identity/browserSignIn';
import { closedAccountFrom, shortDate } from '../identity/closure';
import { base64url, challengeFor, newPkcePair } from '../identity/pkce';
import { identity, setIdentityTransport } from '../identity/portal';

type Call = { url: string; init: RequestInit };
const res = (status: number, body: unknown) => ({ status, ok: status < 300, json: async () => body }) as unknown as Response;

let calls: Call[] = [];
let answers: (Response | Error)[] = [];
beforeEach(() => {
  calls = [];
  answers = [];
  setIdentityTransport(async (url, init) => {
    calls.push({ url: String(url), init: init ?? {} });
    const next = answers.shift();
    if (!next) throw new Error('no answer queued');
    if (next instanceof Error) throw next;
    return next;
  });
});
afterAll(() => setIdentityTransport(null));
const body = (i = 0) => JSON.parse(String(calls[i].init.body));

describe('password sign-in', () => {
  it('posts LoginName / Password and returns the token', async () => {
    answers.push(res(200, { success: '1', aic_auth_token: 'tok', full_name: 'Asha Rao', email: 'asha@x.in' }));
    const r = await identity.passwordSignIn(' asha@x.in ', 'pw');
    expect(calls[0].url).toBe('https://my.aicountly.com/api/login');
    expect(body()).toEqual({ LoginName: 'asha@x.in', Password: 'pw' });
    expect(r).toMatchObject({ ok: true, token: 'tok', name: 'Asha Rao' });
  });

  it('shows the service\'s sentence for a wrong password, never a status code', async () => {
    answers.push(res(401, { success: '0', message: 'Invalid email/username or password' }));
    expect(await identity.passwordSignIn('a@b.c', 'x')).toEqual({ ok: false, message: 'Invalid email/username or password' });
    answers.push(res(500, null));
    const r = await identity.passwordSignIn('a@b.c', 'x');
    expect(r.message).not.toMatch(/500/);
  });

  it('a closed account is reported as such, and "reopen" sends reactivate', async () => {
    answers.push(res(200, { success: '0', pending_deletion: '1', deletion_purge_after: '2026-12-01 10:00:00' }));
    const r = await identity.passwordSignIn('a@b.c', 'pw');
    expect(r.closed?.message).toContain('1 Dec 2026');
    answers.push(res(200, { success: '1', aic_auth_token: 'tok2' }));
    await identity.passwordSignIn('a@b.c', 'pw', true);
    expect(body(1).reactivate).toBe(true);
  });

  it('refuses empty input without a call', async () => {
    expect((await identity.passwordSignIn('', '')).ok).toBe(false);
    expect(calls).toHaveLength(0);
  });

  it('says so when the service cannot be reached', async () => {
    answers.push(new TypeError('Network request failed'));
    expect((await identity.passwordSignIn('a@b.c', 'pw')).message).toMatch(/Could not reach/);
  });
});

describe('Sign in with Apple', () => {
  it('names this app as the audience and retries once on a 5xx', async () => {
    answers.push(res(502, null), res(200, { success: 1, aic_auth_token: 'apple-tok' }));
    const r = await identity.appleSignIn('id-token', { fullName: 'Asha Rao', authorizationCode: 'code' });
    expect(r).toMatchObject({ ok: true, token: 'apple-tok' });
    expect(calls).toHaveLength(2);
    expect(body()).toMatchObject({ provider: 'apple', id_token: 'id-token', client_id: 'com.aicountly.purchases', full_name: 'Asha Rao', authorization_code: 'code' });
  });
});

describe('browser sign-in (PKCE)', () => {
  it('S256 challenge matches Node\'s SHA-256, base64url without padding', async () => {
    const verifier = 'M25iVXpKU3puUjFaYWg3T1NDTDQtcW1ROUY5YXlwalNoc0hhakxifmZHag';
    const expected = createHash('sha256').update(verifier).digest('base64url');
    expect(await challengeFor(verifier)).toBe(expected);
    expect(base64url(new Uint8Array([251, 255]))).toBe('-_8');
    const pair = await newPkcePair();
    expect(pair.verifier).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(pair.state).not.toBe(pair.verifier);
  });

  it('asks the portal for a code with this product, scheme and challenge', () => {
    const url = startUrl('google', { state: 'st', challenge: 'ch' }, 'aicountlypurchases:///auth/callback', 'https://my.aicountly.com');
    expect(url).toBe('https://my.aicountly.com/login/social_start/google?jumpto=purchases&returnUrl=aicountlypurchases%3A%2F%2F%2Fauth%2Fcallback&state=st&response_type=code&code_challenge=ch&code_challenge_method=S256');
  });

  it('reads a callback only when its state is ours, and never accepts a bare token', () => {
    const code = 'a'.repeat(64);
    expect(readCallback(`x://auth/callback?sso_code=${code}&state=s1`, 's1')).toEqual({ kind: 'code', code });
    expect(readCallback(`x://auth/callback?sso_code=${code}&state=other`, 's1')).toEqual({ kind: 'ignored' });
    expect(readCallback('x://auth/callback?auth_token=tok&state=s1', 's1').kind).toBe('error');
    expect(readCallback('x://auth/callback?sso_error=temporarily_unavailable&state=s1', 's1')).toMatchObject({ kind: 'error', message: expect.stringMatching(/unavailable/) });
    expect(readCallback('x://auth/callback?sso_code=short&state=s1', 's1').kind).toBe('error');
    expect(readCallback(`x://auth/callback?sso_code=${code}&state=s1`, null)).toEqual({ kind: 'ignored' });
  });

  it('runs the round trip and redeems the code with its verifier', async () => {
    const code = 'b'.repeat(64);
    (WebBrowser.openAuthSessionAsync as jest.Mock).mockImplementation(async (url: string) => {
      const state = decodeURIComponent(/state=([^&]+)/.exec(url)![1]);
      return { type: 'success', url: `aicountlypurchases:///auth/callback?sso_code=${code}&state=${state}` };
    });
    answers.push(res(200, { status: 1, auth_token: 'browser-tok' }));
    const r = await signInWithBrowser('google');
    expect(r).toMatchObject({ ok: true, token: 'browser-tok' });
    expect(body()).toMatchObject({ sso_code: code, product: 'purchases', redirect_uri: 'aicountlypurchases:///auth/callback' });
    expect(body().code_verifier).toMatch(/^[A-Za-z0-9_-]{43}$/);
    // The attempt is spent: a second landing of the same link redeems nothing.
    expect((await finishFromLink(`aicountlypurchases:///auth/callback?sso_code=${code}&state=x`)).ok).toBe(false);
  });

  it('a cancelled browser is just cancelled', async () => {
    (WebBrowser.openAuthSessionAsync as jest.Mock).mockResolvedValue({ type: 'cancel' });
    expect(await signInWithBrowser('google')).toMatchObject({ ok: false, cancelled: true });
  });

  it('a spent or expired code asks for a fresh sign-in', async () => {
    answers.push(res(401, { error: 'invalid_grant' }));
    expect(await identity.redeemBrowserCode('c'.repeat(64), 'v', 'r')).toMatchObject({ ok: false, restart: true });
  });
});

describe('closing the account', () => {
  it('sends the confirmation with the sign-in token, and the company-loss acknowledgement only when given', async () => {
    answers.push(res(200, { success: '1', deletion_purge_after: '2026-12-07 00:00:00' }));
    const r = await identity.closeAccount('tok', false);
    expect(r).toEqual({ ok: true, purgeAfter: '2026-12-07 00:00:00' });
    expect(calls[0].url).toBe('https://my.aicountly.com/api/auth/account/delete');
    expect((calls[0].init.headers as Record<string, string>).Authorization).toBe('Bearer tok');
    expect(body()).toEqual({ confirm: 'DELETE' });
  });

  it('409 lists the companies still owned', async () => {
    answers.push(res(409, { success: '0', message: 'You own companies.', owned_companies: [{ name: 'Rao Traders' }] }));
    expect(await identity.closeAccount('tok', false)).toMatchObject({ ok: false, ownedCompanies: ['Rao Traders'] });
  });

  it('an older service hands off to the web page', async () => {
    answers.push(res(404, null));
    expect(await identity.closeAccount('tok', true)).toEqual({ ok: false, useWeb: true });
  });

  it('is never retried: no answer is "check before trying again"', async () => {
    answers.push(new TypeError('Network request failed'));
    const r = await identity.closeAccount('tok', false);
    expect(r).toMatchObject({ ok: false, unknown: true });
    expect(calls).toHaveLength(1);
  });

  it('reads the closure summary, or says the service predates it', async () => {
    answers.push(res(200, { success: '1', data: { account: { name: 'Asha', email: 'a@x' }, owned_companies: [{ name: 'Rao Traders' }], shared_companies_count: 2, timeline: { purge_after_days: 45 }, will_delete: ['Your sign-in'], will_keep: ['Tax records'] } }));
    expect(await identity.closureSummary('tok')).toEqual({ ok: true, summary: { name: 'Asha', email: 'a@x', ownedCompanies: ['Rao Traders'], sharedCount: 2, purgeAfterDays: 45, willDelete: ['Your sign-in'], willKeep: ['Tax records'] } });
    answers.push(res(405, null));
    expect(await identity.closureSummary('tok')).toEqual({ ok: false, unsupported: true });
  });

  it('recognises every way the service says an account is closed', () => {
    expect(closedAccountFrom({ pending_deletion: 1 })).not.toBeNull();
    expect(closedAccountFrom({ code: 'account_suspended_pending_deletion' })).not.toBeNull();
    expect(closedAccountFrom({ phase: 'suspended' })).not.toBeNull();
    expect(closedAccountFrom({ success: '0', message: 'Invalid' })).toBeNull();
    expect(shortDate('2026-11-13 00:00:00')).toBe('13 Nov 2026');
    expect(shortDate('garbage')).toBe('');
  });
});
