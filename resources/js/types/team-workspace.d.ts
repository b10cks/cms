export interface TeamOverviewKpis {
  members: { total: number; active_30d: number }
  spaces: { total: number; published: number }
  pending_invites: { total: number; stale: number }
  expiring_access: { total: number }
  sso: { configured: boolean; enabled: boolean }
}

export interface TeamStructurePerson {
  id: string
  name: string
  avatar: string | null
}

export interface TeamStructureSpace {
  id: string
  name: string
  published: boolean
}

export interface TeamStructureNode {
  id: string
  /** `null` for the team the overview belongs to. */
  parent_id: string | null
  name: string
  icon: string | null
  color: string | null
  avatar: string | null
  type: string | null
  people_count: number
  people: TeamStructurePerson[]
  spaces: TeamStructureSpace[]
  can_move: boolean
  can_adopt: boolean
}

export interface TeamAttentionTarget {
  name: string
  params?: Record<string, string>
  query?: Record<string, string>
}

export interface TeamAttentionItem {
  type: string
  count: number
  details: string[]
  target: TeamAttentionTarget | null
}

export interface TeamOverview {
  kpis: TeamOverviewKpis
  structure: TeamStructureNode[]
  attention: TeamAttentionItem[]
}

export interface TeamMovePerson {
  id: string
  name: string
  email: string
  avatar: string | null
  role: string
  role_name: string
  previous_role?: string
  previous_role_name?: string
  via: { id: string; name: string }
}

export interface TeamMoveRole {
  key: string
  name: string
  team: { id: string; name: string | null }
  assignments?: number
}

export interface TeamMovePreview {
  from: { id: string; name: string | null } | null
  to: { id: string; name: string } | null
  gains: TeamMovePerson[]
  losses: TeamMovePerson[]
  changes: TeamMovePerson[]
  roles_starting: TeamMoveRole[]
  roles_stopping: TeamMoveRole[]
  direct_space_roles: number
}

export interface TeamAuditActor {
  id: string
  name: string
  avatar: string | null
}

export interface TeamAuditEntry {
  id: string
  action: string
  created_at: string
  actor: TeamAuditActor | null
  subject: { type: 'team' | 'space'; id: string; name: string | null }
  member: TeamAuditActor | null
  old_values: Record<string, string | null> | null
  new_values: Record<string, string | null> | null
  parents: { from: string | null; to: string | null }
  reason: string | null
}

export type AccessCellReason = 'no_permission' | 'outranks'

export interface AccessMatrixCell {
  direct: { key: string; name: string } | null
  inherited: { key: string; name: string; via: string | null } | null
  expires_at: string | null
  editable: boolean
  reason: AccessCellReason | null
}

export interface AccessMatrixSpace {
  id: string
  name: string
  team_id: string
  team_name: string | null
  can_manage: boolean
  assignable_roles: { key: string; name: string }[]
}

export interface AccessMatrixPerson {
  id: string
  name: string
  email: string
  avatar: string | null
  team_role: { key: string; name: string; via: string | null } | null
  cells: Record<string, AccessMatrixCell>
}

export interface AccessMatrix {
  teams: { id: string; name: string; parent_id: string | null }[]
  spaces: AccessMatrixSpace[]
  people: AccessMatrixPerson[]
}

export interface TeamsDashboardSummary {
  teams: number
  teams_series: number[]
  people: number
  active_people: number
  spaces: number
  spaces_series: number[]
  pending_invites: number
  sso_teams: number
  teams_without_owner: number
}

export interface TeamsDashboardCard {
  id: string
  name: string
  description: string | null
  icon: string | null
  color: string | null
  avatar: string | null
  type: string | null
  created_at: string | null
  people_count: number
  spaces_count: number
  sub_team_count: number
  pending_invites: number
  teams_without_owner: number
  sso: boolean
  content_updated_at: string | null
  people: { id: string; name: string; avatar: string | null }[]
  children: { id: string; name: string; icon: string | null; color: string | null; avatar: string | null }[]
  can_update: boolean
  can_delete: boolean
}

export interface TeamsDashboard {
  summary: TeamsDashboardSummary
  teams: TeamsDashboardCard[]
}
