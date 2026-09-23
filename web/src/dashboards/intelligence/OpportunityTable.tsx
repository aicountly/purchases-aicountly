/**
 * The opportunities table, and the drawer behind each row.
 *
 * Ranked by estimated impact, with the weight of evidence beside it. The
 * evidence column is a COUNT, not a probability: these come from fixed rules
 * over the company's own orders, and a rule does not have a confidence
 * interval. Writing "94%" beside one would be the only number on this screen
 * nobody could reproduce.
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowRight, Lightbulb } from 'lucide-react'
import { Badge, DashboardPanel, EmptyState, PanelUnavailable } from '../shell'
import { Drawer } from '../Drawer'
import type { IntelligenceOpportunity, OpportunityPanel } from '../types'

function priorityTone(priority: string): 'danger' | 'warning' | 'success' {
  return priority === 'high' ? 'danger' : priority === 'medium' ? 'warning' : 'success'
}

function strengthTone(level: string): 'success' | 'warning' | 'neutral' {
  return level === 'strong' ? 'success' : level === 'moderate' ? 'warning' : 'neutral'
}

export function OpportunityTable({ panel, onViewAll }: { panel: OpportunityPanel; onViewAll: () => void }) {
  const navigate = useNavigate()
  const [open, setOpen] = useState<IntelligenceOpportunity | null>(null)

  const go = (route: string, filters: Record<string, string> = {}) => {
    const query = new URLSearchParams(filters).toString()
    navigate(query === '' ? route : `${route}?${query}`)
  }

  return (
    <DashboardPanel
      title="Purchase intelligence opportunities"
      description={panel.available ? 'Prioritised by estimated impact and weight of evidence' : undefined}
      className="purchase-intel-opportunities"
      action={
        panel.available && panel.cards.length > 0 ? (
          <button type="button" onClick={onViewAll} className="purchase-panel__action">
            View all
          </button>
        ) : undefined
      }
      flush
    >
      {!panel.available ? (
        <div style={{ padding: '0 1.25rem 1.25rem' }}>
          <PanelUnavailable reason={panel.reason} kind={panel.kind} />
        </div>
      ) : panel.cards.length === 0 ? (
        <div style={{ padding: '0 1.25rem 1.25rem' }}>
          <EmptyState title="No savings opportunities currently identified.">
            These rules look for fragmented buying, rates rising against their own history and repeated small orders.
          </EmptyState>
        </div>
      ) : (
        <>
          <div className="purchase-table-scroll">
            <table className="purchase-table purchase-table--compact">
              <caption className="purchase-sr-only">{panel.basis}</caption>
              <thead>
                <tr>
                  <th scope="col" style={{ width: 28 }}>
                    #
                  </th>
                  <th scope="col">Opportunity</th>
                  <th scope="col" className="is-numeric">
                    Impact
                  </th>
                  <th scope="col">Evidence</th>
                  <th scope="col">Area</th>
                  <th scope="col">Priority</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                {panel.cards.map((card, index) => (
                  <tr key={card.id}>
                    <td className="purchase-table__rank">{index + 1}</td>
                    <td>
                      {/* A button, not a row click: a whole row that is secretly
                          a control cannot be reached from a keyboard. */}
                      <button type="button" className="purchase-table__link" onClick={() => setOpen(card)}>
                        {card.title}
                      </button>
                      <span className="purchase-table__sub">{card.detail}</span>
                    </td>
                    <td className="is-numeric">
                      {card.impact_formatted ?? <span className="purchase-muted">Not quantified</span>}
                    </td>
                    <td>
                      <Badge tone={strengthTone(card.evidence_strength.level)}>{card.evidence_strength.label}</Badge>
                    </td>
                    <td>{card.area_label}</td>
                    <td>
                      <Badge tone={priorityTone(card.priority)}>{card.priority_label}</Badge>
                    </td>
                    <td>
                      <Badge tone={card.status === 'compare' ? 'success' : 'warning'}>{card.status_label}</Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="purchase-panel__note">{panel.ranking}</p>
        </>
      )}

      <Drawer
        open={open !== null}
        title={open?.title ?? ''}
        subtitle={
          open === null ? undefined : (
            <>
              {open.area_label} · {open.priority_label} priority · {open.evidence_strength.label} evidence
            </>
          )
        }
        onClose={() => setOpen(null)}
      >
        {open && (
          <div className="purchase-intel-drawer">
            <dl className="purchase-dl">
              <div>
                <dt>Estimated impact</dt>
                <dd>{open.impact_formatted ?? 'Not quantified'}</dd>
              </div>
              <div>
                <dt>Baseline</dt>
                <dd>{open.baseline_formatted}</dd>
              </div>
              <div>
                <dt>Evidence</dt>
                <dd>
                  {open.evidence_strength.label} · {open.evidence_strength.observations} observation
                  {open.evidence_strength.observations === 1 ? '' : 's'}
                </dd>
              </div>
            </dl>

            <section>
              <h3>Why this was detected</h3>
              <p>{open.detail}</p>
              <p className="purchase-muted">{open.evidence_strength.basis}</p>
            </section>

            <section>
              <h3>What the estimate assumes</h3>
              <p>{open.assumption}</p>
            </section>

            <section>
              <h3>How it was prioritised</h3>
              <p>{open.priority_basis}</p>
            </section>

            {Object.keys(open.evidence).length > 0 && (
              <section>
                <h3>Records behind it</h3>
                <dl className="purchase-dl">
                  {Object.entries(open.evidence).map(([key, value]) => (
                    <div key={key}>
                      <dt>{key.replace(/_/g, ' ').replace(/^./, (character) => character.toUpperCase())}</dt>
                      <dd>{String(value)}</dd>
                    </div>
                  ))}
                </dl>
              </section>
            )}

            <div className="purchase-intel-drawer__actions">
              <button
                type="button"
                className="purchase-button purchase-button--primary"
                onClick={() => {
                  const target = open
                  setOpen(null)
                  go(target.route, target.filters)
                }}
              >
                {open.action_label} <ArrowRight size={14} aria-hidden />
              </button>
              <button type="button" className="purchase-button purchase-button--secondary" onClick={() => setOpen(null)}>
                Close
              </button>
            </div>

            {/* Said plainly, because a drawer with a Dismiss button that forgets
                itself on the next refresh is worse than no button at all. */}
            <p className="purchase-muted purchase-intel-drawer__note">
              <Lightbulb size={13} aria-hidden /> Opportunities are recomputed from your orders each time this screen
              loads. There is nowhere to record that one was reviewed or dismissed, so nothing here pretends to remember
              it.
            </p>
          </div>
        )}
      </Drawer>
    </DashboardPanel>
  )
}
