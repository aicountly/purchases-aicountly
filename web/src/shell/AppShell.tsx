import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  Bell,
  Building2,
  CalendarDays,
  ChevronDown,
  ClipboardList,
  FileSearch,
  KeyRound,
  LayoutDashboard,
  LogOut,
  MapPin,
  Menu,
  PackageCheck,
  Receipt,
  RotateCcw,
  Scale,
  ScrollText,
  Search,
  Settings as SettingsIcon,
  ShieldCheck,
  Truck,
  BarChart3,
  X,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'
import { usePurchases } from '../context/PurchasesContext'
import { AccessNotice } from './AccessNotice'
import { useScopeLabels } from './useScopeLabels'
import './app-shell.css'

/**
 * Navigation, flat.
 *
 * This was six collapsing groups. It read as a filing cabinet: to find out
 * whether the product could do something you had to open three drawers, and a
 * user with no permissions saw an almost empty cabinet and concluded the
 * product was empty. A flat list says what exists in one glance, which is what
 * the supplied designs do and what they were right about.
 *
 * `match` is a path prefix, and the LONGEST matching prefix wins — so
 * /dashboard/procurement lights Receipts rather than Dashboard, without either
 * entry needing to know about the other.
 */
interface NavItem {
  to: string
  /** Path prefix that lights this entry. Defaults to `to`. */
  match?: string
  label: string
  icon: LucideIcon
  permission?: string
}

type NavEntry = NavItem | { rule: true }

const NAV: NavEntry[] = [
  { to: '/dashboard/overview', match: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/requisitions', label: 'Requisitions', icon: ClipboardList, permission: 'requisition.view' },
  { to: '/rfqs', label: 'Supplier quotations', icon: FileSearch, permission: 'rfq.view' },
  { to: '/purchase-orders', label: 'Purchase orders', icon: Truck, permission: 'po.view' },
  { to: '/dashboard/procurement', label: 'Receipts', icon: PackageCheck, permission: 'po.view' },
  { to: '/bills', label: 'Purchase bills', icon: Receipt, permission: 'bill.enter' },
  { to: '/statements', label: 'Supplier statements', icon: Scale, permission: 'bill.enter' },
  { to: '/returns', label: 'Returns', icon: RotateCcw, permission: 'return.create' },
  { to: '/claims', label: 'Claims', icon: ScrollText, permission: 'claim.create' },
  { to: '/suppliers', label: 'Suppliers', icon: ShieldCheck, permission: 'supplier.view' },
  { to: '/reports', label: 'Reports', icon: BarChart3, permission: 'reports.view' },
  { to: '/approvals', label: 'My approvals', icon: AlertTriangle },
  { rule: true },
  { to: '/access', label: 'Access', icon: KeyRound, permission: 'access.manage' },
  { to: '/settings', label: 'Settings', icon: SettingsIcon },
]

const isLink = (entry: NavEntry): entry is NavItem => 'to' in entry

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '—'
  if (parts.length === 1) return parts[0].slice(0, 2)
  return (parts[0][0] ?? '') + (parts[parts.length - 1][0] ?? '')
}

