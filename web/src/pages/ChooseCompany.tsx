/**
 * Which company this session is buying for.
 *
 * Every endpoint in this API is scoped to a company, a financial year and a
 * branch, so nothing in the product can be drawn before those three are known.
 * That used to be a one-line notice inside the application frame, which meant
 * the first thing a new sign-in saw was a full sidebar of links that all
 * refused to load. This is the front door instead.
 *
 * THE LIST IS MANAGE'S, READ LIVE. Manage owns companies, years and branches,
 * and the call carries the user's own session key — so Manage decides what
 * comes back. This product never filters that list and is never the thing
 * deciding what somebody may open. Nothing from it is stored except the three
 * ids, plus conveniences that live only in this browser: which company to
 * offer first, the handful most recently opened, and each company's default
 * year and branch.
 *
 * ONE CLICK OPENS, AS IN BOOKS. Picking a company opens it at once on the
 * person's saved default year and branch for it, else the latest financial year
 * and the head office (company/companyContextResolver.ts). There is no year and
 * branch step: both stay changeable from the top bar, whose star saves them as
 * the default. A company with no year or no branch says so here and stays shut.
 *
 * DISCOVERY IS NOT ACCESS. "Browse all workspace companies" lists every company of the
 * person's workspaces from Manage's company registry, including ones they may not open in
 * Purchases — listed, so nobody registers one twice. A company Manage marks as not open
 * here (its list row's `can_open_in_current_app`, or the registry's status) shows the
 * "Company Access Restricted" dialog with "Request access" instead of being chosen. The
 * server still asks Manage, for Purchases, on every scoped request.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ArrowLeft,
  Building2,
  Check,
  Clock,
  Loader2,
  LogOut,
  Search,
  Star,
  Users,
  X,
} from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'
import { CompanyIdTag } from '../components/CompanyIdTag'
import { usePurchases } from '../context/PurchasesContext'
import { resolveCompanyScope } from '../company/companyContextResolver'
import { ApiError } from '../services/api'
import { fetchAllCompanies, fetchCompanyInfo } from '../services/manage'
import type { CompanyListOption } from '../services/manage'
// Module by module rather than the package index, so the access panels stay in the lazily
// loaded Users & Access chunk.
import { CompanyAccessBadge } from '../company-access/CompanyAccessBadge.tsx'
import { CompanyDirectoryPicker } from '../company-access/CompanyDirectoryPicker.tsx'
import { RestrictedCompanyDialog } from '../company-access/RestrictedCompanyDialog.tsx'
import type { AccessStatus, DirectoryCompany } from '../company-access/companyRegistryClient.ts'
import { COMPANY_REGISTRY_APP_NAME, companyRegistry } from '../services/companyRegistry'
import './choose-company.css'

/** A row of Manage's list, as the launcher's own option, from a workspace-directory company. */
function fromDirectory(company: DirectoryCompany): CompanyListOption {
  return {
    cmpId: company.cmp_id,
    name: company.legal_name,
    shortName: null,
    ownership: company.is_owner ? 'owner' : 'shared',
    acsType: company.is_owner ? 1 : 0,
    status: null,
    appAccessStatus: company.access_status,
    canOpenInCurrentApp: true,
  }
}

/** A list row Manage marked as not open here, in the registry's shape — used only when the registry cannot be asked. */
function restrictedRow(c: CompanyListOption): DirectoryCompany {
  const status = (c.appAccessStatus ?? 'ACCESS_RESTRICTED') as AccessStatus
  return {
    canonical_company_id: c.cmpId,
    cmp_id: c.cmpId,
    legal_name: c.name,
    display_name: c.name,
    company_code: '',
    company_status: 'active',
    relationship: c.ownership === 'owner' ? 'owner' : 'member',
    workspace: null,
    is_owner: c.ownership === 'owner',
    is_discoverable: true,
    workspace_visibility: null,
    app: 'purchases',
    access_status: status,
    can_open_in_current_app: false,
    can_request_access: ['ACCESS_RESTRICTED', 'ACCESS_EXPIRED', 'ACCESS_REVOKED'].includes(status),
    can_manage_access: false,
    request: null,
    entitlement_version: 0,
  }
}

const DEFAULT_KEY = 'purchases:default-company'
const RECENT_KEY = 'purchases:recent-companies'
const RECENT_LIMIT = 4

interface RecentEntry {
  cmpId: number
  name: string
  at: number
}

/** Browser storage that never throws and never blocks a render. */
function readStore<T>(key: string, fallback: T): T {
  try {
    const raw = window.localStorage.getItem(key)
    return raw === null ? fallback : (JSON.parse(raw) as T)
  } catch {
    return fallback
  }
}

function writeStore(key: string, value: unknown): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(value))
  } catch {
    /* private window, or storage the browser refuses; the choice still applies now */
  }
}

