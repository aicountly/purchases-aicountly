/**
 * The assistant strip.
 *
 * WHAT IT DOES NOT DO. It does not claim a model wrote anything. Whether a
 * language model is configured at all is a server fact — `AiClient::isConfigured()`
 * — and it arrives with the summary, so this strip says "AI assisted" only when
 * there is a model behind the screens it links to, and "Rules-based" when the
 * answers come from the rules engine. That is the same thing the AI insights
 * workspace already tells people about its own panels.
 *
 * Every action here opens something real: a brief that fills in the RFQ editor
 * this product already has, the supplier list read live from Books, the
 * comparable-quotes filter on the table below, and the two intelligence
 * workspaces. None of them fabricates a recommendation.
 */

import { BarChart3, Gauge, ScanSearch, Scale, Sparkles, Users } from 'lucide-react'
import type { SourcingSummary } from '../../../services/types'

interface QuickAction {
  key: string
  label: string
  icon: typeof Users
  hint: string
  onClick: () => void
  disabled?: boolean
}

export function AiSourcingBanner({
  summary,
  canCreate,
  onDraft,
  onFindSuppliers,
  onCompare,
  onAnalysePricing,
  onPastPerformance,
}: {
  summary: SourcingSummary | null
  canCreate: boolean
  onDraft: () => void
  onFindSuppliers: () => void
  onCompare: () => void
  onAnalysePricing: () => void
  onPastPerformance: () => void
}) {
  const modelConfigured = summary?.ai.available ?? false
  const comparable = summary?.values?.comparison_ready ?? 0

  const actions: QuickAction[] = [
    {
      key: 'suppliers',
      label: 'Suggest suppliers',
      icon: Users,
      hint: 'Search suppliers with their procurement profile and prior qualification',
      onClick: onFindSuppliers,
    },
    {
      key: 'compare',
      label: 'Compare quotes',
      icon: Scale,
      hint:
        comparable > 0
          ? `Show the ${comparable} enquir${comparable === 1 ? 'y' : 'ies'} with two or more quotations`
          : 'Nothing has two quotations to compare yet',
      onClick: onCompare,
      disabled: comparable === 0,
    },
    {
      key: 'pricing',
      label: 'Analyse pricing',
      icon: Gauge,
      hint: 'Open purchase intelligence: price movement, anomalies and opportunities',
      onClick: onAnalysePricing,
    },
    {
      key: 'performance',
      label: 'Check past performance',
      icon: BarChart3,
      hint: 'Open supplier performance: on-time delivery, rejections and risk',
      onClick: onPastPerformance,
    },
  ]

  return (
    <section className="sq-assistant" aria-labelledby="sq-assistant-title">
      <div className="sq-assistant__main">
        <span className="sq-assistant__avatar" aria-hidden>
          <Sparkles size={26} />
        </span>

        <div className="sq-assistant__copy">
          <div className="sq-assistant__heading">
            <h2 id="sq-assistant-title">
              {modelConfigured ? 'Find the right suppliers faster with AI' : 'Find the right suppliers faster'}
            </h2>
            <span
              className={modelConfigured ? 'sq-chip sq-chip--ai' : 'sq-chip'}
              title={
                modelConfigured
                  ? 'A language model is configured for this company. It reads figures this product has already fetched under your own permissions — it never queries the database.'
                  : 'No language model is configured, so insights come from this product’s rules engine. Everything below still works.'
              }
            >
              {modelConfigured ? 'AI assisted' : 'Rules-based'}
            </span>
          </div>

          <p>
            Draft an enquiry, shortlist suppliers, compare quotations on landed cost and read the price and
            performance history behind them — without leaving sourcing.
          </p>

          <div className="sq-assistant__actions">
            <button type="button" className="sq-button sq-button--ai" onClick={onDraft} disabled={!canCreate}>
              <ScanSearch size={16} aria-hidden />
              Draft an RFQ
            </button>
            <button type="button" className="sq-button sq-button--quiet" onClick={onFindSuppliers}>
              <Users size={16} aria-hidden />
              Find suppliers
            </button>
          </div>
        </div>
      </div>

      <div className="sq-assistant__quick">
        {actions.map((action) => {
          const Icon = action.icon
          return (
            <button
              key={action.key}
              type="button"
              className="sq-quick"
              onClick={action.onClick}
              disabled={action.disabled}
              title={action.hint}
            >
              <Icon size={14} aria-hidden />
              {action.label}
            </button>
          )
        })}
      </div>
    </section>
  )
}
