import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { AppErrorBoundary } from './AppErrorBoundary'
import { COPYRIGHT, GRIEVANCE_OFFICER, LEGAL_LINKS } from './legal'
import { isAnalyticsConfigured } from '../utils/analytics'
import { showAnalyticsPreferences } from '../utils/analyticsConsent'
import {
  AlertTriangle,
  Bell,
  Building2,
  CalendarDays,
  ChevronDown,
  ClipboardList,
  FileSearch,
  GitBranch,
  History,
  KeyRound,
  LayoutDashboard,
  LogOut,
  UserCog,
  MapPin,
  Menu,
  PackageCheck,
  Pencil,
  Receipt,
  RotateCcw,
  Scale,
  ScrollText,
  Search,
  Settings as SettingsIcon,
  SlidersHorizontal,
  ShieldCheck,
  Sparkles,
  Star,
  Truck,
  UserCheck,
  BarChart3,
  FileSpreadsheet,
  X,
  UserPlus,
  Users,
  ExternalLink,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'
import { manageAccountUrl, manageBranchesUrl, manageCompanyEditUrl, manageProfileUrl } from '../services/appLauncher'
import { ConnectEmbed } from '../components/ConnectEmbed'
import { usePurchases } from '../context/PurchasesContext'
import { activeAccountFirstName, canAddAccount, readAccountRoster, type RosterAccount } from '../auth/accounts'
import { fetchUserPhotoUrl } from '../services/userPhoto'
import { recentCompaniesForMenu, type RecentEntry } from '../company/recentCompanies'
import { AccessNotice } from './AccessNotice'
import { CompanyIdTag } from '../components/CompanyIdTag'
import { useScopeLabels } from './useScopeLabels'
import { getCompanyDefault, saveCompanyDefault } from '../company/companyContextResolver'
import './app-shell.css'
import { CompanyReadOnlyBanner } from '../lifecycle/CompanyReadOnlyBanner'

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
  // Company access from Manage's company registry. No permission: the owner administers it
  // there, anyone else sees their own access and can ask for it.
  { to: '/users-access', label: 'Users & Access', icon: UserCheck },
  { to: '/audit-log', label: 'Audit trail', icon: History, permission: 'audit.view' },
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

/**
 * The signed-in person's photo, read through this product's own API.
 *
 * The portal owns the photo and will not answer the browser directly, so it comes through
 * `/api/global/user/logo` (services/userPhoto.ts). Nothing here can fail loudly: no photo, a
 * relay that is down and a body that is not an image all mean "initials", the object URL is
 * revoked when the menu goes away, and the menu renders before the answer arrives.
 */
function useUserPhoto(): string | null {
  const [photo, setPhoto] = useState<string | null>(null)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false

    void fetchUserPhotoUrl().then((next) => {
      if (cancelled) {
        if (next !== null) URL.revokeObjectURL(next)
        return
      }
      objectUrl = next
      setPhoto(next)
    })

    return () => {
      cancelled = true
      if (objectUrl !== null) URL.revokeObjectURL(objectUrl)
      setPhoto(null)
    }
  }, [])

  return photo
}

/** The photo where there is one, the initials where there is not. */
function Avatar({ initials: fallback, photo, large = false }: { initials: string; photo: string | null; large?: boolean }) {
  return (
    <span className={large ? 'app-shell__avatar app-shell__avatar--lg' : 'app-shell__avatar'} aria-hidden>
      {photo !== null ? <img src={photo} alt="" className="app-shell__avatar-photo" /> : fallback}
    </span>
  )
}

/**
 * The account menu — who you are, the accounts signed in on this browser, the
 * company you are in, and the way out.
 *
 * Purchases had no account menu at all: a strip with the name and two icon
 * buttons, so there was nowhere to put the roster and nothing matched the rest
 * of the suite. This is the layout every AICOUNTLY product shares
 * (AICOUNTLY_ACCOUNT_MENU.md in the Saas Common Handler): identity → accounts
 * → company → links → sign out.
 */
