import { resolveEnvironment, webAppUrl } from '../config/env';
import { onThePhone, onTheWeb } from '../config/mobileScope';
import { messageOf, Problem, problemFromResponse } from '../core/problems';
import { listTotal, pickBranch, pickYear, readCompanyDetail, readCompanyList, yearLabel } from '../scope/companies';

describe('companies from Manage', () => {
  it('reads every list envelope Manage has used, once per company, with Purchases access', () => {
    const rows = [
      { comp_id: 5, company_name: 'Rao Traders', can_open_in_current_app: true },
      { cmp_id: 6, comp_name: 'Desai Mills', can_open_in_current_app: false },
      { id: 5, name: 'dup' },
      { id: 0 },
    ];
    for (const body of [{ data: rows }, { data: { companies: rows } }, rows, { companies: rows }]) {
      expect(readCompanyList(body)).toEqual([
        { cmpId: 5, name: 'Rao Traders', openable: true },
        { cmpId: 6, name: 'Desai Mills', openable: false },
      ]);
    }
    expect(listTotal({ meta: { total: 140 } }, 2)).toBe(140);
    expect(listTotal(null, 2)).toBe(2);
  });

  it('reads years (latest first) and branches from companyinfo', () => {
    const d = readCompanyDetail({
      data: {
        cmp_id: 5,
        comp_name: 'Rao Traders',
        ho_id: 12,
        fy_list: [
          { fy_id: 5, fy_start: '2025-04-01', fy_end: '2026-03-31' },
          { fy_id: 6, fy_beg_date: '2026-04-01 00:00:00', fy_end_date: '2027-03-31' },
        ],
        branch_list: [
          { hobo_id: 11, hobo_name: 'Pune' },
          { bo_id: 12, bo_name: 'Mumbai HO' },
        ],
      },
    });
    expect(d.years.map((y) => [y.fyId, y.label])).toEqual([
      [6, 'FY 2026–27'],
      [5, 'FY 2025–26'],
    ]);
    expect(pickYear(d.years, '2025-10-01')?.fyId).toBe(5);
    expect(pickYear(d.years, '2030-01-01')?.fyId).toBe(6);
    expect(pickBranch(d)).toBe(12);
    expect(pickBranch({ branches: [{ boId: 3, name: 'A', headOffice: false }, { boId: 4, name: 'B', headOffice: true }], hoId: null })).toBe(4);
    expect(pickBranch({ branches: [], hoId: null })).toBe(0);
    expect(yearLabel('', '', 'Year 9')).toBe('Year 9');
  });
});

describe('environment', () => {
  it('a release build with no environment named is production', () => {
    expect(resolveEnvironment({}, true)).toEqual({ appEnv: 'production', apiBase: 'https://purchase.aicountly.com/api', portalLogin: 'https://my.aicountly.com' });
    expect(resolveEnvironment({}, false).appEnv).toBe('sandbox');
  });

  it('a production build never points at a sandbox host, and only https is accepted', () => {
    expect(resolveEnvironment({ appEnv: 'production', apiBase: 'https://purchase.gh.aicountly.com/api' }, true).apiBase).toBe('https://purchase.aicountly.com/api');
    expect(resolveEnvironment({ appEnv: 'sandbox', apiBase: 'http://insecure.example/api' }, false).apiBase).toBe('https://purchase.gh.aicountly.com/api');
    expect(resolveEnvironment({ appEnv: 'sandbox', apiBase: 'https://staging.example/api/' }, false).apiBase).toBe('https://staging.example/api');
    expect(webAppUrl(resolveEnvironment({}, true))).toBe('https://purchase.aicountly.com');
  });
});

describe('phone scope', () => {
  it('names every web-only module with a reason, and nothing is on both lists', () => {
    expect(onTheWeb.length).toBeGreaterThanOrEqual(6);
    for (const m of [...onTheWeb, ...onThePhone]) expect(m.reason.length).toBeGreaterThan(10);
    const phone = new Set(onThePhone.map((m) => m.module));
    expect(onTheWeb.some((m) => phone.has(m.module))).toBe(false);
    expect(onTheWeb.map((m) => m.module).join(' ')).toMatch(/Writing purchase orders/);
  });
});

describe('problems', () => {
  it('maps statuses to kinds', () => {
    expect(problemFromResponse(404, null).kind).toBe('not_found');
    expect(problemFromResponse(409, { error: { code: 'x', message: 'Changed.' } })).toMatchObject({ kind: 'conflict', message: 'Changed.' });
    expect(problemFromResponse(422, { error: { code: 'validation_failed', message: 'Line 2 has 3 outstanding.' } }).kind).toBe('validation');
    expect(problemFromResponse(503, { error: { code: 'upstream_unavailable', message: 'Could not reach the app that owns this information. Please retry.' } }).retryable).toBe(true);
    expect(new Problem('validation', 422, 'x', 'y', { retryable: true }).retryable).toBe(true);
  });

  it('no store-commerce wording reaches the screen', () => {
    expect(messageOf(new Error('Upgrade your plan to continue'))).not.toMatch(/upgrade|plan/i);
    expect(messageOf(problemFromResponse(402, null))).toBe("This company's plan limit is reached. Contact your company administrator.");
    expect(messageOf(new Error('Inventory refused this receipt.'))).toBe('Inventory refused this receipt.');
  });
});
