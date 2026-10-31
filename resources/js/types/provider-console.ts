export type TenantFlag = 'no_owner' | 'trial_ended' | 'payment_failed'

export interface ProviderTenant {
  id: string
  name: string
  type: string | null
  parent_id: string | null
  avatar: string | null
  color: string | null
  spaces_count: number
  people_count: number
  mrr: number
  is_trial: boolean
  sso: boolean
  last_active_at: string | null
  created_at: string | null
  flags: TenantFlag[]
}

export type ProviderUserSegment = 'all' | 'active' | 'suspended' | 'root'

export type ProviderUserStatus = 'active' | 'suspended' | 'root'

export interface ProviderUser {
  id: string
  name: string
  email: string
  avatar: string | null
  initials: string
  status: ProviderUserStatus
  is_root: boolean
  suspended_at: string | null
  suspension_reason: string | null
  last_login_at: string | null
  created_at: string | null
  teams_count: number
  first_team?: string | null
  sign_in: string[]
}

export interface ProviderUserMembership {
  kind: 'team' | 'space'
  id: string
  name: string
  avatar: string | null
  role_key: string | null
  role_name: string | null
  is_sole_owner: boolean
}

export interface ProviderOwnedTeam {
  id: string
  name: string
  is_sole_owner: boolean
  candidates: Array<{ id: string; name: string; email: string }>
}

export interface ProviderUserDetail extends ProviderUser {
  suspended_by: string | null
  counts: { teams: number; sessions: number | null; tokens: number }
  memberships: ProviderUserMembership[]
  owned_teams: ProviderOwnedTeam[]
  activity: Array<{ action: string; created_at: string | null; by_self: boolean }>
}

export interface ProviderUserListResponse extends ApiCollectionResponse<ProviderUser> {
  meta: LaravelMeta & { segments: Record<ProviderUserSegment, number> }
}

export interface ProviderUserQueryParams {
  search?: string
  segment?: ProviderUserSegment
  page?: number
  per_page?: number
}

export interface UsageShare {
  used: number
  share: number | null
}

export interface ProviderUsageRow {
  team: { id: string; name: string; color: string | null }
  revenue: number
  ai_spend: number
  storage: UsageShare
  traffic: UsageShare
  api_requests: number
  flags: Array<'storage' | 'trial_ended' | 'payment_failed'>
}

export interface ProviderHealth {
  queue_connection: string
  queues: Array<{ name: string; size: number | null }>
  failed_jobs_24h: number | null
  space_databases: {
    total: number
    checked: number
    failed: number
    failures: Array<{ space_id: string; space_name: string | null }>
    checked_at: string
  }
}

export type InstanceAuditActionKey =
  | 'root.elevation.started'
  | 'root.elevation.ended'
  | 'root.impersonation.started'
  | 'root.impersonation.ended'
  | 'root.user.suspended'
  | 'root.user.restored'
  | 'root.user.signed_out'
  | 'root.team.ownership_transferred'

export interface InstanceAuditEntry {
  id: string
  action: InstanceAuditActionKey
  actor: { id: string; name: string; email: string } | null
  entity_type: string
  entity_id: string
  metadata: Record<string, unknown>
  created_at: string | null
}

export interface SuspendUserPayload {
  reason: string
  replacement_owners?: Record<string, string>
}

export interface TransferOwnershipPayload {
  team_id: string
  new_owner_id: string
}

export interface RootElevationState {
  is_root: boolean
  is_elevated: boolean
  elevated_until: string | null
  elevation_reason: string | null
}

export const ELEVATION_DURATIONS = [15, 30, 60] as const

export type ElevationDuration = (typeof ELEVATION_DURATIONS)[number]
