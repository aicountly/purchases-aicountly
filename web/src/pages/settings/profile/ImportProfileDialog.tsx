/**
 * Read a profile file back in.
 *
 * AN IMPORTED FILE IS UNTRUSTED INPUT. It is parsed as JSON and never
 * evaluated, every field is checked for type and range before it goes near the
 * form (see profileFile.ts), and a file that fails is refused whole rather than
 * applied half-way. Nothing is written to the server here either: the profile
 * lands in the form as unsaved changes, and the person who opened the file is
 * the one who presses Save.
 *
 * Tolerance policies are the exception, and they say so. They are server-side
 * records with their own endpoint, so importing them is a write — which is why
 * it is a separate, explicit tick rather than something that happens quietly
 * alongside the numbering.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { AlertTriangle, FileJson, Info, Upload } from 'lucide-react'
import { money } from '../../../ui'
import { PpButton, PpDialog, PpDl, PpStrip, PpSummary } from './ProfileUi'
import { parseProfileFile, type ExportedPolicy, type ImportResult } from './profileFile'
import { PREFIX_FIELDS, type ProfileForm } from './profileModel'

export function ImportProfileDialog({
  open,
  knownTypes,
  canWritePolicies,
  onClose,
  onApply,
}: {
  open: boolean
  knownTypes: string[]
  canWritePolicies: boolean
  onClose: () => void
  onApply: (form: ProfileForm, policies: ExportedPolicy[]) => void
}) {
  const [result, setResult] = useState<ImportResult | null>(null)
  const [filename, setFilename] = useState<string | null>(null)
  const [withPolicies, setWithPolicies] = useState(false)
  const [reading, setReading] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!open) return
    setResult(null)
    setFilename(null)
    setWithPolicies(false)
    setReading(false)
  }, [open])

  const load = useCallback(
    async (file: File) => {
      setReading(true)
      setFilename(file.name)
      try {
        const text = await file.text()
        const parsed = parseProfileFile(text, knownTypes)
        setResult(parsed)
        setWithPolicies(parsed.ok && canWritePolicies && parsed.policies.length > 0)
      } catch {
        setResult({ ok: false, errors: ['That file could not be read.'] })
      } finally {
        setReading(false)
      }
    },
    [knownTypes, canWritePolicies],
  )

  const ready = result?.ok === true

  return (
    <PpDialog
      open={open}
      size="wide"
      title="Import configuration"
      subtitle="Read a profile file exported from Aicountly Purchases."
      onClose={onClose}
      footer={
        <>
          <span className="pp-dialog__note">Imported settings land in the form — nothing is saved yet.</span>
          <PpButton onClick={onClose}>Cancel</PpButton>
          <PpButton
            tone="primary"
            disabled={!ready}
            onClick={() => {
              if (result?.ok !== true) return
              onApply(result.form, withPolicies ? result.policies : [])
            }}
          >
            Apply to form
          </PpButton>
        </>
      }
    >
      <label className="pp-dropzone">
        <input
          ref={input}
          type="file"
          accept="application/json,.json"
          onChange={(event) => {
            const file = event.target.files?.[0]
            if (file) void load(file)
            // Cleared so choosing the same file twice fires again — otherwise a
            // corrected file with the same name does nothing at all.
            event.target.value = ''
          }}
        />
        <Upload size={20} aria-hidden style={{ color: 'var(--pp-primary-strong)' }} />
        <strong>{filename ?? 'Choose a profile file'}</strong>
        <small>A .json file exported from this screen</small>
      </label>

      {reading && (
        <div style={{ marginTop: 14 }}>
          <div className="pp-skeleton" style={{ height: 70 }} />
        </div>
      )}

      {result?.ok === false && (
        <div style={{ marginTop: 16 }}>
          <PpStrip tone="danger" icon={<AlertTriangle size={14} aria-hidden />}>
            <strong>This file was not imported.</strong>
            <ul className="pp-list" style={{ marginTop: 6 }}>
              {/* Keyed by position: an imported file can repeat a message,
                  and a duplicate key would drop one of them. */}
              {result.errors.map((message, index) => (
                <li key={index}>{message}</li>
              ))}
            </ul>
          </PpStrip>
        </div>
      )}

      {result?.ok === true && (
        <>
          {result.warnings.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <PpStrip tone="warning" icon={<Info size={14} aria-hidden />}>
                <ul className="pp-list" style={{ margin: 0 }}>
                  {result.warnings.map((message, index) => (
                    <li key={index}>{message}</li>
                  ))}
                </ul>
              </PpStrip>
            </div>
          )}

          <PpSummary title="What this file contains">
            <PpDl
              rows={[
                { label: 'Profile code', value: result.form.code || 'Not set', muted: result.form.code === '' },
                { label: 'Profile name', value: result.form.name || 'Not set', muted: result.form.name === '' },
                { label: 'Profile type', value: result.form.type },
                { label: 'Status', value: result.form.active ? 'Active' : 'Inactive' },
                // The prefix alone: the rest of a document number is
                // the financial year of whoever issues it, which is this
                // company's, not the one being read from.
                ...PREFIX_FIELDS.map((field) => ({
                  label: field.label,
                  value: result.form.numbering[field.key],
                })),
                {
                  label: 'Requisition limit',
                  value: money(Number(result.form.approvals.requisitionLimit || 0)),
                },
                {
                  label: 'Purchase order limit',
                  value: money(Number(result.form.approvals.purchaseOrderLimit || 0)),
                },
              ]}
            />
          </PpSummary>

          {result.policies.length > 0 && (
            <PpSummary title={`Tolerance policies in this file (${result.policies.length})`}>
              <ul className="pp-list">
                {result.policies.map((policy, index) => (
                  <li key={`${policy.policy_name}-${index}`}>
                    <strong>{policy.policy_name}</strong>
                    {policy.is_default ? ' (default)' : ''} — quantity {policy.qty_tolerance_pc}%, rate{' '}
                    {policy.rate_tolerance_pc}%, auto-match below {money(policy.auto_match_below_amt)}
                  </li>
                ))}
              </ul>

              {canWritePolicies ? (
                <label className="pp-check">
                  <input
                    type="checkbox"
                    checked={withPolicies}
                    onChange={(event) => setWithPolicies(event.target.checked)}
                  />
                  <span>
                    <strong>Also create these tolerance policies</strong>
                    <small>
                      Unlike the rest of this file, policies are saved immediately. A policy already using one of
                      these names is updated rather than duplicated.
                    </small>
                  </span>
                </label>
              ) : (
                <PpStrip tone="muted">
                  Creating tolerance policies needs the <code>settings.manage</code> permission, so only the profile
                  settings above will be applied.
                </PpStrip>
              )}
            </PpSummary>
          )}
        </>
      )}

      {result === null && !reading && (
        <div style={{ marginTop: 16 }}>
          <PpStrip tone="muted" icon={<FileJson size={14} aria-hidden />}>
            A profile file carries numbering, approvals, controls and tolerance policies. It never carries company
            ids, financial years, users or session keys — so one is safe to send to a colleague.
          </PpStrip>
        </div>
      )}
    </PpDialog>
  )
}
