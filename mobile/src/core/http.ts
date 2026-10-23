import { ENV, SOURCE_APP } from '../config/env';
import { OFFLINE_MESSAGE, Problem, problemFromResponse } from './problems';
import { currentSessionKey, IdentityUnreachableError, SessionEndedError } from './session';

/**
 * The Purchases API, as the phone calls it.
 *
 *  - `Authorization: Bearer <session key>`; one silent retry on 401 with a refreshed key.
 *  - The company scope (cmp_id, fy_id, bo_id) on every scoped call: in the query string, and in
 *    JSON bodies too, because the API reads both.
 *  - Writes need a connection. A write attempted offline is refused on the phone, before it
 *    leaves, with a sentence that says nothing was sent. Nothing is ever queued: an approval or
 *    a goods receipt replayed hours later, by a phone that has since changed hands, is not a
 *    thing this app does.
 *  - Every refusal becomes a Problem (problems.ts).
 */

export interface Scope {
  cmp_id: number;
  fy_id: number;
  /** 0 = all branches. */
  bo_id: number;
}

export type Query = Record<string, string | number | boolean | null | undefined>;

export interface ListEnvelope<T> {
  data: T[];
  meta?: { total?: number; limit?: number; offset?: number; [key: string]: unknown };
}

type Fetch = typeof fetch;

interface ClientState {
  scope: Scope | null;
  online: boolean;
  fetchImpl: Fetch;
  onSessionEnded: (() => void) | null;
  onRestricted: ((cmpId: number) => void) | null;
}

const state: ClientState = {
  scope: null,
  online: true,
  fetchImpl: (...args) => fetch(...args),
  onSessionEnded: null,
  onRestricted: null,
};

export const client = {
  setScope(scope: Scope | null) {
    state.scope = scope;
  },
  getScope(): Scope | null {
    return state.scope;
  },
  setOnline(online: boolean) {
    state.online = online;
  },
  isOnline(): boolean {
    return state.online;
  },
  setTransport(next: Fetch | null) {
    state.fetchImpl = next ?? ((...args) => fetch(...args));
  },
  onSessionEnded(handler: (() => void) | null) {
    state.onSessionEnded = handler;
  },
  onRestricted(handler: ((cmpId: number) => void) | null) {
    state.onRestricted = handler;
  },
};

const TIMEOUT_MS = 30_000;

interface Options {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  query?: Query;
  body?: Record<string, unknown>;
  /** false for the company list and company info, which choose the scope in the first place. */
  scoped?: boolean;
  headers?: Record<string, string>;
}

function buildUrl(path: string, query: Query | undefined, scoped: boolean): string {
  const params: string[] = [];
  const add = (k: string, v: string | number | boolean) => params.push(`${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);
  if (scoped && state.scope) {
    add('cmp_id', state.scope.cmp_id);
    add('fy_id', state.scope.fy_id);
    add('bo_id', state.scope.bo_id);
  }
  for (const [k, v] of Object.entries(query ?? {})) {
    if (v === null || v === undefined || v === '') continue;
    add(k, v);
  }
  return `${ENV.apiBase}/${path.replace(/^\/+/, '')}${params.length ? `?${params.join('&')}` : ''}`;
}

async function send(path: string, options: Options, key: string): Promise<{ status: number; body: unknown }> {
  const scoped = options.scoped !== false;
  const method = options.method ?? 'GET';
  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${key}`,
    'X-Source-App': SOURCE_APP,
    ...(options.headers ?? {}),
  };
  let body: string | undefined;
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(scoped && state.scope ? { ...state.scope, ...options.body } : options.body);
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
  let res: Response;
  try {
    res = await state.fetchImpl(buildUrl(path, options.query, scoped), { method, headers, body, signal: controller.signal });
  } catch {
    // No answer at all: for a write, the outcome is unknown, and the screen says so.
    throw new Problem(state.online ? 'unavailable' : 'offline', 0, 'no_response', state.online ? 'Purchases did not answer. Check your connection and try again.' : OFFLINE_MESSAGE, { retryable: true });
  } finally {
    clearTimeout(timer);
  }
  const text = await res.text().catch(() => '');
  let parsed: unknown = null;
  if (text) {
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = null;
    }
  }
  return { status: res.status, body: parsed };
}

async function request<T>(path: string, options: Options = {}): Promise<T> {
  const method = options.method ?? 'GET';
  if (method !== 'GET' && !state.online) {
    throw new Problem('offline', 0, 'offline', OFFLINE_MESSAGE, { retryable: true });
  }

  let key: string;
  try {
    key = await currentSessionKey();
  } catch (err) {
    throw sessionProblem(err);
  }

  let answer = await send(path, options, key);
  if (answer.status === 401) {
    try {
      key = await currentSessionKey(true);
    } catch (err) {
      throw sessionProblem(err);
    }
    answer = await send(path, options, key);
    if (answer.status === 401) {
      state.onSessionEnded?.();
      throw problemFromResponse(401, answer.body);
    }
  }

  if (answer.status < 200 || answer.status >= 300) {
    const problem = problemFromResponse(answer.status, answer.body);
    if (problem.kind === 'restricted' && state.scope) state.onRestricted?.(state.scope.cmp_id);
    throw problem;
  }
  return answer.body as T;
}

function sessionProblem(err: unknown): Problem {
  if (err instanceof SessionEndedError) {
    state.onSessionEnded?.();
    return new Problem('session', 401, 'session_ended', err.message);
  }
  if (err instanceof IdentityUnreachableError) {
    return new Problem(state.online ? 'unavailable' : 'offline', 0, 'identity_unreachable', state.online ? err.message : OFFLINE_MESSAGE, { retryable: true });
  }
  return new Problem('unknown', 0, 'error', 'That did not work. Try again.');
}

export const api = {
  get: <T>(path: string, query?: Query) => request<T>(path, { query }),
  unscoped: <T>(path: string, query?: Query) => request<T>(path, { query, scoped: false }),
  post: <T>(path: string, body: Record<string, unknown> = {}, headers?: Record<string, string>) =>
    request<T>(path, { method: 'POST', body, headers }),
};

/** A new random key for one submission, so a resend is recognised instead of recorded twice. */
export function submissionToken(prefix: string): string {
  const random = Array.from({ length: 4 }, () => Math.floor(Math.random() * 0x100000000).toString(16).padStart(8, '0')).join('');
  return `${prefix}-${Date.now().toString(36)}-${random}`;
}
