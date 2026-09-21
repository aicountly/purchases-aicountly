/**
 * The access trail.
 *
 * Real rows from this product's append-only audit log, which has recorded every
 * grant, revoke and profile edit since access administration existed. Nothing
 * is synthesised: a company that has changed nothing shows an empty log, which
 * is the correct answer and not a failure.
 *
 * The actor and the target are both portal uuids. They are truncated with the
 * full value on hover, because a column of 36-character identifiers is a column
 * nobody reads — and they are never replaced with a name this product would
 * have had to invent.
 */

import { History, Pencil, Plus, Trash2, UserMinus, UserPlus } from 'lucide-react'
import type { ReactNode } from 'react'
import type { ActivityEvent } from './types'
import { EmptyState, ErrorState, LoadingRows, fullTimestamp, relativeTime, shortUuid } from './ui'

interface Shape {
  icon: ReactNode
  variant: string
  verb: string
}

const SHAPES: Record<string, Shape> = {
  'access.profile_created': { icon: <Plus size={14} aria-hidden />, variant: '', verb: 'created the profile' },
  'access.profile_updated': { icon: <Pencil size={14} aria-hidden />, variant: 'access-event__icon--edit', verb: 'edited the profile' },
  'access.profile_deleted': { icon: <Trash2 size={14} aria-hidden />, variant: 'access-event__icon--revoke', verb: 'deleted the profile' },
  'access.profile_assigned': { icon: <UserPlus size={14} aria-hidden />, variant: 'access-event__icon--grant', verb: 'granted' },
  'access.profile_unassigned': { icon: <UserMinus size={14} aria-hidden />, variant: 'access-event__icon--revoke', verb: 'revoked' },
}

function Uuid({ value }: { value: string }) {
  return (
    <span className="access-uuid" title={value}>
      {shortUuid(value, 10)}
    </span>
  )
}

function EventRow({ event, myUuid }: { event: ActivityEvent; myUuid: string | null }) {
  // An action this build does not know about still gets a row: the log is
  // append-only and older than this screen, and hiding rows it cannot label
  // would quietly make the audit trail incomplete.
  const shape = SHAPES[event.action] ?? {
    icon: <History size={14} aria-hidden />,
    variant: '',
    verb: event.action.replace(/^access\./, '').replace(/_/g, ' '),
  }

  const actor = event.is_you ? 'You' : null
  const isGrant = event.action === 'access.profile_assigned' || event.action === 'access.profile_unassigned'

  return (
    <li className="access-event">
      <span className={`access-event__icon ${shape.variant}`} aria-hidden="true">
        {shape.icon}
      </span>

      <div style={{ minWidth: 0 }}>
        <div className="access-event__title">
          {actor ?? <Uuid value={event.actor_uuid} />} {shape.verb}{' '}
          {event.profile_name ? <strong>{event.profile_name}</strong> : <em>a deleted profile</em>}
          {isGrant && event.user_uuid && (
            <>
              {' '}
              {event.action === 'access.profile_assigned' ? 'to' : 'from'}{' '}
              {event.user_uuid === myUuid ? 'you' : <Uuid value={event.user_uuid} />}
            </>
          )}
        </div>

        <div className="access-event__meta">
          <span title={fullTimestamp(event.created_at)}>{relativeTime(event.created_at)}</span>
          {event.actor_kind === 'service' && <span>· via {event.source_app ?? 'a service'}</span>}
        </div>

        {event.reason && <p className="access-event__reason">“{event.reason}”</p>}
      </div>
    </li>
  )
}

export function ActivityPanel({
  events,
  loading,
  error,
  onRetry,
  myUuid,
}: {
  events: ActivityEvent[]
  loading: boolean
  error: string | null
  onRetry: () => void
  myUuid: string | null
}) {
  const body = () => {
    if (error) return <ErrorState what="the activity log" message={error} onRetry={onRetry} />
    if (loading && events.length === 0) return <LoadingRows rows={5} columns={2} />

    if (events.length === 0) {
      return (
        <EmptyState
          icon={<History size={26} />}
          title="Nothing has changed yet"
          note="Every profile change and every grant is written to an append-only log the moment it happens. Nothing here is editable, by anyone."
        >
          No profile has been created, edited or assigned in this company, so there is nothing to show.
        </EmptyState>
      )
    }

    return (
      <ul className="access-timeline" style={{ listStyle: 'none', margin: 0, padding: '4px 0' }}>
        {events.map((event) => (
          <EventRow key={event.audit_id} event={event} myUuid={myUuid} />
        ))}
      </ul>
    )
  }

  return (
    <section className="access-panel" aria-labelledby="access-activity-heading">
      <header className="access-panel__header">
        <div>
          <h2 id="access-activity-heading">Activity log</h2>
          <p>Who granted, revoked or rewrote what — from this product's audit trail.</p>
        </div>
        {events.length > 0 && (
          <span className="access-cell-sub" style={{ whiteSpace: 'nowrap' }}>
            {events.length === 80 ? 'Latest 80 changes' : `${events.length} change${events.length === 1 ? '' : 's'}`}
          </span>
        )}
      </header>

      <div className="access-panel__body--flush">{body()}</div>
    </section>
  )
}
