/**
 * The small pieces this screen is built from.
 *
 * They exist for the same reason `pages/access/ui.tsx` exists: the shared
 * primitives in `src/ui` are styled inline from the fleet's tokens, and this
 * screen is styled from a scoped stylesheet. Rather than fight one with the
 * other, the claim screen brings its own handful of controls that render the
 * classes in `supplier-claim.css` — and keeps every one of them a real
 * `<button>`, `<input>` or `<label>`, because a div somebody has attached a
 * click handler to is not a control to anybody navigating by keyboard.
 */

import type { ReactNode } from 'react'
import { AlertCircle, CheckCircle2, Info, TriangleAlert } from 'lucide-react'

/**
 * A field, its label, and the one sentence that explains or corrects it.
 *
 * The hint and the error are wired to the control with `aria-describedby` by
 * the CALLER rather than here, because the control is the caller's — a combobox
 * and an `<input>` describe themselves differently. What this guarantees is
 * that the ids exist and are predictable: `${id}-hint` and `${id}-error`.
 */
export function Field({
  label,
  htmlFor,
  required,
  hint,
  error,
  action,
  children,
}: {
  label: string
  htmlFor: string
  required?: boolean
  hint?: ReactNode
  error?: string
  action?: ReactNode
  children: ReactNode
}) {
  const labelNode = (
    <label className="sc-field__label" htmlFor={htmlFor}>
      {label}
      {required && (
        <>
          <span className="sc-field__required" aria-hidden>
            *
          </span>
          <span className="sc-sr-only"> (required)</span>
        </>
      )}
    </label>
  )

  return (
    <div className="sc-field">
      {action ? (
        <div className="sc-field__label-row">
          {labelNode}
          {action}
        </div>
      ) : (
        labelNode
      )}

      {children}

      {hint && !error && (
        <span className="sc-field__hint" id={`${htmlFor}-hint`}>
          {hint}
        </span>
      )}
      {error && (
        <span className="sc-field__error" id={`${htmlFor}-error`}>
          <AlertCircle size={13} aria-hidden />
          {error}
        </span>
      )}
    </div>
  )
}

/** What a control should point `aria-describedby` at, or nothing. */
export function describedBy(id: string, hint: unknown, error: unknown): string | undefined {
  if (error) return `${id}-error`
  if (hint) return `${id}-hint`

  return undefined
}

type ButtonTone = 'primary' | 'secondary' | 'ghost' | 'danger' | 'ai'

export function Button({
  children,
  onClick,
  tone = 'secondary',
  type = 'button',
  disabled,
  small,
  title,
  ariaLabel,
}: {
  children: ReactNode
  onClick?: () => void
  tone?: ButtonTone
  type?: 'button' | 'submit'
  disabled?: boolean
  small?: boolean
  title?: string
  ariaLabel?: string
}) {
  return (
    <button
      type={type}
      className={`sc-btn sc-btn--${tone}${small ? ' sc-btn--sm' : ''}`}
      onClick={onClick}
      disabled={disabled}
      title={title}
      aria-label={ariaLabel}
    >
      {children}
    </button>
  )
}

export function Notice({
  tone = 'info',
  title,
  children,
  actions,
}: {
  tone?: 'info' | 'warning' | 'danger' | 'success'
  title?: string
  children?: ReactNode
  actions?: ReactNode
}) {
  const Icon =
    tone === 'danger' ? AlertCircle : tone === 'warning' ? TriangleAlert : tone === 'success' ? CheckCircle2 : Info

  return (
    <div className={`sc-notice sc-notice--${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon size={16} aria-hidden />
      <div>
        {title && <strong>{title}</strong>}
        {children}
      </div>
      {actions && <div className="sc-notice__actions">{actions}</div>}
    </div>
  )
}

export function EmptyState({ icon, title, children }: { icon: ReactNode; title: string; children?: ReactNode }) {
  return (
    <div className="sc-empty">
      <div className="sc-empty__mark" aria-hidden>
        {icon}
      </div>
      <strong>{title}</strong>
      {children && <p>{children}</p>}
    </div>
  )
}

export function Skeleton({ height = 40, width = '100%' }: { height?: number; width?: number | string }) {
  return <div className="sc-skeleton" style={{ height, width }} aria-hidden />
}
