/**
 * Importing a file of returns.
 *
 * TWO STEPS, AND THE FIRST ONE WRITES NOTHING. The file is read, the columns
 * are reported, and every row gets a verdict with the offending cell quoted
 * back — all before anything is created. A file with a mis-read quantity column
 * imports confidently and completely wrongly, and showing somebody what was
 * read is the only defence against that.
 *
 * Only rows that passed are created, and each one is created as a DRAFT through
 * the same call a buyer uses by hand. An import is not a side door: nothing
 * reaches Inventory or Smart Books because a spreadsheet said so.
 */

import { useRef, useState } from 'react'
import { AlertTriangle, Download, FileUp, Upload } from 'lucide-react'
import { ApiError } from '../../services/api'
import { purchaseReturnsApi } from './api'
import type { ImportPreview } from './types'
import { Drawer, Notice } from './ui'

/**
 * The template, built here rather than fetched.
 *
 * It is a fixed header row and one example — it has no data in it and there is
 * nothing for a server to compute. The column names are the ones the reader's
 * vocabulary recognises.
 */
const TEMPLATE_COLUMNS = [
  'Return date',
  'Supplier account',
  'Supplier name',
  'Source document',
  'Item',
  'Quantity',
  'Rate',
  'Reason',
  'Warehouse',
  'Notes',
]

