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

import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BarChart3, Download, ExternalLink, FileText, Loader2 } from 'lucide-react'
import { api } from '../services/api'
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
  usePurchases()
  const [busy, setBusy] = useState<string | null>(null)
  const [failed, setFailed] = useState<string | null>(null)

  // Fetched with the session key and saved from the Blob. A plain link cannot
  // carry a bearer token, so a link here answers 401 and the click looks like
  // it did nothing.
  const take = async (view: string, format: 'csv' | 'pdf') => {
    setBusy(`${view}:${format}`)
    setFailed(null)
    try {
      await api.download(`v1/dashboards/${view}/export`, `purchases-${view}.${format}`, { format })
    } catch (error) {
      setFailed(error instanceof Error ? error.message : 'That export could not be produced.')
    } finally {
      setBusy(null)
    }
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
        {failed !== null && (
          <div className="purchase-notice purchase-notice--danger" style={{ marginBottom: '1rem' }}>
            {failed}
          </div>
        )}

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
                            <button
                              type="button"
                              className="purchase-button purchase-button--secondary"
                              onClick={() => void take(report.view, 'csv')}
                              disabled={busy !== null}
                            >
                              {busy === `${report.view}:csv` ? (
                                <Loader2 size={14} aria-hidden />
                              ) : (
                                <Download size={14} aria-hidden />
                              )}{' '}
                              CSV
                            </button>
                            <button
                              type="button"
                              className="purchase-button purchase-button--secondary"
                              onClick={() => void take(report.view, 'pdf')}
                              disabled={busy !== null}
                            >
                              {busy === `${report.view}:pdf` ? (
                                <Loader2 size={14} aria-hidden />
                              ) : (
                                <FileText size={14} aria-hidden />
                              )}{' '}
                              PDF
                            </button>
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
                  <strong>Reading a scan.</strong> A PDF with text in it is read exactly. A scanned or
                  photographed document is a picture, and getting figures out of a picture needs
                  optical character recognition, which is not installed on this server. The reader
                  says which of the two it was given rather than returning an empty result.
                </li>
                <li>
                  <strong>Creating a bill from an uploaded invoice.</strong> A document can be read —
                  that is what statement reconciliation uses — but nothing turns one into a bill. A
                  bill is a financial document, and creating one from a parsed file needs the
                  duplicate refusal, tolerance and approval rules manual entry already has.
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
