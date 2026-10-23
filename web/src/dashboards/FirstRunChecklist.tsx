import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2, Circle, ExternalLink } from 'lucide-react'
import { api } from '../services/api'
import { getAppById, launchApp } from '../services/appLauncher'
import { usePurchases } from '../context/PurchasesContext'
import { countOf, firstRunSteps, needsFirstRun } from './firstRun'
import type { FirstRunCounts } from './firstRun'

/**
 * The first-run checklist on the overview (launch-readiness P1-P8): shown only while this company has
 * no supplier, no item or no purchase order yet, then gone. Each undone step links to where it is done.
 * A count that could not be read (Books or Inventory unavailable) is never shown as "to do".
 */
export function FirstRunChecklist() {
  const { scope, can } = usePurchases()
  const [counts, setCounts] = useState<FirstRunCounts | null>(null)

  useEffect(() => {
    if (!scope) return
    const controller = new AbortController()
    const read = (path: string) =>
      api.get<unknown>(path, { limit: 1 }, controller.signal).then(countOf, () => null)
    Promise.all([read('v1/catalog/suppliers'), read('v1/catalog/items'), read('v1/purchase-orders')]).then(([suppliers, items, orders]) => {
      if (!controller.signal.aborted) setCounts({ suppliers, items, orders })
    })
    return () => controller.abort()
  }, [scope])

  if (!counts || !needsFirstRun(counts)) return null
  const steps = firstRunSteps(counts, { addSupplier: can('supplier.manage'), createOrder: can('po.create') })
  const next = steps.find((s) => !s.done)?.key

  return (
    <section className="purchase-panel first-run" aria-label="Get started">
      <h2 className="first-run__title">Get this company ready for purchasing</h2>
      <p className="first-run__lead">Three things before the first purchase order. Each takes a minute.</p>
      <ol className="first-run__steps">
        {steps.map((step, i) => (
          <li key={step.key} className={`first-run__step${step.done ? ' is-done' : step.key === next ? ' is-next' : ''}`}>
            {step.done ? <CheckCircle2 size={16} aria-label="Done" className="first-run__mark is-done" /> : <Circle size={16} aria-hidden className="first-run__mark" />}
            <span>
              <span className="first-run__step-title">
                {i + 1}. {step.title}
              </span>
              <span className="first-run__hint">{step.hint}</span>
              {!step.done && step.to ? (
                <Link to={step.to} className="first-run__action">
                  {step.action}
                </Link>
              ) : null}
              {!step.done && step.external ? (
                <button type="button" className="first-run__action first-run__action--link" onClick={() => launchApp(getAppById(step.external ?? ''), { newTab: true })}>
                  {step.action} <ExternalLink size={12} aria-hidden />
                </button>
              ) : null}
            </span>
          </li>
        ))}
      </ol>
    </section>
  )
}
