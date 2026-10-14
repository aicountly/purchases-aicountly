/**
 * Administration → Audit trail.
 *
 * Every row of this company's append-only audit log: who did what to which document, the
 * field changes (old -> new), and where the request came from. Filters run on the server
 * (GET v1/audit-log), so the total is the trail's, not this page's. The CSV is the same
 * filters, capped; a capped file says it is partial.
 *
 * Needs audit.view to read and audit.export to export — the API enforces both.
 */

import { useState, type FormEvent } from 'react'
import { Download } from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { LoadFailed, Pager } from '../components/LoadFailed'
import { AuditDrawer, SeverityBadge, actorOf, when, type AuditEntry } from '../components/AuditDetail'
import { Button, Card, DataTable, Field, Input, Notice, Select } from '../ui'

const LIMIT = 50

interface Filters {
  from: string
  to: string
  action: string
  entity_type: string
  actor_uuid: string
  severity: string
  q: string
}

const EMPTY: Filters = { from: '', to: '', action: '', entity_type: '', actor_uuid: '', severity: '', q: '' }

export default function AuditLog() {
  const { scope, can } = usePurchases()
  const [draft, setDraft] = useState<Filters>(EMPTY)
  const [filters, setFilters] = useState<Filters>(EMPTY)
  const [offset, setOffset] = useState(0)
  const [open, setOpen] = useState<AuditEntry | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const allowed = can('audit.view')
  const { data, loading, error, reload } = useApi(
    (signal) => api.list<AuditEntry>('v1/audit-log', { ...filters, limit: LIMIT, offset }, signal),
    [scope?.cmp_id, offset, JSON.stringify(filters)],
    Boolean(scope) && allowed,
  )
  const entityTypes = (data?.meta.entity_types as string[] | undefined) ?? []

  function apply(event: FormEvent) {
    event.preventDefault()
    setOffset(0)
    setFilters(draft)
  }

  function clear() {
    setDraft(EMPTY)
    setFilters(EMPTY)
    setOffset(0)
  }

  async function exportCsv() {
    setExporting(true)
    setExportError(null)
    try {
      await api.download('v1/audit-log/export', `purchases-audit-trail-${new Date().toISOString().slice(0, 10)}.csv`, { ...filters })
    } catch (err) {
      setExportError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setExporting(false)
    }
  }

  if (!allowed) {
    return (
      <Notice tone="warning" title="You cannot see the audit trail">
        Reading the audit trail needs the <code>audit.view</code> permission. The company owner, or anyone who
        administers Purchases access, can give it to you under Administration → Access.
      </Notice>
    )
  }

  const set = (key: keyof Filters) => (e: { target: { value: string } }) => setDraft({ ...draft, [key]: e.target.value })
  const total = data?.meta.total ?? 0

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '0.6rem' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Audit trail</h1>
          <p style={{ margin: '0.25rem 0 0', color: 'var(--muted)', fontSize: '0.88rem' }}>
            Every change made in Purchases for this company, as it was recorded. Rows cannot be edited or deleted.
          </p>
        </div>
        {can('audit.export') && (
          <Button onClick={exportCsv} disabled={exporting}>
            <Download size={15} aria-hidden /> {exporting ? 'Exporting…' : 'Export CSV'}
          </Button>
        )}
      </header>

      {exportError && <Notice tone="danger" title="Export failed" onDismiss={() => setExportError(null)}>{exportError}</Notice>}

      <Card>
        <form onSubmit={apply} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(10rem, 1fr))', gap: '0.75rem', alignItems: 'end' }}>
          <Field label="From"><Input type="date" value={draft.from} onChange={set('from')} /></Field>
          <Field label="To"><Input type="date" value={draft.to} onChange={set('to')} /></Field>
          <Field label="Record type">
            <Select value={draft.entity_type} onChange={set('entity_type')}>
              <option value="">Any</option>
              {entityTypes.map((t) => <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>)}
            </Select>
          </Field>
          <Field label="Action" hint="e.g. po.approve, or po.* for all"><Input value={draft.action} onChange={set('action')} /></Field>
          <Field label="Actor (user id)"><Input value={draft.actor_uuid} onChange={set('actor_uuid')} /></Field>
          <Field label="Severity">
            <Select value={draft.severity} onChange={set('severity')}>
              <option value="">Any</option>
              <option value="info">Info</option>
              <option value="material">Material</option>
              <option value="security">Security</option>
            </Select>
          </Field>
          <Field label="Search"><Input value={draft.q} placeholder="Number, summary, reason…" onChange={set('q')} /></Field>
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <Button type="submit" tone="primary">Apply</Button>
            <Button onClick={clear}>Clear</Button>
          </div>
        </form>
      </Card>

      {error && <LoadFailed what="the audit trail" error={error} onRetry={reload} />}

      <Card title={error ? 'Audit trail (not loaded)' : `${total} entr${total === 1 ? 'y' : 'ies'}`}>
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.audit_id}
          onRowClick={setOpen}
          empty={error ? 'Not loaded — see above.' : 'Nothing recorded matches these filters.'}
          columns={[
            { key: 'when', header: 'When', render: (row) => <span style={{ whiteSpace: 'nowrap' }}>{when(row.created_at)}</span> },
            { key: 'action', header: 'Action', render: (row) => <code style={{ fontSize: '0.8rem' }}>{row.action}</code> },
            { key: 'entity', header: 'Record', render: (row) => `${row.entity_name} ${row.entity_label ?? (row.entity_id ? `#${row.entity_id}` : '')}`.trim() },
            { key: 'summary', header: 'Summary', render: (row) => row.summary },
            { key: 'actor', header: 'By', render: (row) => <span title={row.actor_uuid}>{actorOf(row)}</span> },
            { key: 'severity', header: 'Severity', render: (row) => <SeverityBadge severity={row.severity} /> },
          ]}
        />
        <Pager offset={offset} limit={LIMIT} total={total} onChange={setOffset} />
      </Card>

      {open && <AuditDrawer entry={open} onClose={() => setOpen(null)} />}
    </div>
  )
}
