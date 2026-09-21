/**
 * Administration → Access.
 *
 * Who may do what in Purchases. The permission tables have existed since the
 * first migration and the catalogue with them; what this screen adds is a way
 * to read and write both that an administrator can actually work in.
 *
 * TWO RULES DO THE REAL WORK, and both are enforced by the API rather than
 * here. They are SHOWN here so they read as design rather than as a rejection
 * somebody hits by surprise:
 *
 *   - You can only grant permissions you hold yourself. A non-owner sees the
 *     rest of the catalogue greyed out with the reason on it, and the actions
 *     that would rewrite a profile beyond their reach are unavailable rather
 *     than failing after the click.
 *   - You cannot remove your own last grant of access management. The server
 *     refuses it; the confirmation says so before you get there.
 *
 * NOTHING ON THIS PAGE IS SECURITY. Every hidden button is a courtesy. The
 * checks that matter run in AccessController, one curl away from this bundle.
 */

import { useCallback, useState } from 'react'
import { Plus, ShieldAlert, Sparkles } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import { usePurchases } from '../../context/PurchasesContext'
import { useUrlFilter } from '../../hooks/useUrlFilter'
import { AccessGuide } from './AccessGuide'
import { AccessHero } from './AccessHero'
import { AccessMetrics } from './AccessMetrics'
import { AccessTabs, TAB_PANEL_ID } from './AccessTabs'
import { ActivityPanel } from './ActivityPanel'
import { AiAssistantCard } from './AiAssistantCard'
import { ConfirmDialog } from './ConfirmDialog'
import { GrantAccessForm, type GrantBody, type GrantResult } from './GrantAccessForm'
import { PeoplePanel } from './PeoplePanel'
import { PermissionsDialog } from './PermissionsDialog'
import { ProfileEditorDialog, type EditorMode, type ProfileBody } from './ProfileEditorDialog'
import { ProfilesPanel } from './ProfilesPanel'
import { RequestsPanel } from './RequestsPanel'
import { StarterProfilesDialog } from './StarterProfilesDialog'
import { EmptyState, InlineNotice, Toasts, useToasts } from './ui'
import { useAccessData } from './useAccessData'
import type { AccessTabId, Member, Profile } from './types'
import './access.css'

const TABS: AccessTabId[] = ['profiles', 'people', 'requests', 'activity']

type Dialog =
  | { kind: 'starters' }
  | { kind: 'editor'; mode: EditorMode; profile: Profile | null }
  | { kind: 'permissions'; title: string; description?: string; held: string[] }
  | { kind: 'delete-profile'; profile: Profile }
  | { kind: 'revoke'; member: Member; assignmentId: number; profileName: string }
  | null

