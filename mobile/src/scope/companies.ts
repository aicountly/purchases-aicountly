import { api } from '../core/http';

/**
 * Companies, financial years and branches, as Manage reports them through the Purchases API
 * (`v1/manage/companies`, `v1/manage/companyinfo`). Manage's envelopes have taken several shapes
 * over the years; the readers below accept the ones in use and nothing is stored but the ids.
 *
 * Which year and branch a company opens on is the fleet's rule: the year that contains today
 * (else the latest), and the head-office branch (companyinfo `ho_id`, else the branch flagged
 * head office, else the first); a company with no branches opens on 0, all branches.
 */

type Row = Record<string, unknown>;
const isRow = (v: unknown): v is Row => !!v && typeof v === 'object' && !Array.isArray(v);
const rows = (v: unknown): Row[] => (Array.isArray(v) ? v.filter(isRow) : []);
const num = (v: unknown): number | null => {
  if (v === null || v === undefined || v === '') return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
};
const text = (v: unknown): string => (v === null || v === undefined ? '' : String(v).trim());
const yes = (v: unknown) => v === true || v === 1 || (typeof v === 'string' && ['1', 'true', 'yes'].includes(v.toLowerCase()));

export interface Company {
  cmpId: number;
  name: string;
  /** false: listed in the workspace, but not open to this person in Purchases. */
  openable: boolean;
}

export interface FinancialYear {
  fyId: number;
  start: string;
  end: string;
  label: string;
}

export interface Branch {
  boId: number;
  name: string;
  headOffice: boolean;
}

export interface CompanyDetail {
  cmpId: number;
  name: string;
  years: FinancialYear[];
  branches: Branch[];
  hoId: number | null;
}

function companyRows(body: unknown): Row[] {
  if (Array.isArray(body)) return rows(body);
  if (!isRow(body)) return [];
  if (Array.isArray(body.data)) return rows(body.data);
  if (isRow(body.data)) return rows(body.data.companies ?? body.data.items);
  return rows(body.companies ?? body.items);
}

export function readCompanyList(body: unknown): Company[] {
  const out: Company[] = [];
  const seen = new Set<number>();
  for (const r of companyRows(body)) {
    const cmpId = num(r.comp_id) ?? num(r.cmp_id) ?? num(r.id);
    if (!cmpId || cmpId <= 0 || seen.has(cmpId)) continue;
    seen.add(cmpId);
    out.push({
      cmpId,
      name: text(r.company_name) || text(r.comp_name) || text(r.cmp_name) || text(r.name) || `Company ${cmpId}`,
      openable: r.can_open_in_current_app === false ? false : true,
    });
  }
  return out;
}

export function listTotal(body: unknown, fallback: number): number {
  if (!isRow(body)) return fallback;
  const meta = isRow(body.meta) ? body.meta : {};
  return num(meta.total) ?? num(meta.total_count) ?? num(body.total) ?? fallback;
}

const isoDay = (v: unknown): string => {
  const m = /^(\d{4}-\d{2}-\d{2})/.exec(text(v));
  return m ? m[1] : '';
};

export function yearLabel(start: string, end: string, fallback: string): string {
  if (!start || !end) return fallback;
  const a = start.slice(0, 4);
  const b = end.slice(0, 4);
  return a === b ? `FY ${a}` : `FY ${a}–${b.slice(2)}`;
}

export function readCompanyDetail(body: unknown): CompanyDetail {
  const d = isRow(body) && isRow(body.data) ? body.data : isRow(body) ? body : {};
  const years: FinancialYear[] = [];
  for (const r of rows(d.fy_list ?? d.financial_years ?? d.fys)) {
    const fyId = num(r.fy_id) ?? num(r.comp_fy_id) ?? num(r.id);
    if (!fyId || fyId <= 0 || years.some((y) => y.fyId === fyId)) continue;
    const start = isoDay(r.fy_start ?? r.fy_beg_date ?? r.fy_from ?? r.start_date ?? r.from_date);
    const end = isoDay(r.fy_end ?? r.fy_end_date ?? r.fy_to ?? r.end_date ?? r.to_date);
    years.push({ fyId, start, end, label: yearLabel(start, end, text(r.fy_short) || text(r.fy_label) || `Year ${fyId}`) });
  }
  years.sort((a, b) => b.end.localeCompare(a.end) || b.fyId - a.fyId);
  const branches: Branch[] = [];
  for (const r of rows(d.branch_list ?? d.bo_list ?? d.branches)) {
    const boId = num(r.bo_id) ?? num(r.hobo_id) ?? num(r.branch_id) ?? num(r.id);
    if (!boId || boId <= 0 || branches.some((b) => b.boId === boId)) continue;
    branches.push({
      boId,
      name: text(r.hobo_name) || text(r.bo_name) || text(r.branch_name) || text(r.name) || `Branch ${boId}`,
      headOffice: yes(r.mark_ho) || yes(r.is_ho) || yes(r.is_head_office),
    });
  }
  const cmpId = num(d.cmp_id) ?? num(d.comp_id) ?? 0;
  return { cmpId, name: text(d.comp_name) || text(d.company_name) || text(d.cmp_name) || '', years, branches, hoId: num(d.ho_id) };
}

export function pickYear(years: FinancialYear[], today: string): FinancialYear | null {
  return years.find((y) => y.start && y.end && y.start <= today && today <= y.end) ?? years[0] ?? null;
}

export function pickBranch(detail: Pick<CompanyDetail, 'branches' | 'hoId'>): number {
  const { branches, hoId } = detail;
  if (branches.length === 0) return 0;
  if (hoId && branches.some((b) => b.boId === hoId)) return hoId;
  return (branches.find((b) => b.headOffice) ?? branches[0]).boId;
}

export function localToday(now = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`;
}

const PAGE = 100;

/** Every company this person can see from Purchases, paged through Manage (bounded). */
export async function fetchCompanies(): Promise<Company[]> {
  const all: Company[] = [];
  for (let page = 1; page <= 20; page += 1) {
    const body = await api.unscoped<unknown>('v1/manage/companies', { filter: 'all', page, per_page: PAGE });
    const list = readCompanyList(body);
    for (const c of list) if (!all.some((a) => a.cmpId === c.cmpId)) all.push(c);
    if (list.length < PAGE || all.length >= listTotal(body, all.length)) break;
  }
  return all.sort((a, b) => Number(b.openable) - Number(a.openable) || a.name.localeCompare(b.name));
}

export async function fetchCompanyDetail(cmpId: number): Promise<CompanyDetail> {
  const detail = readCompanyDetail(await api.unscoped<unknown>('v1/manage/companyinfo', { comp_id: cmpId }));
  return { ...detail, cmpId: detail.cmpId || cmpId };
}
