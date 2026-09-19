/**
 * What to say when the app has nothing to show a person.
 *
 * An empty sidebar and a grid of "Unavailable" cards is what this product
 * showed every company owner for its whole life, and it reads as a product
 * that was never built rather than as an account that was never granted
 * anything. Whatever the answer is, the app says it in words.
 *
 * Three different situations, three different things to do, and they must not
 * be collapsed into one apology:
 *
 *   no role reported   Manage did not say what this person is here. Nothing
 *                      the user can fix; it is ours, and it says so.
 *   no permissions     A real answer: they are a delegate and nobody has
 *                      granted them anything in Purchases yet. Says who can.
 *   nothing wrong      Renders nothing at all.
 */

import { ShieldAlert } from 'lucide-react'
import { usePurchases } from '../context/PurchasesContext'

export function AccessNotice() {
  const { session, loading } = usePurchases()

  // Say nothing while the answer is still in flight: a banner that appears for
  // half a second on every page load trains people to dismiss it unread.
  if (loading || !session) return null
  if (session.is_owner || session.permissions.length > 0) return null

  const unresolved = session.access_resolved === false

  return (
    <div
      role="status"
      style={{
        display: 'flex',
        gap: '0.85rem',
        alignItems: 'flex-start',
        margin: '1.25rem 1.5rem 0',
        padding: '0.95rem 1.1rem',
        border: '1px solid #f0d8a8',
        background: '#fdf8ec',
        borderRadius: 'var(--radius-md, 10px)',
        color: '#6b4e12',
        fontSize: '0.88rem',
        lineHeight: 1.55,
      }}
    >
      <ShieldAlert size={18} aria-hidden style={{ flexShrink: 0, marginTop: '0.15rem' }} />
      <div>
        <strong style={{ display: 'block', marginBottom: '0.3rem', color: '#513a0a' }}>
          {unresolved
            ? 'Aicountly Purchases could not confirm your role in this company'
            : 'You have no permissions in Aicountly Purchases yet'}
        </strong>
        {unresolved ? (
          <>
            Aicountly Manage did not report whether you own this company, so nothing here can be opened
            yet. This is a fault on our side rather than anything wrong with your account — the reason is
            in the Purchases server log, under <code>[context]</code>.
          </>
        ) : (
          <>
            Permissions here are separate from Aicountly Manage and from Smart Books: being a member of
            the company does not by itself grant anything in Purchases. The company owner grants them
            under <strong>Administration → Access</strong>, by assigning you a permission profile.
          </>
        )}
      </div>
    </div>
  )
}