export default function AccessPage() {
  const { can } = usePurchases()
  const [tabParam, setTabParam] = useUrlFilter('tab', 'profiles')
  const activeTab: AccessTabId = TABS.includes(tabParam as AccessTabId) ? (tabParam as AccessTabId) : 'profiles'

  const data = useAccessData(activeTab)
  const { toasts, push, dismiss } = useToasts()

  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [dialogError, setDialogError] = useState<string | null>(null)
  const [prefill, setPrefill] = useState<{ uuid: string; token: number } | null>(null)

  const closeDialog = useCallback(() => {
    setDialog(null)
    setDialogError(null)
  }, [])

  /**
   * Run one write, and hand back the server's refusal rather than a summary of
   * it. Escalation and self-lockout both come back with the reason spelled out,
   * and those sentences are better than anything this file could write.
   */
  const act = useCallback(
    async (run: () => Promise<unknown>): Promise<GrantResult | null> => {
      setBusy(true)
      try {
        await run()
        data.reload()
        return null
      } catch (error) {
        if (error instanceof ApiError) {
          const field = error.details.field
          return { message: error.message, field: typeof field === 'string' ? field : undefined }
        }
        return { message: 'That change could not be saved. Check your connection and try again.' }
      } finally {
        setBusy(false)
      }
    },
    [data],
  )

  // -------------------------------------------------------------------------
  // Writes
  // -------------------------------------------------------------------------

  const createStarters = async () => {
    const failure = await act(async () => {
      const response = await api.post<{ created: { profile_name: string }[]; skipped: string[] }>(
        'v1/access/profiles/bootstrap',
      )
      const created = response.data.created.length
      push(
        'success',
        created === 0 ? 'Nothing to create' : `${created} starter profile${created === 1 ? '' : 's'} created`,
        created === 0
          ? 'Every starter profile already exists in this company.'
          : response.data.created.map((profile) => profile.profile_name).join(', '),
      )
    })

    if (failure) push('danger', 'Starter profiles not created', failure.message)
    else closeDialog()
  }

  const saveProfile = async (body: ProfileBody) => {
    const failure = await act(() => api.post('v1/access/profiles', body))
    if (failure) {
      setDialogError(failure.message)
      return
    }
    push('success', body.profile_id ? 'Profile saved' : 'Profile created', body.profile_name)
    closeDialog()
  }

  const deleteProfile = async (profile: Profile) => {
    const failure = await act(() => api.del(`v1/access/profiles/${profile.profile_id}`))
    if (failure) {
      setDialogError(failure.message)
      return
    }
    push('success', 'Profile deleted', profile.profile_name)
    closeDialog()
  }

  const toggleActive = async (profile: Profile) => {
    const failure = await act(() =>
      api.post('v1/access/profiles', {
        profile_id: profile.profile_id,
        profile_name: profile.profile_name,
        description: profile.description ?? undefined,
        permissions: profile.permissions,
        is_active: !profile.is_active,
      }),
    )

    if (failure) push('danger', 'Not saved', failure.message)
    else push('success', profile.is_active ? 'Profile deactivated' : 'Profile reactivated', profile.profile_name)
  }

  const grant = async (body: GrantBody): Promise<GrantResult | null> => {
    const failure = await act(() => api.post('v1/access/members', body))
    if (failure) return failure

    const profile = data.profileList.find((entry) => entry.profile_id === body.profile_id)
    push('success', 'Access granted', `${body.member_label ?? 'They'} now hold ${profile?.profile_name ?? 'the profile'}.`)
    return null
  }

  const revoke = async (assignmentId: number, profileName: string) => {
    const failure = await act(() => api.del(`v1/access/members/${assignmentId}`))
    if (failure) {
      setDialogError(failure.message)
      return
    }
    push('success', 'Access revoked', `${profileName} was removed.`)
    closeDialog()
  }

  // -------------------------------------------------------------------------

  const sendToGrantForm = (uuid: string) => {
    setTabParam('profiles')
    setPrefill({ uuid, token: Date.now() })
  }

  // The whole screen is behind one permission, checked by every route it calls.
  if (!can('access.manage')) {
    return (
      <div className="access-workspace">
        <section className="access-panel" style={{ maxWidth: 720, margin: '40px auto' }}>
          <EmptyState
            icon={<ShieldAlert size={26} />}
            title="Managing access is not available to you"
            tone="warn"
            note={
              <>
                Permissions in Purchases are separate from Aicountly Manage: being a member of the company
                does not by itself grant anything here.
              </>
            }
          >
            Managing who can do what needs the <code>access.manage</code> permission. The company owner
            always has it — ask them, or whoever administers Purchases for this company.
          </EmptyState>
        </section>
      </div>
    )
  }

  const catalogueError = data.catalogue.error
  // When the starter lookup itself failed — an older API, a network blip — the
  // button stays available rather than being disabled with a reason that was
  // never established. `bootstrap()` skips whatever already exists, so pressing
  // it is safe; refusing to offer it because we could not check is worse.
  const startersUnknown = data.starters.error !== null
  const startersAvailable = startersUnknown || data.startersAvailable > 0

  return (
    <div className="access-workspace">
      <AccessHero>
        <AccessMetrics
          profileCount={data.profileCount}
          peopleCount={data.peopleCount}
          isOwner={data.isOwner}
        />
      </AccessHero>

      <div className="access-toolbar">
        <AccessTabs
          active={activeTab}
          onChange={(tab) => setTabParam(tab)}
          counts={{ profiles: data.profileCount, people: data.peopleCount }}
        />

        <div className="access-toolbar__actions">
          <button
            type="button"
            className="access-btn access-btn--secondary"
            onClick={() => setDialog({ kind: 'starters' })}
            disabled={busy || data.starters.loading || !startersAvailable}
            title={
              data.starters.loading
                ? 'Checking what this company already has…'
                : startersAvailable
                  ? undefined
                  : 'The starter profiles already exist in this company'
            }
          >
            <Sparkles size={15} aria-hidden /> Create starter profiles
          </button>

          <button
            type="button"
            className="access-btn access-btn--primary"
            onClick={() => setDialog({ kind: 'editor', mode: 'new', profile: null })}
            disabled={busy || data.cat === null}
            title={data.cat === null ? 'Waiting for the permission catalogue' : undefined}
          >
            <Plus size={15} aria-hidden /> New profile
          </button>
        </div>
      </div>

      {catalogueError && (
        <div style={{ marginTop: 14 }}>
          <InlineNotice tone="danger" title="Could not load the permission catalogue">
            {catalogueError} Profiles can still be listed, but nothing can be created or edited until this
            loads.
          </InlineNotice>
        </div>
      )}

      <div className="access-layout">
        <main className="access-main">
          <div
            role="tabpanel"
            id={TAB_PANEL_ID.profiles}
            aria-labelledby="access-tab-profiles"
            hidden={activeTab !== 'profiles'}
          >
            {activeTab === 'profiles' && (
              <ProfilesPanel
                profiles={data.profileList}
                loading={data.profiles.loading}
                error={data.profiles.error}
                onRetry={data.reload}
                grantable={data.grantable}
                busy={busy}
                canCreateStarters={startersAvailable}
                onCreateStarters={() => setDialog({ kind: 'starters' })}
                onNewProfile={() => setDialog({ kind: 'editor', mode: 'new', profile: null })}
                onView={(profile) =>
                  setDialog({
                    kind: 'permissions',
                    title: profile.profile_name,
                    description: profile.description ?? undefined,
                    held: profile.permissions,
                  })
                }
                onEdit={(profile) => setDialog({ kind: 'editor', mode: 'edit', profile })}
                onDuplicate={(profile) => setDialog({ kind: 'editor', mode: 'duplicate', profile })}
                onToggleActive={(profile) => void toggleActive(profile)}
                onDelete={(profile) => setDialog({ kind: 'delete-profile', profile })}
              />
            )}
          </div>

          <div
            role="tabpanel"
            id={TAB_PANEL_ID.people}
            aria-labelledby="access-tab-people"
            hidden={activeTab !== 'people'}
          >
            {activeTab === 'people' && (
              <PeoplePanel
                members={data.memberList}
                profiles={data.profileList}
                loading={data.members.loading}
                error={data.members.error}
                onRetry={data.reload}
                busy={busy}
                myUuid={data.myUuid}
                onView={(member) =>
                  setDialog({
                    kind: 'permissions',
                    title: member.label ?? 'This person',
                    description: member.user_uuid,
                    held: member.permissions,
                  })
                }
                onAssignAnother={(member) => sendToGrantForm(member.user_uuid)}
                onRevoke={(member, assignmentId, profileName) =>
                  setDialog({ kind: 'revoke', member, assignmentId, profileName })
                }
                onGrantFirst={() => sendToGrantForm('')}
              />
            )}
          </div>

          <div
            role="tabpanel"
            id={TAB_PANEL_ID.requests}
            aria-labelledby="access-tab-requests"
            hidden={activeTab !== 'requests'}
          >
            {activeTab === 'requests' && (
              <RequestsPanel
                candidates={data.candidateList}
                loading={data.candidates.loading}
                error={data.candidates.error}
                onRetry={data.reload}
                busy={busy}
                onGiveAccess={sendToGrantForm}
              />
            )}
          </div>

          <div
            role="tabpanel"
            id={TAB_PANEL_ID.activity}
            aria-labelledby="access-tab-activity"
            hidden={activeTab !== 'activity'}
          >
            {activeTab === 'activity' && (
              <ActivityPanel
                events={data.activityList}
                loading={data.activity.loading}
                error={data.activity.error}
                onRetry={data.reload}
                myUuid={data.myUuid}
              />
            )}
          </div>

          {/* The form stays below every tab: granting is what an administrator
              came here to do, and hiding it behind a tab adds a click to the
              one action this page exists for. */}
          <GrantAccessForm
            profiles={data.profileList.filter((profile) => profile.is_active)}
            candidates={data.candidateList}
            busy={busy}
            prefillUuid={prefill}
            onSubmit={grant}
            onNewProfile={() => setDialog({ kind: 'editor', mode: 'new', profile: null })}
          />
        </main>

        <aside className="access-aside">
          <AccessGuide
            isOwner={data.isOwner}
            grantedCount={data.cat?.granted.length ?? null}
            ownerNote={data.cat?.owner_note ?? null}
          />
          <AiAssistantCard />
        </aside>
      </div>

      {/* --------------------------------------------------------------- */}

      <StarterProfilesDialog
        open={dialog?.kind === 'starters'}
        starters={data.starterList}
        loading={data.starters.loading}
        error={data.starters.error}
        busy={busy}
        onConfirm={() => void createStarters()}
        onClose={closeDialog}
      />

      {dialog?.kind === 'editor' && data.cat && (
        <ProfileEditorDialog
          key={`${dialog.mode}-${dialog.profile?.profile_id ?? 'new'}`}
          open
          mode={dialog.mode}
          profile={dialog.profile}
          catalogue={data.cat}
          busy={busy}
          error={dialogError}
          onClose={closeDialog}
          onSave={(body) => void saveProfile(body)}
        />
      )}

      {dialog?.kind === 'permissions' && (
        <PermissionsDialog
          open
          title={dialog.title}
          description={dialog.description}
          held={dialog.held}
          catalog={data.cat?.catalog ?? {}}
          onClose={closeDialog}
        />
      )}

      {dialog?.kind === 'delete-profile' && (
        <ConfirmDialog
          open
          title={`Delete ${dialog.profile.profile_name}?`}
          description="The profile and everything it grants are removed. This cannot be undone."
          confirmLabel="Delete profile"
          busy={busy}
          onConfirm={() => void deleteProfile(dialog.profile)}
          onClose={closeDialog}
        >
          {dialogError && (
            <InlineNotice tone="danger" title="Not deleted">
              {dialogError}
            </InlineNotice>
          )}
          <p style={{ margin: dialogError ? '12px 0 0' : 0, fontSize: 12.5, color: 'var(--ap-text-secondary)' }}>
            It grants {dialog.profile.permission_count} permission
            {dialog.profile.permission_count === 1 ? '' : 's'} and is held by {dialog.profile.member_count}{' '}
            {dialog.profile.member_count === 1 ? 'person' : 'people'}. You can create it again afterwards, but
            anybody who held it will have to be given the new one.
          </p>
        </ConfirmDialog>
      )}

      {dialog?.kind === 'revoke' && (
        <ConfirmDialog
          open
          title={`Revoke ${dialog.profileName}?`}
          description={`${dialog.member.label ?? 'This person'} loses everything that profile grants, immediately.`}
          confirmLabel="Revoke access"
          busy={busy}
          onConfirm={() => void revoke(dialog.assignmentId, dialog.profileName)}
          onClose={closeDialog}
        >
          {dialogError && (
            <InlineNotice tone="danger" title="Not revoked">
              {dialogError}
            </InlineNotice>
          )}

          {dialog.member.is_you && !data.isOwner && (
            <div style={{ marginTop: dialogError ? 12 : 0 }}>
              <InlineNotice tone="warning" title="This is your own access">
                If it is your last grant of access management, the server will refuse it — a company with no
                administrator needs the owner to log in. Give somebody else that permission first.
              </InlineNotice>
            </div>
          )}

          <p style={{ margin: '12px 0 0', fontSize: 12.5, color: 'var(--ap-text-secondary)' }}>
            Other profiles they hold are unaffected, and the change is recorded against your name.
          </p>
        </ConfirmDialog>
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  )
}
