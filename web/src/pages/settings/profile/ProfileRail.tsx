/**
 * The right-hand column: what this screen is for, what state the profile is
 * in, what else can be done to it, and what is left to look at.
 *
 * Context, not controls-in-disguise. Nothing here changes a document; the one
 * thing that changes anything — the status switch — says in words what turning
 * it off does before it is turned off.
 */

import type { ReactNode } from 'react'
import {
  Check,
  ChevronRight,
  Circle,
  Copy,
  Download,
  Gauge,
  RotateCcw,
  Settings2,
  ShieldCheck,
  Upload,
  Zap,
} from 'lucide-react'
import { PpSideCard } from './ProfileUi'
import type { SetupSection } from './profileModel'

// ---------------------------------------------------------------------------
// Guidance
// ---------------------------------------------------------------------------

const BENEFITS = [
  {
    tone: 'blue',
    icon: <Settings2 size={16} aria-hidden />,
    title: 'Flexible configuration',
    body: 'Numbering, thresholds and matching in one place, per company',
  },
  {
    tone: 'indigo',
    icon: <ShieldCheck size={16} aria-hidden />,
    title: 'Better control',
    body: 'Define approval limits and matching rules',
  },
  {
    tone: 'green',
    icon: <Zap size={16} aria-hidden />,
    title: 'Faster processing',
    body: 'Bills inside tolerance clear without anyone looking at them',
  },
] as const

export function ProfileGuideCard() {
  return (
    <section className="pp-card pp-guide" aria-label="About purchase profiles">
      <div className="pp-guide__hero">
        <h3>Create a Purchase Profile</h3>
        <p>Configure document numbering, approvals and matching rules to match your procurement process.</p>
        <Settings2 size={46} className="pp-guide__art" aria-hidden />
      </div>
      {BENEFITS.map((benefit) => (
        <div className="pp-benefit" key={benefit.title}>
          <span className={`pp-benefit__icon pp-benefit__icon--${benefit.tone}`} aria-hidden>
            {benefit.icon}
          </span>
          <span>
            <strong>{benefit.title}</strong>
            <small>{benefit.body}</small>
          </span>
        </div>
      ))}
    </section>
  )
}

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

export function ProfileStatusCard({
  active,
  editable,
  onChange,
}: {
  active: boolean
  editable: boolean
  onChange: (next: boolean) => void
}) {
  return (
    <PpSideCard title="Profile Status">
      <div className="pp-status">
        <span className="pp-switch">
          <input
            id="profile-active"
            type="checkbox"
            role="switch"
            checked={active}
            disabled={!editable}
            aria-describedby="profile-active-note"
            onChange={(event) => onChange(event.target.checked)}
          />
          <span className="pp-switch__track" aria-hidden />
        </span>
        {/* The state is said in words as well as painted, so it is not a colour
            somebody has to be able to see. */}
        <label htmlFor="profile-active" className={active ? 'pp-status__label is-on' : 'pp-status__label is-off'}>
          {active ? 'Active' : 'Inactive'}
        </label>
      </div>
      <p id="profile-active-note">
        {active
          ? 'This profile is active and can be used for new documents.'
          : 'This profile is inactive. Once saved, no new requisition, RFQ, purchase order, return or claim can be raised for this company. Documents already raised are unaffected.'}
      </p>
    </PpSideCard>
  )
}

// ---------------------------------------------------------------------------
// Quick actions
// ---------------------------------------------------------------------------

function QuickAction({
  icon,
  title,
  subtitle,
  danger = false,
  disabled = false,
  onClick,
}: {
  icon: ReactNode
  title: string
  subtitle: string
  danger?: boolean
  disabled?: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      className={danger ? 'pp-quick pp-quick--danger' : 'pp-quick'}
      onClick={onClick}
      disabled={disabled}
    >
      <span className="pp-quick__icon" aria-hidden>
        {icon}
      </span>
      <span className="pp-quick__text">
        <strong>{title}</strong>
        <small>{subtitle}</small>
      </span>
      <ChevronRight size={15} className="pp-quick__chevron" aria-hidden />
    </button>
  )
}

export function ProfileQuickActions({
  editable,
  onCopy,
  onImport,
  onExport,
  onReset,
}: {
  editable: boolean
  onCopy: () => void
  onImport: () => void
  onExport: () => void
  onReset: () => void
}) {
  return (
    <PpSideCard title="Quick Actions">
      <QuickAction
        icon={<Copy size={15} aria-hidden />}
        title="Copy from Existing Profile"
        subtitle="Clone settings from another company"
        disabled={!editable}
        onClick={onCopy}
      />
      <QuickAction
        icon={<Upload size={15} aria-hidden />}
        title="Import Configuration"
        subtitle="Read settings from a profile file"
        disabled={!editable}
        onClick={onImport}
      />
      <QuickAction
        icon={<Download size={15} aria-hidden />}
        title="Export Configuration"
        subtitle="Download this profile as JSON"
        onClick={onExport}
      />
      <QuickAction
        icon={<RotateCcw size={15} aria-hidden />}
        title="Reset to Defaults"
        subtitle="Return the form to its starting values"
        danger
        disabled={!editable}
        onClick={onReset}
      />
    </PpSideCard>
  )
}

// ---------------------------------------------------------------------------
// Setup review
// ---------------------------------------------------------------------------

/**
 * What is set and what is still on its defaults.
 *
 * Not a score. "Using defaults" is a legitimate answer for three of these five
 * — exact matching is the safe default and this product says so — so each row
 * states what it found rather than marking it wrong, and the bar is there to
 * show at a glance what is left to look at.
 */
export function ProfileSetupReview({
  sections,
  configured,
  total,
  percent,
  onSelect,
}: {
  sections: SetupSection[]
  configured: number
  total: number
  percent: number
  onSelect: (id: string) => void
}) {
  return (
    <PpSideCard title="Profile Setup">
      <div className="pp-review__top">
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
          <Gauge size={14} aria-hidden /> Sections configured
        </span>
        <span className="pp-review__count">
          {configured} of {total}
        </span>
      </div>

      <div
        className="pp-meter"
        role="progressbar"
        aria-valuenow={configured}
        aria-valuemin={0}
        aria-valuemax={total}
        aria-valuetext={`${configured} of ${total} sections configured`}
      >
        <div className="pp-meter__fill" style={{ width: `${percent}%` }} />
      </div>

      {sections.map((section) => (
        <div key={section.id} className={section.configured ? 'pp-review__row is-set' : 'pp-review__row is-unset'}>
          {section.configured ? <Check size={14} aria-hidden /> : <Circle size={14} aria-hidden />}
          <span style={{ minWidth: 0 }}>
            <strong>
              <button type="button" className="pp-review__jump" onClick={() => onSelect(section.id)}>
                {section.label}
              </button>
            </strong>
            <small>{section.detail}</small>
          </span>
        </div>
      ))}

      <p>Sections still on their defaults are not errors — exact matching and open approval are real choices.</p>
    </PpSideCard>
  )
}