function AccountMenu() {
  const { signOut, switchAccount } = useAuth()
  const { session, scope } = usePurchases()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [roster, setRoster] = useState<RosterAccount[]>([])
  const [recent, setRecent] = useState<RecentEntry[]>([])
  const photo = useUserPhoto()
  const wrap = useRef<HTMLDivElement | null>(null)
  const trigger = useRef<HTMLButtonElement | null>(null)

  // Read when the menu opens, not on every render: only a full page load can
  // change the cookie, because the portal is what writes it.
  useEffect(() => {
    if (!open) return
    setRoster(readAccountRoster())
    // The recent companies are read on open for the same reason: the picker writes them, so
    // between two openings of this menu they can only have changed on another screen.
    setRecent(recentCompaniesForMenu())
  }, [open])

  useEffect(() => {
    if (!open) return undefined
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
        trigger.current?.focus()
      }
    }
    const onClick = (event: MouseEvent) => {
      if (wrap.current && !wrap.current.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('keydown', onKey)
    document.addEventListener('mousedown', onClick)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('mousedown', onClick)
    }
  }, [open])

  const name = activeAccountFirstName() || (session?.display_name ?? 'Signed in')
  const custId = session?.kind === 'user' && session.uuid ? String(session.uuid) : ''
  const activeEntry =
    roster.find((account) => account.active && String(account.uuid) === custId) ??
    roster.find((account) => account.active) ??
    null
  const others = roster.filter((account) => !account.active)

  return (
    <div className="app-shell__account" ref={wrap}>
      <button
        type="button"
        ref={trigger}
        className="app-shell__account-trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`${name}: account menu`}
        onClick={() => setOpen((was) => !was)}
      >
        <Avatar initials={session?.display_name ? initials(session.display_name) : '\u2022'} photo={photo} />
        <span className="app-shell__user-name" title={session?.display_name ?? undefined}>
          {name}
        </span>
        <ChevronDown size={14} aria-hidden />
      </button>

      {open && (
        <div className="app-shell__account-menu" role="menu">
          {/* Who you are — the photo belongs HERE, not only on the trigger (§1). */}
          <div className="app-shell__account-head">
            <Avatar initials={session?.display_name ? initials(session.display_name) : '\u2022'} photo={photo} large />
            <div className="app-shell__account-who">
              <strong>{name}</strong>
              {activeEntry?.maskedEmail && <span>{activeEntry.maskedEmail}</span>}
              {activeEntry?.maskedMobile && (
                <span title="Your registered mobile number">{activeEntry.maskedMobile}</span>
              )}
              {custId && (
                <span title="Your AICOUNTLY customer ID">
                  Cust ID: <b>{custId}</b>
                </span>
              )}
            </div>
          </div>

          {(others.length > 0 || canAddAccount(roster)) && (
            <>
              <p className="app-shell__account-section">Accounts</p>
              {others.map((account) => (
                <button
                  key={account.uuid}
                  type="button"
                  role="menuitem"
                  className="app-shell__account-item"
                  onClick={() => {
                    setOpen(false)
                    switchAccount(account.uuid)
                  }}
                >
                  <Users size={15} aria-hidden />
                  <span>{account.name || account.maskedEmail || `Account ${account.uuid}`}</span>
                </button>
              ))}
              {canAddAccount(roster) && (
                <button
                  type="button"
                  role="menuitem"
                  className="app-shell__account-item"
                  onClick={() => {
                    setOpen(false)
                    switchAccount()
                  }}
                >
                  <UserPlus size={15} aria-hidden />
                  <span>Use another account</span>
                </button>
              )}
            </>
          )}

          {/* Company. Purchases is company-scoped — every order, bill and claim hangs off
              `cmp_id` — so this block is mandatory, in Books' order, and the picker in the top
              bar is no reason to leave it out (§3). Switch company → Recent → Edit company →
              Manage branches. Manage owns the company and its branches, so the last two open
              Manage in a new tab rather than a company editor this product does not have. */}
          <p className="app-shell__account-section">Company</p>
          <button
            type="button"
            role="menuitem"
            className="app-shell__account-item"
            onClick={() => {
              setOpen(false)
              navigate('/choose-company')
            }}
          >
            <Building2 size={15} aria-hidden />
            <span>Switch company</span>
          </button>

          {recent.length > 0 && (
            <>
              <p className="app-shell__account-section">Recent</p>
              {recent.map((row) => (
                <button
                  key={row.cmpId}
                  type="button"
                  role="menuitem"
                  className="app-shell__account-item app-shell__account-item--quiet"
                  title={`Open the company picker on ${row.name}`}
                  onClick={() => {
                    setOpen(false)
                    navigate('/choose-company')
                  }}
                >
                  <span>{row.name}</span>
                  <CompanyIdTag id={row.cmpId} />
                </button>
              ))}
            </>
          )}

          <button
            type="button"
            role="menuitem"
            className="app-shell__account-item"
            title="Opens this company in Manage in a new tab"
            onClick={() => {
              setOpen(false)
              window.open(manageCompanyEditUrl(scope?.cmp_id), '_blank', 'noopener,noreferrer')
            }}
          >
            <Pencil size={15} aria-hidden />
            <span>Edit company</span>
            <ExternalLink size={12} aria-hidden />
          </button>
          <button
            type="button"
            role="menuitem"
            className="app-shell__account-item"
            title="Opens this company's branches in Manage in a new tab"
            onClick={() => {
              setOpen(false)
              window.open(manageBranchesUrl(scope?.cmp_id), '_blank', 'noopener,noreferrer')
            }}
          >
            <GitBranch size={15} aria-hidden />
            <span>Manage branches</span>
            <ExternalLink size={12} aria-hidden />
          </button>

          <button
            type="button"
            role="menuitem"
            className="app-shell__account-item"
            title="Opens your profile in Manage in a new tab"
            onClick={() => {
              setOpen(false)
              window.open(manageProfileUrl(), '_blank', 'noopener,noreferrer')
            }}
          >
            <UserCog size={15} aria-hidden />
            <span>Manage profile</span>
            <ExternalLink size={12} aria-hidden />
          </button>
          <button
            type="button"
            role="menuitem"
            className="app-shell__account-item"
            title="Opens your account settings in Manage in a new tab"
            onClick={() => {
              setOpen(false)
              window.open(manageAccountUrl(), '_blank', 'noopener,noreferrer')
            }}
          >
            <Users size={15} aria-hidden />
            <span>Manage Account</span>
            <ExternalLink size={12} aria-hidden />
          </button>

          <button type="button" role="menuitem" className="app-shell__account-item" onClick={signOut}>
            <LogOut size={15} aria-hidden />
            <span>Sign out</span>
          </button>
        </div>
      )}
    </div>
  )
}

