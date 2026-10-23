/**
 * A closed AICOUNTLY account, met at sign-in.
 *
 * Closing an account (Account → Delete my account) takes effect at once. For the next 45 days
 * the holder can reopen it by signing in again and choosing "Reopen my account" — the same
 * sign-in, sent again with `reactivate: true`. After 45 days the account and its data are
 * erased. The identity service decides all of this; the phone only reads its answer.
 */

export interface ClosedAccount {
  message: string;
  purgeAfter?: string;
}

export const CLOSURE_WINDOW_DAYS = 45;

export const CLOSURE_TERMS =
  'Closing your account signs you out everywhere at once. For 45 days you can reopen it by signing in again and choosing Reopen my account. After 45 days the account and its data are erased for good.';

const truthy = (v: unknown) => v === '1' || v === 1 || v === true;

/** "13 Nov 2026" from the service's 'YYYY-MM-DD HH:MM:SS'; '' when unreadable. */
export function shortDate(value: string | undefined | null): string {
  if (!value) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value));
  if (!m) return '';
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const month = months[Number(m[2]) - 1];
  return month ? `${Number(m[3])} ${month} ${m[1]}` : '';
}

/** The closed-account details in a sign-in answer, or null when the answer is about something else. */
export function closedAccountFrom(data: Record<string, unknown> | null | undefined): ClosedAccount | null {
  if (!data) return null;
  const closed =
    truthy(data.pending_deletion) ||
    data.code === 'account_suspended_pending_deletion' ||
    truthy(data.account_suspended_pending_deletion) ||
    data.phase === 'suspended' ||
    data.phase === 'cooling_off';
  if (!closed) return null;
  const purgeAfter = typeof data.deletion_purge_after === 'string' ? data.deletion_purge_after : undefined;
  const until = shortDate(purgeAfter);
  return {
    message: until
      ? `This account was closed. You can reopen it until ${until}; after that it and its data are erased.`
      : `This account was closed. You can reopen it within ${CLOSURE_WINDOW_DAYS} days of closing it; after that it and its data are erased.`,
    ...(purgeAfter ? { purgeAfter } : {}),
  };
}