export function rememberOpened(cmpId: number, name: string): void {
  const rest = readStore<RecentEntry[]>(RECENT_KEY, []).filter((r) => r.cmpId !== cmpId)
  writeStore(RECENT_KEY, [{ cmpId, name, at: Date.now() }, ...rest].slice(0, RECENT_LIMIT))
}

export default function ChooseCompany() {
  const { signOut } = useAuth()
  const { scope, setCompanyScope } = usePurchases()
  const navigate = useNavigate()

  const [companies, setCompanies] = useState<CompanyListOption[] | null>(null)
  const [browsing, setBrowsing] = useState(false)
  const [restricted, setRestricted] = useState<DirectoryCompany | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [query, setQuery] = useState('')

  /** The company being opened (its year and branch are being resolved), and why the last one would not open. */
  const [opening, setOpening] = useState<number | null>(null)
  const [openError, setOpenError] = useState<{ name: string; message: string } | null>(null)

  const [defaultId, setDefaultId] = useState<number | null>(() => readStore<number | null>(DEFAULT_KEY, null))
  const [recent] = useState<RecentEntry[]>(() => readStore<RecentEntry[]>(RECENT_KEY, []))

  const infoAbort = useRef<AbortController | null>(null)
  const openErrorRef = useRef<HTMLDivElement | null>(null)

  // ------------------------------------------------------------- the list

  useEffect(() => {
    let cancelled = false
    fetchAllCompanies()
      .then((rows) => {
        if (cancelled) return
        setCompanies(rows)
      })
      .catch((e: unknown) => {
        if (cancelled) return
        setCompanies([])
        setError(e instanceof Error ? e.message : 'Could not reach Aicountly Manage.')
      })
    return () => {
      cancelled = true
    }
  }, [])

  // ------------------------------------------------------ opening one

  useEffect(() => () => infoAbort.current?.abort(), [])

  // A company picked from far down a long list explains itself where it can be seen.
  useEffect(() => {
    if (openError !== null) openErrorRef.current?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
  }, [openError])

  /** A restricted company explains itself ("Request access"), from the registry when it answers. */
  const showRestricted = useCallback((company: CompanyListOption) => {
    companyRegistry
      .summary(company.cmpId)
      .then(setRestricted)
      .catch(() => setRestricted(restrictedRow(company)))
  }, [])

  /**
   * Opens the company on its default year and branch, else the latest year and the head
   * office. No confirm step: both are changeable from the top bar once it is open.
   */
  const openCompany = useCallback((company: CompanyListOption) => {
    infoAbort.current?.abort()
    const controller = new AbortController()
    infoAbort.current = controller
    setOpening(company.cmpId)
    setOpenError(null)

    resolveCompanyScope(company.cmpId, fetchCompanyInfo, controller.signal)
      .then((resolved) => {
        if (controller.signal.aborted) return
        if (resolved.scope === null) {
          setOpenError({ name: company.name, message: resolved.error })
          return
        }
        rememberOpened(company.cmpId, company.name)
        setCompanyScope(resolved.scope)
        navigate('/dashboard/overview')
      })
      .catch((e: unknown) => {
        if (controller.signal.aborted) return
        // Manage says the company is in this person's workspace but not open to them here.
        if (e instanceof ApiError && e.details.manage_code === 'COMPANY_ACCESS_REQUIRED') {
          showRestricted({ ...company, canOpenInCurrentApp: false, appAccessStatus: company.appAccessStatus ?? 'ACCESS_RESTRICTED' })
          return
        }
        setOpenError({ name: company.name, message: 'Could not load its financial years and branches from Aicountly Manage. Try again.' })
      })
      .finally(() => {
        if (!controller.signal.aborted) setOpening(null)
      })
  }, [setCompanyScope, navigate, showRestricted])

  /** Choosing a card: a company Manage will not open here for this person is explained, never opened. */
  const pick = useCallback((company: CompanyListOption) => {
    if (company.canOpenInCurrentApp !== false) {
      openCompany(company)
      return
    }
    showRestricted(company)
  }, [openCompany, showRestricted])

  /** From the workspace directory: only ever called for a company this person may open here. */
  const pickFromDirectory = useCallback((company: DirectoryCompany) => {
    const option = (companies ?? []).find((c) => c.cmpId === company.cmp_id) ?? fromDirectory(company)
    setCompanies((prev) => (prev === null || prev.some((c) => c.cmpId === option.cmpId) ? prev : [...prev, option]))
    setBrowsing(false)
    openCompany(option)
  }, [companies, openCompany])

  const toggleDefault = useCallback((cmpId: number) => {
    setDefaultId((current) => {
      const next = current === cmpId ? null : cmpId
      writeStore(DEFAULT_KEY, next)
      return next
    })
  }, [])

  // ------------------------------------------------------------- derived

  const filtered = useMemo(() => {
    const rows = companies ?? []
    const q = query.trim().toLowerCase()
    const matched = q === '' ? rows : rows.filter((c) => c.name.toLowerCase().includes(q))
    // The default first, then owned before shared, then alphabetical: the order
    // somebody scanning for their own company actually reads in.
    return [...matched].sort((a, b) => {
      if (a.cmpId === defaultId) return -1
      if (b.cmpId === defaultId) return 1
      if (a.ownership !== b.ownership) return a.ownership === 'shared' ? 1 : -1
      return a.name.localeCompare(b.name)
    })
  }, [companies, query, defaultId])

  const owned = (companies ?? []).filter((c) => c.ownership !== 'shared').length
  const openable = (companies ?? []).filter((c) => c.canOpenInCurrentApp !== false).length
  const recentRows = useMemo(
    () => recent.filter((r) => (companies ?? []).some((c) => c.cmpId === r.cmpId)),
    [recent, companies],
  )

  const loading = companies === null

  return (
    <div className="launcher">
      <header className="launcher__bar">
        <div className="launcher__brand">
          <strong>Aicountly</strong>
          <span>Purchase</span>
        </div>
        <div className="launcher__bar-tools">
          <AppLauncher />
          <button type="button" className="launcher__btn" onClick={signOut}>
            <LogOut size={15} aria-hidden /> Log out
          </button>
        </div>
      </header>

      <div className="launcher__body">
        <div className="launcher__hero">
          <h1>Which company are you buying for?</h1>
          <p>
            Purchase orders, bills and approvals all belong to one company and one financial year.
            Pick a company and it opens on your default year and branch — else the latest year and
            the head office. Change either from the top bar.
          </p>

          <div className="launcher__search">
            <Search size={17} aria-hidden />
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search your companies…"
              aria-label="Search your companies"
              autoFocus
            />
          </div>

          <div style={{ marginTop: '0.75rem' }}>
            <button
              type="button"
              className="launcher__btn"
              aria-pressed={browsing}
              onClick={() => setBrowsing((b) => !b)}
              title="Every company registered in your workspaces, with your access in Purchases"
            >
              {browsing ? <ArrowLeft size={15} aria-hidden /> : <Users size={15} aria-hidden />}
              {browsing ? 'Back to your companies' : 'Browse all workspace companies'}
            </button>
            {scope !== null && (
              <button type="button" className="launcher__btn" onClick={() => navigate(-1)} style={{ marginLeft: '0.5rem' }}>
                <X size={15} aria-hidden /> Cancel
              </button>
            )}
          </div>
        </div>

        {browsing && (
          <section className="launcher__section">
            <div className="launcher__section-head">
              <h2>Workspace companies</h2>
            </div>
            <div className="launcher__note">
              <CompanyDirectoryPicker
                client={companyRegistry}
                appName={COMPANY_REGISTRY_APP_NAME}
                title={null}
                selectedCmpId={opening ?? scope?.cmp_id ?? null}
                onOpen={pickFromDirectory}
              />
            </div>
          </section>
        )}

        {!browsing && !loading && (companies ?? []).length > 0 && (
          <div className="launcher__stats">
            <div className="launcher__stat">
              <strong>{openable}</strong>
              <span>{openable === 1 ? 'Company' : 'Companies'} you can open</span>
            </div>
            <div className="launcher__stat">
              <strong>{owned}</strong>
              <span>Owned by you</span>
            </div>
            <div className="launcher__stat">
              <strong>{(companies ?? []).length - owned}</strong>
              <span>Shared with you</span>
            </div>
          </div>
        )}

        {error !== null && (
          <div className="launcher__section">
            <div className="launcher__note launcher__note--danger">
              <strong>Aicountly Manage did not answer</strong>
              {error} Your companies live in Manage, so nothing can be opened until it replies.
            </div>
          </div>
        )}

        {openError !== null && (
          <div className="launcher__section" role="alert" ref={openErrorRef}>
            <div className="launcher__note launcher__note--warning">
              <strong>{openError.name} could not be opened</strong>
              {openError.message}
            </div>
          </div>
        )}

        {!browsing && recentRows.length > 0 && query.trim() === '' && (
          <section className="launcher__section">
            <div className="launcher__section-head">
              <h2>
                <Clock size={12} aria-hidden style={{ marginRight: 5, verticalAlign: -1 }} />
                Recently opened
              </h2>
            </div>
            <div className="launcher__grid">
              {recentRows.map((row) => {
                const company = (companies ?? []).find((c) => c.cmpId === row.cmpId)
                if (!company) return null
                return (
                  <CompanyCard
                    key={`recent-${row.cmpId}`}
                    company={company}
                    selected={scope?.cmp_id === company.cmpId}
                    opening={opening === company.cmpId}
                    isDefault={defaultId === company.cmpId}
                    onPick={() => pick(company)}
                    onToggleDefault={() => toggleDefault(company.cmpId)}
                  />
                )
              })}
            </div>
          </section>
        )}

        {!browsing && (
        <section className="launcher__section">
          <div className="launcher__section-head">
            <h2>{query.trim() === '' ? 'All companies' : `Matching “${query.trim()}”`}</h2>
            {!loading && <span className="launcher__tag">{filtered.length}</span>}
          </div>

          {loading ? (
            <div className="launcher__grid" aria-busy="true">
              {Array.from({ length: 6 }, (_, i) => (
                <div key={i} className="launcher__skeleton" />
              ))}
            </div>
          ) : filtered.length === 0 ? (
            <div className="launcher__note launcher__note--warning">
              <strong>
                {(companies ?? []).length === 0
                  ? 'No companies yet'
                  : 'Nothing matches that search'}
              </strong>
              {(companies ?? []).length === 0 ? (
                <>
                  Aicountly Manage lists no companies for this sign-in. Search your workspace companies
                  first (Browse all workspace companies, above) — a company someone in your workspace
                  already registered is listed there even before you can open it, so it is never
                  registered twice. Otherwise create one in Manage, or ask whoever owns the company to
                  give you access.
                </>
              ) : (
                <>
                  Try part of the company name, or clear the search to see all of them. Not one of
                  yours? Search your workspace companies first (Browse all workspace companies, above)
                  before creating it.
                </>
              )}
            </div>
          ) : (
            <div className="launcher__grid">
              {filtered.map((company) => (
                <CompanyCard
                  key={company.cmpId}
                  company={company}
                  selected={scope?.cmp_id === company.cmpId}
                  opening={opening === company.cmpId}
                  isDefault={defaultId === company.cmpId}
                  onPick={() => pick(company)}
                  onToggleDefault={() => toggleDefault(company.cmpId)}
                />
              ))}
            </div>
          )}
        </section>
        )}

      </div>

      {restricted !== null && (
        <RestrictedCompanyDialog
          client={companyRegistry}
          company={restricted}
          appName={COMPANY_REGISTRY_APP_NAME}
          onClose={() => setRestricted(null)}
          onRequested={(next) =>
            setCompanies((prev) =>
              prev === null ? prev : prev.map((c) => (c.cmpId === next.cmp_id ? { ...c, appAccessStatus: next.access_status } : c)),
            )
          }
        />
      )}

      <footer className="launcher__foot">
        <span>Companies, branches and financial years are managed in Aicountly Manage.</span>
        <span>Aicountly Purchase</span>
      </footer>
    </div>
  )
}

