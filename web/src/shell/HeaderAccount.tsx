/**
 * The approvals bell and the user menu.
 *
 * THE BADGE COUNTS WHAT THE PAGE IT OPENS SHOWS. `/approvals` lists every
 * pending approval in the company, so that is what the badge counts and what
 * its accessible name says. It is deliberately NOT the Overview's "Awaiting
 * your approval" card, which is the narrower question — the ones you hold the
 * permission for and did not raise yourself. Two numbers that mean two things
 * are fine; two numbers that look like the same thing and differ are not, so
 * both say which they are.
 *
 * Nothing here is a hardcoded avatar or a fabricated notification feed. The
 * name is the session's, the initials are derived from it, and the only badge
 * is a count from a live endpoint.
 */

import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Bell, LogOut, Settings as SettingsIcon, ShieldCheck } from 'lucide-react'
import { api } from '../services/api'
import { useAuth } from '../auth/AuthProvider'
import { usePurchases } from '../context/PurchasesContext'

/** "Rohit Sharma" → "RS"; "rohit@example.com" → "RO". */
function initialsOf(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '—'
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase()

  return (words[0][0] + words[words.length - 1][0]).toUpperCase()
}

export function ApprovalsBell() {
  const { scope } = usePurchases()
  const [pending, setPending] = useState<number | null>(null)

  useEffect(() => {
    if (!scope) {
      setPending(null)
      return
    }

    const controller = new AbortController()
    // limit=1 because only the total is wanted: the rows are the Approvals
    // page's job, and fetching a hundred of them to count them would be a
    // hundred rows nobody renders.
    api
      .list<unknown>('v1/approvals', { status: 'PENDING', limit: 1 }, controller.signal)
      .then((response) => setPending(response.meta.total))
      .catch(() => {
        // A bell that cannot count says nothing rather than saying zero.
        if (!controller.signal.aborted) setPending(null)
      })

    return () => controller.abort()
  }, [scope])

  const label =
    pending === null
      ? 'Approvals'
      : `Approvals: ${pending} pending in this company`

  return (
    <Link to="/approvals" className="app-icon-button" aria-label={label} title={label}>
      <Bell size={17} aria-hidden />
      {pending !== null && pending > 0 && (
        <span className="app-icon-button__badge" aria-hidden>
          {pending > 99 ? '99+' : pending}
        </span>
      )}
    </Link>
  )
}

export function UserMenu() {
  const { signOut } = useAuth()
  const { session, scope } = usePurchases()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const boxRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    function onClickAway(event: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) setOpen(false)
    }
    function onEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onClickAway)
    document.addEventListener('keydown', onEscape)
    return () => {
      document.removeEventListener('mousedown', onClickAway)
      document.removeEventListener('keydown', onEscape)
    }
  }, [])

  const name = session?.display_name ?? 'Signed in'
  const context = scope ? `Company ${scope.cmp_id} · FY ${scope.fy_id}${scope.bo_id > 0 ? ` · Branch ${scope.bo_id}` : ''}` : null

  return (
    <div className="app-user" ref={boxRef}>
      <button
        type="button"
        className="app-user__trigger"
        aria-expanded={open}
        aria-haspopup="menu"
        onClick={() => setOpen((was) => !was)}
      >
        <span className="app-user__avatar" aria-hidden>
          {initialsOf(name)}
        </span>
        <span className="app-user__who">
          <span className="app-user__name">{name}</span>
          {context && <span className="app-user__context">{context}</span>}
        </span>
      </button>

      {open && (
        <div className="app-user__menu" role="menu">
          <div className="app-user__header">
            <strong>{name}</strong>
            {session?.is_owner && (
              <span className="app-user__role">
                <ShieldCheck size={12} aria-hidden /> Company owner
              </span>
            )}
            {context && <span className="app-user__context">{context}</span>}
          </div>

          <button type="button" role="menuitem" onClick={() => { setOpen(false); navigate('/settings') }}>
            <SettingsIcon size={14} aria-hidden /> Settings
          </button>
          <button type="button" role="menuitem" onClick={signOut}>
            <LogOut size={14} aria-hidden /> Log out
          </button>
        </div>
      )}
    </div>
  )
}
