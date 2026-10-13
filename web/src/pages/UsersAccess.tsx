/**
 * Administration → Users & Access. Who may open this company in Purchases.
 *
 * A separate screen from Access, deliberately:
 *
 *   Users & Access   whether a person may OPEN this company in Purchases at all. That is
 *                    company access, owned by Manage's company registry; this page renders
 *                    the shared panel and every change goes to Manage (/api/v1/*) — nothing
 *                    is copied into Purchases.
 *   Access           what a person may DO in Purchases once they are in: Purchases
 *                    permission profiles, which stay this product's own and authoritative.
 *
 * The verified company owner grants, changes and revokes access here and reviews requests;
 * anyone else sees their own access and can ask for it. No redirect to Manage.
 */

import { Link } from 'react-router-dom'
import { CompanyUsersAccessPanel, MyWorkspacesPanel } from '../company-access'
import { COMPANY_REGISTRY_APP_NAME, companyRegistry } from '../services/companyRegistry'
import { usePurchases } from '../context/PurchasesContext'
import { Notice } from '../ui'

export default function UsersAccess() {
  const { scope } = usePurchases()

  if (!scope) return <Notice tone="info">Choose a company first.</Notice>

  return (
    <div className="purchase-workspace">
      <header className="purchase-page-header">
        <div style={{ minWidth: 0 }}>
          <p className="purchase-eyebrow">Aicountly Purchases</p>
          <h1>Users &amp; Access</h1>
          <p className="purchase-page-subtitle">
            Who may open this company in Purchases. What they can do once they are in is set under{' '}
            <Link to="/access">Access</Link> (Purchases permission profiles).
          </p>
        </div>
      </header>

      <div className="purchase-dashboard-content" style={{ display: 'grid', gap: '1rem' }}>
        {/* No `roles`: Purchases profiles are per company and are assigned under Access, so a
            registry role_key would be advisory only and is not offered here. */}
        <CompanyUsersAccessPanel client={companyRegistry} cmpId={scope.cmp_id} appName={COMPANY_REGISTRY_APP_NAME} />
        <MyWorkspacesPanel client={companyRegistry} />
      </div>
    </div>
  )
}
