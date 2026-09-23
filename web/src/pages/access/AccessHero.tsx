/**
 * The page header, and the one piece of illustration on this screen.
 *
 * Drawn from CSS and the icon set already in the bundle rather than fetched:
 * a remote illustration is a request that can fail, and a security page that
 * half-loads is not a reassuring security page. It is also the reason the
 * orbit is decorative — `aria-hidden`, with nothing in it a screen reader
 * needs, because everything it depicts is said in the words beside it.
 */

import { CheckCircle2, Eye, LockKeyhole, ShieldCheck, Stamp, ShoppingCart } from 'lucide-react'
import type { ReactNode } from 'react'

function SecurityOrbit() {
  return (
    <div className="access-orbit" aria-hidden="true">
      <span className="access-orbit__ring access-orbit__ring--outer" />
      <span className="access-orbit__ring access-orbit__ring--inner" />

      <span className="access-orbit__shield">
        <LockKeyhole size={30} strokeWidth={1.7} />
      </span>

      <span className="access-orbit__node access-orbit__node--buyer">
        <ShoppingCart size={11} aria-hidden /> Buyer
      </span>
      <span className="access-orbit__node access-orbit__node--approver">
        <Stamp size={11} aria-hidden /> Approver
      </span>
      <span className="access-orbit__node access-orbit__node--viewer">
        <Eye size={11} aria-hidden /> Viewer
      </span>
    </div>
  )
}

export function AccessHero({ children }: { children?: ReactNode }) {
  return (
    <header className="access-hero">
      <nav className="access-breadcrumb" aria-label="Breadcrumb">
        <span>Administration</span>
        <span aria-hidden="true">›</span>
        <strong aria-current="page">Access</strong>
      </nav>

      <div className="access-hero__title-row">
        <div>
          <h1>Access &amp; Permissions</h1>
          <p className="access-hero__lede">
            Control who can do what in Aicountly Purchase. Use ready profiles or create your own.
          </p>
        </div>

        <div className="access-hero__security">
          <div className="access-hero__copy">
            <h2>
              Right people.
              <br />
              Right access.
            </h2>
            <p>Keep your purchase process secure, streamlined and under control.</p>

            <ul className="access-hero__assurances">
              <li>
                <ShieldCheck size={13} aria-hidden /> Enforced on the server
              </li>
              <li>
                <CheckCircle2 size={13} aria-hidden /> Least privilege by default
              </li>
              <li>
                <CheckCircle2 size={13} aria-hidden /> Every change recorded
              </li>
            </ul>
          </div>

          <SecurityOrbit />
        </div>
      </div>

      {children}
    </header>
  )
}
