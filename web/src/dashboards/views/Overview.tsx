/**
 * Dashboard 1 — Overview.
 *
 * The screen a purchase head reads first thing: what needs a decision, what is
 * stuck, and what was actually spent.
 */

import { useNavigate } from 'react-router-dom'
import { ArrowUpRight, Plus } from 'lucide-react'
import { DashboardPanel, EmptyState, PanelUnavailable, PriorityCard } from '../shell'
import { BarChart, ShareBar, TrendChart } from '../charts'
import type {
  BriefingItem,
  ConcentrationRow,
  DashboardResponse,
  Panel,
  PipelineStage,
  PriorityItem,
  TrendPoint,
} from '../types'

type BriefingPanel = Panel<{ method: string; method_label: string; items: BriefingItem[] }>
type TrendPanel = Panel<{ points: TrendPoint[]; currency: string; basis: string; total: string }>
type PipelinePanel = Panel<{ stages: PipelineStage[]; basis: string }>
type InboxPanel = Panel<{ items: PriorityItem[]; basis: string }>
type ConcentrationPanel = Panel<{
  basis: string
  base_formatted: string
  suppliers: ConcentrationRow[]
  others: { amount: string; formatted: string; share_pc: string | null }
}>
type ActionsPanel = Panel<{ actions: { id: string; label: string; route: string; tone: string }[] }>

export function OverviewDashboard({ data }: { data: DashboardResponse }) {
  const navigate = useNavigate()
  const open = (route: string, filters: Record<string, string> = {}) => {
    const query = new URLSearchParams(filters).toString()
    navigate(query === '' ? route : `${route}?${query}`)
  }

  const briefing = data.panels.briefing as BriefingPanel
  const trend = data.panels.trend as TrendPanel
  const pipeline = data.panels.pipeline as PipelinePanel
  const inbox = data.panels.priority_inbox as InboxPanel
  const concentration = data.panels.concentration as ConcentrationPanel
  const actions = data.panels.quick_actions as ActionsPanel

  return (
    <>
      {actions.available && actions.actions.length > 0 && (
        <div className="purchase-header-actions" style={{ marginTop: -4 }}>
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
              style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))' }}
            >
              {briefing.items.map((item) => (
                <PriorityCard key={item.id} item={item} onReview={() => open(item.route, item.filters)} />
              ))}
            </div>
          )}
        </DashboardPanel>

        <DashboardPanel title="Purchase trend" description={trend.available ? trend.basis : undefined}>
          {!trend.available ? (
            <PanelUnavailable reason={trend.reason} kind={trend.kind} />
          ) : trend.points.length === 0 ? (
            <EmptyState title="No posted purchases in this period.">
              Smart Books answered; it has nothing dated in this range.
            </EmptyState>
          ) : (
            <TrendChart
              title="Net posted purchases by day"
              unitLabel={`Amount in ${trend.currency}`}
              points={trend.points.map((point) => ({
                label: point.label ?? point.date,
                value: point.amount,
                formatted: point.formatted ?? point.amount,
              }))}
            />
          )}
        </DashboardPanel>

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
          className="purchase-span-all"
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

        <DashboardPanel
          title="Supplier concentration"
          description={concentration.available ? concentration.basis : undefined}
          className="purchase-span-all"
        >
          {!concentration.available ? (
            <PanelUnavailable reason={concentration.reason} kind={concentration.kind} />
          ) : concentration.suppliers.length === 0 ? (
            <EmptyState title="No supplier spend in this period." />
          ) : (
            <div className="purchase-dashboard-grid" style={{ gap: 24 }}>
              <ShareBar
                title="Share of purchases by supplier"
                totalLabel={concentration.base_formatted}
                data={concentration.suppliers.map((row) => ({
                  id: String(row.supplier_account_id ?? row.supplier_name),
                  label: row.supplier_name ?? `Account ${row.supplier_account_id}`,
                  formatted: row.formatted_amount,
                  sharePc: row.share_pc,
                  onOpen: row.supplier_account_id
                    ? () => open('/dashboard/suppliers', { supplier_id: String(row.supplier_account_id) })
                    : undefined,
                }))}
                others={{ formatted: concentration.others.formatted, sharePc: concentration.others.share_pc }}
              />

              <BarChart
                title="Supplier spend"
                unitLabel="Amount"
                data={concentration.suppliers.map((row) => ({
                  id: String(row.supplier_account_id ?? row.supplier_name),
                  label: row.supplier_name ?? `Account ${row.supplier_account_id}`,
                  value: row.amount,
                  formatted: row.formatted_amount,
                  onOpen: row.supplier_account_id
                    ? () => open('/purchase-orders', { supplier_id: String(row.supplier_account_id) })
                    : undefined,
                }))}
              />
            </div>
          )}
        </DashboardPanel>
      </div>
    </>
  )
}
