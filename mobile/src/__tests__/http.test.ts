jest.mock('../core/session', () => {
  const actual = jest.requireActual('../core/session');
  return { ...actual, currentSessionKey: jest.fn() };
});

import { api, client, submissionToken } from '../core/http';
import { PLAN_LIMIT_MESSAGE, Problem, RESTRICTED_MESSAGE } from '../core/problems';
import { currentSessionKey, SessionEndedError } from '../core/session';

const mint = currentSessionKey as jest.MockedFunction<typeof currentSessionKey>;
const reply = (status: number, body: unknown) => ({ status, ok: status < 300, text: async () => (body === undefined ? '' : JSON.stringify(body)) }) as unknown as Response;

describe('Purchases API client', () => {
  let calls: { url: string; init: RequestInit }[];
  let answers: Response[];

  beforeEach(() => {
    calls = [];
    answers = [];
    mint.mockReset();
    mint.mockImplementation(async (force?: boolean) => (force ? 'fresh-key' : 'key-1'));
    client.setOnline(true);
    client.setScope({ cmp_id: 88, fy_id: 6, bo_id: 0 });
    client.setTransport(async (url, init) => {
      calls.push({ url: String(url), init: init ?? {} });
      const next = answers.shift();
      if (!next) throw new Error('no answer queued');
      return next;
    });
  });
  afterAll(() => client.setTransport(null));

  it('puts the company scope on the query and the bearer key on the header', async () => {
    answers.push(reply(200, { data: [] }));
    await api.get('v1/approvals', { status: 'PENDING', empty: '', none: null });
    expect(calls[0].url).toBe('https://purchase.gh.aicountly.com/api/v1/approvals?cmp_id=88&fy_id=6&bo_id=0&status=PENDING');
    const headers = calls[0].init.headers as Record<string, string>;
    expect(headers.Authorization).toBe('Bearer key-1');
    expect(headers['X-Source-App']).toBe('purchases-mobile');
  });

  it('merges the scope into JSON bodies, and sends headers asked for', async () => {
    answers.push(reply(200, { data: {} }));
    await api.post('v1/purchase-orders/5/receive', { lines: [] }, { 'Idempotency-Key': 'tok' });
    expect(JSON.parse(String(calls[0].init.body))).toEqual({ cmp_id: 88, fy_id: 6, bo_id: 0, lines: [] });
    expect((calls[0].init.headers as Record<string, string>)['Idempotency-Key']).toBe('tok');
  });

  it('leaves the scope off unscoped calls', async () => {
    answers.push(reply(200, { data: [] }));
    await api.unscoped('v1/manage/companies', { page: 1 });
    expect(calls[0].url).toBe('https://purchase.gh.aicountly.com/api/v1/manage/companies?page=1');
  });

  it('retries a 401 once with a refreshed key', async () => {
    answers.push(reply(401, {}), reply(200, { data: 1 }));
    await expect(api.get('v1/session')).resolves.toEqual({ data: 1 });
    expect((calls[1].init.headers as Record<string, string>).Authorization).toBe('Bearer fresh-key');
  });

  it('a second 401 ends the session', async () => {
    const ended = jest.fn();
    client.onSessionEnded(ended);
    answers.push(reply(401, {}), reply(401, {}));
    await expect(api.get('v1/session')).rejects.toMatchObject({ kind: 'session' });
    expect(ended).toHaveBeenCalledTimes(1);
    client.onSessionEnded(null);
  });

  it('a refused sign-in token ends the session without calling the API', async () => {
    const ended = jest.fn();
    client.onSessionEnded(ended);
    mint.mockRejectedValueOnce(new SessionEndedError());
    await expect(api.get('v1/session')).rejects.toMatchObject({ kind: 'session' });
    expect(calls).toHaveLength(0);
    expect(ended).toHaveBeenCalled();
    client.onSessionEnded(null);
  });

  it('402 is the plan-limit sentence and nothing else', async () => {
    answers.push(reply(402, { error: { code: 'PLAN_LIMIT_REACHED', message: 'Your plan allows 50 purchase orders a month — ask the account owner to upgrade in Manage.', details: { limit: 50 } } }));
    const err = (await api.post('v1/purchase-orders', {}).catch((e) => e)) as Problem;
    expect(err.kind).toBe('plan_limit');
    expect(err.message).toBe(PLAN_LIMIT_MESSAGE);
    expect(err.message).not.toMatch(/upgrade|plan allows/i);
  });

  it('a bare 402 is still the plan-limit sentence', async () => {
    answers.push(reply(402, undefined));
    await expect(api.post('v1/x', {})).rejects.toMatchObject({ kind: 'plan_limit', message: PLAN_LIMIT_MESSAGE });
  });

  it('423 marks the company read-only', async () => {
    const restricted = jest.fn();
    client.onRestricted(restricted);
    answers.push(reply(423, { error: { code: 'COMPANY_RESTRICTED', message: 'Subscription lapsed: renew your plan' } }));
    await expect(api.post('v1/purchase-orders/1/approve', {})).rejects.toMatchObject({ kind: 'restricted', message: RESTRICTED_MESSAGE });
    expect(restricted).toHaveBeenCalledWith(88);
    client.onRestricted(null);
  });

  it('403s are told apart: no access to the company, no seat, and a refusal that names the document', async () => {
    answers.push(reply(403, { error: { code: 'company_access_denied', message: 'x' } }));
    await expect(api.get('v1/session')).rejects.toMatchObject({ kind: 'no_access' });
    answers.push(reply(403, { error: { code: 'no_purchases_access', message: 'x' } }));
    await expect(api.get('v1/session')).rejects.toMatchObject({ kind: 'no_access' });
    answers.push(reply(403, { error: { code: 'sod_reason_required', message: 'You raised this purchase order. As the company owner you may decide on it yourself only by saying why; the reason is recorded.' } }));
    await expect(api.post('v1/purchase-orders/1/approve', {})).rejects.toMatchObject({ kind: 'forbidden', code: 'sod_reason_required', message: expect.stringContaining('You raised this purchase order') });
  });

  it('refuses a write offline before it leaves the phone; reads are still attempted', async () => {
    client.setOnline(false);
    await expect(api.post('v1/purchase-orders/1/approve', {})).rejects.toMatchObject({ kind: 'offline' });
    expect(calls).toHaveLength(0);
    answers.push(reply(200, { data: [] }));
    await expect(api.get('v1/approvals')).resolves.toEqual({ data: [] });
  });

  it('no answer at all is "unavailable" with status 0 and retryable', async () => {
    client.setTransport(async () => {
      throw new TypeError('Network request failed');
    });
    const err = (await api.post('v1/purchase-orders/1/receive', {}).catch((e) => e)) as Problem;
    expect(err.kind).toBe('unavailable');
    expect(err.status).toBe(0);
    expect(err.retryable).toBe(true);
  });

  it('keeps the API\'s own sentence and details on a 502 from Inventory', async () => {
    answers.push(reply(502, { error: { code: 'inventory_unavailable', message: 'Inventory did not record this receipt (timeout). Nothing has been received — press Retry.', details: { request_id: 9, retryable: true } } }));
    await expect(api.post('v1/purchase-orders/1/receive', {})).rejects.toMatchObject({ kind: 'unavailable', code: 'inventory_unavailable', details: { request_id: 9 } });
  });

  it('never shows operator text', async () => {
    answers.push(reply(500, { error: { code: 'error', message: 'SQLSTATE[42P01] relation does not exist' } }));
    const err = (await api.get('v1/approvals').catch((e) => e)) as Problem;
    expect(err.message).not.toMatch(/SQLSTATE/);
  });

  it('submission tokens are unique and fit the API limit', () => {
    const a = submissionToken('grn-1');
    const b = submissionToken('grn-1');
    expect(a).not.toBe(b);
    expect(a.length).toBeLessThanOrEqual(120);
  });
});
