import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import {
  AlertTriangle,
  BarChart3,
  ChevronDown,
  ClipboardList,
  FileSearch,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Menu,
  PackageCheck,
  Receipt,
  RotateCcw,
  ScrollText,
  Settings as SettingsIcon,
  ShieldCheck,
  Sparkles,
  Truck,
  X,
} from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AccessNotice } from './AccessNotice'
import { AppLauncher } from '../components/AppLauncher'
import { usePurchases } from '../context/PurchasesContext'
import { CompanyPicker } from './CompanyPicker'
import './app-shell.css'

/**
 * Navigation, grouped rather than listed.
 *
 * Ten items in one flat column is a column nobody reads past the sixth entry.
 * These are the groups the work actually falls into, and a group closes when
 * you are not in it — a permanently expanded sidebar showing every child is the
 * thing progressive disclosure exists to avoid.
 *
 * A group with no visible children does not render at all, so a user without
 * the permissions behind it never sees an empty heading.
 */
const NAV_GROUPS = [
  {
    id: 'workspace',
    label: 'Workspace',
    items: [
      { to: '/dashboard/overview', label: 'Dashboards', icon: LayoutDashboard, match: '/dashboard' },
      { to: '/approvals', label: 'My approvals', icon: AlertTriangle },
    ],
  },
  {
    id: 'procurement',
    label: 'Procurement',
    items: [
      { to: '/requisitions', label: 'Requisitions', icon: ClipboardList, permission: 'requisition.view' },
      { to: '/rfqs', label: 'Supplier quotations', icon: FileSearch, permission: 'rfq.view' },
      { to: '/purchase-orders', label: 'Purchase orders', icon: Truck, permission: 'po.view' },
      { to: '/dashboard/procurement', label: 'Deliveries & receipts', icon: PackageCheck, permission: 'po.view' },
    ],
  },
  {
    id: 'processing',
    label: 'Purchase processing',
    items: [
      { to: '/bills', label: 'Purchase bills', icon: Receipt, permission: 'bill.enter' },
      { to: '/dashboard/bills-payables', label: 'Matching workbench', icon: AlertTriangle, permission: 'match.view' },
      { to: '/returns', label: 'Returns', icon: RotateCcw, permission: 'return.create' },
      { to: '/claims', label: 'Claims', icon: ScrollText, permission: 'claim.create' },
    ],
  },
  {
    id: 'relationships',
    label: 'Relationships & finance',
    items: [
      { to: '/suppliers', label: 'Suppliers', icon: ShieldCheck, permission: 'supplier.view' },
      { to: '/dashboard/bills-payables', label: 'Bills & payables', icon: BarChart3, permission: 'reports.view' },
    ],
  },
  {
    id: 'intelligence',
    label: 'Intelligence',
    items: [
      { to: '/dashboard/suppliers', label: 'Supplier reports', icon: BarChart3, permission: 'reports.view' },
      { to: '/dashboard/ai-insights', label: 'AI insights', icon: Sparkles },
    ],
  },
  {
    id: 'administration',
    label: 'Administration',
    items: [
      { to: '/access', label: 'Access', icon: KeyRound, permission: 'access.manage' },
      { to: '/settings', label: 'Settings', icon: SettingsIcon },
    ],
  },
] as const

