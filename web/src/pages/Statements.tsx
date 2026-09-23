/**
 * Reconciling a supplier's statement against Smart Books.
 *
 * TWO STEPS ON PURPOSE. The file is read and shown first, and compared only
 * when somebody has looked at how its columns were understood. A statement read
 * with the wrong amount column reconciles confidently and completely wrongly,
 * and there is no way to tell from the result that it happened — so the check
 * happens before the comparison rather than after it.
 *
 * NOTHING IS UPLOADED IN THE SENSE OF BEING KEPT. The file is parsed in memory
 * on the server and discarded before the answer is written. It is the
 * supplier's document; this product has no reason to hold a copy and every
 * reason not to.
 */

import { useCallback, useRef, useState } from 'react'
import {
  AlertTriangle,
  CheckCircle2,
  FileSpreadsheet,
  FileUp,
  Info,
  Loader2,
  RotateCcw,
  Scale,
} from 'lucide-react'
import { api } from '../services/api'
import { usePurchases } from '../context/PurchasesContext'
import '../dashboards/purchase.css'

interface PreviewResponse {
  file: { name: string; kind: string; readable: boolean }
  table: { headers: string[]; rows: string[][]; row_count: number; notes: string[]; source: string }
  mapping: {
    header_row: number
    headers: string[]
    columns: Record<string, number | null>
    notes: string[]
    vocabulary: string[]
  }
  sample: string[][]
  guidance: string
}

interface ReconcileResponse {
  file: { name: string; kind: string; notes: string[] }
  mapping: PreviewResponse['mapping']
  period: { from: string; to: string }
  report: {
    statement_total: string
    ledger_total: string
    difference: string
    lines_read: number
    lines_skipped: number
    basis: string
    buckets: {
      id: string
      label: string
      tone: 'good' | 'warn' | 'bad'
      count: number
      meaning: string
      rows: Record<string, unknown>[]
    }[]
  }
  retention: string
}

/** The fields worth asking about. The API knows more; these are the ones a statement has. */
const FIELDS: { id: string; label: string; hint: string }[] = [
  { id: 'date', label: 'Date', hint: 'The date on each line' },
  { id: 'reference', label: 'Invoice reference', hint: 'Matched first, and punctuation is ignored' },
  { id: 'description', label: 'Narration', hint: 'Shown, never matched on' },
  { id: 'amount', label: 'Amount', hint: 'Or leave blank and set debit instead' },
  { id: 'debit', label: 'Debit', hint: 'For statements with separate columns' },
  { id: 'credit', label: 'Credit', hint: 'For statements with separate columns' },
]

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function monthsAgo(months: number): string {
  const date = new Date()
  date.setMonth(date.getMonth() - months)
  return date.toISOString().slice(0, 10)
}