export function AppShell() {
  const { signOut } = useAuth()
  const { session, can, scope } = usePurchases()
  const labels = useScopeLabels(scope)
  const location = useLocation()
  const navigate = useNavigate()
  const [navOpen, setNavOpen] = useState(false)
  const [query, setQuery] = useState('')

  // Following a link on a phone should leave the menu behind, not on top of
  // the page it just opened.
  useEffect(() => setNavOpen(false), [location.pathname, location.search])

  // Before a company is chosen there is no session, so `can()` answers false
  // for everything. Filtering on that would hide most of the product behind
  // what looks exactly like a permission problem when nothing has been asked.
  const permissionsKnown = session !== null

  const items = useMemo(
    () =>
      NAV.filter(
        (entry) =>
          !isLink(entry) || entry.permission === undefined || !permissionsKnown || can(entry.permission),
      ),
    [permissionsKnown, can],
  )

  // Longest matching prefix wins.
  const activeTo = useMemo(() => {
    let best: string | null = null
    let bestLength = -1
    for (const entry of items) {
      if (!isLink(entry)) continue
      const prefix = entry.match ?? entry.to
      if (location.pathname.startsWith(prefix) && prefix.length > bestLength) {
        best = entry.to
        bestLength = prefix.length
      }
    }
    return best
  }, [items, location.pathname])

  function search(event: FormEvent) {
    event.preventDefault()
    const q = query.trim()
    if (q === '') return
    // The procurement workbench is the one screen that searches orders,
    // suppliers and invoice references together, so that is where this goes.
    navigate(`/dashboard/procurement?q=${encodeURIComponent(q)}`)
  }

  return (
    <div className="app-shell">
      <div
        className={navOpen ? 'app-shell__scrim is-open' : 'app-shell__scrim'}
        onClick={() => setNavOpen(false)}
        aria-hidden
      />

      <aside className={navOpen ? 'app-shell__sidebar is-open' : 'app-shell__sidebar'}>
        <div className="app-shell__brand-row">
          <NavLink to="/dashboard/overview" className="app-shell__brand">
            <span className="app-shell__brand-name">Aicountly</span>
            <span className="app-shell__brand-product">Purchase</span>
          </NavLink>

          {/* Off-canvas, the panel covers the button that opened it, so the way
              out has to live inside the panel. Hidden on a desktop, where the
              sidebar is never in the way of anything. */}
          <button
            type="button"
            className="app-shell__close"
            onClick={() => setNavOpen(false)}
            aria-label="Close the menu"
          >
            <X size={18} aria-hidden />
          </button>
        </div>

        <nav className="app-shell__nav" aria-label="Purchases">
          {items.map((entry, index) => {
            if (!isLink(entry)) return <div key={`rule-${index}`} className="app-shell__nav-rule" />
            const Icon = entry.icon
            return (
              <NavLink
                key={entry.to}
                to={entry.to}
                className={
                  activeTo === entry.to ? 'app-shell__nav-item is-active' : 'app-shell__nav-item'
                }
                aria-current={activeTo === entry.to ? 'page' : undefined}
              >
                <Icon size={17} aria-hidden />
                {entry.label}
              </NavLink>
            )
          })}
        </nav>

        <div className="app-shell__foot">
          <p className="app-shell__assurance">
            <ShieldCheck size={15} aria-hidden />
            <span>No order, bill or payment leaves this product without an approval.</span>
          </p>

          <p className="app-shell__signature">
            <strong>Aicountly Purchase</strong>
            <span>Smarter procurement. Stronger businesses.</span>
          </p>

          <div className="app-shell__user">
            <span className="app-shell__avatar" aria-hidden>
              {initials(session?.display_name ?? '')}
            </span>
            <span className="app-shell__user-name" title={session?.display_name ?? undefined}>
              {session?.display_name ?? '—'}
            </span>
            <button type="button" className="app-shell__signout" onClick={signOut} aria-label="Log out">
              <LogOut size={15} aria-hidden />
            </button>
          </div>
        </div>
      </aside>

      <div className="app-shell__main">
        <header className="app-shell__header">
          <button
            type="button"
            className="app-shell__menu"
            aria-label="Open the menu"
            aria-expanded={navOpen}
            onClick={() => setNavOpen(true)}
          >
            <Menu size={18} aria-hidden />
          </button>

          {/* The scope, in words. "Company 30 · FY 61" told you the ids were
              set and nothing about where you were. */}
          <div className="app-shell__scope">
            <button
              type="button"
              className="app-shell__pill"
              onClick={() => navigate('/choose-company')}
              title="Change company"
            >
              <span className="app-shell__pill-mark">
                <Building2 size={14} aria-hidden />
              </span>
              <span>{labels.company ?? (scope ? `Company ${scope.cmp_id}` : 'Choose a company')}</span>
              <ChevronDown size={14} className="app-shell__pill-caret" aria-hidden />
            </button>

            <button
              type="button"
              className="app-shell__pill app-shell__pill--quiet"
              onClick={() => navigate('/choose-company')}
              title="Change branch"
            >
              <MapPin size={15} aria-hidden />
              <span>{labels.branch ?? 'All branches'}</span>
              <ChevronDown size={14} className="app-shell__pill-caret" aria-hidden />
            </button>

            <button
              type="button"
              className="app-shell__pill app-shell__pill--quiet"
              onClick={() => navigate('/choose-company')}
              title="Change financial year"
            >
              <CalendarDays size={15} aria-hidden />
              <span>{labels.fy ?? (scope ? `FY ${scope.fy_id}` : 'Financial year')}</span>
              <ChevronDown size={14} className="app-shell__pill-caret" aria-hidden />
            </button>
          </div>

          <form className="app-shell__search" onSubmit={search} role="search">
            <Search size={16} aria-hidden />
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search orders, suppliers or invoice references…"
              aria-label="Search purchases"
            />
          </form>

          <div className="app-shell__header-tools">
            {/* No unread dot. The designs show one, but a dot that is always
                lit is not a notification — it is decoration that teaches people
                to ignore the real thing. It goes back when there is a count
                behind it. */}
            <NavLink to="/approvals" className="app-shell__bell" aria-label="Approvals waiting for you">
              <Bell size={18} aria-hidden />
            </NavLink>
            <AppLauncher />
          </div>
        </header>

        <main className="app-shell__content">
          <AccessNotice />
          <Outlet />
        </main>
      </div>
    </div>
  )
}