function CompanyCard({
  company,
  selected,
  opening,
  isDefault,
  onPick,
  onToggleDefault,
}: {
  company: CompanyListOption
  selected: boolean
  /** Its year and branch are being resolved; it opens as soon as they are. */
  opening: boolean
  isDefault: boolean
  onPick: () => void
  onToggleDefault: () => void
}) {
  return (
    <div className={selected ? 'launcher__card is-selected' : 'launcher__card'}>
      <span className="launcher__card-mark" aria-hidden>
        {opening ? <Loader2 size={18} className="launcher__spin" /> : selected ? <Check size={18} /> : <Building2 size={18} />}
      </span>

      <button
        type="button"
        className="launcher__card-text"
        onClick={onPick}
        aria-busy={opening}
        style={{ appearance: 'none', border: 0, background: 'none', font: 'inherit', textAlign: 'left', cursor: 'pointer', padding: 0 }}
      >
        <span className="launcher__card-name">
          {company.name} <CompanyIdTag id={company.cmpId} />
        </span>
        <span className="launcher__card-meta">
          <span className={company.ownership === 'shared' ? 'launcher__tag' : 'launcher__tag launcher__tag--owner'}>
            {company.ownership === 'shared' ? 'Shared with you' : 'You own this'}
          </span>
          {isDefault && <span className="launcher__tag launcher__tag--default">Opens first</span>}
          {company.canOpenInCurrentApp === false && <CompanyAccessBadge status={company.appAccessStatus} />}
        </span>
      </button>

      <button
        type="button"
        className={isDefault ? 'launcher__star is-on' : 'launcher__star'}
        onClick={onToggleDefault}
        aria-pressed={isDefault}
        aria-label={isDefault ? `Stop offering ${company.name} first` : `Offer ${company.name} first`}
        title={isDefault ? 'Stop offering this one first' : 'Offer this one first'}
      >
        <Star size={16} aria-hidden fill={isDefault ? 'currentColor' : 'none'} />
      </button>
    </div>
  )
}