export default function Statements() {
  const { can } = usePurchases()
  const fileInput = useRef<HTMLInputElement | null>(null)

  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<PreviewResponse | null>(null)
  const [columns, setColumns] = useState<Record<string, number | null>>({})
  const [supplierId, setSupplierId] = useState('')
  const [from, setFrom] = useState(monthsAgo(6))
  const [to, setTo] = useState(today())
  const [report, setReport] = useState<ReconcileResponse | null>(null)
  const [busy, setBusy] = useState<'reading' | 'reconciling' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const mayRead = can('bill.enter')
  const mayReconcile = can('cost.view')

  const reset = useCallback(() => {
    setFile(null)
    setPreview(null)
    setColumns({})
    setReport(null)
    setError(null)
    if (fileInput.current) fileInput.current.value = ''
  }, [])

  const read = useCallback(async (chosen: File) => {
    setBusy('reading')
    setError(null)
    setReport(null)
    try {
      const form = new FormData()
      form.append('file', chosen)
      const response = await api.upload<PreviewResponse>('v1/import/preview', form)
      setPreview(response.data)
      setColumns(response.data.mapping.columns)
    } catch (e) {
      setPreview(null)
      setError(e instanceof Error ? e.message : 'That file could not be read.')
    } finally {
      setBusy(null)
    }
  }, [])

  const reconcile = useCallback(async () => {
    if (file === null) return
    setBusy('reconciling')
    setError(null)
    try {
      const form = new FormData()
      form.append('file', file)
      form.append('columns', JSON.stringify(columns))
      const response = await api.upload<ReconcileResponse>('v1/import/reconcile-statement', form, {
        supplier_account_id: supplierId,
        from,
        to,
      })
      setReport(response.data)
    } catch (e) {
      setReport(null)
      setError(e instanceof Error ? e.message : 'The reconciliation could not be run.')
    } finally {
      setBusy(null)
    }
  }, [file, columns, supplierId, from, to])

  return (
    <div className="purchase-workspace">
      <header className="purchase-page-header">
        <div style={{ minWidth: 0 }}>
          <h1>Supplier statements</h1>
          <p className="purchase-page-subtitle">
            Compare what a supplier says we owe with what Smart Books holds.
          </p>
        </div>
        {(preview !== null || report !== null) && (
          <div className="purchase-header-actions">
            <button type="button" className="purchase-button purchase-button--secondary" onClick={reset}>
              <RotateCcw size={15} aria-hidden /> Start again
            </button>
          </div>
        )}
      </header>

      <div className="purchase-dashboard-content">
        {!mayRead && (
          <div className="purchase-notice purchase-notice--warning" style={{ marginBottom: '1rem' }}>
            Reading a statement needs the “Enter a supplier bill” permission, and comparing it with the
            ledger needs “See historical purchase prices and spend”. Ask whoever manages access.
          </div>
        )}

        {error !== null && (
          <div className="purchase-notice purchase-notice--danger" style={{ marginBottom: '1rem' }}>
            {error}
          </div>
        )}

        <div className="purchase-dashboard-grid">
          {/* ---------------------------------------------------- step one */}
          <section className="purchase-panel purchase-span-all">
            <header className="purchase-panel__header">
              <div>
                <h2>
                  <FileUp size={17} aria-hidden /> 1 · Choose the statement
                </h2>
                <p>
                  CSV, XLSX, or a PDF that has text in it. A scan is a picture and cannot be read —
                  export the statement instead of printing it.
                </p>
              </div>
            </header>
            <div className="purchase-panel__body">
              <input
                ref={fileInput}
                type="file"
                accept=".csv,.txt,.xlsx,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                disabled={!mayRead || busy !== null}
                onChange={(event) => {
                  const chosen = event.target.files?.[0] ?? null
                  setFile(chosen)
                  setReport(null)
                  if (chosen !== null) void read(chosen)
                }}
                style={{ maxWidth: '26rem' }}
              />

              {busy === 'reading' && (
                <p className="purchase-muted" style={{ marginTop: '0.75rem', fontSize: '0.88rem' }}>
                  <Loader2 size={14} aria-hidden /> Reading {file?.name}…
                </p>
              )}

              <p className="purchase-soft" style={{ marginTop: '0.85rem', fontSize: '0.8rem' }}>
                The file is read on the server and discarded. Nothing about it is stored except that a
                reconciliation was run, by whom, and for which supplier.
              </p>
            </div>
          </section>

          {/* ---------------------------------------------------- step two */}
          {preview !== null && (
            <section className="purchase-panel purchase-span-all">
              <header className="purchase-panel__header">
                <div>
                  <h2>
                    <FileSpreadsheet size={17} aria-hidden /> 2 · Check how it was read
                  </h2>
                  <p>{preview.guidance}</p>
                </div>
                <span className="purchase-badge purchase-badge--neutral">
                  {preview.file.kind.toUpperCase()} · {preview.table.row_count} rows
                </span>
              </header>
              <div className="purchase-panel__body">
                {[...preview.table.notes, ...preview.mapping.notes].map((note, index) => (
                  <p key={index} className="purchase-notice purchase-notice--info" style={{ marginBottom: '0.6rem' }}>
                    <Info size={14} aria-hidden style={{ marginRight: 6, verticalAlign: -2 }} />
                    {note}
                  </p>
                ))}

                {preview.file.readable && (
                  <>
                    <div className="purchase-filterbar" style={{ padding: '0.5rem 0 1rem' }}>
                      {FIELDS.map((field) => (
                        <label key={field.id} title={field.hint}>
                          {field.label}
                          <select
                            value={columns[field.id] ?? ''}
                            onChange={(event) =>
                              setColumns((current) => ({
                                ...current,
                                [field.id]: event.target.value === '' ? null : Number(event.target.value),
                              }))
                            }
                          >
                            <option value="">Not in this file</option>
                            {preview.mapping.headers.map((header, index) => (
                              <option key={index} value={index}>
                                {header.trim() === '' ? `Column ${index + 1}` : header}
                              </option>
                            ))}
                          </select>
                        </label>
                      ))}
                    </div>

                    <div className="purchase-table-scroll">
                      <table className="purchase-table">
                        <thead>
                          <tr>
                            {preview.mapping.headers.map((header, index) => (
                              <th key={index} scope="col">
                                {header.trim() === '' ? `Column ${index + 1}` : header}
                              </th>
                            ))}
                          </tr>
                        </thead>
                        <tbody>
                          {preview.sample.slice(0, 8).map((row, rowIndex) => (
                            <tr key={rowIndex}>
                              {preview.mapping.headers.map((_, cellIndex) => (
                                <td key={cellIndex}>{row[cellIndex] ?? ''}</td>
                              ))}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </>
                )}
              </div>
            </section>
          )}

          {/* -------------------------------------------------- step three */}
          {preview !== null && preview.file.readable && (
            <section className="purchase-panel purchase-span-all">
              <header className="purchase-panel__header">
                <div>
                  <h2>
                    <Scale size={17} aria-hidden /> 3 · Compare with Smart Books
                  </h2>
                  <p>
                    Smart Books answers one supplier at a time, so this needs the supplier account and
                    the period the statement covers.
                  </p>
                </div>
              </header>
              <div className="purchase-panel__body">
                <div className="purchase-filterbar" style={{ padding: '0 0 1rem' }}>
                  <label>
                    Supplier account
                    <input
                      type="text"
                      inputMode="numeric"
                      value={supplierId}
                      placeholder="Account id"
                      onChange={(event) => setSupplierId(event.target.value.replace(/\D/g, ''))}
                    />
                  </label>
                  <label>
                    From
                    <input type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
                  </label>
                  <label>
                    To
                    <input type="date" value={to} onChange={(event) => setTo(event.target.value)} />
                  </label>
                  <button
                    type="button"
                    className="purchase-button purchase-button--primary"
                    onClick={() => void reconcile()}
                    disabled={!mayReconcile || supplierId === '' || busy !== null}
                  >
                    {busy === 'reconciling' ? <Loader2 size={15} aria-hidden /> : <Scale size={15} aria-hidden />}
                    {busy === 'reconciling' ? 'Comparing…' : 'Reconcile'}
                  </button>
                </div>
              </div>
            </section>
          )}

          {/* ------------------------------------------------------ result */}
          {report !== null && <Result report={report} />}
        </div>
      </div>
    </div>
  )
}

function Result({ report }: { report: ReconcileResponse }) {
  const { buckets } = report.report

  return (
    <>
      <section className="purchase-panel purchase-span-all">
        <header className="purchase-panel__header">
          <div>
            <h2>What the two sides say</h2>
            <p>{report.report.basis}</p>
          </div>
        </header>
        <div className="purchase-panel__body">
          <dl className="purchase-dl" style={{ maxWidth: '26rem' }}>
            <div>
              <dt>Statement total</dt>
              <dd>{report.report.statement_total}</dd>
            </div>
            <div>
              <dt>Smart Books total</dt>
              <dd>{report.report.ledger_total}</dd>
            </div>
            <div>
              <dt>Difference</dt>
              <dd>{report.report.difference}</dd>
            </div>
            <div>
              <dt>Lines read</dt>
              <dd>
                {report.report.lines_read}
                {report.report.lines_skipped > 0 && (
                  <span className="purchase-soft">
                    {' '}
                    ({report.report.lines_skipped} with no amount skipped)
                  </span>
                )}
              </dd>
            </div>
          </dl>
          <p className="purchase-soft" style={{ marginTop: '0.9rem', fontSize: '0.8rem' }}>
            {report.retention}
          </p>
        </div>
      </section>

      {buckets.map((bucket) => (
        <section
          key={bucket.id}
          className={
            bucket.count === 0
              ? 'purchase-panel purchase-span-all'
              : bucket.tone === 'bad'
                ? 'purchase-panel purchase-span-all purchase-panel--warning'
                : 'purchase-panel purchase-span-all'
          }
        >
          <header className="purchase-panel__header">
            <div>
              <h2>
                {bucket.tone === 'good' ? (
                  <CheckCircle2 size={17} aria-hidden />
                ) : (
                  <AlertTriangle size={17} aria-hidden />
                )}
                {bucket.label}
                <span
                  className={`purchase-badge purchase-badge--${
                    bucket.tone === 'good' ? 'good' : bucket.tone === 'bad' ? 'danger' : 'warning'
                  }`}
                >
                  {bucket.count}
                </span>
              </h2>
              <p>{bucket.meaning}</p>
            </div>
          </header>

          {bucket.count > 0 && (
            <div className="purchase-panel__body--flush">
              <div className="purchase-table-scroll">
                <table className="purchase-table">
                  <thead>
                    <tr>
                      <th scope="col">Statement</th>
                      <th scope="col">Smart Books</th>
                      <th scope="col">Note</th>
                    </tr>
                  </thead>
                  <tbody>
                    {bucket.rows.map((row, index) => (
                      <tr key={index}>
                        <td>{describeStatement(bucket.id, row)}</td>
                        <td>{describeBill(bucket.id, row)}</td>
                        <td className="purchase-muted">{describeNote(row)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </section>
      ))}
    </>
  )
}

/**
 * The rows differ in shape by bucket, so the BUCKET decides how to read them.
 *
 * Sniffing the shape instead was wrong in a way worth keeping a note about: a
 * bill row has an `amount` too, so "does it have an amount?" answered yes for a
 * bill and printed it in the statement column — a row that exists only in Smart
 * Books rendered as though the supplier had billed it.
 */
function describeStatement(bucketId: string, row: Record<string, unknown>): string {
  if (bucketId === 'only_books') return '—'
  const line = (bucketId === 'only_statement' ? row : (row.statement ?? {})) as Record<string, unknown>
  const parts = [line.reference, line.date, line.amount_formatted ?? line.amount].filter((p) => p !== null && p !== undefined)
  return parts.length === 0 ? '—' : parts.join(' · ')
}

function describeBill(bucketId: string, row: Record<string, unknown>): string {
  if (bucketId === 'only_statement') return 'Not in Smart Books'
  const bill = (bucketId === 'only_books' ? row : (row.bill ?? {})) as Record<string, unknown>
  const parts = [bill.bill_ref, bill.bill_date, bill.amount_formatted].filter((p) => p !== null && p !== undefined)
  return parts.length === 0 ? '—' : parts.join(' · ')
}

function describeNote(row: Record<string, unknown>): string {
  if (typeof row.reason === 'string') return row.reason
  if (typeof row.difference_formatted === 'string' && row.agrees === false) {
    return `Statement is ${row.difference_formatted} away from the bill`
  }
  if (row.settled === true) return 'Settled in Smart Books — the supplier may not have applied the payment yet'
  return ''
}