export function AppShell() {
  const { signOut } = useAuth()
  const { session, can, scope } = usePurchases()
  const location = useLocation()
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})
  const [navOpen, setNavOpen] = useState(false)

  // Following a link on a phone should leave the menu behind, not on top of the
  // page it just opened.
  useEffect(() => setNavOpen(false), [location.pathname, location.search])

  return (
    <div className="app-shell">
      {navOpen && (
        <div
          className="app-shell__scrim"
          aria-hidden="true"
          onClick={() => setNavOpen(false)}
        />
      )}

      <aside className={navOpen ? 'app-shell__sidebar is-open' : 'app-shell__sidebar'}>
        <div
          style={{
            display: 'flex',
            alignItems: 'flex-start',
            justifyContent: 'space-between',
            gap: '0.5rem',
            padding: '1rem',
            borderBottom: '1px solid var(--border)',
          }}
        >
          <div>
            <div style={{ fontWeight: 700, fontSize: '1rem', letterSpacing: '-0.01em' }}>AICOUNTLY</div>
            <div style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>Purchases</div>
          </div>
          <button
            type="button"
            className="app-shell__close"
            aria-label="Close the menu"
            onClick={() => setNavOpen(false)}
          >
            <X size={17} aria-hidden />
          </button>
        </div>

        <nav style={{ padding: '0.5rem', flex: 1, overflowY: 'auto' }} aria-label="Purchases">
          {NAV_GROUPS.map((group) => {
            // Before a company is chosen there is no session, so `can()` cannot
            // answer and returns false for everything. Filtering on that hid
            // three whole groups behind what looked exactly like a permission
            // problem, when in fact nothing had been asked yet. Until the
            // session is known, show the nav; every link lands on the company
            // picker anyway, which is the honest next step.
            const permissionsKnown = session !== null
            const items = group.items.filter(
              (item) => !('permission' in item) || !permissionsKnown || can(item.permission as string),
            )
            if (items.length === 0) return null

            // A group opens when the current page is inside it, so the sidebar
            // arrives showing where you are rather than everything at once.
            const active = items.some((item) => location.pathname.startsWith(('match' in item ? item.match : item.to) as string))
            const open = expanded[group.id] ?? active

            return (
              <div key={group.id} style={{ marginBottom: '0.35rem' }}>
                <button
                  type="button"
                  aria-expanded={open}
                  onClick={() => setExpanded((state) => ({ ...state, [group.id]: !open }))}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    width: '100%',
                    padding: '0.4rem 0.65rem',
                    border: 0,
                    borderRadius: 'var(--radius-sm)',
                    background: 'transparent',
                    color: 'var(--muted)',
                    cursor: 'pointer',
                    fontSize: '0.72rem',
                    fontWeight: 700,
                    letterSpacing: '0.06em',
                    textTransform: 'uppercase',
                  }}
                >
                  {group.label}
                  <ChevronDown
                    size={13}
                    aria-hidden
                    style={{ transform: open ? 'none' : 'rotate(-90deg)', transition: 'transform .15s ease' }}
                  />
                </button>

                {open &&
                  items.map((entry) => {
                    const Icon = entry.icon
                    return (
                      <NavLink
                        key={`${group.id}-${entry.to}-${entry.label}`}
                        to={entry.to}
                        end={entry.to === '/dashboard/overview' ? false : undefined}
                        style={({ isActive }) => ({
                          display: 'flex',
                          alignItems: 'center',
                          gap: '0.6rem',
                          padding: '0.45rem 0.65rem 0.45rem 1.1rem',
                          marginBottom: '0.1rem',
                          borderRadius: 'var(--radius-sm)',
                          color: isActive ? 'var(--fg)' : 'var(--muted)',
                          background: isActive ? 'var(--surface)' : 'transparent',
                          fontWeight: isActive ? 600 : 400,
                          fontSize: '0.86rem',
                          textDecoration: 'none',
                        })}
                      >
                        <Icon size={15} aria-hidden />
                        {entry.label}
                      </NavLink>
                    )
                  })}
              </div>
            )
          })}
        </nav>

        <div style={{ padding: '0.75rem', borderTop: '1px solid var(--border)' }}>
          <div style={{ fontSize: '0.82rem', marginBottom: '0.5rem', overflow: 'hidden', textOverflow: 'ellipsis' }}>
            {session?.display_name ?? '—'}
            {session?.is_owner && (
              <span style={{ color: 'var(--muted)', fontSize: '0.75rem', display: 'block' }}>Company owner</span>
            )}
          </div>
          <button
            type="button"
            onClick={signOut}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '0.4rem',
              width: '100%',
              padding: '0.4rem 0.5rem',
              background: 'transparent',
              border: '1px solid var(--border-strong)',
              borderRadius: 'var(--radius-sm)',
              cursor: 'pointer',
              color: 'var(--muted)',
            }}
          >
            <LogOut size={14} aria-hidden /> Log out
          </button>
        </div>
      </aside>

      <div className="app-shell__main">
        <header className="app-shell__header">
          <button
            type="button"
            className="app-shell__menu"
            aria-label={navOpen ? 'Close the menu' : 'Open the menu'}
            aria-expanded={navOpen}
            onClick={() => setNavOpen((open) => !open)}
          >
            <Menu size={18} aria-hidden />
          </button>

          <div className="app-shell__context" style={{ minWidth: 0 }}>
            <CompanyPicker />
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            {scope && (
              <span style={{ color: 'var(--muted)', fontSize: '0.8rem' }} className="num">
                Company {scope.cmp_id} · FY {scope.fy_id}
                {scope.bo_id > 0 ? ` · Branch ${scope.bo_id}` : ''}
              </span>
            )}
            <AppLauncher />
          </div>
        </header>

        {/* No padding here: the dashboards bring their own, and the other
            pages get it from the wrapper below. */}
        <main className="app-shell__content">
          <AccessNotice />
          <Outlet />
        </main>
      </div>
    </div>
  )
}
