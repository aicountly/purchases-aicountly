import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Plus, Trash2, Wand2 } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { CatalogItem, Requisition } from '../services/types'
import { useApi } from '../hooks/useApi'
import { usePurchases } from '../context/PurchasesContext'
import { ItemPicker } from '../components/LivePicker'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, Select, StatusBadge, Textarea } from '../ui'

const STATUSES = ['', 'DRAFT', 'APPROVAL_PENDING', 'APPROVED', 'REJECTED', 'SOURCING', 'ORDERED', 'CLOSED', 'CANCELLED']

export function RequisitionsList() {
  const navigate = useNavigate()
  const { scope, can } = usePurchases()
  const [status, setStatus] = useState('')

  const { data, loading, error, reload } = useApi(
    (signal) => api.list<Requisition>('v1/requisitions', { status: status || undefined, limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id, status],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Requisitions</h1>
        {can('requisition.create') && (
          <Button tone="primary" onClick={() => navigate('/requisitions/new')}>
            <Plus size={15} aria-hidden /> New requisition
          </Button>
        )}
      </header>

      {error && (
        <Notice tone="danger" title="Could not load requisitions">
          {error} <Button tone="ghost" onClick={reload}>Retry</Button>
        </Notice>
      )}

      <Card
        title={`${data?.meta.total ?? 0} requisition${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
        action={
          <Select value={status} onChange={(e) => setStatus(e.target.value)} style={{ width: '12rem' }}>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {value === '' ? 'All statuses' : value.replace(/_/g, ' ')}
              </option>
            ))}
          </Select>
        }
      >
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.requisition_id}
          onRowClick={(row) => navigate(`/requisitions/${row.requisition_id}`)}
          empty="No requisitions yet."
          columns={[
            {
              key: 'no',
              header: 'Number',
              render: (row) => (
                <Link to={`/requisitions/${row.requisition_id}`} onClick={(e) => e.stopPropagation()}>
                  {row.requisition_no}
                </Link>
              ),
            },
            { key: 'date', header: 'Raised', render: (row) => date(row.requisition_date) },
            { key: 'dept', header: 'Department', render: (row) => row.department ?? '—' },
            { key: 'needed', header: 'Needed by', render: (row) => date(row.required_by) },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
            { key: 'value', header: 'Estimated', numeric: true, render: (row) => money(row.estimated_value) },
          ]}
        />
      </Card>
    </div>
  )
}

export function RequisitionDetail() {
  const { id } = useParams<{ id: string }>()
  const { scope, can } = usePurchases()
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [note, setNote] = useState('')

  const { data, loading, error, reload } = useApi(
    (signal) => api.one<Requisition>(`v1/requisitions/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  async function act(path: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setActionError(null)
    try {
      await api.post(`v1/requisitions/${id}/${path}`, body)
      setNote('')
      reload()
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>
  if (error) return <Notice tone="danger" title="Could not load this requisition">{error}</Notice>

  const requisition = data?.data
  if (!requisition) return <Notice tone="warning">That requisition does not exist.</Notice>

  const pending = requisition.approvals.filter((a) => a.status === 'PENDING')

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/requisitions" style={{ fontSize: '0.85rem' }}>← Requisitions</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {requisition.requisition_no}
            <StatusBadge status={requisition.status} />
          </h1>
          <p style={{ margin: '0.3rem 0 0', color: 'var(--muted)' }}>
            {requisition.department ?? 'No department'} · raised {date(requisition.requisition_date)}
            {requisition.required_by && ` · needed by ${date(requisition.required_by)}`}
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {requisition.status === 'DRAFT' && can('requisition.create') && (
            <Button tone="primary" disabled={busy} onClick={() => act('submit')}>Submit for approval</Button>
          )}
          {requisition.status === 'APPROVED' && can('rfq.create') && (
            <Link to={`/rfqs/new?requisition_id=${requisition.requisition_id}`}>
              <Button tone="primary">Raise an RFQ</Button>
            </Link>
          )}
        </div>
      </header>

      {actionError && <Notice tone="danger" title="That did not work">{actionError}</Notice>}

      {pending.length > 0 && (
        <Card title="Waiting for approval">
          <ul style={{ margin: '0 0 0.85rem', paddingLeft: '1.1rem' }}>
            {pending.map((approval) => (
              <li key={approval.approval_id}>{approval.reason_detail ?? approval.reason_kind}</li>
            ))}
          </ul>
          {can('requisition.approve') && (
            <div style={{ display: 'grid', gap: '0.6rem' }}>
              <Field label="Note" hint="Required when rejecting.">
                <Input value={note} onChange={(e) => setNote(e.target.value)} />
              </Field>
              <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
                <Button tone="danger" disabled={busy || note.trim() === ''} onClick={() => act('reject', { note: note.trim() })}>
                  Reject
                </Button>
                <Button tone="primary" disabled={busy} onClick={() => act('approve', { note: note.trim() || undefined })}>
                  Approve
                </Button>
              </div>
              <p style={{ color: 'var(--muted)', fontSize: '0.78rem', margin: 0 }}>
                A requisition cannot be approved by the person who raised it.
              </p>
            </div>
          )}
        </Card>
      )}

      <Card title="Lines">
        <DataTable
          rows={requisition.lines}
          rowKey={(line) => line.line_id}
          columns={[
            { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
            {
              key: 'item',
              header: 'Item',
              render: (line) =>
                line.is_service ? (
                  line.description ?? 'Service'
                ) : (
                  <span>
                    {line.description ?? `Item #${line.item_id}`}
                    <span style={{ color: 'var(--muted)', fontSize: '0.78rem', display: 'block' }}>Inventory item {line.item_id}</span>
                  </span>
                ),
            },
            { key: 'qty', header: 'Needed', numeric: true, render: (line) => qty(line.required_qty) },
            { key: 'ordered', header: 'Ordered', numeric: true, render: (line) => qty(line.ordered_qty) },
            { key: 'rate', header: 'Estimated rate', numeric: true, render: (line) => money(line.estimated_rate) },
            { key: 'by', header: 'Needed by', render: (line) => date(line.required_by) },
          ]}
        />
        <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
          The estimated rate routes the approval. It is not a price — that is agreed with a supplier during sourcing —
          and it is not a valuation, which belongs to Inventory.
        </p>
      </Card>

      {requisition.justification && (
        <Card title="Justification">
          <p style={{ margin: 0 }}>{requisition.justification}</p>
        </Card>
      )}
    </div>
  )
}

interface DraftLine {
  key: string
  item_id: number | null
  item_label: string
  unit_id: number | null
  is_service: boolean
  description: string
  required_qty: string
  estimated_rate: string
}

function emptyLine(): DraftLine {
  return {
    key: Math.random().toString(36).slice(2),
    item_id: null,
    item_label: '',
    unit_id: null,
    is_service: false,
    description: '',
    required_qty: '1',
    estimated_rate: '0',
  }
}

export function RequisitionEditor() {
  const navigate = useNavigate()
  const [department, setDepartment] = useState('')
  const [requiredBy, setRequiredBy] = useState('')
  const [priority, setPriority] = useState('normal')
  const [justification, setJustification] = useState('')
  const [flags, setFlags] = useState({ emergency: false, single_source: false, non_preferred_vendor: false })
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [suggesting, setSuggesting] = useState(false)

  function patchLine(key: string, patch: Partial<DraftLine>) {
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  }

  /**
   * Pull Inventory's own replenishment suggestions into the form.
   *
   * Read live and stored nowhere: the reorder point and the suggested quantity
   * are Inventory's, and what leaves here is a requisition the buyer has edited.
   */
  async function suggest() {
    setSuggesting(true)
    setError(null)
    try {
      const response = await api.get<{ data: { rows: Array<Record<string, unknown>> } }>('v1/requisitions/replenishment')
      const rows = response.data.rows ?? []
      if (rows.length === 0) {
        setError('Inventory has nothing to suggest right now.')
        return
      }
      setLines(
        rows.slice(0, 25).map((row) => ({
          key: Math.random().toString(36).slice(2),
          item_id: Number(row.item_id ?? 0) || null,
          item_label: String(row.item_name ?? `Item #${row.item_id}`),
          unit_id: row.unit_id ? Number(row.unit_id) : null,
          is_service: false,
          description: String(row.item_name ?? ''),
          required_qty: String(row.suggested_qty ?? row.reorder_qty ?? 1),
          estimated_rate: '0',
        })),
      )
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSuggesting(false)
    }
  }

  const estimated = lines.reduce((sum, line) => sum + Number(line.required_qty || 0) * Number(line.estimated_rate || 0), 0)

  async function save() {
    setSaving(true)
    setError(null)
    try {
      const response = await api.post<Requisition>('v1/requisitions', {
        department: department || undefined,
        required_by: requiredBy || undefined,
        priority,
        justification: justification || undefined,
        ...flags,
        lines: lines
          .filter((line) => line.is_service || line.item_id)
          .map((line) => ({
            item_id: line.item_id,
            unit_id: line.unit_id,
            is_service: line.is_service,
            description: line.description || undefined,
            required_qty: Number(line.required_qty || 0),
            estimated_rate: Number(line.estimated_rate || 0),
          })),
      })
      navigate(`/requisitions/${response.data.requisition_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '60rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>New requisition</h1>

      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}

      <Card title="What is needed">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))', gap: '0.85rem' }}>
          <Field label="Department"><Input value={department} onChange={(e) => setDepartment(e.target.value)} /></Field>
          <Field label="Needed by"><Input type="date" value={requiredBy} onChange={(e) => setRequiredBy(e.target.value)} /></Field>
          <Field label="Priority">
            <Select value={priority} onChange={(e) => setPriority(e.target.value)}>
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
            </Select>
          </Field>
        </div>

        <div style={{ display: 'flex', gap: '1.25rem', flexWrap: 'wrap', marginTop: '0.85rem' }}>
          {([
            ['emergency', 'Emergency purchase'],
            ['single_source', 'Single source'],
            ['non_preferred_vendor', 'Non-preferred vendor'],
          ] as const).map(([key, label]) => (
            <label key={key} style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.85rem' }}>
              <input type="checkbox" checked={flags[key]} onChange={(e) => setFlags({ ...flags, [key]: e.target.checked })} />
              {label}
            </label>
          ))}
        </div>
        <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginBottom: 0 }}>
          Any of these sends the requisition for approval whatever it is worth.
        </p>
      </Card>

      <Card
        title="Lines"
        action={
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <Button disabled={suggesting} onClick={suggest} title="Ask Inventory what needs reordering">
              <Wand2 size={14} aria-hidden /> {suggesting ? 'Asking Inventory…' : 'Suggest from Inventory'}
            </Button>
            <Button onClick={() => setLines((c) => [...c, emptyLine()])}>Add line</Button>
          </div>
        }
      >
        <div style={{ display: 'grid', gap: '0.85rem' }}>
          {lines.map((line) => (
            <div key={line.key} style={{ border: '1px solid var(--border)', borderRadius: 'var(--radius-sm)', padding: '0.75rem', display: 'grid', gap: '0.6rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.85rem' }}>
                  <input
                    type="checkbox"
                    checked={line.is_service}
                    onChange={(e) => patchLine(line.key, { is_service: e.target.checked, item_id: null, item_label: '' })}
                  />
                  Service line (no stock item)
                </label>
                <Button tone="ghost" onClick={() => setLines((c) => (c.length > 1 ? c.filter((l) => l.key !== line.key) : c))}>
                  <Trash2 size={14} aria-hidden />
                </Button>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(9rem, 1fr))', gap: '0.6rem', alignItems: 'end' }}>
                {line.is_service ? (
                  <div style={{ gridColumn: 'span 2' }}>
                    <Field label="Description"><Input value={line.description} onChange={(e) => patchLine(line.key, { description: e.target.value })} /></Field>
                  </div>
                ) : (
                  <div style={{ gridColumn: 'span 2' }}>
                    <ItemPicker
                      selectedLabel={line.item_label || null}
                      onPick={(item: CatalogItem) =>
                        patchLine(line.key, { item_id: item.item_id, item_label: item.item_name, unit_id: item.unit_id, description: item.item_name })
                      }
                    />
                  </div>
                )}
                <Field label="Quantity"><Input value={line.required_qty} inputMode="decimal" onChange={(e) => patchLine(line.key, { required_qty: e.target.value })} /></Field>
                <Field label="Estimated rate" hint="For approval routing">
                  <Input value={line.estimated_rate} inputMode="decimal" onChange={(e) => patchLine(line.key, { estimated_rate: e.target.value })} />
                </Field>
              </div>
            </div>
          ))}
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '1.5rem', marginTop: '1rem' }}>
          <span style={{ color: 'var(--muted)' }}>Estimated value</span>
          <span className="num" style={{ fontWeight: 600 }}>{money(estimated)}</span>
        </div>
      </Card>

      <Card title="Justification">
        <Textarea value={justification} onChange={(e) => setJustification(e.target.value)} placeholder="Why this is needed — the approver reads this." />
      </Card>

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button tone="primary" disabled={saving} onClick={save}>{saving ? 'Saving…' : 'Save requisition'}</Button>
      </div>
    </div>
  )
}
