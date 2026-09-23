/**
 * The shapes `v1/access/*` returns.
 *
 * One copy, read by every panel. The server is the authority on all of it —
 * nothing here is a local model, and no field is invented to make a card look
 * fuller than the data behind it.
 */

/** Permission code → human label, grouped as the catalogue groups them. */
export type PermissionCatalog = Record<string, Record<string, string>>

export interface Catalogue {
  catalog: PermissionCatalog
  /** What the signed-in administrator holds. */
  granted: string[]
  /** What they may hand out. Owners hold everything; delegates hold their own. */
  grantable: string[]
  is_owner: boolean
  my_uuid: string
  owner_note: string
}

export interface Profile {
  profile_id: number
  profile_name: string
  description: string | null
  permissions: string[]
  permission_count: number
  is_active: boolean
  /** Set when the profile came from the starter set. Null for hand-built ones. */
  system_key: string | null
  member_count: number
  created_by?: string | null
  updated_at: string | null
}

export interface Assignment {
  assignment_id: number
  profile_id: number
  profile_name: string
  is_active: boolean
  note: string | null
  assigned_by?: string | null
  assigned_at: string
}

export interface Member {
  user_uuid: string
  label: string | null
  is_you: boolean
  permission_count: number
  permissions: string[]
  assignments: Assignment[]
}

/**
 * Somebody who has used Purchases here and holds no profile.
 *
 * From this product's own audit trail, not a user directory — Purchases does
 * not have one and must not grow one. Identity lives in Aicountly Manage.
 */
export interface Candidate {
  user_uuid: string
  actions: number
  last_seen: string
  is_you: boolean
}

export interface Starter {
  key: string
  name: string
  description: string
  permissions: string[]
  permission_count: number
  /** The subset this administrator may actually grant, which is what gets written. */
  grantable: string[]
  grantable_count: number
  exists: boolean
  profile_id: number | null
}

export interface StarterCatalogue {
  starters: Starter[]
  /** How many would be created if the button were pressed now. */
  available: number
}

export interface ActivityEvent {
  audit_id: number
  action: string
  actor_uuid: string
  actor_kind: string
  is_you: boolean
  source_app: string | null
  entity_type: string
  entity_id: string | null
  profile_name: string | null
  user_uuid: string | null
  reason: string | null
  created_at: string
}

export type AccessTabId = 'profiles' | 'people' | 'requests' | 'activity'
