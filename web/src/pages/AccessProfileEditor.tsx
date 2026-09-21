/**
 * Administration → Access → Profiles → New profile.
 *
 * The screen that decides what somebody can do in Purchases, which is why it
 * is a page rather than a strip of checkboxes at the bottom of a list: the
 * whole catalogue in one undifferentiated wall is a wall an administrator ticks
 * their way through without reading, and the segregation of duties the rest of
 * this product enforces is only as good as the profile somebody wrote here.
 *
 * WHAT IS PRESENTATION AND WHAT IS NOT.
 *
 * The catalogue is the authority. Groups, permission keys and their labels all
 * come from `v1/access/catalogue` exactly as the server sends them — this file
 * adds a description, an icon and a tint per group and nothing else, and a
 * group the server grows tomorrow renders on its own with a neutral icon. No
 * key is invented, renamed or hard-coded into the payload.
 *
 * The two server rules stay visible, because they are design rather than a
 * rejection somebody hits by surprise:
 *
 *   - You can only grant permissions you hold. Anything outside `grantable`
 *     renders locked with the reason on it, and Select all steps over it.
 *   - Editing a profile that grants more than you hold must not strip what you
 *     cannot grant. Those permissions stay checked, locked, and survive
 *     templates, Copy from existing profile and Select all alike.
 */

