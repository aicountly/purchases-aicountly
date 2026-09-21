/**
 * Dashboard 1 — Overview.
 *
 * The screen a purchase head reads first thing: what needs a decision, what is
 * stuck, and what was actually spent.
 *
 * The order on this page is the order of those questions. The briefing and the
 * priority inbox come first because they are the only parts of the screen with
 * something for the reader to DO; the trend, the suppliers, the split and the
 * ageing come after, because they are context for the decisions above them.
 *
 * Every panel fails on its own. One upstream refusing is one panel saying why,
 * never an empty dashboard — which is the whole reason the server sends each
 * panel with its own `available` flag rather than one status for the page.
 */

import { useNavigate } from 'react-router-dom'
import { ArrowUpRight, Plus } from 'lucide-react'
import { DashboardPanel, EmptyState, PanelUnavailable, PriorityCard } from '../shell'
import { ExecutiveStrip } from '../overview/ExecutiveStrip'
import { SpendTrendPanel } from '../overview/SpendTrendPanel'
import { TopSuppliersPanel } from '../overview/TopSuppliersPanel'
import { CategorySpendPanel } from '../overview/CategorySpendPanel'
import { PayablesAgeingPanel } from '../overview/PayablesAgeingPanel'
import { AiBanner, AiInsightsPanel } from '../overview/AiInsights'
import type { SupplierPayablesState } from '../overview/useSupplierPayables'
import type {
  AgeingPanel,
  BriefingItem,
  CategorySpendPanel as CategorySpendPanelData,
  DashboardResponse,
  HealthPanel,
  IntelligencePanel,
  Panel,
  PipelineStage,
  PriorityItem,
  SpendTrendPanel as SpendTrendPanelData,
  SupplierRiskPanel,
  TopSuppliersPanel as TopSuppliersPanelData,
} from '../types'

type BriefingPanel = Panel<{ method: string; method_label: string; items: BriefingItem[] }>
type PipelinePanel = Panel<{ stages: PipelineStage[]; basis: string }>
type InboxPanel = Panel<{ items: PriorityItem[]; basis: string }>
type ActionsPanel = Panel<{ actions: { id: string; label: string; route: string; tone: string }[] }>

/** The supplier ids the payables column will be asked about, in table order. */
export function topSupplierIds(data: DashboardResponse): number[] {
  const panel = data.panels.concentration as TopSuppliersPanelData
  if (!panel.available) return []

  return panel.suppliers
    .map((row) => row.supplier_account_id)
    .filter((id): id is number => typeof id === 'number' && id > 0)
}

export function OverviewExecutive({ data }: { data: DashboardResponse }) {
  return (
    <ExecutiveStrip
      health={data.panels.health as HealthPanel}
      intelligence={data.panels.intelligence as IntelligencePanel}
      supplierRisk={data.panels.supplier_risk as SupplierRiskPanel}
      approvals={data.metrics.find((metric) => metric.id === 'my_approvals')}
    />
  )
}

export function OverviewDashboard({
  data,
  payables,
  granularity,
  onGranularityChange,
}: {
  data: DashboardResponse
  payables: SupplierPayablesState
  granularity: string
  onGranularityChange: (next: string) => void
}) {
  const navigate = useNavigate()
  const open = (route: string, filters: Record<string, string> = {}) => {
    const query = new URLSearchParams(filters).toString()
    navigate(query === '' ? route : `${route}?${query}`)
  }

  const briefing = data.panels.briefing as BriefingPanel
  const trend = data.panels.trend as SpendTrendPanelData
  const pipeline = data.panels.pipeline as PipelinePanel
  const inbox = data.panels.priority_inbox as InboxPanel
  const suppliers = data.panels.concentration as TopSuppliersPanelData
  const ageing = data.panels.ageing as AgeingPanel
  const categories = data.panels.category_spend as CategorySpendPanelData
  const intelligence = data.panels.intelligence as IntelligencePanel
  const actions = data.panels.quick_actions as ActionsPanel

  return (
    <>
      {actions.available && actions.actions.length > 0 && (
        <div className="purchase-header-actions" style={{ justifyContent: 'flex-start' }}>
          {actions.actions.map((action) => (
            <button
              key={action.id}
              type="button"
              className={`purchase-button purchase-button--${action.tone === 'primary' ? 'primary' : 'secondary'}`}
              onClick={() => open(action.route)}
            >
              {action.tone === 'primary' && <Plus size={15} aria-hidden />}
              {action.label}
            </button>
          ))}
        </div>
      )}

      <div className="purchase-dashboard-grid">
        <DashboardPanel
          title="Your purchase briefing"
          description={briefing.available ? briefing.method_label : undefined}
          className="purchase-span-all"
        >
          {!briefing.available ? (
            <PanelUnavailable reason={briefing.reason} kind={briefing.kind} />
          ) : briefing.items.length === 0 ? (
            <EmptyState title="Nothing needs a decision right now." />
          ) : (
            <div
              className="purchase-priority-list"
              style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))' }}
            >
              {briefing.items.map((item) => (
                <PriorityCard key={item.id} item={item} onReview={() => open(item.route, item.filters)} />
              ))}
            </div>
          )}
        </DashboardPanel>
      </div>

      {/* Row three: what was spent, with whom, and on what. */}
      <div className="purchase-analytics-grid">
        <SpendTrendPanel panel={trend} granularity={granularity} onGranularityChange={onGranularityChange} />
        <TopSuppliersPanel panel={suppliers} payables={payables} />
        <CategorySpendPanel panel={categories} />
      </div>

      {/* Row four: what is owed, what the rules found, and where to go next. */}
      <div className="purchase-bottom-grid">
        <PayablesAgeingPanel panel={ageing} />
        <AiInsightsPanel panel={intelligence} />
        <AiBanner />
      </div>

      <div className="purchase-dashboard-grid">
        <DashboardPanel title="Priority inbox" description={inbox.available ? inbox.basis : undefined}>
          {!inbox.available ? (
            <PanelUnavailable reason={inbox.reason} kind={inbox.kind} />
          ) : inbox.items.length === 0 ? (
            <EmptyState title="Your inbox is clear." />
          ) : (
            <div className="purchase-priority-list">
              {inbox.items.slice(0, 6).map((item) => (
                <PriorityCard key={item.id} item={item} onReview={() => open(item.route)} />
              ))}
            </div>
          )}
        </DashboardPanel>

        <DashboardPanel
          title="Procurement pipeline"
          description={pipeline.available ? pipeline.basis : undefined}
        >
          {!pipeline.available ? (
            <PanelUnavailable reason={pipeline.reason} kind={pipeline.kind} />
          ) : (
            <div className="purchase-pipeline">
              {pipeline.stages.map((stage) => (
                <button
                  key={stage.id}
                  type="button"
                  className="purchase-pipeline__stage"
                  onClick={() => open(stage.route, stage.filters)}
                >
                  <span className="purchase-pipeline__count">{stage.count}</span>
                  <span className="purchase-pipeline__label">{stage.label}</span>
                  <span
                    className="purchase-muted"
                    style={{ fontSize: 11, display: 'flex', alignItems: 'center', gap: 3 }}
                  >
                    Open <ArrowUpRight size={11} aria-hidden />
                  </span>
                </button>
              ))}
            </div>
          )}
        </DashboardPanel>
      </div>
    </>
  )
}
