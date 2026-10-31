import type { SimpleUser } from './invites'

export type TeamDomainPolicy = 'request' | 'auto_join' | 'require_sso'

export interface TeamDomainResource {
  id: string
  team_id: string
  /** Lowercase ASCII, punycode for IDNs. Also the TXT record host. */
  domain: string
  display_domain: string
  txt_record: string
  verified_at: string | null
  policy: TeamDomainPolicy
  default_role: string
  created_at: string
  updated_at: string
}

export interface UpdateTeamDomainPayload {
  policy?: TeamDomainPolicy
  default_role?: string
}

export type JoinRequestStatus = 'pending' | 'approved' | 'declined'

export interface TeamJoinRequestResource {
  id: string
  team_id: string
  status: JoinRequestStatus
  user?: SimpleUser
  domain?: string | null
  default_role?: string
  decided_at: string | null
  created_at: string
}

/** A team the signed-in user may ask to join through their email domain. */
export interface JoinableTeamResource {
  team: { id: string; name: string; avatar: string | null }
  domain: string
  request: { id: string; status: JoinRequestStatus; created_at: string } | null
}