import { useCallback, useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react'
import {
  ArrowLeft,
  Check,
  ChevronDown,
  ChevronRight,
  ClipboardList,
  Copy,
  FileSearch,
  Grid3x3,
  KeyRound,
  LayoutTemplate,
  Lightbulb,
  Lock,
  PackageCheck,
  RotateCcw,
  Search,
  Settings2,
  Shapes,
  ShieldCheck,
  Sparkles,
  Truck,
  Users,
  X,
  type LucideIcon,
} from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { AccessCatalogue, AccessProfile } from '../services/types'
import './access-profile.css'

/**
 * The server truncates a description at 200 characters. Counting to 200 in the
 * UI is the difference between a limit and text that silently disappears.
 */
const DESCRIPTION_LIMIT = 200

// ---------------------------------------------------------------------------
// Presentation metadata
//
// Keyed by the catalogue's own group titles. A title with no entry here still
// renders — it just gets the neutral treatment, which is the right failure for
// a group added to the server before this map catches up.
// ---------------------------------------------------------------------------

interface GroupPresentation {
  description: string
  Icon: LucideIcon
  tint: string
}

const GROUP_PRESENTATION: Record<string, GroupPresentation> = {
  Requisitions: {
    description: 'Manage purchase requisitions from request to approval',
    Icon: ClipboardList,
    tint: 'tint-violet',
  },
  Sourcing: {
    description: 'Manage RFQs, supplier quotes and sourcing activities',
    Icon: FileSearch,
    tint: 'tint-green',
  },
  'Purchase orders': {
    description: 'Create and manage purchase orders',
    Icon: Truck,
    tint: 'tint-blue',
  },
  'Receiving and billing': {
    description: 'Goods receiving, supplier bills and 3-way match',
    Icon: PackageCheck,
    tint: 'tint-amber',
  },
  'Returns and claims': {
    description: 'Manage purchase returns and supplier claims',
    Icon: RotateCcw,
    tint: 'tint-rose',
  },
  Suppliers: {
    description: 'Supplier profiles, approvals and spend visibility',
    Icon: Users,
    tint: 'tint-indigo',
  },
  Administration: {
    description: 'Purchases configuration, access and reporting',
    Icon: Settings2,
    tint: 'tint-slate',
  },
}

const NEUTRAL_PRESENTATION: GroupPresentation = {
  description: 'Permissions in this module',
  Icon: Shapes,
  tint: '',
}

// ---------------------------------------------------------------------------
// Templates
//
// A UI convenience only: nothing here is stored, and every key is filtered
// against the catalogue before it is applied, so a template that names a
// permission this company's server does not have quietly drops it rather than
// producing a profile the API would refuse.
//
// The sets mirror the server's own starter profiles, which are this product's
// considered answer to "what does an approver actually need"; keeping a second
// opinion here would mean two definitions of Read only that drift apart.
// ---------------------------------------------------------------------------

interface Template {
  id: string
  name: string
  blurb: string
  detail: string
  /** '*' means every permission the catalogue offers. */
  keys: string[] | '*'
}

const TEMPLATES: Template[] = [
  {
    id: 'procurement-executive',
    name: 'Procurement Executive',
    blurb: 'Requisitions, RFQs, POs',
    detail: 'Raises requisitions, sources quotes and issues purchase orders. Deliberately cannot approve their own.',
    keys: [
      'requisition.view', 'requisition.create',
      'rfq.view', 'rfq.create', 'quote.enter',
      'po.view', 'po.create',
      'receipt.request',
      'supplier.view', 'cost.view',
    ],
  },
  {
    id: 'accounts-payable',
    name: 'Accounts Payable',
    blurb: 'Supplier bills, payments, GRN',
    detail: 'Records goods received, enters supplier bills, clears match exceptions and posts to Smart Books.',
    keys: [
      'po.view', 'receipt.request',
      'bill.enter', 'bill.post',
      'match.view', 'match.resolve',
      'return.create', 'claim.create',
      'supplier.view', 'cost.view', 'reports.view',
    ],
  },
  {
    id: 'purchase-manager',
    name: 'Purchase Manager',
    blurb: 'Full purchase access',
    detail: 'Everything this product can do, including settings and access administration.',
    keys: '*',
  },
  {
    id: 'read-only',
    name: 'Read Only',
    blurb: 'View access to all modules',
    detail: 'Sees the dashboards and the documents behind them, and changes nothing.',
    keys: [
      'requisition.view', 'rfq.view', 'po.view',
      'match.view', 'supplier.view', 'reports.view',
    ],
  },
]

// ---------------------------------------------------------------------------
// Shapes the screen renders
// ---------------------------------------------------------------------------

interface PresentedPermission {
  key: string
  label: string
  /** False when the catalogue says this caller cannot hand it out. */
  grantable: boolean
}

interface PresentedGroup {
  id: string
  title: string
  description: string
  Icon: LucideIcon
  tint: string
  permissions: PresentedPermission[]
}

function slug(title: string): string {
  return title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
}

function sameSet(a: Set<string>, b: Set<string>): boolean {
  if (a.size !== b.size) return false
  for (const value of a) if (!b.has(value)) return false
  return true
}

// ---------------------------------------------------------------------------

export default function AccessProfileEditor({
  profile,
  catalogue,
  catalogueLoading,
  catalogueError,
  onRetryCatalogue,
  profiles,
  profilesLoading,
  onClose,
  onSaved,
}: {
  /** null for a new profile; the profile being rewritten otherwise. */
  profile: AccessProfile | null
  catalogue: AccessCatalogue | null
  catalogueLoading: boolean
  catalogueError: string | null
  onRetryCatalogue: () => void
  /** Existing profiles, for Copy from existing profile. */
  profiles: AccessProfile[]
  profilesLoading: boolean
  onClose: () => void
  onSaved: (saved: AccessProfile) => void
}) {
  const isNew = profile === null

  const [name, setName] = useState(profile?.profile_name ?? '')
  const [description, setDescription] = useState(profile?.description ?? '')
  const [chosen, setChosen] = useState<Set<string>>(() => new Set(profile?.permissions ?? []))
  const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set())
  const [search, setSearch] = useState('')
  const [dialog, setDialog] = useState<'templates' | 'copy' | 'matrix' | 'discard' | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [nameError, setNameError] = useState<string | null>(null)
  const [saveError, setSaveError] = useState<{ title: string; message: string } | null>(null)
  const [note, setNote] = useState<string | null>(null)

  const nameInput = useRef<HTMLInputElement>(null)

  const grantable = useMemo(() => new Set(catalogue?.grantable ?? []), [catalogue])

  /**
   * Permissions this profile already grants that the current administrator
   * cannot. The server refuses to let them be dropped, so nothing in this
   * screen — Select all, a template, a copied profile — may drop them either.
   */
  const locked = useMemo(() => {
    if (isNew || !catalogue) return new Set<string>()
    return new Set((profile?.permissions ?? []).filter((key) => !grantable.has(key)))
  }, [isNew, catalogue, profile, grantable])

  const groups = useMemo<PresentedGroup[]>(() => {
    if (!catalogue) return []

    return Object.entries(catalogue.catalog).map(([title, permissions]) => {
      const presentation = GROUP_PRESENTATION[title] ?? NEUTRAL_PRESENTATION
      return {
        id: slug(title),
        title,
        description: presentation.description,
        Icon: presentation.Icon,
        tint: presentation.tint,
        permissions: Object.entries(permissions).map(([key, label]) => ({
          key,
          label,
          grantable: grantable.has(key),
        })),
      }
    })
  }, [catalogue, grantable])

  const allKeys = useMemo(() => groups.flatMap((group) => group.permissions.map((p) => p.key)), [groups])

  const query = search.trim().toLowerCase()

  const visibleGroups = useMemo<PresentedGroup[]>(() => {
    if (!query) return groups

    return groups
      .map((group) => {
        // A module whose own name matches keeps all of its permissions: somebody
        // searching "suppliers" wants the module, not the four labels that
        // happen to repeat the word.
        const groupMatches = group.title.toLowerCase().includes(query) || group.description.toLowerCase().includes(query)
        return {
          ...group,
          permissions: groupMatches
            ? group.permissions
            : group.permissions.filter(
                (p) => p.label.toLowerCase().includes(query) || p.key.toLowerCase().includes(query),
              ),
        }
      })
      .filter((group) => group.permissions.length > 0)
  }, [groups, query])

  const selectedModuleCount = useMemo(
    () => groups.filter((group) => group.permissions.some((p) => chosen.has(p.key))).length,
    [groups, chosen],
  )

  const resolveTemplate = useCallback(
    (template: Template): string[] => {
      const wanted = template.keys === '*' ? allKeys : template.keys
      return wanted.filter((key) => grantable.has(key))
    },
    [allKeys, grantable],
  )

  /**
   * Derived rather than remembered: a highlight that survives the first manual
   * tick is a highlight that lies about what the profile now grants.
   */
  const activeTemplateId = useMemo(() => {
    if (chosen.size === 0) return null
    const match = TEMPLATES.find((template) => {
      const resolved = new Set([...locked, ...resolveTemplate(template)])
      return resolved.size > 0 && sameSet(resolved, chosen)
    })
    return match?.id ?? null
  }, [chosen, locked, resolveTemplate])

  // What the editor was opened with, so Cancel can tell an untouched form from
  // a filled one rather than warning about nothing.
  const pristine = useRef({
    name: profile?.profile_name ?? '',
    description: profile?.description ?? '',
    permissions: new Set(profile?.permissions ?? []),
  })

  const dirty =
    name !== pristine.current.name ||
    description !== pristine.current.description ||
    !sameSet(chosen, pristine.current.permissions)

  const toggle = useCallback((key: string) => {
    setChosen((previous) => {
      const next = new Set(previous)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }, [])

  /** Select all / deselect all within one module, over what is on screen. */
  const setGroupChosen = useCallback((group: PresentedGroup, on: boolean) => {
    setChosen((previous) => {
      const next = new Set(previous)
      for (const permission of group.permissions) {
        if (!permission.grantable) continue
        if (on) next.add(permission.key)
        else next.delete(permission.key)
      }
      return next
    })
  }, [])

  const applyTemplate = useCallback(
    (template: Template) => {
      const resolved = resolveTemplate(template)
      setChosen(new Set([...locked, ...resolved]))
      setNote(
        `${template.name} applied — ${resolved.length} permission${resolved.length === 1 ? '' : 's'}. ` +
          'Adjust anything below; nothing is fixed until you save.',
      )
      setDialog(null)
    },
    [locked, resolveTemplate],
  )

  const copyFrom = useCallback(
    (source: AccessProfile) => {
      const usable = source.permissions.filter((key) => grantable.has(key))
      const dropped = source.permissions.length - usable.length

      setChosen(new Set([...locked, ...usable]))
      if (description.trim() === '' && source.description) setDescription(source.description)

      setNote(
        `Copied ${usable.length} permission${usable.length === 1 ? '' : 's'} from ${source.profile_name}. ` +
          (dropped > 0
            ? `${dropped} could not be copied because you do not hold ${dropped === 1 ? 'it' : 'them'} yourself. `
            : '') +
          `${source.profile_name} itself is untouched.`,
      )
      setDialog(null)
    },
    [grantable, locked, description],
  )

  const requestClose = useCallback(() => {
    if (dirty && !submitting) setDialog('discard')
    else onClose()
  }, [dirty, submitting, onClose])

  // Escape leaves the editor the same way Close does, including the warning.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && dialog === null) requestClose()
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [dialog, requestClose])

  const permissionsUnknown = catalogue === null
  const canSubmit = !submitting && !permissionsUnknown && name.trim() !== '' && chosen.size > 0

  const blockedReason = permissionsUnknown
    ? 'The permission catalogue has not loaded yet.'
    : name.trim() === ''
      ? 'Give the profile a name first.'
      : chosen.size === 0
        ? 'A profile with no permissions grants nothing. Choose at least one.'
        : undefined

  async function submit() {
    if (submitting) return

    const trimmed = name.trim()
    if (trimmed === '') {
      setNameError('Give the profile a name.')
      nameInput.current?.focus()
      return
    }
    if (chosen.size === 0 || permissionsUnknown) return

    setSubmitting(true)
    setNameError(null)
    setSaveError(null)

    try {
      // One request, the same endpoint and the same payload this screen has
      // always sent. `is_active` is carried through so saving a disabled
      // profile does not quietly switch it back on.
      const response = await api.post<AccessProfile>('v1/access/profiles', {
        profile_id: profile?.profile_id,
        profile_name: trimmed,
        description: description.trim() || undefined,
        permissions: [...chosen],
        is_active: profile?.is_active ?? true,
      })
      onSaved(response.data)
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.status === 409) {
          setNameError(error.message)
          setSaveError({ title: 'That name is taken', message: error.message })
          nameInput.current?.focus()
        } else if (error.status === 422) {
          if (error.details.field === 'profile_name') setNameError(error.message)
          setSaveError({ title: 'Not saved', message: error.message })
        } else if (error.status === 403) {
          setSaveError({ title: 'Beyond what you can grant', message: error.message })
        } else if (error.status === 401) {
          setSaveError({ title: 'Your session has expired', message: 'Sign in again, then create the profile.' })
        } else {
          setSaveError({
            title: 'Could not save the profile',
            message: error.retryable ? `${error.message} Try again in a moment.` : error.message,
          })
        }
      } else {
        setSaveError({ title: 'Could not save the profile', message: 'Something went wrong. Try again.' })
      }
    } finally {
      setSubmitting(false)
    }
  }

  const crumb = isNew ? 'New profile' : profile.profile_name

  return (
    <div className="purchase-access-page">
      <header className="access-page-header">
        <div style={{ minWidth: 0 }}>
          <nav className="access-breadcrumbs" aria-label="Breadcrumb">
            <button type="button" onClick={requestClose}>Access</button>
            <span aria-hidden>›</span>
            <button type="button" onClick={requestClose}>Profiles</button>
            <span aria-hidden>›</span>
            <strong aria-current="page">{crumb}</strong>
          </nav>

          <div className="access-title-row">
            <span className="access-title-icon" aria-hidden>
              <KeyRound size={17} />
            </span>
            <div style={{ minWidth: 0 }}>
              <h1>{isNew ? 'New Profile' : 'Edit Profile'}</h1>
              <p>
                {isNew
                  ? 'Create a custom access profile to control what users can do in Purchase.'
                  : `Change what ${profile.profile_name} can do in Purchase. ${profile.member_count} ${profile.member_count === 1 ? 'person holds' : 'people hold'} it today.`}
              </p>
            </div>
          </div>
        </div>

        <div className="access-header-actions">
          <button type="button" className="access-btn tone-secondary" onClick={requestClose}>
            <ArrowLeft size={15} aria-hidden /> Back
          </button>
          <button type="button" className="access-btn tone-secondary" onClick={requestClose}>
            <X size={15} aria-hidden /> Close
          </button>
        </div>
      </header>

      <div className="access-layout">
        <main className="access-main">
          {saveError && (
            <Alert tone="danger" title={saveError.title} onDismiss={() => setSaveError(null)}>
              {saveError.message}
            </Alert>
          )}

          {note && (
            <Alert tone="info" onDismiss={() => setNote(null)}>
              {note}
            </Alert>
          )}

          {catalogue && !catalogue.is_owner && (
            <Alert tone="warning" title="You can grant what you hold">
              {catalogue.grantable.length} of {allKeys.length} permissions are yours to hand out. The rest are shown
              locked — {catalogue.owner_note.toLowerCase()}
            </Alert>
          )}

          {/* ---------------------------------------------------------- */}
          {/* Step 1 — profile details                                    */}
          {/* ---------------------------------------------------------- */}
          <section className="access-card" aria-labelledby="access-step-1">
            <div className="section-heading">
              <span className="step-badge" aria-hidden>1</span>
              <div>
                <h2 id="access-step-1">Profile details</h2>
                <p>Give a clear name and description so your team understands the purpose of this profile.</p>
              </div>
            </div>

            <div className="profile-details-grid">
              <div className="access-field">
                <label className="access-field-label" htmlFor="access-profile-name">
                  Profile name <span className="required" aria-hidden>*</span>
                </label>
                <span className="access-input-wrap">
                  <span className="access-leading-icon" aria-hidden>
                    <Shapes size={15} />
                  </span>
                  <input
                    id="access-profile-name"
                    ref={nameInput}
                    className="access-input"
                    type="text"
                    value={name}
                    required
                    autoComplete="off"
                    placeholder="e.g. Accounts payable"
                    aria-invalid={nameError !== null}
                    aria-describedby={nameError ? 'access-profile-name-error' : undefined}
                    onChange={(event) => {
                      setName(event.target.value)
                      if (nameError) setNameError(null)
                    }}
                  />
                </span>
                {nameError && (
                  <div className="field-meta">
                    <span id="access-profile-name-error" className="field-error">{nameError}</span>
                  </div>
                )}
              </div>

              <div className="access-field">
                <label className="access-field-label" htmlFor="access-profile-description">Description</label>
                <textarea
                  id="access-profile-description"
                  className="access-textarea"
                  rows={3}
                  value={description}
                  maxLength={DESCRIPTION_LIMIT}
                  placeholder="What this profile is for"
                  onChange={(event) => setDescription(event.target.value)}
                />
                <div className="field-meta">
                  <span>What this profile is for.</span>
                  <span className="num">{description.length} / {DESCRIPTION_LIMIT}</span>
                </div>
              </div>
            </div>
          </section>

          {/* ---------------------------------------------------------- */}
          {/* Step 2 — permissions                                        */}
          {/* ---------------------------------------------------------- */}
          <section className="access-card" aria-labelledby="access-step-2">
            <div className="permissions-toolbar">
              <div className="section-heading">
                <span className="step-badge" aria-hidden>2</span>
                <div>
                  <h2 id="access-step-2">Permissions</h2>
                  <p>
                    Select the modules and actions this profile can access. You can enable all, select by category,
                    or customize.
                  </p>
                </div>
              </div>

              <div className="permission-tools">
                <div className="permission-search">
                  <span className="search-icon" aria-hidden><Search size={15} /></span>
                  <input
                    className="access-input"
                    type="search"
                    value={search}
                    aria-label="Search permissions by label, module or key"
                    placeholder="Search permissions..."
                    onChange={(event) => setSearch(event.target.value)}
                  />
                  {search !== '' && (
                    <button type="button" className="search-clear" aria-label="Clear the search" onClick={() => setSearch('')}>
                      <X size={14} aria-hidden />
                    </button>
                  )}
                </div>

                <button
                  type="button"
                  className="access-btn tone-secondary"
                  onClick={() => setCollapsed(new Set())}
                  disabled={groups.length === 0}
                >
                  Expand all
                </button>
                <button
                  type="button"
                  className="access-btn tone-secondary"
                  onClick={() => setCollapsed(new Set(groups.map((group) => group.id)))}
                  disabled={groups.length === 0}
                >
                  Collapse all
                </button>
              </div>
            </div>

            {catalogueError && (
              <Alert
                tone="danger"
                title="Permissions could not be loaded"
                action={
                  <button type="button" className="access-btn tone-secondary" onClick={onRetryCatalogue}>
                    Retry
                  </button>
                }
              >
                {catalogueError} Until they load, this profile cannot be created — a profile saved against permissions
                nobody could read is a profile nobody can trust.
              </Alert>
            )}

            {!catalogueError && catalogueLoading && permissionsUnknown && <PermissionSkeleton />}

            {!catalogueError && !catalogueLoading && catalogue && groups.length === 0 && (
              <div className="access-empty">
                <strong>No permissions are currently available for Purchase.</strong>
                There is nothing to grant on this company yet.
              </div>
            )}

            {catalogue && groups.length > 0 && visibleGroups.length === 0 && (
              <div className="access-empty">
                <strong>No permissions match “{search.trim()}”.</strong>
                Try a module name, part of a label, or a permission key such as <code>bill.post</code>.
                <div style={{ marginTop: 12 }}>
                  <button type="button" className="access-btn tone-secondary" onClick={() => setSearch('')}>
                    Clear search
                  </button>
                </div>
              </div>
            )}

            {visibleGroups.length > 0 && (
              <div className="permission-groups">
                {visibleGroups.map((group) => (
                  <PermissionGroupCard
                    key={group.id}
                    group={group}
                    chosen={chosen}
                    /* A search result is open whatever the accordion was set to:
                       a match hidden inside a collapsed card is a match the
                       search failed to make. */
                    open={query !== '' || !collapsed.has(group.id)}
                    onToggleOpen={() =>
                      setCollapsed((previous) => {
                        const next = new Set(previous)
                        if (next.has(group.id)) next.delete(group.id)
                        else next.add(group.id)
                        return next
                      })
                    }
                    onTogglePermission={toggle}
                    onSelectAll={(on) => setGroupChosen(group, on)}
                  />
                ))}
              </div>
            )}
          </section>
        </main>

        {/* ------------------------------------------------------------ */}
        {/* Right rail                                                    */}
        {/* ------------------------------------------------------------ */}
        <aside className="access-sidebar" aria-label="Profile guidance">
          <section className="security-callout">
            <div className="security-visual" aria-hidden>
              <ShieldCheck size={30} />
            </div>
            <h3>Create the right access.<br />Keep your data safe.</h3>
            <p>Give only the permissions needed. You can always edit this profile later.</p>
          </section>

          <section className="side-card" aria-labelledby="access-summary-title">
            <div className="side-card-title">
              <Sparkles size={15} aria-hidden />
              <h3 id="access-summary-title">Profile summary</h3>
            </div>
            <div className="summary-grid">
              <div className="summary-stat">
                <strong>{selectedModuleCount}</strong>
                <span>Modules selected</span>
              </div>
              <div className="summary-stat">
                <strong>{chosen.size}</strong>
                <span>Permissions selected</span>
              </div>
              <div className="summary-stat">
                <strong>{profile?.member_count ?? 0}</strong>
                <span>Users assigned</span>
              </div>
            </div>
          </section>

          <section className="side-card" aria-labelledby="access-quick-title">
            <div className="side-card-title">
              <h3 id="access-quick-title">Quick actions</h3>
            </div>

            <button
              type="button"
              className="side-action side-action-primary"
              onClick={() => setDialog('templates')}
              disabled={permissionsUnknown}
            >
              <span><LayoutTemplate size={15} aria-hidden /> Use a template</span>
              <ChevronRight size={15} aria-hidden />
            </button>

            <button
              type="button"
              className="side-action"
              onClick={() => setDialog('copy')}
              disabled={permissionsUnknown}
            >
              <span><Copy size={15} aria-hidden /> Copy from existing profile</span>
              <ChevronRight size={15} aria-hidden />
            </button>

            <button
              type="button"
              className="side-action"
              onClick={() => setDialog('matrix')}
              disabled={permissionsUnknown}
            >
              <span><Grid3x3 size={15} aria-hidden /> View permission matrix</span>
              <ChevronRight size={15} aria-hidden />
            </button>
          </section>

          <section className="side-card" aria-labelledby="access-templates-title">
            <div className="side-card-title">
              <h3 id="access-templates-title">Popular templates</h3>
            </div>

            {TEMPLATES.map((template) => {
              const resolved = resolveTemplate(template)
              return (
                <button
                  key={template.id}
                  type="button"
                  className={template.id === activeTemplateId ? 'template-option is-selected' : 'template-option'}
                  aria-pressed={template.id === activeTemplateId}
                  disabled={permissionsUnknown || resolved.length === 0}
                  title={
                    resolved.length === 0
                      ? 'Nothing in this template is yours to grant'
                      : `${resolved.length} permissions`
                  }
                  onClick={() => applyTemplate(template)}
                >
                  <span className="template-copy">
                    <strong>{template.name}</strong>
                    <small>{template.blurb}</small>
                  </span>
                  {template.id === activeTemplateId ? <Check size={15} aria-hidden /> : <ChevronRight size={15} aria-hidden />}
                </button>
              )
            })}
          </section>

          <section className="best-practice-card">
            <div className="side-card-title">
              <Lightbulb size={14} aria-hidden />
              <h3>Best practice</h3>
            </div>
            <p>Start with a template and fine tune the permissions to match your process.</p>
          </section>
        </aside>
      </div>

      {/* -------------------------------------------------------------- */}
      {/* Sticky action bar                                               */}
      {/* -------------------------------------------------------------- */}
      <footer className="access-action-bar">
        <button type="button" className="access-btn tone-secondary" onClick={requestClose} disabled={submitting}>
          Cancel
        </button>

        <span className="selected-count" aria-live="polite">
          <strong>{chosen.size}</strong> permission{chosen.size === 1 ? '' : 's'} selected
          {selectedModuleCount > 0 && ` · ${selectedModuleCount} module${selectedModuleCount === 1 ? '' : 's'}`}
        </span>

        <button
          type="button"
          className="access-btn tone-primary"
          onClick={() => void submit()}
          disabled={!canSubmit}
          title={blockedReason}
        >
          <ShieldCheck size={15} aria-hidden />
          {submitting ? 'Saving…' : isNew ? 'Create profile' : 'Save profile'}
        </button>
      </footer>

      {/* -------------------------------------------------------------- */}
      {/* Dialogs                                                         */}
      {/* -------------------------------------------------------------- */}
      <AccessDialog
        open={dialog === 'templates'}
        title="Use a template"
        subtitle="A starting point, not a decision. Everything stays editable afterwards."
        onClose={() => setDialog(null)}
      >
        <div className="profile-pick-list">
          {TEMPLATES.map((template) => {
            const resolved = resolveTemplate(template)
            return (
              <button
                key={template.id}
                type="button"
                className="profile-pick"
                disabled={resolved.length === 0}
                onClick={() => applyTemplate(template)}
              >
                <span>
                  <strong>{template.name}</strong>
                  <p>{template.detail}</p>
                </span>
                <span className="profile-pick-count">
                  {resolved.length === 0 ? (
                    <span className="is-disabled-note">Not yours to grant</span>
                  ) : (
                    `${resolved.length} permissions`
                  )}
                </span>
              </button>
            )
          })}
        </div>
      </AccessDialog>

      <AccessDialog
        open={dialog === 'copy'}
        title="Copy from existing profile"
        subtitle="Copies the permissions into this form only. The profile you pick is not changed."
        onClose={() => setDialog(null)}
      >
        <CopyProfileList
          profiles={profiles.filter((candidate) => candidate.profile_id !== profile?.profile_id)}
          loading={profilesLoading}
          grantable={grantable}
          onPick={copyFrom}
        />
      </AccessDialog>

      <AccessDialog
        open={dialog === 'matrix'}
        title="Permission matrix"
        subtitle="Every permission this product has, and what this profile grants right now."
        onClose={() => setDialog(null)}
      >
        <PermissionMatrix groups={groups} chosen={chosen} />
      </AccessDialog>

      <AccessDialog
        open={dialog === 'discard'}
        narrow
        title="Discard this profile?"
        subtitle="Nothing has been saved yet, and leaving now loses what you have chosen."
        onClose={() => setDialog(null)}
        footer={
          <>
            <button type="button" className="access-btn tone-secondary" onClick={() => setDialog(null)}>
              Keep editing
            </button>
            <button type="button" className="access-btn tone-primary" onClick={onClose}>
              Discard
            </button>
          </>
        }
      >
        <p style={{ margin: 0, fontSize: 13, color: 'var(--access-text-secondary)' }}>
          {chosen.size} permission{chosen.size === 1 ? '' : 's'} selected
          {name.trim() !== '' && ` for “${name.trim()}”`}.
        </p>
      </AccessDialog>
    </div>
  )
}