function downloadTemplate() {
  const csv = [
    TEMPLATE_COLUMNS.join(','),
    '22/09/2026,601,Metro Electronics Pvt. Ltd.,PO/2026/0042,201,4,250,Damaged goods,3,Two cartons crushed in transit',
  ].join('\r\n')

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'purchase-returns-template.csv'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export function ImportDrawer({
  open,
  onClose,
  onImported,
}: {
  open: boolean
  onClose: () => void
  onImported: (created: number, failed: number) => void
}) {
  const input = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const [reading, setReading] = useState(false)
  const [creating, setCreating] = useState(false)
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [error, setError] = useState<string | null>(null)

  function reset() {
    setPreview(null)
    setError(null)
    setReading(false)
    setCreating(false)
  }

  async function read(file: File) {
    setReading(true)
    setError(null)
    setPreview(null)

    try {
      const response = await purchaseReturnsApi.importPreview(file)
      setPreview(response.data)
    } catch (failure) {
      setError(failure instanceof ApiError ? failure.message : String(failure))
    } finally {
      setReading(false)
    }
  }

  async function commit() {
    if (!preview || preview.returns.length === 0) return
    setCreating(true)
    setError(null)

    try {
      const response = await purchaseReturnsApi.importCommit(preview.returns)
      onImported(response.data.created_count, response.data.failed.length)
      reset()
    } catch (failure) {
      setError(failure instanceof ApiError ? failure.message : String(failure))
    } finally {
      setCreating(false)
    }
  }

  return (
    <Drawer
      open={open}
      wide
      title="Import returns"
      subtitle="Read the file, check what it says, then create the drafts."
      onClose={() => {
        reset()
        onClose()
      }}
      footer={
        <>
          <button type="button" className="pr-btn pr-btn--quiet" onClick={downloadTemplate}>
            <Download size={14} aria-hidden /> Download the template
          </button>
          <span style={{ flex: 1 }} />
          <button
            type="button"
            className="pr-btn"
            onClick={() => {
              reset()
              onClose()
            }}
          >
            Cancel
          </button>
          <button
            type="button"
            className="pr-btn pr-btn--primary"
            disabled={!preview || preview.returns.length === 0 || creating}
            onClick={commit}
          >
            {creating
              ? 'Creating…'
              : preview
                ? `Create ${preview.summary.returns} draft${preview.summary.returns === 1 ? '' : 's'}`
                : 'Create the drafts'}
          </button>
        </>
      }
    >
      {error && <Notice tone="danger">{error}</Notice>}

      <div
        className={dragging ? 'pr-dropzone is-over' : 'pr-dropzone'}
        onDragOver={(event) => {
          event.preventDefault()
          setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          event.preventDefault()
          setDragging(false)
          const file = event.dataTransfer.files?.[0]
          if (file) void read(file)
        }}
      >
        <FileUp size={26} aria-hidden style={{ color: 'var(--pr-primary)' }} />
        <strong>{preview ? preview.file.name : 'Drop a CSV or a workbook here'}</strong>
        <span>
          Columns: {TEMPLATE_COLUMNS.join(', ')}. The header does not have to be the first row — a letterhead above it is
          read and skipped.
        </span>
        <input
          ref={input}
          type="file"
          accept=".csv,.tsv,.txt,.xlsx,.xls"
          hidden
          onChange={(event) => {
            const file = event.target.files?.[0]
            if (file) void read(file)
            // Cleared so choosing the same file twice still fires a change.
            event.target.value = ''
          }}
        />
        <button type="button" className="pr-btn" disabled={reading} onClick={() => input.current?.click()}>
          <Upload size={14} aria-hidden /> {reading ? 'Reading…' : 'Choose a file'}
        </button>
      </div>

      {preview && (
        <>
          {preview.notes.length > 0 && (
            <Notice tone="warning">
              <ul style={{ margin: 0, paddingLeft: 16 }}>
                {preview.notes.map((note) => (
                  <li key={note}>{note}</li>
                ))}
              </ul>
            </Notice>
          )}

          <div className="pr-section">
            <h3>What was read</h3>
            <dl className="pr-facts">
              <dt>Rows</dt>
              <dd>{preview.summary.rows}</dd>
              <dt>Usable</dt>
              <dd style={{ color: 'var(--pr-success)', fontWeight: 650 }}>{preview.summary.valid}</dd>
              <dt>With a problem</dt>
              <dd style={{ color: preview.summary.errors > 0 ? 'var(--pr-danger)' : undefined, fontWeight: 650 }}>
                {preview.summary.errors}
              </dd>
              <dt>Returns to create</dt>
              <dd>{preview.summary.returns}</dd>
            </dl>

            {preview.summary.returns > 0 && preview.summary.returns < preview.summary.valid && (
              <p className="pr-field__hint" style={{ marginTop: 8 }}>
                Lines for the same supplier, source document and date are grouped into one return — which is what a
                return is. Ten lines against one order make one return with ten lines, not ten returns.
              </p>
            )}
          </div>

          <div className="pr-section">
            <h3>Every row</h3>
            <div className="pr-import-rows">
              <table>
                <thead>
                  <tr>
                    <th>Row</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Source</th>
                    <th>Item</th>
                    <th style={{ textAlign: 'right' }}>Qty</th>
                    <th style={{ textAlign: 'right' }}>Rate</th>
                    <th>Reason</th>
                    <th>Verdict</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.rows.map((row) => (
                    <tr key={row.row} className={row.ok ? undefined : 'is-bad'}>
                      <td>{row.row}</td>
                      <td>{row.cells.return_date || row.return_date}</td>
                      <td>{row.supplier_name ?? row.cells.supplier ?? '—'}</td>
                      <td>{row.po_no ?? row.cells.source ?? '—'}</td>
                      <td>{row.cells.item || '—'}</td>
                      <td style={{ textAlign: 'right' }}>{row.cells.quantity}</td>
                      <td style={{ textAlign: 'right' }}>{row.cells.rate}</td>
                      <td>{row.reason_code ?? row.cells.reason ?? '—'}</td>
                      <td>
                        {row.ok ? (
                          <span style={{ color: 'var(--pr-success)', fontWeight: 650 }}>Ready</span>
                        ) : (
                          <span className="pr-import-errors">
                            <AlertTriangle size={11} aria-hidden style={{ verticalAlign: -1, marginRight: 3 }} />
                            {row.errors.join(' ')}
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <p className="pr-ai-basis" style={{ marginTop: 10 }}>
              {preview.retention ?? 'The file was read and discarded. Only the returns you create are kept.'}
            </p>
          </div>

          {preview.summary.errors > 0 && (
            <Notice tone="warning">
              {preview.summary.returns === 0
                ? 'Every row has a problem, so there is nothing to create. Fix them in the file and import it again.'
                : `Rows with a problem are left out. Fix them in the file and import it again, or create the ${
                    preview.summary.returns === 1 ? 'one return' : `${preview.summary.returns} returns`
                  } that ${preview.summary.returns === 1 ? 'is' : 'are'} ready now.`}
            </Notice>
          )}
        </>
      )}
    </Drawer>
  )
}
