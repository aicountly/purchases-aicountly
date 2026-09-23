/**
 * The contextual panel: what a profile is, and where this administrator stands.
 *
 * It collapses on narrow screens rather than pushing the thing people came to
 * do below the fold. Guidance that is always open on a phone is guidance that
 * gets scrolled past every single time.
 */

import { useState } from 'react'
import {
  ChevronDown,
  Info,
  KeyRound,
  Layers,
  ScrollText,
  ShieldCheck,
  Users,
} from 'lucide-react'

const ITEMS = [
  {
    icon: <Users size={16} aria-hidden />,
    title: 'Use profiles',
    body: 'Profiles are sets of permissions you can assign to people — Buyer, Approver, Accounts payable.',
  },
  {
    icon: <ShieldCheck size={16} aria-hidden />,
    title: 'Stay secure',
    body: 'Follow the principle of least privilege. Give only the access needed for the job, and nothing else.',
  },
  {
    icon: <ScrollText size={16} aria-hidden />,
    title: 'Audit ready',
    body: 'Every profile change and every grant is written to an append-only log, with the reason you typed.',
  },
  {
    icon: <Layers size={16} aria-hidden />,
    title: 'Flexibility',
    body: 'Start from the starter profiles, or build your own from the permission catalogue.',
  },
]

export function AccessGuide({
  isOwner,
  grantedCount,
  ownerNote,
}: {
  isOwner: boolean
  /** How many permissions a delegate administrator holds, and so can hand out. */
  grantedCount: number | null
  ownerNote: string | null
}) {
  const [open, setOpen] = useState(true)

  return (
    <section className="access-panel access-guide" aria-labelledby="access-guide-heading">
      <div className="access-guide__header">
        <div className="access-guide__title">
          <ShieldCheck size={17} aria-hidden />
          <h2 id="access-guide-heading">About access control</h2>
        </div>

        <button
          type="button"
          className="access-guide__toggle"
          aria-expanded={open}
          aria-controls="access-guide-body"
          onClick={() => setOpen((was) => !was)}
        >
          {open ? 'Hide' : 'Show'}
          <ChevronDown size={13} aria-hidden style={{ transform: open ? 'none' : 'rotate(-90deg)' }} />
        </button>
      </div>

      <div id="access-guide-body" className="access-guide__body" hidden={!open}>
        {ITEMS.map((item) => (
          <div className="access-guide__item" key={item.title}>
            <span className="access-guide__icon" aria-hidden="true">
              {item.icon}
            </span>
            <div>
              <strong>{item.title}</strong>
              <p>{item.body}</p>
            </div>
          </div>
        ))}

        {isOwner ? (
          <div className="access-owner">
            <ShieldCheck size={16} aria-hidden />
            <div>
              <strong>You are the company owner</strong>
              <p>
                You have full access to all features in Aicountly Purchase. Ownership is set in Aicountly
                Manage, and nothing on this page can take it away from you.
              </p>
            </div>
          </div>
        ) : (
          <div className="access-owner access-owner--delegate">
            <KeyRound size={16} aria-hidden />
            <div>
              <strong>You administer access here</strong>
              <p>
                {grantedCount === null
                  ? 'You can grant the permissions you hold yourself.'
                  : `You hold ${grantedCount} permission${grantedCount === 1 ? '' : 's'} and can grant those. The rest are shown but cannot be given out.`}
                {ownerNote ? ` ${ownerNote}` : ''}
              </p>
            </div>
          </div>
        )}

        <p className="access-field__hint" style={{ display: 'flex', gap: 6, marginTop: 12 }}>
          <Info size={12} aria-hidden style={{ flexShrink: 0, marginTop: 2 }} />
          <span>
            These permissions are enforced by the Purchases API, not by this page. Hiding a button is a
            courtesy; the rule is on the server.
          </span>
        </p>
      </div>
    </section>
  )
}
