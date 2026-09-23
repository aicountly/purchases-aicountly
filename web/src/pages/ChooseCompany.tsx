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
 * ids, plus two conveniences that live only in this browser: which company to
 * offer first, and the handful most recently opened.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Building2,
  Check,
  ChevronRight,
  Clock,
  LogOut,
  Search,
  Star,
  X,
} from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'
import { usePurchases } from '../context/PurchasesContext'
import { fetchAllCompanies, fetchCompanyInfo } from '../services/manage'
import type { CompanyInfo, CompanyOption } from '../services/manage'
import './choose-company.css'

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

  const [companies, setCompanies] = useState<CompanyOption[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [query, setQuery] = useState('')

  const [chosen, setChosen] = useState<CompanyOption | null>(null)
  const [info, setInfo] = useState<CompanyInfo | null>(null)
  const [loadingInfo, setLoadingInfo] = useState(false)
  const [fyId, setFyId] = useState<number | null>(null)
  const [boId, setBoId] = useState(0)

  const [defaultId, setDefaultId] = useState<number | null>(() => readStore<number | null>(DEFAULT_KEY, null))
  const [recent] = useState<RecentEntry[]>(() => readStore<RecentEntry[]>(RECENT_KEY, []))

  const infoAbort = useRef<AbortController | null>(null)
  const confirmRef = useRef<HTMLDivElement | null>(null)

  // ------------------------------------------------------------- the list

  useEffect(() => {
    let cancelled = false
    fetchAllCompanies()
      .then((rows) => {
        if (cancelled) return
        setCompanies(rows)
        // A list of one is not a choice worth making somebody click through.
        if (rows.length === 1) setChosen(rows[0])
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

  // ------------------------------------------------- the chosen company

  useEffect(() => {
    if (chosen === null) {
      setInfo(null)
      return
    }

    infoAbort.current?.abort()
    const controller = new AbortController()
    infoAbort.current = controller
    setLoadingInfo(true)

    fetchCompanyInfo(chosen.cmpId, controller.signal)
      .then((next) => {
        if (controller.signal.aborted) return
        setInfo(next)
        // Newest year first, which is how Manage sorts them.
        setFyId(next.fyList[0]?.fyId ?? null)
        setBoId(0)
      })
      .catch(() => {
        if (controller.signal.aborted) return
        setInfo(null)
        setError(`Could not load the years and branches for ${chosen.name}.`)
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoadingInfo(false)
      })

    return () => controller.abort()
  }, [chosen])

  // Bring the confirm step into view when a company is picked from far down
  // a long list — otherwise the click appears to do nothing.
  useEffect(() => {
    if (chosen !== null) confirmRef.current?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  }, [chosen])

  const open = useCallback(() => {
    if (chosen === null || fyId === null) return
    rememberOpened(chosen.cmpId, chosen.name)
    setCompanyScope({ cmp_id: chosen.cmpId, fy_id: fyId, bo_id: boId })
    navigate('/dashboard/overview')
  }, [chosen, fyId, boId, setCompanyScope, navigate])

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
            Pick them here and everything that follows is scoped to them.
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
        </div>

        {!loading && (companies ?? []).length > 0 && (
          <div className="launcher__stats">
            <div className="launcher__stat">
              <strong>{(companies ?? []).length}</strong>
              <span>{(companies ?? []).length === 1 ? 'Company' : 'Companies'} you can open</span>
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

        {recentRows.length > 0 && query.trim() === '' && (
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
                    selected={chosen?.cmpId === company.cmpId}
                    isDefault={defaultId === company.cmpId}
                    onPick={() => setChosen(company)}
                    onToggleDefault={() => toggleDefault(company.cmpId)}
                  />
                )
              })}
            </div>
          </section>
        )}

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
                  Aicountly Manage lists no companies for this sign-in. Create one in Manage, or ask
                  whoever owns the company to give you access — this product cannot grant it, and it
                  would be wrong if it could.
                </>
              ) : (
                <>Try part of the company name, or clear the search to see all of them.</>
              )}
            </div>
          ) : (
            <div className="launcher__grid">
              {filtered.map((company) => (
                <CompanyCard
                  key={company.cmpId}
                  company={company}
                  selected={chosen?.cmpId === company.cmpId}
                  isDefault={defaultId === company.cmpId}
                  onPick={() => setChosen(company)}
                  onToggleDefault={() => toggleDefault(company.cmpId)}
                />
              ))}
            </div>
          )}
        </section>

        {chosen !== null && (
          <div className="launcher__confirm" ref={confirmRef}>
            <p className="launcher__confirm-head">
              <Building2 size={17} aria-hidden style={{ color: '#187b12' }} />
              {chosen.name}
            </p>

            {loadingInfo ? (
              <p className="launcher__note">Loading its financial years and branches…</p>
            ) : info !== null && info.fyList.length === 0 ? (
              <div className="launcher__note launcher__note--warning">
                <strong>No financial year</strong>
                This company has no financial year set up. Add one in Aicountly Manage — every
                document here is filed against a year, so there is nothing this product can do
                until one exists.
              </div>
            ) : (
              <div className="launcher__confirm-row">
                <div className="launcher__field">
                  <label htmlFor="launcher-fy">Financial year</label>
                  <select
                    id="launcher-fy"
                    value={fyId ?? ''}
                    onChange={(event) => setFyId(event.target.value === '' ? null : Number(event.target.value))}
                  >
                    {(info?.fyList ?? []).map((fy) => (
                      <option key={fy.fyId} value={fy.fyId}>
                        {fy.label}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="launcher__field">
                  <label htmlFor="launcher-branch">Branch</label>
                  <select
                    id="launcher-branch"
                    value={boId}
                    onChange={(event) => setBoId(Number(event.target.value) || 0)}
                  >
                    <option value={0}>All branches</option>
                    {(info?.branches ?? []).map((branch) => (
                      <option key={branch.boId} value={branch.boId}>
                        {branch.name}
                        {branch.isHeadOffice ? ' (head office)' : ''}
                      </option>
                    ))}
                  </select>
                </div>

                <button
                  type="button"
                  className="launcher__btn launcher__btn--primary"
                  onClick={open}
                  disabled={fyId === null}
                >
                  Open <ChevronRight size={16} aria-hidden />
                </button>

                {scope !== null && (
                  <button type="button" className="launcher__btn" onClick={() => navigate(-1)}>
                    <X size={15} aria-hidden /> Cancel
                  </button>
                )}
              </div>
            )}
          </div>
        )}
      </div>

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
  isDefault,
  onPick,
  onToggleDefault,
}: {
  company: CompanyOption
  selected: boolean
  isDefault: boolean
  onPick: () => void
  onToggleDefault: () => void
}) {
  return (
    <div className={selected ? 'launcher__card is-selected' : 'launcher__card'}>
      <span className="launcher__card-mark" aria-hidden>
        {selected ? <Check size={18} /> : <Building2 size={18} />}
      </span>

      <button
        type="button"
        className="launcher__card-text"
        onClick={onPick}
        style={{ appearance: 'none', border: 0, background: 'none', font: 'inherit', textAlign: 'left', cursor: 'pointer', padding: 0 }}
      >
        <span className="launcher__card-name">{company.name}</span>
        <span className="launcher__card-meta">
          <span className={company.ownership === 'shared' ? 'launcher__tag' : 'launcher__tag launcher__tag--owner'}>
            {company.ownership === 'shared' ? 'Shared with you' : 'You own this'}
          </span>
          {isDefault && <span className="launcher__tag launcher__tag--default">Opens first</span>}
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
