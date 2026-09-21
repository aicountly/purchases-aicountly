/**
 * The dashboard contract, as the API actually returns it.
 *
 * Every money value is a STRING. It arrives as an exact decimal from
 * PostgreSQL, is added up on the server and is formatted there too — parsing it
 * into a JavaScript number here would undo all of that at the last step, which
 * is why `raw_value` is typed as a string and `formatted_value` is what gets
 * rendered.
 */

export type SourceState = 'ready' | 'stale' | 'unavailable'

export interface SourceStatus {
  id: string
  label: string
  status: SourceState
  status_label: string
  as_of: string | null
  message: string | null
}

export type MetricDirection = 'higher_is_better' | 'lower_is_better' | 'neutral'
export type MetricFormat = 'currency' | 'percent' | 'count' | 'quantity' | 'days'

export interface Drilldown {
  route: string
  filters: Record<string, string>
}

export interface MetricComparison {
  available: boolean
  text: string
  tone: 'is-positive' | 'is-negative' | 'is-neutral'
  reason?: string
  previous_raw?: string
  change_raw?: string
  change_pc?: string | null
  label?: string
}

export interface DashboardMetric {
  id: string
  label: string
  status: 'ready' | 'unavailable'
  /** An exact decimal string, or null. Never parse this into a number. */
  raw_value: string | null
  formatted_value: string | null
  format: MetricFormat
  currency: string | null
  unit: string | null
  basis: string
  explanation: string
  direction: MetricDirection
  unavailable_reason?: string
  comparison: MetricComparison
  comparison_text: string
  change_tone: 'is-positive' | 'is-negative' | 'is-neutral'
  footnote: string | null
  drilldown?: Drilldown
  /**
   * The history behind the figure, when one genuinely exists.
   *
   * Absent — not an empty array and not a row of zeroes — for a figure that is
   * a position rather than a period total. The card reserves the space either
   * way so a row of six keeps one baseline.
   */
  series?: { label: string; value: string; formatted: string }[]
}

export interface DashboardScope {
  company_id: number
  financial_year_id: number
  branch_id: number
  branch_label: string
  timezone: string
  /** null when documents in scope use more than one currency. */
  reporting_currency: string | null
}

export interface DashboardPeriod {
  from: string
  to: string
  preset: string
  label: string
  days: number
  timezone: string
  comparison_mode: string
  compare_from: string | null
  compare_to: string | null
  comparison_label: string
}

/** A panel is either rendered, or it says why it is not. */
export type Panel<T> = ({ available: true } & T) | { available: false; reason: string; kind: 'permission' | 'source' }

export interface DashboardResponse {
  view: string
  scope: DashboardScope
  period: DashboardPeriod
  filters: Record<string, unknown>
  generated_at: string
  sources: SourceStatus[]
  metrics: DashboardMetric[]
  panels: Record<string, unknown>
  [key: string]: unknown
}

// ---------------------------------------------------------------------------
// Panel shapes, per dashboard
// ---------------------------------------------------------------------------

export interface BriefingItem {
  id: string
  severity: 'danger' | 'warning' | 'info' | 'success'
  severity_label: string
  title: string
  explanation: string
  amount: string | null
  impact_label: string
  count: number | null
  source_label: string
  action_label: string
  route: string
  filters: Record<string, string>
}

export interface PriorityItem {
  id: string
  kind: string
  severity: 'danger' | 'warning' | 'info' | 'success'
  severity_label: string
  title: string
  detail: string
  amount: string | null
  source: string
  age_days: number
  route: string
  action_label: string
}

export interface PipelineStage {
  id: string
  label: string
  count: number
  route: string
  filters: Record<string, string>
}

export interface TrendPoint {
  date: string
  amount: string
  /** Formatted on the server, so the chart and an export cannot disagree. */
  label?: string
  formatted?: string
}

export interface ConcentrationRow {
  supplier_account_id: number | null
  supplier_name: string | null
  amount: string
  formatted_amount: string
  share_pc: string | null
  order_count: number | null
}

export interface WorkbenchRow {
  po_id: number
  po_no: string
  po_date: string
  po_date_label: string
  status: string
  supplier_account_id: number
  supplier_name: string | null
  buyer_uuid: string | null
  promised_date: string | null
  promised_label: string | null
  days_late: number | null
  line_count: number
  open_lines: number
  currency: string
  value: string | null
  value_formatted: string | null
  remaining_value: string | null
  remaining_formatted: string | null
  next_action: string
  route: string
}

