/**
 * Every refusal the Purchases API can give, turned into one shape a screen can act on.
 *
 * The API answers `{error: {code, message, details}}`. The kinds below are what the screens
 * branch on; `message` is what a person reads. Three sentences are fixed, because store rules
 * and company policy fix them:
 *
 *   402 (plan limit)        "This company's plan limit is reached. Contact your company administrator."
 *   423 (company restricted) read-only, said as such
 *   offline                 nothing is sent; nothing is queued
 */

export type ProblemKind =
  | 'offline'
  | 'session'
  | 'plan_limit'
  | 'restricted'
  | 'no_access'
  | 'forbidden'
  | 'not_found'
  | 'conflict'
  | 'validation'
  | 'unavailable'
  | 'unknown';

export const PLAN_LIMIT_MESSAGE = "This company's plan limit is reached. Contact your company administrator.";
export const RESTRICTED_MESSAGE =
  'This company is read-only in Purchases right now. You can look things up, but approvals and receipts cannot be recorded. Contact your company administrator.';
export const OFFLINE_MESSAGE = "You're offline. Nothing was sent — try again when you have a connection.";
export const UNAVAILABLE_MESSAGE = 'Purchases did not answer just now. Try again in a moment.';

export class Problem extends Error {
  readonly kind: ProblemKind;
  readonly status: number;
  readonly code: string;
  readonly details: Record<string, unknown>;

  constructor(kind: ProblemKind, status: number, code: string, message: string, details: Record<string, unknown> = {}) {
    super(message);
    this.name = 'Problem';
    this.kind = kind;
    this.status = status;
    this.code = code;
    this.details = details;
  }

  /** Pressing the same button again could work. The API says so explicitly where it knows. */
  get retryable(): boolean {
    if (typeof this.details.retryable === 'boolean') return this.details.retryable;
    return this.kind === 'offline' || this.kind === 'unavailable';
  }
}

export function isProblem(value: unknown): value is Problem {
  return value instanceof Problem;
}

/** Words that must never reach a person on a phone: store rules (3.1.1) and operator jargon. */
const COMMERCE_WORDS = /\b(upgrade|plan|pricing|price plan|subscription|subscribe|purchase a|buy now|coupon|billing portal)\b/i;
const OPERATOR_WORDS = /(SQLSTATE|stack trace|exception|\.php\b|\bline \d+\b|SERVICE_KEY|\.env\b|curl error)/i;

function cleanMessage(message: string | undefined, fallback: string): string {
  const text = (message ?? '').trim();
  if (!text || OPERATOR_WORDS.test(text)) return fallback;
  return text;
}

type Row = Record<string, unknown>;
const isRow = (v: unknown): v is Row => !!v && typeof v === 'object' && !Array.isArray(v);

/** The problem an HTTP answer describes. `body` is the parsed JSON, or null. */
export function problemFromResponse(status: number, body: unknown): Problem {
  const envelope = isRow(body) ? body : {};
  const error = isRow(envelope.error) ? envelope.error : {};
  const code = typeof error.code === 'string' ? error.code : status === 402 ? 'PLAN_LIMIT_REACHED' : 'error';
  const details = isRow(error.details) ? error.details : {};
  const raw = typeof error.message === 'string' ? error.message : typeof envelope.message === 'string' ? envelope.message : '';

  if (status === 402 || code === 'PLAN_LIMIT_REACHED') {
    return new Problem('plan_limit', status, 'PLAN_LIMIT_REACHED', PLAN_LIMIT_MESSAGE, details);
  }
  if (status === 423 || code === 'COMPANY_RESTRICTED' || code === 'company_restricted') {
    return new Problem('restricted', status, code, RESTRICTED_MESSAGE, details);
  }
  if (status === 401) {
    return new Problem('session', status, code, 'Your sign-in has ended. Sign in again to continue.', details);
  }
  if (status === 403) {
    if (code === 'company_access_denied') {
      return new Problem('no_access', status, code, 'You do not have access to this company in Purchases. Ask the company owner for access, or choose another company.', details);
    }
    if (code === 'no_purchases_access') {
      return new Problem('no_access', status, code, 'You have no Purchases access in this company yet. Ask the company owner or a Purchases administrator to give you a profile.', details);
    }
    // sod_reason_required / sod_self_decision keep the API's own sentence: it names the document.
    return new Problem('forbidden', status, code, cleanMessage(raw, 'You do not have permission to do that here.'), details);
  }
  if (status === 404) return new Problem('not_found', status, code, cleanMessage(raw, 'That record no longer exists.'), details);
  if (status === 409) return new Problem('conflict', status, code, cleanMessage(raw, 'Something changed since you opened this. Refresh and try again.'), details);
  if (status === 422 || status === 400) {
    return new Problem('validation', status, code, cleanMessage(raw, 'Check what you entered and try again.'), details);
  }
  if (status >= 500 || status === 408 || status === 429 || status === 0) {
    // 502 inventory_* / 503 upstream_*: the API's own sentence says what happened and what to press.
    const text = code !== 'error' && raw ? cleanMessage(raw, UNAVAILABLE_MESSAGE) : UNAVAILABLE_MESSAGE;
    return new Problem('unavailable', status, code, text, details);
  }
  return new Problem('unknown', status, code, cleanMessage(raw, 'That did not work. Try again.'), details);
}

/** A sentence for anything thrown, problem or not. Never commerce wording. */
export function messageOf(error: unknown): string {
  const text = error instanceof Error ? error.message : 'That did not work. Try again.';
  if (isProblem(error) && error.kind === 'plan_limit') return PLAN_LIMIT_MESSAGE;
  return COMMERCE_WORDS.test(text) && !(isProblem(error) && error.kind === 'restricted') ? 'That did not work. Contact your company administrator.' : text;
}
