/**
 * Settings → New Profile.
 *
 * The company's purchase profile, in one workspace: what it is called, how its
 * documents are numbered, what needs approving, how strictly a supplier's bill
 * must match the order and the receipt, and who may do any of it.
 *
 * ONE ROW, NAMED. `purchase_settings` is keyed by company and has always been
 * the profile every purchase document in this product is written against —
 * NumberSeries, RequisitionService, BillService, PurchaseOrderService and the
 * insight rules all read it and all expect one answer. This screen gives that
 * row an identity and a status; it does not turn it into a set of profiles
 * those five callers would then have to choose between.
 *
 * This component holds the state and talks to the API. Every card below is
 * presentational and takes what it needs — which is what keeps a screen with
 * five sections, four dialogs and a dirty-state guard readable.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowLeft,
  Building2,
  CheckCircle2,
  Eye,
  Info,
  RefreshCw,
  Save,
} from 'lucide-react'
import { ApiError, api } from '../../services/api'
import {
  deleteMatchPolicy,
  fetchMatchPolicies,
  fetchProfile,
  saveMatchPolicy,
  saveProfile,
  type MatchPolicyPayload,
} from '../../services/purchaseProfile'
import type { ProfileTypeOption } from '../../services/types'
import { useApi } from '../../hooks/useApi'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { usePurchases } from '../../context/PurchasesContext'
import { ApprovalControlsCard } from './profile/ApprovalControlsCard'
import { CopyProfileDialog } from './profile/CopyProfileDialog'
import { DocumentNumberingCard } from './profile/DocumentNumberingCard'
import { ImportProfileDialog } from './profile/ImportProfileDialog'
import { MatchToleranceCard } from './profile/MatchToleranceCard'
import { ProfileInformationCard } from './profile/ProfileInformationCard'
import { ProfilePermissionsCard, type PermissionCatalogue } from './profile/ProfilePermissionsCard'
import { ProfilePreviewDialog } from './profile/ProfilePreviewDialog'
import { ProfileGuideCard, ProfileQuickActions, ProfileSetupReview, ProfileStatusCard } from './profile/ProfileRail'
import { ProfileSetupProgress } from './profile/ProfileSetupProgress'
import { ProfileSkeleton } from './profile/ProfileSkeleton'
import { ResetProfileDialog } from './profile/ResetProfileDialog'
import { PpButton, PpDialog, PpStrip } from './profile/ProfileUi'
import { buildExport, downloadJson, exportFilename, type ExportedPolicy } from './profile/profileFile'
import {
  DEFAULT_PROFILE,
  FIELD_SECTIONS,
  fieldForColumn,
  fromSettings,
  sameProfile,
  setupReview,
  toPayload,
  validateProfile,
  type ProfileErrors,
  type ProfileForm,
} from './profile/profileModel'
import { SECTION_IDS } from './profile/sections'
import { useSectionSpy } from './profile/useSectionSpy'
import './profile/purchase-profile.css'

type Banner = { tone: 'success' | 'danger' | 'info'; text: string } | null
type Dialog = 'preview' | 'copy' | 'import' | 'reset' | null

export default function PurchaseProfilePage() {
  const { scope, session, can } = usePurchases()
  const navigate = useNavigate()
  const location = useLocation()

  const [form, setForm] = useState<ProfileForm>(DEFAULT_PROFILE)
  /** The profile as last read from, or written to, the server. */
  const [baseline, setBaseline] = useState<ProfileForm>(DEFAULT_PROFILE)
  const [errors, setErrors] = useState<ProfileErrors>({})
  const [banner, setBanner] = useState<Banner>(null)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [saving, setSaving] = useState(false)
  const [policyBusy, setPolicyBusy] = useState(false)

  const deps = [scope?.cmp_id, scope?.fy_id]
  const enabled = Boolean(scope)

  const profile = useApi((signal) => fetchProfile(signal), deps, enabled)
  const catalogue = useApi(
    (signal) => api.one<PermissionCatalogue>('v1/permissions', undefined, signal),
    deps,
    enabled,
  )
  const policies = useApi((signal) => fetchMatchPolicies(signal), deps, enabled && can('match.view'))

  const editable = can('settings.manage')
  // `useApi` returns a fresh object each render but a stable `reload`, so the
  // callbacks below depend on the callback rather than on the state around it.
  const reloadPolicies = policies.reload
  const policyList = useMemo(() => policies.data?.data ?? [], [policies.data])
  const types: ProfileTypeOption[] = useMemo(() => profile.data?.meta?.profile_types ?? [], [profile.data])
  const fyId = profile.data?.meta?.number_format?.fy_id ?? scope?.fy_id ?? null

  // The server's row is the truth this form starts from and returns to.
  useEffect(() => {
    if (!profile.data?.data) return
    const next = fromSettings(profile.data.data)
    setForm(next)
    setBaseline(next)
    setErrors({})
  }, [profile.data])

  const dirty = useMemo(() => !sameProfile(form, baseline), [form, baseline])
  const { pendingHref, cancelLeave } = useUnsavedChangesGuard(dirty)

  const { active, goTo, setActive } = useSectionSpy(SECTION_IDS, !profile.loading && profile.error === null)

  // The sidebar links straight to a section (/settings/new-profile#numbering),
  // so a deep link has to land on it rather than at the top of the page.
  const ready = !profile.loading && profile.error === null
  useEffect(() => {
    if (!ready) return
    const id = location.hash.replace('#', '')
    if (id && SECTION_IDS.includes(id)) {
      // One frame, so the cards exist to scroll to.
      const timer = window.setTimeout(() => goTo(id), 60)
      return () => window.clearTimeout(timer)
    }
  }, [location.hash, ready, goTo])

  const change = useCallback((patch: Partial<ProfileForm>) => {
    setForm((current) => ({ ...current, ...patch }))
    // Clearing an error the moment the field it belongs to is touched: leaving
    // it up until the next save makes a corrected field look still wrong.
    setErrors((current) => {
      const keys = Object.keys(patch)
      if (!keys.some((key) => key in current)) return current
      const next = { ...current }
      for (const key of keys) delete next[key]
      return next
    })
  }, [])

  const focusField = useCallback(
    (name: string) => {
      const section = FIELD_SECTIONS[name]
      if (section) setActive(section)
      const element = document.getElementById(name)
      if (!element) return
      element.scrollIntoView({ behavior: 'smooth', block: 'center' })
      element.focus({ preventScroll: true })
    },
    [setActive],
  )

  // -------------------------------------------------------------------- save

  const saveRef = useRef(false)

  const save = useCallback(async () => {
    // Two clicks on Save is two PUTs, and the second one races the first.
    if (saveRef.current) return
    const found = validateProfile(form)
    setErrors(found)
    const firstInvalid = Object.keys(found)[0]
    if (firstInvalid) {
      setBanner({ tone: 'danger', text: 'Some fields need attention before this profile can be saved.' })
      focusField(firstInvalid)
      return
    }

    saveRef.current = true
    setSaving(true)
    setBanner(null)
    try {
      const response = await saveProfile(toPayload(form))
      const next = fromSettings(response.data)
      setForm(next)
      setBaseline(next)
      setDialog(null)
      setBanner({ tone: 'success', text: 'Purchase profile saved.' })
    } catch (error) {
      if (error instanceof ApiError) {
        // The server names the column it refused. Highlighting it beats a
        // banner the reader has to match to a field by eye.
        const field = fieldForColumn(error.details.field)
        if (field) {
          setErrors((current) => ({ ...current, [field]: error.message }))
          focusField(field)
        }
        setBanner({ tone: 'danger', text: error.message })
      } else {
        setBanner({ tone: 'danger', text: 'That profile could not be saved. Please try again.' })
      }
    } finally {
      saveRef.current = false
      setSaving(false)
    }
  }, [form, focusField])

  // ------------------------------------------------------------- policies

  const onSavePolicy = useCallback(
    async (payload: MatchPolicyPayload) => {
      setPolicyBusy(true)
      setBanner(null)
      try {
        await saveMatchPolicy(payload)
        reloadPolicies()
        setBanner({ tone: 'success', text: `Tolerance policy “${payload.policy_name}” saved.` })
        return true
      } catch (error) {
        setBanner({
          tone: 'danger',
          text: error instanceof ApiError ? error.message : 'That tolerance policy could not be saved.',
        })
        return false
      } finally {
        setPolicyBusy(false)
      }
    },
    [reloadPolicies],
  )

  const onDeletePolicy = useCallback(
    async (policyId: number) => {
      setPolicyBusy(true)
      setBanner(null)
      try {
        await deleteMatchPolicy(policyId)
        reloadPolicies()
        setBanner({ tone: 'success', text: 'Tolerance policy deleted.' })
        return true
      } catch (error) {
        setBanner({
          tone: 'danger',
          text: error instanceof ApiError ? error.message : 'That tolerance policy could not be deleted.',
        })
        return false
      } finally {
        setPolicyBusy(false)
      }
    },
    [reloadPolicies],
  )

  // -------------------------------------------------------- quick actions

  const onExport = useCallback(() => {
    downloadJson(exportFilename(form), buildExport(form, policyList))
    setBanner({ tone: 'info', text: 'Profile exported. The file carries no company, user or session data.' })
  }, [form, policyList])

  const onImport = useCallback(
    async (imported: ProfileForm, importedPolicies: ExportedPolicy[]) => {
      setForm(imported)
      setErrors({})
      setDialog(null)

      if (importedPolicies.length === 0) {
        setBanner({ tone: 'info', text: 'Configuration imported into the form. Save Profile to apply it.' })
        return
      }

      setPolicyBusy(true)
      let created = 0
      const failed: string[] = []
      for (const policy of importedPolicies) {
        try {
          await saveMatchPolicy(policy)
          created += 1
        } catch {
          failed.push(policy.policy_name)
        }
      }
      setPolicyBusy(false)
      reloadPolicies()

      setBanner(
        failed.length === 0
          ? {
              tone: 'info',
              text: `Configuration imported into the form, and ${created} tolerance ${created === 1 ? 'policy' : 'policies'} saved. Save Profile to apply the rest.`,
            }
          : {
              tone: 'danger',
              text: `Configuration imported, but these tolerance policies could not be saved: ${failed.join(', ')}.`,
            },
      )
    },
    [reloadPolicies],
  )

  const onCopy = useCallback(
    (source: Pick<ProfileForm, 'type' | 'description' | 'numbering' | 'approvals'>, label: string) => {
      setForm((current) => ({ ...current, ...source }))
      setErrors({})
      setDialog(null)
      setBanner({
        tone: 'info',
        text: `Settings copied from ${label}. Your profile code and name are unchanged — Save Profile to apply.`,
      })
    },
    [],
  )

  const onReset = useCallback(() => {
    setForm({ ...DEFAULT_PROFILE })
    setErrors({})
    setDialog(null)
    setBanner({ tone: 'info', text: 'The form is back to its defaults. Nothing is saved until you press Save Profile.' })
  }, [])

  // ------------------------------------------------------------------ render

  if (profile.loading && !profile.data) {
    return (
      <div className="purchase-profile-page">
        <ProfileSkeleton />
      </div>
    )
  }

  if (profile.error && !profile.data) {
    return (
      <div className="purchase-profile-page">
        <div className="pp-container">
          <div className="pp-card" style={{ padding: 32, textAlign: 'center' }}>
            <div className="pp-empty__icon" style={{ background: 'var(--pp-red-soft)', color: 'var(--pp-red)' }}>
              <AlertTriangle size={18} aria-hidden />
            </div>
            <h1 style={{ margin: '0 0 6px', fontSize: 18 }}>Unable to load purchase profile settings.</h1>
            <p style={{ margin: '0 0 18px', color: 'var(--pp-text-muted)', fontSize: 13 }}>{profile.error}</p>
            <PpButton tone="primary" onClick={() => profile.reload()}>
              <RefreshCw size={14} aria-hidden /> Retry
            </PpButton>
          </div>
        </div>
      </div>
    )
  }

  const review = setupReview(form, policyList, session?.is_owner === true || session?.access_resolved === true)
  const permissionSummary = session?.is_owner
    ? 'You own this company, so you hold all Purchases permissions.'
    : `${catalogue.data?.data?.granted?.length ?? 0} permissions are granted to you in this company.`

  return (
    <div className="purchase-profile-page">
      <div className="pp-container">
        <header className="pp-heading">
          <div style={{ minWidth: 0 }}>
            <nav className="pp-breadcrumb" aria-label="Breadcrumb">
              <button type="button" className="pp-icon-button" aria-label="Back to Settings" onClick={() => navigate('/settings')}>
                <ArrowLeft size={15} aria-hidden />
              </button>
              <Link to="/settings">Settings</Link>
              <span aria-hidden>›</span>
              <strong aria-current="page">New Profile</strong>
            </nav>

            <div className="pp-title-row">
              <span className="pp-title-icon" aria-hidden>
                <Building2 size={22} />
              </span>
              <div style={{ minWidth: 0 }}>
                <h1>New Profile</h1>
                <p>
                  Set up a new purchase profile for your company. Configure document numbers, approvals and matching
                  rules.
                </p>
              </div>
            </div>
          </div>

          <div className="pp-page-actions">
            <PpButton onClick={() => setDialog('preview')}>
              <Eye size={15} aria-hidden /> Preview Profile
            </PpButton>
            {editable && (
              <PpButton tone="primary" disabled={saving} onClick={() => void save()}>
                <Save size={15} aria-hidden /> {saving ? 'Saving…' : 'Save Profile'}
              </PpButton>
            )}
          </div>
        </header>

        {/* One live region for everything that happens on this screen, rather
            than a stack of banners nobody dismisses. */}
        <div aria-live="polite">
          {banner && (
            <div style={{ marginBottom: 14 }}>
              <PpStrip
                tone={banner.tone === 'success' ? 'success' : banner.tone === 'danger' ? 'danger' : 'info'}
                icon={
                  banner.tone === 'success' ? (
                    <CheckCircle2 size={15} aria-hidden />
                  ) : banner.tone === 'danger' ? (
                    <AlertTriangle size={15} aria-hidden />
                  ) : (
                    <Info size={15} aria-hidden />
                  )
                }
              >
                <span style={{ display: 'flex', alignItems: 'center', gap: 12, width: '100%' }}>
                  <span style={{ flex: 1 }}>{banner.text}</span>
                  <button
                    type="button"
                    onClick={() => setBanner(null)}
                    aria-label="Dismiss this message"
                    style={{ border: 0, background: 'none', color: 'inherit', fontSize: 16, lineHeight: 1, padding: '0 2px' }}
                  >
                    ×
                  </button>
                </span>
              </PpStrip>
            </div>
          )}
        </div>

        {!editable && (
          <div style={{ marginBottom: 14 }}>
            <PpStrip tone="muted" icon={<Info size={15} aria-hidden />}>
              You can see this profile but not change it. Changing Purchases settings needs the{' '}
              <code>settings.manage</code> permission.
            </PpStrip>
          </div>
        )}

        <ProfileSetupProgress active={active} onSelect={goTo} />

        <div className="pp-layout">
          <main className="pp-main">
            <ProfileInformationCard
              form={form}
              errors={errors}
              types={types}
              editable={editable}
              onChange={change}
            />

            <DocumentNumberingCard
              form={form}
              errors={errors}
              editable={editable}
              fyId={fyId}
              onChange={(numbering) => change({ numbering })}
            />

            <ApprovalControlsCard
              form={form}
              errors={errors}
              editable={editable}
              onChange={(approvals) => change({ approvals })}
            />

            <MatchToleranceCard
              policies={policyList}
              loading={policies.loading}
              error={policies.error}
              canView={can('match.view')}
              editable={editable}
              busy={policyBusy}
              onSave={onSavePolicy}
              onDelete={onDeletePolicy}
            />

            <ProfilePermissionsCard
              catalogue={catalogue.data?.data ?? null}
              loading={catalogue.loading}
              error={catalogue.error}
              isOwner={session?.is_owner === true}
              accessResolved={session?.access_resolved !== false}
              canManageAccess={can('access.manage')}
            />
          </main>

          <aside className="pp-rail" aria-label="Profile context and actions">
            <ProfileGuideCard />
            <ProfileStatusCard
              active={form.active}
              editable={editable}
              onChange={(next) => change({ active: next })}
            />
            <ProfileQuickActions
              editable={editable}
              onCopy={() => setDialog('copy')}
              onImport={() => setDialog('import')}
              onExport={onExport}
              onReset={() => setDialog('reset')}
            />
            <ProfileSetupReview
              sections={review.sections}
              configured={review.configured}
              total={review.total}
              percent={review.percent}
              onSelect={goTo}
            />
          </aside>
        </div>
      </div>

      <ProfilePreviewDialog
        open={dialog === 'preview'}
        form={form}
        policies={policyList}
        types={types}
        fyId={fyId}
        permissionSummary={permissionSummary}
        saving={saving}
        canSave={editable}
        onClose={() => setDialog(null)}
        onSave={() => void save()}
      />

      <CopyProfileDialog
        open={dialog === 'copy'}
        currentCmpId={scope?.cmp_id ?? null}
        onClose={() => setDialog(null)}
        onApply={onCopy}
      />

      <ImportProfileDialog
        open={dialog === 'import'}
        knownTypes={types.map((type) => type.value)}
        canWritePolicies={editable}
        onClose={() => setDialog(null)}
        onApply={(imported, importedPolicies) => void onImport(imported, importedPolicies)}
      />

      <ResetProfileDialog open={dialog === 'reset'} onClose={() => setDialog(null)} onConfirm={onReset} />

      <PpDialog
        open={pendingHref !== null}
        size="narrow"
        title="Leave without saving?"
        subtitle="This profile has changes that have not been saved."
        onClose={cancelLeave}
        footer={
          <>
            <PpButton onClick={cancelLeave}>Stay on this page</PpButton>
            <PpButton
              tone="danger"
              onClick={() => {
                const href = pendingHref
                cancelLeave()
                // Back to the saved profile first, so the guard is off before
                // the router moves and cannot re-arm on the way out.
                setForm(baseline)
                if (href) navigate(href)
              }}
            >
              Discard changes
            </PpButton>
          </>
        }
      >
        <p style={{ margin: 0 }}>
          Your edits to this purchase profile will be lost. Tolerance policies are saved separately and are not
          affected.
        </p>
      </PpDialog>
    </div>
  )
}
