/**
 * What needs attention, and where to go and do something about it.
 *
 * Every line is a count over rows already on this screen, and every line opens
 * the screen that holds those rows. A risk list that cannot be clicked is a
 * list that gets read once.
 */

import { AlertTriangle, ChevronRight, FileWarning, ShieldAlert, Timer, UserX } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { DashboardPanel, EmptyState, PanelUnavailable } from '../../shell'
import type { MatrixPanel } from '../types'
import type { Severity, SupplierRiskItem } from '../signals'

const FACE: Record<string, LucideIcon> = {
  delayed: Timer,
  'on-time': Timer,
  acceptance: AlertTriangle,
  claims: FileWarning,
  'risk-flag': ShieldAlert,
  qualification: UserX,
}

const TONE: Record<Severity, string> = {
  high: 'is-danger',
  medium: 'is-warning',
  low: 'is-info',
  info: 'is-info',
}

const WORD: Record<Severity, string> = {
  high: 'Urgent',
  medium: 'Attention',
  low: 'For information',
  info: 'For information',
}

export function RiskPanel({
  matrix,
  items,
  onOpen,
}: {
  matrix: MatrixPanel
  items: SupplierRiskItem[]
  onOpen: (route: string, filters?: Record<string, string>) => void
}) {
  return (
    <DashboardPanel
      title="Supplier risk & issues"
      description="Open positions, not period totals"
      flush
      action={
        items.length > 0 ? (
          <button type="button" className="purchase-button purchase-button--quiet" onClick={() => onOpen('/claims')}>
            View all
          </button>
        ) : undefined
      }
    >
      {!matrix.available ? (
        <div style={{ padding: '0 17px 17px' }}>
          <PanelUnavailable reason={matrix.reason} kind={matrix.kind} />
        </div>
      ) : items.length === 0 ? (
        <EmptyState reassuring title="Nothing is flagged in this period.">
          No overdue lines, no open claims and no risk flags on the suppliers bought from.
        </EmptyState>
      ) : (
        items.map((item) => {
          const Face = FACE[item.id] ?? AlertTriangle

          return (
            <button
              key={item.id}
              type="button"
              className="purchase-row"
              onClick={() => onOpen(item.route, item.filters)}
            >
              <span className={`purchase-row__icon ${TONE[item.severity]}`} aria-hidden>
                <Face size={15} />
              </span>

              <span className="purchase-row__body">
                <span className="purchase-row__title">
                  {/* The severity is a word before it is a colour. */}
                  <span className={`purchase-row__tag ${TONE[item.severity]}`}>{WORD[item.severity]}</span>
                  {item.title}
                </span>
                <span className="purchase-row__subtitle">{item.detail}</span>
              </span>

              <ChevronRight size={15} className="purchase-row__chevron" aria-hidden />
            </button>
          )
        })
      )}
    </DashboardPanel>
  )
}
