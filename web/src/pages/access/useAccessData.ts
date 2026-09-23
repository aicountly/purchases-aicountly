/**
 * Everything this screen reads, in one place.
 *
 * The four panels, the KPI cards and the grant form all look at the same
 * server data, so it is fetched once here rather than per panel: two panels
 * fetching `members` independently is two answers that can disagree, and the
 * count on a card disagreeing with the rows under it is the bug that makes
 * people stop trusting the card.
 *
 * `reload()` is the cache invalidation. There is no query library in this
 * product — `useApi` plus a token in the dependency list is the idiom the rest
 * of the pages use, and one screen is not the place to introduce a second one.
 */

import { useCallback, useMemo, useState } from 'react'
import { api } from '../../services/api'
import { useApi } from '../../hooks/useApi'
import { usePurchases } from '../../context/PurchasesContext'
import type { AccessTabId, ActivityEvent, Candidate, Catalogue, Member, Profile, StarterCatalogue } from './types'

export function useAccessData(activeTab: AccessTabId) {
  const { scope, session } = usePurchases()
  const [reloadToken, setReloadToken] = useState(0)

  const reload = useCallback(() => setReloadToken((n) => n + 1), [])

  const enabled = Boolean(scope)
  const deps = [scope?.cmp_id, scope?.fy_id, reloadToken]

  const catalogue = useApi((s) => api.one<Catalogue>('v1/access/catalogue', undefined, s), deps, enabled)
  const profiles = useApi((s) => api.one<Profile[]>('v1/access/profiles', undefined, s), deps, enabled)
  const members = useApi((s) => api.one<Member[]>('v1/access/members', undefined, s), deps, enabled)
  const candidates = useApi((s) => api.one<Candidate[]>('v1/access/people', undefined, s), deps, enabled)
  const starters = useApi((s) => api.one<StarterCatalogue>('v1/access/starters', undefined, s), deps, enabled)

  // The audit trail is the only read here that can run to dozens of rows, and
  // three of the four tabs never show it. It loads when its tab is opened.
  const activity = useApi(
    (s) => api.one<ActivityEvent[]>('v1/access/activity', { limit: 80 }, s),
    deps,
    enabled && activeTab === 'activity',
  )

  const cat = catalogue.data?.data ?? null
  const profileList = useMemo(() => profiles.data?.data ?? [], [profiles.data])
  const memberList = useMemo(() => members.data?.data ?? [], [members.data])

  /** What this administrator may hand out. Owners hold the whole catalogue. */
  const grantable = useMemo(() => new Set(cat?.grantable ?? []), [cat])

  /** Permission code → label, flattened out of the grouped catalogue. */
  const permissionLabels = useMemo(() => {
    const labels = new Map<string, string>()
    for (const group of Object.values(cat?.catalog ?? {})) {
      for (const [code, label] of Object.entries(group)) labels.set(code, label)
    }
    return labels
  }, [cat])

  return {
    catalogue,
    profiles,
    members,
    candidates,
    starters,
    activity,

    cat,
    profileList,
    memberList,
    candidateList: candidates.data?.data ?? [],
    starterList: starters.data?.data?.starters ?? [],
    startersAvailable: starters.data?.data?.available ?? 0,
    activityList: activity.data?.data ?? [],

    grantable,
    permissionLabels,
    isOwner: cat?.is_owner ?? session?.is_owner ?? false,
    myUuid: cat?.my_uuid ?? session?.uuid ?? null,

    // Derived counts. Null means "not known yet", which the cards draw as a
    // skeleton — never as a zero. A zero that is really a loading state is the
    // one number on this page nobody should be shown.
    profileCount: profiles.loading && profiles.data === null ? null : profileList.length,
    peopleCount: members.loading && members.data === null ? null : memberList.length,

    reload,
    reloadToken,
  }
}

export type AccessData = ReturnType<typeof useAccessData>