// ---------------------------------------------------------------------------
// One module
// ---------------------------------------------------------------------------

function PermissionGroupCard({
  group,
  chosen,
  open,
  onToggleOpen,
  onTogglePermission,
  onSelectAll,
}: {
  group: PresentedGroup
  chosen: Set<string>
  open: boolean
  onToggleOpen: () => void
  onTogglePermission: (key: string) => void
  onSelectAll: (on: boolean) => void
}) {
  const panelId = `access-group-${group.id}`
  const selectable = group.permissions.filter((permission) => permission.grantable)
  const lockedCount = group.permissions.length - selectable.length
  const selectedHere = selectable.filter((permission) => chosen.has(permission.key)).length

  const allSelected = selectable.length > 0 && selectedHere === selectable.length
  const someSelected = selectedHere > 0 && !allSelected
  const anyChosen = group.permissions.some((permission) => chosen.has(permission.key))
  const Icon = group.Icon

  const classes = ['permission-group']
  if (open) classes.push('is-open')
  if (anyChosen) classes.push('is-chosen')

  return (
    <article className={classes.join(' ')}>
      <header className="permission-group-header">
        <div className="permission-group-info">
          <span className={`permission-group-icon ${group.tint}`} aria-hidden>
            <Icon size={17} />
          </span>
          <div style={{ minWidth: 0 }}>
            <h3>{group.title}</h3>
            <p>{group.description}</p>
          </div>
        </div>

        <div className="permission-group-actions">
          {lockedCount > 0 && (
            <span className="group-locked" title="You do not hold these, so you cannot grant them">
              <Lock size={11} aria-hidden /> {lockedCount} locked
            </span>
          )}

          <label className={selectable.length === 0 ? 'select-all-control is-locked' : 'select-all-control'}>
            <TriState
              checked={allSelected}
              indeterminate={someSelected}
              disabled={selectable.length === 0}
              onChange={(next) => onSelectAll(next)}
              label={`Select every permission in ${group.title}`}
            />
            <span>Select all ({selectable.length})</span>
          </label>

          <button
            type="button"
            className="collapse-trigger"
            aria-expanded={open}
            aria-controls={panelId}
            aria-label={`${open ? 'Collapse' : 'Expand'} ${group.title}`}
            onClick={onToggleOpen}
          >
            <ChevronDown size={17} aria-hidden />
          </button>
        </div>
      </header>

      <div className="permission-grid" id={panelId} hidden={!open}>
        {group.permissions.map((permission) => (
          <label
            key={permission.key}
            className={permission.grantable ? 'permission-option' : 'permission-option is-locked'}
            title={
              permission.grantable
                ? permission.key
                : `${permission.key} — you do not hold this, so you cannot grant it`
            }
          >
            <input
              type="checkbox"
              checked={chosen.has(permission.key)}
              disabled={!permission.grantable}
              onChange={() => onTogglePermission(permission.key)}
            />
            <span className="permission-copy">
              <span className="permission-label">{permission.label}</span>
              <code>
                {!permission.grantable && <Lock size={10} aria-hidden style={{ marginRight: 3, verticalAlign: '-1px' }} />}
                {permission.key}
              </code>
            </span>
          </label>
        ))}
      </div>
    </article>
  )
}

