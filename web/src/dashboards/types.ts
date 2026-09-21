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
  /** formatted_value shortened for a card (₹12.4L). A display form, never a second figure. */
  compact_value: string | null
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
  /** What the supplier and material-centre controls may be set to. */
  filter_options?: FilterOptions
  /** Whether a model is configured for this deployment. Never used to invent a finding. */
  ai?: { available: boolean; reason: string | null }
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
// Procurement workspace
// ---------------------------------------------------------------------------

/** What the supplier and material-centre controls may be set to, per the server. */
export interface FilterOptions {
  suppliers: { id: number; label: string; count: number }[]
  centres: { id: number; label: string; named: boolean }[]
  reason: string | null
}

export type FlowTone = 'success' | 'info' | 'warning' | 'danger' | 'neutral'

export interface FlowStage {
  id: string
  label: string
  count: number
  count_label: string
  /** An exact decimal string, or null when the value is withheld or not one currency. */
  value: string | null
  value_formatted: string | null
  value_compact: string | null
  /** What kind of money this is: "estimated", "ordered", "still to arrive"… */
  value_label: string
  tone: FlowTone
  status_label: string
  detail: string
  route: string
  filters: Record<string, string>
}

export interface SpendTrendPoint {
  date: string
  label: string
  amount: string
  formatted: string
  orders: number
}

export interface SupplierOnTimeRow {
  supplier_account_id: number
  supplier_name: string
  on_time_pc: string
  on_time_label: string
  on_time_count: number
  sample: number
  sample_label: string
  tone: 'success' | 'brand' | 'warning' | 'danger'
  route: string
  filters: Record<string, string>
}

export interface MaterialCentreRow {
  centre_id: number | null
  label: string
  named: boolean
  amount: string
  formatted: string
  share_pc: string | null
  orders: number
  route: string
  filters: Record<string, string>
}

/** A deterministic finding. The category chooses the icon; nothing here is a forecast. */
export interface ProcurementInsight extends BriefingItem {
  category: string
}

export interface ActivityRow {
  id: string
  date: string
  date_label: string
  type: string
  reference: string
  supplier_account_id: number | null
  supplier_name: string | null
  description: string
  value: string | null
  value_formatted: string | null
  status: string
  tone: FlowTone
  route: string
  rank: number
  actions: { label: string; route: string }[]
}

export type FlowPanel = Panel<{
  stages: FlowStage[]
  currency: string
  values_visible: boolean
  values_hidden_reason: string | null
  basis: string
}>

export type SpendTrendPanel = Panel<{
  granularity: string
  currency: string
  points: SpendTrendPoint[]
  total: string
  total_formatted: string
  total_compact: string
  basis: string
  comparison:
    | { available: false; reason: string }
    | { available: true; label: string; previous: string; previous_formatted: string; change_pc: string | null }
}>

export type SupplierOnTimePanel = Panel<{ rows: SupplierOnTimeRow[]; basis: string }>

export type MaterialCentrePanel = Panel<{
  centres: MaterialCentreRow[]
  others: { count: number; amount: string; formatted: string; share_pc: string | null }
  total: string
  total_formatted: string
  total_compact: string
  currency: string
  names_available: boolean
  basis: string
}>

export type ProcurementInsightsPanel = Panel<{
  items: ProcurementInsight[]
  method: string
  method_label: string
  ai: { available: boolean; reason: string | null }
  basis: string
}>

export type ActivityPanel = Panel<{ rows: ActivityRow[]; values_visible: boolean; basis: string }>
