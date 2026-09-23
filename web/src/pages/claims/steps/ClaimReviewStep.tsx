/**
 * Step 5 — read it once before it goes.
 *
 * Every block here links back to the step that owns it, because "Subject is
 * missing" is only useful next to a way of fixing it. What is missing is named
 * and counted rather than summarised as "invalid": somebody on this screen is
 * about to ask a supplier for money, and the last thing they see should be
 * exactly what they are asking for.
 */

import {
  Building2,
  ClipboardList,
  FileText,
  Package,
  Paperclip,
  Send,
  TriangleAlert,
  CheckCircle2,
} from 'lucide-react'
import { date, money } from '../../../ui'
import { filledLines, issuesFor, lineAmount, totalAmount } from '../model'
import type { ClaimStepId } from '../types'
import type { StepProps } from './props'

function labelFor(options: Array<{ value: string; label: string }> | undefined, value: string): string | null {
  return options?.find((option) => option.value === value)?.label ?? (value === '' ? null : value)
}

export function ClaimReviewStep({ draft, meta, goTo }: StepProps) {
  const issues = issuesFor(draft, meta)
  const lines = filledLines(draft)
  const total = totalAmount(draft)

  const references = [
    { label: 'Purchase order', value: draft.purchaseOrder?.primary ?? null },
    { label: 'Purchase bill', value: draft.purchaseBill?.primary ?? null },
    { label: 'Delivery / GRN', value: draft.delivery?.primary ?? null },
    { label: 'Return', value: draft.returnRef?.primary ?? null },
  ].filter((reference) => reference.value !== null)

  function Block({
    title,
    step,
    incomplete,
    children,
  }: {
    title: string
    step: ClaimStepId
    incomplete?: boolean
    children: React.ReactNode
  }) {
    return (
      <div className={incomplete ? 'claim-review-block is-incomplete' : 'claim-review-block'}>
        <h3>
          {title}
          <button type="button" onClick={() => goTo(step)}>
            Edit
          </button>
        </h3>
        {children}
      </div>
    )
  }

  return (
    <section className="claim-card claim-step-panel">
      <div className="claim-card-header">
        <h2>
          <Send size={15} aria-hidden />
          Review &amp; submit
        </h2>
        <span className="claim-card-hint">Nothing has been raised yet</span>
      </div>

      <div className={issues.length === 0 ? 'claim-review-verdict is-ready' : 'claim-review-verdict is-blocked'} role="status">
        {issues.length === 0 ? <CheckCircle2 size={20} aria-hidden /> : <TriangleAlert size={20} aria-hidden />}
        <div>
          <strong>
            {issues.length === 0
              ? 'Ready to submit'
              : `${issues.length} item${issues.length === 1 ? '' : 's'} require attention`}
          </strong>
          <span>
            {issues.length === 0
              ? `${money(total)} will be claimed from ${draft.supplier?.name ?? 'the supplier'}.`
              : 'Each one below links to the step it belongs to.'}
          </span>

          {issues.length > 0 && (
            <ul className="claim-review-issues">
              {issues.map((issue) => (
                <li key={`${issue.step}-${issue.field}`}>
                  <button type="button" onClick={() => goTo(issue.step)}>
                    {issue.message}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="claim-review-grid">
        <Block title="Supplier" step="details" incomplete={!draft.supplier}>
          <dl>
            <div>
              <dt>
                <Building2 size={11} aria-hidden style={{ verticalAlign: '-1px', marginRight: 4 }} />
                Party
              </dt>
              <dd className={draft.supplier ? undefined : 'is-missing'}>{draft.supplier?.name ?? 'Not selected'}</dd>
            </div>
            {draft.supplier?.gstin && (
              <div>
                <dt>GSTIN</dt>
                <dd>{draft.supplier.gstin}</dd>
              </div>
            )}
            <div>
              <dt>Contact</dt>
              <dd>{draft.supplierContact.trim() || '—'}</dd>
            </div>
          </dl>
        </Block>

        <Block title="Claim details" step="details" incomplete={draft.claimKind === '' || draft.subject.trim() === ''}>
          <dl>
            <div>
              <dt>Type</dt>
              <dd className={draft.claimKind ? undefined : 'is-missing'}>
                {labelFor(meta?.kinds, draft.claimKind) ?? 'Not selected'}
              </dd>
            </div>
            <div>
              <dt>Claim date</dt>
              <dd>{date(draft.claimDate)}</dd>
            </div>
            <div>
              <dt>Subject</dt>
              <dd className={draft.subject.trim() ? undefined : 'is-missing'}>{draft.subject.trim() || 'Not written'}</dd>
            </div>
          </dl>
        </Block>
      </div>

      <div style={{ marginTop: 14 }} className="claim-review-block">
        <h3>
          <span>
            <FileText size={12} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
            Description
          </span>
          <button type="button" onClick={() => goTo('details')}>
            Edit
          </button>
        </h3>
        <p className={draft.description.trim() ? 'claim-review-prose' : 'claim-review-prose is-missing'}>
          {draft.description.trim() || 'Nothing written yet.'}
        </p>
      </div>

      <div className="claim-review-grid" style={{ marginTop: 14 }}>
        <Block title="Linked references" step="references">
          {references.length === 0 ? (
            <p style={{ margin: 0, fontSize: 12.5, color: 'var(--sc-text-muted)' }}>
              <ClipboardList size={12} aria-hidden style={{ verticalAlign: '-1px', marginRight: 5 }} />
              None linked. A claim settles faster with the order or the invoice on it.
            </p>
          ) : (
            <dl>
              {references.map((reference) => (
                <div key={reference.label}>
                  <dt>{reference.label}</dt>
                  <dd>{reference.value}</dd>
                </div>
              ))}
            </dl>
          )}
        </Block>

        <Block title="Requested resolution" step="more">
          <dl>
            <div>
              <dt>Asking for</dt>
              <dd>{labelFor(meta?.resolutions, draft.requestedResolution) ?? 'Not decided yet'}</dd>
            </div>
            <div>
              <dt>Expected by</dt>
              <dd>{draft.expectedResolutionDate ? date(draft.expectedResolutionDate) : '—'}</dd>
            </div>
            <div>
              <dt>Priority / owner</dt>
              <dd>
                {labelFor(meta?.priorities, draft.priority) ?? 'Normal'}
                {draft.internalOwner.trim() ? ` · ${draft.internalOwner.trim()}` : ''}
              </dd>
            </div>
          </dl>
        </Block>
      </div>

      <div className="claim-review-block" style={{ marginTop: 14 }}>
        <h3>
          <span>
            <Package size={12} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
            Claim lines
          </span>
          <button type="button" onClick={() => goTo('items')}>
            Edit
          </button>
        </h3>

        {lines.length === 0 ? (
          <p style={{ margin: 0, fontSize: 12.5 }} className="is-missing">
            Nothing is being claimed yet.
          </p>
        ) : (
          <div className="claim-lines-scroll">
            <table className="claim-lines" style={{ minWidth: 560 }}>
              <thead>
                <tr>
                  <th scope="col">Item</th>
                  <th scope="col">Reference</th>
                  <th scope="col" className="is-numeric">Claim qty</th>
                  <th scope="col" className="is-numeric">Rate</th>
                  <th scope="col" className="is-numeric">Amount</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((line) => (
                  <tr key={line.key}>
                    <td>{line.description || '—'}</td>
                    <td>{line.referenceNo || '—'}</td>
                    <td className="is-numeric">{line.claimQty || '—'}</td>
                    <td className="is-numeric">{line.rate || '—'}</td>
                    <td className="is-numeric claim-line-amount">{money(lineAmount(line))}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="claim-lines-total">
          <span>Total claim amount</span>
          <strong>{money(total)}</strong>
        </div>
      </div>

      {draft.attachments.length > 0 && (
        <div className="claim-review-block" style={{ marginTop: 14 }}>
          <h3>
            <span>
              <Paperclip size={12} aria-hidden style={{ verticalAlign: '-2px', marginRight: 6 }} />
              Attachments
            </span>
            <button type="button" onClick={() => goTo('references')}>
              Edit
            </button>
          </h3>
          <p style={{ margin: 0, fontSize: 12.5, color: 'var(--sc-text-muted)' }}>
            {draft.attachments.length} file{draft.attachments.length === 1 ? '' : 's'} chosen.
            {meta?.capabilities.attachments.available
              ? ''
              : ' They cannot be stored by this deployment and will not be sent with the claim.'}
          </p>
        </div>
      )}
    </section>
  )
}
