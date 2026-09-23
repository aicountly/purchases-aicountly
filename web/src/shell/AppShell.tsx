import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowRight,
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
  SlidersHorizontal,
  ShieldCheck,
  Sparkles,
  Truck,
  BarChart3,
  FileSpreadsheet,
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
 * Navigation, in six named sections.
 *
 * This was six COLLAPSING groups, then one flat list of fourteen. The flat
 * list fixed the real complaint — you could see what the product does without
 * opening three drawers — and introduced a smaller one: fourteen links with no
 * captions read as one pile, and nobody could tell the screens they work in
 * from the screens they administer. These are captions, not drawers: nothing
 * opens, nothing closes, and everything is still visible in one glance.
 *
 * `match` is a path prefix, and the LONGEST matching prefix wins — so
 * /dashboard/suppliers lights Supplier reports rather than Dashboards, without
 * either entry needing to know about the other.
 */
interface NavItem {
  to: string
  /** Path prefix that lights this entry. Defaults to `to`. */
  match?: string
  label: string
  icon: LucideIcon
  permission?: string
}

type NavEntry = NavItem | { group: string }

const NAV: NavEntry[] = [
  { group: 'Workspace' },
  { to: '/dashboard/overview', match: '/dashboard/overview', label: 'Dashboards', icon: LayoutDashboard },
  { to: '/approvals', label: 'My approvals', icon: AlertTriangle },

  { group: 'Procurement' },
  { to: '/requisitions', label: 'Requisitions', icon: ClipboardList, permission: 'requisition.view' },
  { to: '/purchase-orders', label: 'Purchase orders', icon: Truck, permission: 'po.view' },
  { to: '/rfqs', label: 'Supplier quotations', icon: FileSearch, permission: 'rfq.view' },

  { group: 'Purchase processing' },
  { to: '/dashboard/procurement', label: 'Goods receipts', icon: PackageCheck, permission: 'po.view' },
  { to: '/bills', label: 'Purchase bills', icon: Receipt, permission: 'bill.enter' },
  { to: '/returns', label: 'Returns', icon: RotateCcw, permission: 'return.create' },
  { to: '/claims', label: 'Claims', icon: ScrollText, permission: 'claim.create' },

  { group: 'Relationships & finance' },
  { to: '/suppliers', label: 'Suppliers', icon: ShieldCheck, permission: 'supplier.view' },
  { to: '/statements', label: 'Supplier statements', icon: Scale, permission: 'bill.enter' },

  { group: 'Intelligence' },
  { to: '/dashboard/suppliers', label: 'Supplier reports', icon: BarChart3, permission: 'supplier.view' },
  { to: '/dashboard/ai-insights', label: 'AI insights', icon: Sparkles },
  { to: '/reports', label: 'Reports', icon: FileSpreadsheet, permission: 'reports.view' },

  { group: 'Administration' },
  { to: '/access', label: 'Access', icon: KeyRound, permission: 'access.manage' },
  { to: '/settings', label: 'Settings', icon: SettingsIcon },
  // Its own entry, not a child of Settings: the same choice this list already
  // makes for Receipts alongside Dashboard. Always visible, like Settings
  // itself — viewing needs no permission, and the page reads as a notice
  // rather than breaking for anyone who cannot edit it.
  { to: '/settings/new-profile', label: 'New Profile', icon: SlidersHorizontal },
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

  const items = useMemo(() => {
    const visible = NAV.filter(
      (entry) =>
        !isLink(entry) || entry.permission === undefined || !permissionsKnown || can(entry.permission),
    )

    // A caption with nothing beneath it names a section the user cannot open.
    return visible.filter((entry, index) => isLink(entry) || isLink(visible[index + 1] ?? { group: '' }))
  }, [permissionsKnown, can])

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
            <span className="app-shell__brand-name">AICOUNTLY</span>
            <span className="app-shell__brand-product">Purchases</span>
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
            if (!isLink(entry)) {
              return (
                <p key={`group-${index}`} className="app-shell__nav-group">
                  {entry.group}
                </p>
              )
            }
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
          {/* The assistant this product already has, offered from the frame
              rather than rebuilt per screen. It is a link, not a widget: the
              answering happens on Ask Purchases, which is backed by a real
              endpoint. */}
          <NavLink to="/dashboard/ai-insights" className="app-shell__ai">
            <span className="app-shell__ai-title">
              <Sparkles size={14} aria-hidden />
              Ask AICOUNTLY AI
            </span>
            <span className="app-shell__ai-copy">Create a requisition, check approvals, or analyse spend.</span>
            <span className="app-shell__ai-cta">
              Chat with AI <ArrowRight size={13} aria-hidden />
            </span>
          </NavLink>

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