export function AppShell() {
  const { session, can, scope, setCompanyScope } = usePurchases()
  const labels = useScopeLabels(scope)
  // "Set as default": the star only needs a re-render after a save; the default itself is read below.
  const [, setDefaultSaved] = useState<string | null>(null)
  const savedDefault = scope ? getCompanyDefault(scope.cmp_id) : null
  const isDefault = scope !== null && savedDefault !== null
    && savedDefault.fy_id === scope.fy_id && savedDefault.bo_id === scope.bo_id
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
          <p className="app-shell__assurance">
            <ShieldCheck size={15} aria-hidden />
            <span>No order, bill or payment leaves this product without an approval.</span>
          </p>

          <p className="app-shell__signature">
            <strong>Aicountly Purchase</strong>
            <span>Smarter procurement. Stronger businesses.</span>
          </p>

          {/* Privacy, terms, support and grievance, as every AICOUNTLY app carries them. */}
          <nav className="app-shell__legal" aria-label="Legal and support">
            {LEGAL_LINKS.map((link) => (
              <a key={link.href} href={link.href} target="_blank" rel="noopener noreferrer">
                {link.label}
              </a>
            ))}
            {isAnalyticsConfigured() ? (
              // The consent banner again, to change the analytics choice made for every AICOUNTLY app.
              <button
                type="button"
                className="app-shell__legal-button"
                onClick={() => {
                  setNavOpen(false)
                  showAnalyticsPreferences()
                }}
              >
                Analytics preferences
              </button>
            ) : null}
            <span className="app-shell__legal-note" title={`Grievance officer: ${GRIEVANCE_OFFICER.name}, ${GRIEVANCE_OFFICER.email}`}>
              {COPYRIGHT}
            </span>
          </nav>

          <AccountMenu />
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
              {labels.company && <CompanyIdTag id={scope?.cmp_id} />}
              <ChevronDown size={14} className="app-shell__pill-caret" aria-hidden />
            </button>

            {scope !== null ? (
              <>
                {/* Year and branch switch here, in place — opening a company never asks for
                    them (it lands on the default, else the latest year and the head office). */}
                <label className="app-shell__pill app-shell__pill--quiet app-shell__pill--select" title="Change branch">
                  <MapPin size={15} aria-hidden />
                  <select
                    aria-label="Branch"
                    value={scope.bo_id}
                    onChange={(event) => setCompanyScope({ ...scope, bo_id: Number(event.target.value) || 0 })}
                  >
                    <option value={0}>All branches</option>
                    {scope.bo_id > 0 && !labels.branches.some((b) => b.boId === scope.bo_id) && (
                      <option value={scope.bo_id}>{labels.branch ?? `Branch ${scope.bo_id}`}</option>
                    )}
                    {labels.branches.map((b) => (
                      <option key={b.boId} value={b.boId}>
                        {b.name}
                        {b.isHeadOffice ? ' (head office)' : ''}
                      </option>
                    ))}
                  </select>
                  <ChevronDown size={14} className="app-shell__pill-caret" aria-hidden />
                </label>

                <label className="app-shell__pill app-shell__pill--quiet app-shell__pill--select" title="Change financial year">
                  <CalendarDays size={15} aria-hidden />
                  <select
                    aria-label="Financial year"
                    value={scope.fy_id}
                    onChange={(event) => setCompanyScope({ ...scope, fy_id: Number(event.target.value) })}
                  >
                    {!labels.fyList.some((f) => f.fyId === scope.fy_id) && (
                      <option value={scope.fy_id}>{labels.fy ?? `FY ${scope.fy_id}`}</option>
                    )}
                    {labels.fyList.map((f) => (
                      <option key={f.fyId} value={f.fyId}>
                        {f.label}
                      </option>
                    ))}
                  </select>
                  <ChevronDown size={14} className="app-shell__pill-caret" aria-hidden />
                </label>

                <button
                  type="button"
                  className={isDefault ? 'app-shell__star is-on' : 'app-shell__star'}
                  onClick={() => {
                    if (saveCompanyDefault(scope.cmp_id, scope.fy_id, scope.bo_id)) {
                      setDefaultSaved(`${scope.cmp_id}:${scope.fy_id}:${scope.bo_id}`)
                    }
                  }}
                  aria-pressed={isDefault}
                  aria-label={isDefault ? 'Default financial year and branch' : 'Set as default financial year and branch'}
                  title={isDefault
                    ? 'This financial year and branch open by default for this company'
                    : 'Open this company on this financial year and branch by default'}
                >
                  <Star size={15} aria-hidden fill={isDefault ? 'currentColor' : 'none'} />
                </button>
              </>
            ) : (
              <>
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
                  <span>{labels.fy ?? 'Financial year'}</span>
                  <ChevronDown size={14} className="app-shell__pill-caret" aria-hidden />
                </button>
              </>
            )}
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
          <CompanyReadOnlyBanner cmpId={scope?.cmp_id} />
          {/* A screen that fails to render shows Reload and a reference here; the shell keeps working
              and choosing another page clears it. */}
          <AppErrorBoundary resetKey={location.pathname} homeHref="/dashboard/overview">
            <Outlet />
          </AppErrorBoundary>
        </main>
        {/* Aicountly Connect draws its own launcher, in its own shadow root; nothing of it
            touches this shell's styles. */}
        <ConnectEmbed />
      </div>
    </div>
  )
}