export interface TimelineLine {
  po_id: number
  po_no: string
  line_id: number
  line_no: number
  supplier_name: string | null
  item_id: number | null
  item_label: string
  is_service: boolean
  ordered_qty: string
  received_qty: string
  remaining_qty: string
  unit: string | null
  remaining_label: string
  revised: boolean
  revised_from: string | null
  route: string
}

export interface TimelineGroup {
  date: string | null
  date_label: string
  is_overdue: boolean
  lines: TimelineLine[]
}

export interface ReorderRow {
  item_id: number
  item_label: string
  unit: string | null
  available_qty: string | null
  available_label: string
  on_order_qty: string
  on_order_label: string
  next_arrival_label: string | null
  lead_days: number | null
  inventory_suggested_qty: string | null
  suggested_qty: string | null
  suggested_label: string
  basis: string
  stale: boolean
  route: string
  filters: Record<string, string>
}

export interface ApprovalRow {
  approval_id: number
  entity_type: string
  entity_id: number
  reference: string
  supplier_name: string | null
  stage: string
  required_permission: string | null
  reason: string | null
  threshold_formatted: string | null
  value_formatted: string | null
  currency: string
  raised_label: string | null
  age_days: number
  route: string
  approve_endpoint: string
  reject_endpoint: string
}

export interface SupplierRow {
  supplier_account_id: number
  supplier_name: string | null
  qualification_status: string
  is_preferred: boolean
  risk_flag: string | null
  currency: string | null
  ordered_value: string | null
  ordered_formatted: string | null
  po_count: number
  on_time_pc: string | null
  on_time_sample: number
  on_time_label: string
  acceptance_pc: string | null
  acceptance_sample: number
  acceptance_label: string
  fulfilment_pc: string | null
  avg_lead_days: string | null
  overdue_lines: number
  open_exposure_formatted: string | null
  open_claims: number
  score: string | null
  score_components: { key: string; label: string; weight: number; value: string | null; counted: boolean }[]
  score_missing: string[]
  next_action: string
}

export interface PriceMovementRow {
  item_id: number
  item_label: string
  unit: string | null
  currency: string
  observations: number
  supplier_count: number
  first_formatted: string
  first_date: string
  last_formatted: string
  last_date: string
  last_supplier: string | null
  change_pc: string | null
  direction: 'up' | 'down' | 'flat'
}

export interface AgeingBucket {
  id: string
  label: string
  amount: string
  formatted: string
  share_pc: string | null
  tone: string
}

export interface MatchRow {
  kind: 'exception' | 'duplicate'
  category: string
  exception_id?: number
  rule: string
  bill_request_id: number | null
  supplier_invoice_no: string | null
  supplier_name: string | null
  po_no?: string | null
  currency?: string
  variance_formatted?: string | null
  compared_reference?: string | null
  age_days: number
  route: string
  accept_endpoint?: string
  reject_endpoint?: string
}

export interface PaymentRow {
  supplier_account_id: number
  supplier_name: string | null
  bill_ref: string | null
  bill_date: string | null
  due_date: string | null
  due_label: string
  pending: string
  pending_formatted: string
  part_paid: boolean
  days_overdue: number | null
  held: boolean
  held_reason: string | null
  state: string
}

export interface OpportunityCard {
  id: string
  kind: string
  title: string
  detail: string
  estimate: string | null
  estimate_formatted: string | null
  baseline: string
  baseline_formatted: string
  assumption: string
  evidence: Record<string, unknown>
  route: string
  filters: Record<string, string>
}

export interface AnomalyRow {
  id: string
  kind: string
  title: string
  detail: string
  amount_formatted: string | null
  status: string
  route: string
  note: string
}

export interface AskAnswer {
  understood: boolean
  intent?: string
  question: string
  method: string
  method_label: string
  answer: string
  scope: Record<string, unknown>
  sources: { id: string; label: string; as_of: string | null; status?: string }[]
  records: Record<string, unknown>[]
  calculation: string | null
  uncertainty: string | null
  next_action: { label: string; route: string | null; filters?: Record<string, string> } | null
  suggestions?: { id: string; question: string }[]
  ai?: { available: boolean; reason: string | null }
}

// ---------------------------------------------------------------------------
// Overview — the executive strip and the 2026 panels
// ---------------------------------------------------------------------------

