import { APPLE_AUDIENCE, IDENTITY_ORIGIN, PRODUCT_KEY } from '../config/env';
import { closedAccountFrom, type ClosedAccount } from './closure';

/**
 * The identity service on my.aicountly.com: every call that signs a person in, out, or closes
 * their account. None of them touches the Purchases API, which only ever sees a session key.
 *
 * The service answers `success` as '1', 1 or true depending on the endpoint; `ok()` reads all
 * three. Messages it sends are shown as written (they are written for people); a reply with no
 * usable body gets this app's own sentence, never a bare status code.
 */

type Json = Record<string, unknown>;
type Fetch = typeof fetch;

let transport: Fetch = (...args) => fetch(...args);
export function setIdentityTransport(next: Fetch | null): void {
  transport = next ?? ((...args) => fetch(...args));
}

const ok = (v: unknown) => v === '1' || v === 1 || v === true;

interface Answer {
  status: number;
  data: Json | null;
  failed: boolean;
}

async function call(path: string, init: { body?: Json; method?: 'GET' | 'POST'; token?: string; retries?: number } = {}): Promise<Answer> {
  const { body, method = 'POST', token, retries = 0 } = init;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;
  let last: Answer = { status: 0, data: null, failed: true };
  for (let attempt = 0; attempt <= retries; attempt += 1) {
    try {
      const res = await transport(`${IDENTITY_ORIGIN}/api${path}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
      const data = (await res.json().catch(() => null)) as Json | null;
      last = { status: res.status, data, failed: false };
      if (res.status < 500) return last;
    } catch {
      last = { status: 0, data: null, failed: true };
    }
  }
  return last;
}

function sentence(answer: Answer, fallback: string): string {
  if (answer.failed) return 'Could not reach AICOUNTLY sign-in. Check your connection and try again.';
  const msg = typeof answer.data?.message === 'string' ? answer.data.message.trim() : '';
  if (answer.status >= 500) return 'AICOUNTLY sign-in is busy right now. Try again in a moment.';
  return msg && msg.length < 240 && !/exception|SQLSTATE|\.php/i.test(msg) ? msg : fallback;
}

export interface SignInResult {
  ok: boolean;
  token?: string;
  name?: string;
  email?: string;
  message?: string;
  closed?: ClosedAccount;
}

function signInFrom(answer: Answer, fallback: string): SignInResult {
  const token = typeof answer.data?.aic_auth_token === 'string' ? answer.data.aic_auth_token.trim() : '';
  if (!answer.failed && answer.status < 400 && ok(answer.data?.success) && token) {
    return {
      ok: true,
      token,
      name: typeof answer.data?.full_name === 'string' ? answer.data.full_name : undefined,
      email: typeof answer.data?.email === 'string' ? answer.data.email : undefined,
    };
  }
  const closed = closedAccountFrom(answer.data);
  if (closed) return { ok: false, closed, message: closed.message };
  return { ok: false, message: sentence(answer, fallback) };
}

export const identity = {
  /** POST /api/login {LoginName, Password} — email or username and password. */
  async passwordSignIn(email: string, password: string, reopen = false): Promise<SignInResult> {
    if (!email.trim() || !password) return { ok: false, message: 'Enter your email and password.' };
    const answer = await call('/login', { body: { LoginName: email.trim(), Password: password, ...(reopen ? { reactivate: true } : {}) } });
    return signInFrom(answer, 'That email and password did not match.');
  },

  /**
   * POST /api/auth/social_login — Sign in with Apple. The service verifies Apple's identity
   * token itself; the audience is this app's bundle id. One retry on a 5xx, because Apple's
   * sheet has already closed and the person would otherwise have to start over.
   */
  async appleSignIn(identityToken: string, opts: { fullName?: string; authorizationCode?: string | null; reopen?: boolean } = {}): Promise<SignInResult> {
    const answer = await call('/auth/social_login', {
      body: {
        provider: 'apple',
        id_token: identityToken,
        client_id: APPLE_AUDIENCE,
        ...(opts.fullName ? { full_name: opts.fullName } : {}),
        ...(opts.authorizationCode ? { authorization_code: opts.authorizationCode } : {}),
        ...(opts.reopen ? { reactivate: true } : {}),
      },
      retries: 1,
    });
    return signInFrom(answer, 'Sign in with Apple did not complete. Try again, or use your email and password.');
  },

  /** POST /api/sso/exchange — redeem the one-time browser code with its PKCE verifier. */
  async redeemBrowserCode(code: string, verifier: string, redirectUri: string): Promise<SignInResult & { restart?: boolean }> {
    const answer = await call('/sso/exchange', {
      body: { sso_code: code, code_verifier: verifier, redirect_uri: redirectUri, product: PRODUCT_KEY },
    });
    const token = typeof answer.data?.auth_token === 'string' ? answer.data.auth_token : typeof answer.data?.aic_auth_token === 'string' ? answer.data.aic_auth_token : '';
    if (!answer.failed && answer.status < 400 && (ok(answer.data?.status) || ok(answer.data?.success)) && token) {
      return { ok: true, token };
    }
    if (answer.status === 400 || answer.status === 401) {
      return { ok: false, restart: true, message: 'That sign-in link has expired. Start the sign-in again.' };
    }
    return { ok: false, message: sentence(answer, 'The browser sign-in did not complete. Try again.') };
  },

  /** POST /api/logout — best effort; the phone forgets its credentials either way. */
  async signOut(token: string | null): Promise<void> {
    if (!token) return;
    await call('/logout', { token });
  },

  /** GET /api/auth/account/closure-summary — what closing the account takes away. */
  async closureSummary(token: string): Promise<{ ok: boolean; unsupported?: boolean; summary?: ClosureSummary; message?: string }> {
    const answer = await call('/auth/account/closure-summary', { method: 'GET', token });
    if ([404, 405, 501].includes(answer.status)) return { ok: false, unsupported: true };
    if (answer.failed || answer.status >= 400 || !ok(answer.data?.success)) return { ok: false, message: sentence(answer, 'Could not load your account details.') };
    return { ok: true, summary: parseSummary(answer.data?.data) };
  },

  /**
   * POST /api/auth/account/delete. Never retried: a request that timed out may have closed the
   * account, and the person is told to check rather than the request being sent twice.
   */
  async closeAccount(token: string, confirmCompanyLoss: boolean): Promise<CloseAccountResult> {
    const answer = await call('/auth/account/delete', {
      token,
      body: { confirm: 'DELETE', ...(confirmCompanyLoss ? { confirm_company_loss: true } : {}) },
    });
    if ([404, 405, 501].includes(answer.status)) return { ok: false, useWeb: true };
    if (answer.status === 409) {
      const owned = Array.isArray(answer.data?.owned_companies) ? (answer.data?.owned_companies as Json[]) : [];
      return { ok: false, ownedCompanies: owned.map((c) => String(c.name ?? c.comp_name ?? c.cmp_name ?? 'A company')), message: sentence(answer, 'You still own companies.') };
    }
    if (answer.failed) return { ok: false, unknown: true, message: 'We could not confirm whether your account was closed. Sign in again to check before trying again.' };
    if (answer.status >= 400 || !ok(answer.data?.success)) return { ok: false, message: sentence(answer, 'Your account could not be closed.') };
    return {
      ok: true,
      purgeAfter: typeof answer.data?.deletion_purge_after === 'string' ? answer.data.deletion_purge_after : undefined,
    };
  },
};

export interface ClosureSummary {
  name: string;
  email: string;
  ownedCompanies: string[];
  sharedCount: number;
  purgeAfterDays: number;
  willDelete: string[];
  willKeep: string[];
}

export interface CloseAccountResult {
  ok: boolean;
  useWeb?: boolean;
  unknown?: boolean;
  ownedCompanies?: string[];
  purgeAfter?: string;
  message?: string;
}

function parseSummary(raw: unknown): ClosureSummary {
  const d = (raw && typeof raw === 'object' ? raw : {}) as Json;
  const account = (d.account && typeof d.account === 'object' ? d.account : {}) as Json;
  const timeline = (d.timeline && typeof d.timeline === 'object' ? d.timeline : {}) as Json;
  const names = (v: unknown) => (Array.isArray(v) ? v.map((c) => String((c as Json)?.name ?? (c as Json)?.comp_name ?? c)).filter(Boolean) : []);
  const texts = (v: unknown) => (Array.isArray(v) ? v.map(String).filter(Boolean) : []);
  return {
    name: String(account.name ?? ''),
    email: String(account.email ?? ''),
    ownedCompanies: names(d.owned_companies),
    sharedCount: Number(d.shared_companies_count ?? (Array.isArray(d.shared_companies) ? d.shared_companies.length : 0)) || 0,
    purgeAfterDays: Number(timeline.purge_after_days ?? 45) || 45,
    willDelete: texts(d.will_delete),
    willKeep: texts(d.will_keep),
  };
}
