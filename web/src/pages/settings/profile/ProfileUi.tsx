/**
 * The small pieces every card on this screen is built from.
 *
 * Here rather than in `src/ui` on purpose: these carry this workspace's local
 * tokens, and promoting them would quietly make the whole fleet indigo. When a
 * second screen wants them, that is the moment to move them — not before.
 */

import { useId, type ReactNode } from 'react'
import { AlertCircle, X } from 'lucide-react'
import { useFocusTrap } from '../../../hooks/useFocusTrap'

// ---------------------------------------------------------------------------
// Buttons
// ---------------------------------------------------------------------------

type ButtonTone = 'primary' | 'secondary' | 'danger' | 'quiet'

export function PpButton({
  children,
  onClick,
  tone = 'secondary',
  small = false,
  disabled = false,
  type = 'button',
  title,
  ariaLabel,
}: {
  children: ReactNode
  onClick?: () => void
  tone?: ButtonTone
  small?: boolean
  disabled?: boolean
  type?: 'button' | 'submit'
  title?: string
  ariaLabel?: string
}) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      aria-label={ariaLabel}
      className={`pp-btn pp-btn--${tone}${small ? ' pp-btn--small' : ''}`}
    >
      {children}
    </button>
  )
}

// ---------------------------------------------------------------------------
// Cards
// ---------------------------------------------------------------------------

export function PpCard({
  id,
  icon,
  tone = 'indigo',
  title,
  subtitle,
  aside,
  children,
}: {
  id?: string
  icon: ReactNode
  tone?: 'indigo' | 'green' | 'orange' | 'blue'
  title: string
  subtitle?: ReactNode
  aside?: ReactNode
  children: ReactNode
}) {
  const headingId = useId()

  return (
    <section id={id} className="pp-card" aria-labelledby={headingId} tabIndex={-1}>
      <header className="pp-card__header">
        <span className={`pp-card__icon pp-card__icon--${tone}`} aria-hidden>
          {icon}
        </span>
        <div className="pp-card__titles">
          <h2 id={headingId}>{title}</h2>
          {subtitle && <p>{subtitle}</p>}
        </div>
        {aside && <div className="pp-card__aside">{aside}</div>}
      </header>
      <div className="pp-card__body">{children}</div>
    </section>
  )
}

export function PpSideCard({ title, children }: { title: string; children: ReactNode }) {
  const headingId = useId()

  return (
    <section className="pp-card pp-side" aria-labelledby={headingId}>
      <h3 id={headingId}>{title}</h3>
      {children}
    </section>
  )
}

// ---------------------------------------------------------------------------
// Fields
// ---------------------------------------------------------------------------

/**
 * A labelled control with its hint and its error.
 *
 * The error is wired with `aria-describedby` and `aria-invalid` rather than
 * being red text that happens to sit underneath, because a red border is not
 * something a screen reader can see and "invalid" is not a colour.
 */
export function PpField({
  id,
  label,
  required = false,
  hint,
  error,
  children,
}: {
  id: string
  label: string
  required?: boolean
  hint?: ReactNode
  error?: string
  children: (props: { id: string; 'aria-invalid'?: true; 'aria-describedby'?: string }) => ReactNode
}) {
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const describedBy = error ? errorId : hint ? hintId : undefined

  return (
    <div className={`pp-field${error ? ' is-invalid' : ''}`}>
      <label htmlFor={id}>
        {label}
        {required && (
          <>
            {' '}
            <span className="pp-req" aria-hidden>
              *
            </span>
            <span className="pp-sr-only">(required)</span>
          </>
        )}
      </label>
      {children({
        id,
        ...(error ? { 'aria-invalid': true as const } : {}),
        ...(describedBy ? { 'aria-describedby': describedBy } : {}),
      })}
      {error ? (
        <span className="pp-error" id={errorId} role="alert">
          <AlertCircle size={12} aria-hidden /> {error}
        </span>
      ) : (
        hint && (
          <span className="pp-hint" id={hintId}>
            {hint}
          </span>
        )
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Strips
// ---------------------------------------------------------------------------

export function PpStrip({
  tone = 'info',
  icon,
  children,
}: {
  tone?: 'info' | 'muted' | 'warning' | 'danger' | 'success'
  icon?: ReactNode
  children: ReactNode
}) {
  return (
    <div className={`pp-strip pp-strip--${tone}`} role={tone === 'danger' ? 'alert' : undefined}>
      {icon}
      <span>{children}</span>
    </div>
  )
}

export function PpEmpty({ icon, title, children }: { icon: ReactNode; title: string; children?: ReactNode }) {
  return (
    <div className="pp-empty">
      <div className="pp-empty__icon" aria-hidden>
        {icon}
      </div>
      <strong>{title}</strong>
      {children && <p>{children}</p>}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Dialog
// ---------------------------------------------------------------------------

export function PpDialog({
  open,
  title,
  subtitle,
  size = 'default',
  onClose,
  footer,
  children,
}: {
  open: boolean
  title: string
  subtitle?: ReactNode
  size?: 'narrow' | 'default' | 'wide'
  onClose: () => void
  footer?: ReactNode
  children: ReactNode
}) {
  const panel = useFocusTrap<HTMLDivElement>(open, onClose)
  const titleId = useId()
  const subtitleId = useId()

  if (!open) return null

  return (
    <div
      className="pp-dialog-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={panel}
        className={`pp-dialog${size === 'default' ? '' : ` pp-dialog--${size}`}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={subtitle ? subtitleId : undefined}
        tabIndex={-1}
      >
        <header className="pp-dialog__header">
          <div style={{ minWidth: 0 }}>
            <h2 id={titleId}>{title}</h2>
            {subtitle && <p id={subtitleId}>{subtitle}</p>}
          </div>
          <button type="button" className="pp-dialog__close" onClick={onClose} aria-label={`Close ${title}`}>
            <X size={17} aria-hidden />
          </button>
        </header>
        <div className="pp-dialog__body">{children}</div>
        {footer && <div className="pp-dialog__footer">{footer}</div>}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Read-only summary, shared by Preview and Copy
// ---------------------------------------------------------------------------

export function PpSummary({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="pp-summary">
      <h3>{title}</h3>
      {children}
    </section>
  )
}

export function PpDl({ rows }: { rows: Array<{ label: string; value: ReactNode; muted?: boolean }> }) {
  return (
    <dl className="pp-dl">
      {rows.map((row) => (
        <div key={row.label}>
          <dt>{row.label}</dt>
          <dd className={row.muted ? 'pp-dl__muted' : undefined}>{row.value}</dd>
        </div>
      ))}
    </dl>
  )
}