/** A checkbox with the third state the DOM only exposes as a property. */
function TriState({
  checked,
  indeterminate,
  disabled,
  onChange,
  label,
}: {
  checked: boolean
  indeterminate: boolean
  disabled?: boolean
  onChange: (next: boolean) => void
  label: string
}) {
  const box = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (box.current) box.current.indeterminate = indeterminate
  }, [indeterminate])

  return (
    <input
      ref={box}
      type="checkbox"
      checked={checked}
      disabled={disabled}
      aria-label={label}
      onChange={(event) => onChange(event.target.checked)}
    />
  )
}

// ---------------------------------------------------------------------------
// Loading, notices
// ---------------------------------------------------------------------------

function PermissionSkeleton() {
  return (
    <div className="permission-skeleton" role="status" aria-label="Loading permissions">
      {[0, 1, 2].map((row) => (
        <div key={row} className="skeleton-group">
          <div className="skeleton-bar" style={{ width: '32%' }} />
          <div className="skeleton-row">
            {[0, 1, 2, 3].map((cell) => (
              <div key={cell} className="skeleton-bar" />
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

function Alert({
  tone,
  title,
  children,
  action,
  onDismiss,
}: {
  tone: 'danger' | 'warning' | 'info'
  title?: string
  children: ReactNode
  action?: ReactNode
  onDismiss?: () => void
}) {
  return (
    <div className={`access-alert tone-${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <div style={{ minWidth: 0 }}>
        {title && <strong>{title}</strong>}
        <span>{children}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
        {action}
        {onDismiss && (
          <button
            type="button"
            aria-label="Dismiss"
            onClick={onDismiss}
            style={{ border: 0, background: 'none', color: 'inherit', padding: 2, lineHeight: 1 }}
          >
            <X size={15} aria-hidden />
          </button>
        )}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Dialog
//
// Escape closes, Tab is trapped, focus moves in on open and back to whatever
// opened it on close. A dialog that traps nothing is a dialog a keyboard user
// falls out of the back of, into a page they cannot see.
// ---------------------------------------------------------------------------

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

function AccessDialog({
  open,
  title,
  subtitle,
  narrow = false,
  footer,
  onClose,
  children,
}: {
  open: boolean
  title: string
  subtitle?: string
  narrow?: boolean
  footer?: ReactNode
  onClose: () => void
  children: ReactNode
}) {
  const panel = useRef<HTMLDivElement>(null)
  const returnFocusTo = useRef<HTMLElement | null>(null)
  const titleId = useId()

  useEffect(() => {
    if (!open) return

    returnFocusTo.current = document.activeElement as HTMLElement | null
    panel.current?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
        return
      }
      if (event.key !== 'Tab') return

      const items = Array.from(panel.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []).filter(
        (element) => element.offsetParent !== null,
      )
      if (items.length === 0) {
        event.preventDefault()
        panel.current?.focus()
        return
      }

      const first = items[0]
      const last = items[items.length - 1]
      const active = document.activeElement

      if (event.shiftKey && (active === first || active === panel.current)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && active === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      document.body.style.overflow = previousOverflow
      returnFocusTo.current?.focus?.()
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div
      className="access-dialog-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={panel}
        className={narrow ? 'access-dialog is-narrow' : 'access-dialog'}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
      >
        <header className="access-dialog__header">
          <div style={{ minWidth: 0 }}>
            <h2 id={titleId}>{title}</h2>
            {subtitle && <p>{subtitle}</p>}
          </div>
          <button type="button" className="access-dialog__close" aria-label={`Close ${title}`} onClick={onClose}>
            <X size={16} aria-hidden />
          </button>
        </header>

        <div className="access-dialog__body">{children}</div>
        {footer && <div className="access-dialog__footer">{footer}</div>}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Copy from an existing profile
// ---------------------------------------------------------------------------

function CopyProfileList({
  profiles,
  loading,
  grantable,
  onPick,
}: {
  profiles: AccessProfile[]
  loading: boolean
  grantable: Set<string>
  onPick: (source: AccessProfile) => void
}) {
  const [filter, setFilter] = useState('')
  const query = filter.trim().toLowerCase()

  const matches = profiles.filter(
    (candidate) =>
      query === '' ||
      candidate.profile_name.toLowerCase().includes(query) ||
      (candidate.description ?? '').toLowerCase().includes(query),
  )

  if (loading) {
    return <div className="access-empty">Loading profiles…</div>
  }
  if (profiles.length === 0) {
    return (
      <div className="access-empty">
        <strong>There is nothing to copy yet.</strong>
        This company has no other permission profile.
      </div>
    )
  }

  return (
    <>
      <div className="permission-search" style={{ width: '100%', marginBottom: 12 }}>
        <span className="search-icon" aria-hidden><Search size={15} /></span>
        <input
          className="access-input"
          type="search"
          value={filter}
          aria-label="Search profiles"
          placeholder="Search profiles..."
          onChange={(event) => setFilter(event.target.value)}
        />
      </div>

      {matches.length === 0 ? (
        <div className="access-empty">No profile matches “{filter.trim()}”.</div>
      ) : (
        <div className="profile-pick-list">
          {matches.map((candidate) => {
            const copyable = candidate.permissions.filter((key) => grantable.has(key)).length
            const blocked = candidate.permissions.length - copyable
            return (
              <button key={candidate.profile_id} type="button" className="profile-pick" onClick={() => onPick(candidate)}>
                <span style={{ minWidth: 0 }}>
                  <strong>
                    {candidate.profile_name}
                    {!candidate.is_active && <span className="profile-pick-count"> · disabled</span>}
                  </strong>
                  <p>{candidate.description ?? 'No description.'}</p>
                </span>
                <span className="profile-pick-count">
                  {copyable} of {candidate.permission_count}
                  {blocked > 0 && <span className="is-disabled-note"> · {blocked} locked</span>}
                </span>
              </button>
            )
          })}
        </div>
      )}
    </>
  )
}

// ---------------------------------------------------------------------------
// Permission matrix — read only
// ---------------------------------------------------------------------------

function PermissionMatrix({ groups, chosen }: { groups: PresentedGroup[]; chosen: Set<string> }) {
  const [filter, setFilter] = useState('')
  const query = filter.trim().toLowerCase()

  const rows = groups.flatMap((group) =>
    group.permissions
      .filter(
        (permission) =>
          query === '' ||
          group.title.toLowerCase().includes(query) ||
          permission.label.toLowerCase().includes(query) ||
          permission.key.toLowerCase().includes(query),
      )
      .map((permission) => ({ group: group.title, ...permission })),
  )

  return (
    <>
      <div className="permission-search" style={{ width: '100%', marginBottom: 12 }}>
        <span className="search-icon" aria-hidden><Search size={15} /></span>
        <input
          className="access-input"
          type="search"
          value={filter}
          aria-label="Search the permission matrix"
          placeholder="Search permissions..."
          onChange={(event) => setFilter(event.target.value)}
        />
      </div>

      {rows.length === 0 ? (
        <div className="access-empty">No permissions match “{filter.trim()}”.</div>
      ) : (
        <table className="access-matrix">
          <thead>
            <tr>
              <th scope="col">Module</th>
              <th scope="col">Permission</th>
              <th scope="col">Key</th>
              <th scope="col">Selected</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.key}>
                <td className="matrix-module">{row.group}</td>
                <td>{row.label}</td>
                <td><code>{row.key}</code></td>
                <td>
                  {chosen.has(row.key) ? (
                    <span className="matrix-mark is-on"><Check size={12} aria-hidden /> Granted</span>
                  ) : row.grantable ? (
                    <span className="matrix-mark">Not granted</span>
                  ) : (
                    <span className="matrix-mark is-locked"><Lock size={11} aria-hidden /> Locked</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  )
}
