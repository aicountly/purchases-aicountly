/**
 * Reports.
 *
 * The supplied designs carry a Reports entry in the sidebar, and it should not
 * lead to a dead link or to a page that implies a report suite exists. What
 * exists today is five dashboards, each of which exports exactly the figures it
 * shows, from the same code that drew them — so an export and a screen can
 * never disagree. That is what this page hands you.
 *
 * It also says plainly what is NOT here. A page that listed twelve report names
 * and produced nothing would be worse than one that lists five and produces
 * them.
 */

import { Link } from 'react-router-dom'
import { BarChart3, Download, ExternalLink } from 'lucide-react'
import { getApiBaseUrl } from '../config'
import { usePurchases } from '../context/PurchasesContext'
import '../dashboards/purchase.css'

const REPORTS = [
  {
    view: 'overview',
    title: 'Purchase overview',
    detail: 'Posted purchases, open commitment, supplier dues and what is waiting on you.',
  },
  {
    view: 'procurement',
    title: 'Procurement workspace',
    detail: 'Requests, open orders, fulfilment by supplier, delayed lines and expected arrivals.',
  },
  {
    view: 'suppliers',
    title: 'Supplier performance',
    detail: 'On-time delivery, acceptance, lead time, spend concentration and scorecards.',
  },
  {
    view: 'bills-payables',
    title: 'Bills and payables',
    detail: 'Payables ageing, three-way match results and the invoice workbench.',
  },
  {
    view: 'ai-insights',
    title: 'Purchase intelligence',
    detail: 'Rules-based anomalies, duplicate candidates and price movement evidence.',
  },
] as const

export default function Reports() {
  const { scope } = usePurchases()

  // The same link the dashboard's own Export button builds, so a report taken
  // from here and one taken from the screen are the same request.
  const exportUrl = (view: string) => {
    const params = new URLSearchParams()
    if (scope) {
      params.set('cmp_id', String(scope.cmp_id))
      params.set('fy_id', String(scope.fy_id))
      params.set('bo_id', String(scope.bo_id))
    }
    return `${getApiBaseUrl()}/v1/dashboards/${view}/export?${params.toString()}`
  }

  return (
    <div className="purchase-workspace">
      <header className="purchase-page-header">
        <div style={{ minWidth: 0 }}>
          <p className="purchase-eyebrow">Aicountly Purchases</p>
          <h1>Reports</h1>
          <p className="purchase-page-subtitle">
            Every export comes from the same code that draws the screen, so the two cannot disagree.
          </p>
        </div>
      </header>

      <div className="purchase-dashboard-content">
        <div className="purchase-dashboard-grid">
          <section className="purchase-panel purchase-span-all">
            <header className="purchase-panel__header">
              <div>
                <h2>
                  <BarChart3 size={17} aria-hidden /> Available now
                </h2>
                <p>Open the screen, or take the figures as a CSV for the period and filters it is showing.</p>
              </div>
            </header>
            <div className="purchase-panel__body--flush">
              <div className="purchase-table-scroll">
                <table className="purchase-table">
                  <thead>
                    <tr>
                      <th scope="col">Report</th>
                      <th scope="col">What it covers</th>
                      <th scope="col" style={{ width: 210 }}>
                        <span className="purchase-sr-only">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {REPORTS.map((report) => (
                      <tr key={report.view}>
                        <td>
                          <strong>{report.title}</strong>
                        </td>
                        <td className="purchase-muted">{report.detail}</td>
                        <td>
                          <div style={{ display: 'flex', gap: '0.4rem', justifyContent: 'flex-end' }}>
                            <Link
                              className="purchase-button purchase-button--secondary"
                              to={`/dashboard/${report.view}`}
                            >
                              <ExternalLink size={14} aria-hidden /> Open
                            </Link>
                            <a
                              className="purchase-button purchase-button--secondary"
                              href={exportUrl(report.view)}
                            >
                              <Download size={14} aria-hidden /> CSV
                            </a>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          <section className="purchase-panel purchase-span-all">
            <header className="purchase-panel__header">
              <div>
                <h2>Not built yet</h2>
                <p>Listed so nobody plans around a report that does not exist.</p>
              </div>
            </header>
            <div className="purchase-panel__body">
              <ul style={{ margin: 0, paddingLeft: '1.1rem', display: 'grid', gap: '0.5rem', fontSize: '0.89rem' }}>
                <li>
                  <strong>PDF output.</strong> Exports are CSV only. A print-ready renderer is not
                  wired up, so there is nothing here that produces one.
                </li>
                <li>
                  <strong>Supplier statement reconciliation.</strong> Matching a supplier&apos;s own
                  statement against our ledger needs an import pipeline that does not exist yet.
                </li>
                <li>
                  <strong>Scheduled delivery.</strong> Nothing here emails or files a report on a
                  timetable.
                </li>
              </ul>
            </div>
          </section>
        </div>
      </div>
    </div>
  )
}
