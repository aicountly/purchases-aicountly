/**
 * Plans (CONTRACTS §3.5/§3.6). A new purchase order the company's plan does not allow answers
 *
 *   402 {"error":{"code":"PLAN_LIMIT_REACHED","details":{"code":"purchases.max_purchase_orders_per_month","limit":50,"used":50}}}
 *
 * and the web says "Your plan allows N … — ask the account owner to upgrade in Manage." Plans are
 * changed by the account owner in Manage (Plans & billing), never here. A Manage that predates plans
 * sends no plan: nothing is limited.
 */

export const PLAN_LIMIT_CODE = 'PLAN_LIMIT_REACHED'

export interface PlanLimitDetails {
  code: string
  limit: number
  used: number | null
}

const NOUNS: Record<string, [string, string]> = {
  'purchases.max_purchase_orders_per_month': ['purchase order a month', 'purchase orders a month'],
  max_companies: ['company', 'companies'],
  max_users: ['user', 'users'],
}

type Row = Record<string, unknown>

function isRow(v: unknown): v is Row {
  return !!v && typeof v === 'object' && !Array.isArray(v)
}

function wholeNumber(v: unknown): number | null {
  if (typeof v === 'number' && Number.isInteger(v) && v >= 0) return v
  if (typeof v === 'string' && /^\d{1,9}$/.test(v.trim())) return Number(v.trim())
  return null
}

/** The refusal's details when this is a plan-limit refusal (by code, or a bare 402), otherwise null. */
export function planLimitFromError(status: number, code: string | null | undefined, details: unknown): PlanLimitDetails | null {
  const bare = !code || code === 'error'
  if (code !== PLAN_LIMIT_CODE && !(status === 402 && bare)) return null
  const d = isRow(details) ? details : {}
  return { code: typeof d.code === 'string' ? d.code : '', limit: wholeNumber(d.limit) ?? 0, used: wholeNumber(d.used) }
}

/** "Your plan allows 50 purchase orders a month — ask the account owner to upgrade in Manage." */
export function planLimitMessage(details: PlanLimitDetails | null): string {
  if (!details || !details.code) return "This company's plan limit is reached — ask the account owner to upgrade in Manage."
  const [one, many] = NOUNS[details.code] ?? ['record', 'records']
  return `Your plan allows ${details.limit} ${details.limit === 1 ? one : many} — ask the account owner to upgrade in Manage.`
}