export interface ScoreComponent {
  key: string
  label: string
  weight: number
  value: string | null
  counted: boolean
  /** What was divided by what. Null on the supplier model, which predates it. */
  basis?: string | null
  sample?: number | null
}

export interface ScoreBand {
  id: 'good' | 'fair' | 'poor' | 'low' | 'medium' | 'high' | 'unknown'
  label: string
  tone: 'success' | 'warning' | 'danger' | 'neutral'
  action?: string
}

export type HealthPanel = Panel<{
  score: string | null
  score_formatted: string | null
  band: ScoreBand
  summary: string
  components: ScoreComponent[]
  missing: string[]
  counted_weight: number
  confidence: { counted: number; total: number; counted_weight: number; label: string; partial: boolean }
  basis: string
  method: string
}>

export type SupplierRiskPanel = Panel<{
  score: string | null
  score_formatted: string | null
  band: ScoreBand
  components: ScoreComponent[]
  missing: string[]
  suppliers: number
  flagged: number
  receipts: number
  direction: string
  basis: string
  route: string
}>

export interface OpportunityCardSummary {
  id: string
  kind: string
  title: string
  detail: string
  estimate_formatted: string | null
  baseline_formatted: string
  assumption: string
  counted_in_total: boolean
  action_label: string
  route: string
  filters: Record<string, string>
}

export type IntelligencePanel = Panel<{
  total: string
  total_formatted: string
  total_compact: string
  currency: string
  card_count: number
  overlapping: number
  cards: OpportunityCardSummary[]
  method: string
  method_label: string
  narrowed_by_filters: boolean
  /** Present when filters are applied that these findings do not honour. */
  scope_note: string | null
  basis: string
  route: string
}>

export interface TrendBucket {
  key: string
  label: string
  amount: string
  formatted: string
  compact: string
  previous_amount: string | null
  previous_formatted: string | null
  previous_label: string | null
}

export type SpendTrendPanel = Panel<{
  granularity: 'day' | 'week' | 'month'
  granularity_label: string
  granularity_options: string[]
  currency: string
  points: TrendBucket[]
  total: string
  total_formatted: string
  total_compact: string
  comparison: {
    label: string
    available: boolean
    reason: string | null
    previous: string | null
    current: string | null
  }
  /** Present only when Books answered about days outside the range asked for. */
  outside_range: { points: number; amount: string; formatted: string; note: string } | null
  basis: string
}>

export interface AgeingBucketRow extends AgeingBucket {
  compact: string
  route: string
  filters: Record<string, string>
}

export type AgeingPanel = Panel<{
  as_of: string
  as_of_label: string
  currency: string
  buckets: AgeingBucketRow[]
  total: string
  total_formatted: string
  basis: string
  caveat: string
}>

export interface CategorySlice {
  id: string
  label: string
  amount: string
  formatted: string
  compact: string
  share_pc: string | null
  /** How many smaller categories this slice rolls up. */
  rolled_up?: number
  /** True for the slice covering spend beyond the item lookup's cap. */
  capped?: boolean
}

export type CategorySpendPanel = Panel<{
  currency: string
  total: string
  total_formatted: string
  total_compact: string
  categories: CategorySlice[]
  /** The part of the total the split actually classified. */
  classified?: string
  source: string
  basis: string
  route?: string
}>

export interface TopSupplierRow extends ConcentrationRow {
  compact_amount: string
  on_time_pc: string | null
  on_time_label: string
  on_time_sample: number
  score: string | null
  risk: ScoreBand
  overdue_lines: number
  open_claims: number
  route: string
  filters: Record<string, string>
}

export type TopSuppliersPanel = Panel<{
  basis: string
  /** False when the reader lacks supplier.view; the scorecard columns are then empty. */
  rated: boolean
  scorecard_basis: string
  payables_basis: string
  unrated: number
  base_source: string
  base_amount: string
  base_formatted: string
  currency: string
  suppliers: TopSupplierRow[]
  others: { amount: string; formatted: string; share_pc: string | null }
}>

/** One supplier's open payable, fetched after the dashboard has drawn. */
export type SupplierPayable =
  | { supplier_account_id: number; available: true; pending: string; formatted: string; compact: string; overdue: string; overdue_formatted: string; bills: number }
  | { supplier_account_id: number; available: false; reason: string }

export interface SupplierPayablesResponse {
  as_on: string
  as_on_label: string
  currency: string
  suppliers: SupplierPayable[]
  basis: string
}
