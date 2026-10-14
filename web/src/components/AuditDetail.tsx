/**
 * Audit trail pieces shared by the Audit trail screen and the History panel on record screens.
 *
 * Everything shown is what the row recorded at the time — the record's number, the
 * field-by-field change, the before/after snapshots. Nothing is looked up now: a profile
 * deleted last week still names itself in the row that deleted it. Actors are portal uuids,
 * shown as stored; this product does not invent names for them.
 */

import { useState } from 'react'
import { History, X } from 'lucide-react'
import { api } from '../services/api'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { Card, DataTable, Notice } from '../ui'

export interface AuditChange {
  field: string
  label: string
  old: unknown
  new: unknown
}

export interface AuditEntry {
  audit_id: number
  created_at: string
  action: string
  severity: 'info' | 'material' | 'security'
  actor_uuid: string
  actor_kind: string
  source_app: string
  is_you: boolean
  entity_type: string
  entity_name: string
  entity_id: string | null
  entity_label: string | null
  summary: string
  reason: string | null
  changes: AuditChange[]
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  request_id: string | null
}

export function when(value: string): string {
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(parsed)
}

export function show(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

export function actorOf(entry: AuditEntry): string {
  if (entry.is_you) return 'You'
  return entry.actor_kind === 'service' ? `${entry.actor_uuid} (via ${entry.source_app})` : entry.actor_uuid
}

const SEVERITY_TONE: Record<string, { fg: string; bg: string }> = {
  info: { fg: 'var(--muted)', bg: 'var(--surface-2)' },
  material: { fg: 'var(--warning)', bg: 'var(--warning-bg)' },
  security: { fg: 'var(--danger)', bg: 'var(--danger-bg)' },
}

export function SeverityBadge({ severity }: { severity: string }) {
  const tone = SEVERITY_TONE[severity] ?? SEVERITY_TONE.info
  return (
    <span
      style={{
        display: 'inline-block',
        padding: '0.05rem 0.45rem',
        borderRadius: '999px',
        fontSize: '0.7rem',
        fontWeight: 600,
        color: tone.fg,
        background: tone.bg,
        border: `1px solid ${tone.fg}`,
        whiteSpace: 'nowrap',
      }}
    >
      {severity}
    </span>
  )
}

/** Field changes as old -> new. */
export function ChangeList({ changes }: { changes: AuditChange[] }) {
  if (changes.length === 0) return <p style={{ color: 'var(--muted)', margin: 0 }}>No field-level change recorded.</p>
  return (
    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem' }}>
      <thead>
        <tr>
          {['Field', 'Before', 'After'].map((h) => (
            <th key={h} style={{ textAlign: 'left', padding: '0.35rem 0.4rem', borderBottom: '1px solid var(--border-strong)', color: 'var(--muted)', fontSize: '0.75rem' }}>
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {changes.map((c) => (
          <tr key={c.field}>
            <td style={{ padding: '0.35rem 0.4rem', borderBottom: '1px solid var(--border)', fontWeight: 500 }}>{c.label}</td>
            <td style={{ padding: '0.35rem 0.4rem', borderBottom: '1px solid var(--border)', color: 'var(--danger)', wordBreak: 'break-word' }}>{show(c.old)}</td>
            <td style={{ padding: '0.35rem 0.4rem', borderBottom: '1px solid var(--border)', color: 'var(--success)', wordBreak: 'break-word' }}>{show(c.new)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function Snapshot({ title, state }: { title: string; state: Record<string, unknown> | null }) {
  if (!state || Object.keys(state).length === 0) return null
  return (
    <div>
      <h3 style={{ fontSize: '0.8rem', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.03em', margin: '0 0 0.35rem' }}>{title}</h3>
      <dl style={{ display: 'grid', gridTemplateColumns: 'minmax(7rem, auto) 1fr', gap: '0.25rem 0.75rem', margin: 0, fontSize: '0.85rem' }}>
        {Object.entries(state).map(([key, value]) => (
          <div key={key} style={{ display: 'contents' }}>
            <dt style={{ color: 'var(--muted)' }}>{key.replace(/_/g, ' ')}</dt>
            <dd style={{ margin: 0, wordBreak: 'break-word' }}>{show(value)}</dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

/** One row, in full: what changed, the snapshots, and where the request came from. */
export function AuditDrawer({ entry, onClose }: { entry: AuditEntry; onClose: () => void }) {
  const meta: [string, string][] = [
    ['When', when(entry.created_at)],
    ['Action', entry.action],
    ['Record', `${entry.entity_name} ${entry.entity_label ?? (entry.entity_id ? `#${entry.entity_id}` : '')}`.trim()],
    ['By', actorOf(entry)],
    ['IP address', entry.ip_address ?? '—'],
    ['Browser', entry.user_agent ?? '—'],
    ['Request', entry.request_id ?? '—'],
  ]

  return (
    <>
      <div onClick={onClose} aria-hidden style={{ position: 'fixed', inset: 0, background: 'rgba(16, 24, 20, 0.35)', zIndex: 60 }} />
      <aside
        role="dialog"
        aria-label="Audit entry"
        style={{
          position: 'fixed',
          top: 0,
          right: 0,
          bottom: 0,
          width: 'min(540px, 100vw)',
          background: 'var(--surface)',
          borderLeft: '1px solid var(--border)',
          boxShadow: 'var(--shadow-lg)',
          zIndex: 61,
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', padding: '1rem 1.15rem', borderBottom: '1px solid var(--border)' }}>
          <div style={{ minWidth: 0 }}>
            <h2 style={{ margin: 0, fontSize: '1rem' }}>{entry.summary}</h2>
            <div style={{ marginTop: '0.3rem' }}><SeverityBadge severity={entry.severity} /></div>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            style={{ border: '1px solid var(--border-strong)', background: 'var(--surface)', borderRadius: 'var(--radius-sm)', width: 32, height: 32, display: 'grid', placeItems: 'center', cursor: 'pointer', color: 'var(--muted)', flexShrink: 0 }}
          >
            <X size={16} aria-hidden />
          </button>
        </header>
        <div style={{ padding: '1rem 1.15rem', overflowY: 'auto', overflowX: 'hidden', display: 'grid', gap: '1.1rem', alignContent: 'start' }}>
          {entry.reason && <Notice tone="info" title="Reason given">{entry.reason}</Notice>}
          <div>
            <h3 style={{ fontSize: '0.8rem', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.03em', margin: '0 0 0.35rem' }}>Changes</h3>
            <ChangeList changes={entry.changes} />
          </div>
          <Snapshot title="Before" state={entry.before} />
          <Snapshot title="After" state={entry.after} />
          <dl style={{ display: 'grid', gridTemplateColumns: 'minmax(7rem, auto) 1fr', gap: '0.25rem 0.75rem', margin: 0, fontSize: '0.85rem' }}>
            {meta.map(([k, v]) => (
              <div key={k} style={{ display: 'contents' }}>
                <dt style={{ color: 'var(--muted)' }}>{k}</dt>
                <dd style={{ margin: 0, wordBreak: 'break-word' }}>{v}</dd>
              </div>
            ))}
          </dl>
        </div>
      </aside>
    </>
  )
}

/**
 * The History panel on a record screen: that record's rows of the trail, newest first.
 * Shown only to people holding audit.view — the endpoint refuses everybody else.
 */
export function RecordHistory({ entityType, entityId }: { entityType: string; entityId: number | string }) {
  const { scope, can } = usePurchases()
  const allowed = can('audit.view')
  const [open, setOpen] = useState<AuditEntry | null>(null)
  const { data, loading, error } = useApi(
    (signal) => api.one<AuditEntry[]>(`v1/audit-log/entity/${entityType}/${entityId}`, { limit: 50 }, signal),
    [scope?.cmp_id, entityType, entityId],
    Boolean(scope) && allowed,
  )

  if (!allowed) return null

  return (
    <Card title={<span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.4rem' }}><History size={15} aria-hidden /> History</span>}>
      {error ? (
        <Notice tone="danger" title="Could not load the history">{error}</Notice>
      ) : (
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.audit_id}
          onRowClick={setOpen}
          empty="Nothing recorded for this document yet."
          columns={[
            { key: 'when', header: 'When', render: (row) => when(row.created_at) },
            { key: 'what', header: 'What', render: (row) => row.summary },
            { key: 'who', header: 'By', render: (row) => <span title={row.actor_uuid}>{actorOf(row)}</span> },
            { key: 'changes', header: 'Changes', render: (row) => (row.changes.length === 0 ? '—' : row.changes.map((c) => c.label).join(', ')) },
          ]}
        />
      )}
      {open && <AuditDrawer entry={open} onClose={() => setOpen(null)} />}
    </Card>
  )
}
