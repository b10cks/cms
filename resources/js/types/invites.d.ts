export type InviteRole = 'owner' | 'admin' | 'editor' | 'member' | 'viewer'

export enum InviteStatus {
  PENDING = 'pending',
  ACCEPTED = 'accepted',
  EXPIRED = 'expired',
}

export interface SimpleUser {
  id: string
  email: string
  firstname: string
  lastname: string
}

export interface SimpleSpaceResource {
  id: string
  name: string
}

export interface SimpleTeamResource {
  id: string
  name: string
}

export interface InviteResource {
  id: string
  space_id: string | null
  team_id: string | null
  invitee_id: string | null
  invited_by: string
  email: string
  role: InviteRole
  message: string | null
  status: InviteStatus
  expires_at: string
  accepted_at: string | null
  created_at: string
  updated_at: string
  inviter?: SimpleUser
  invitee?: SimpleUser | null
  space?: SimpleSpaceResource | null
  team?: SimpleTeamResource | null
}

export interface PublicInviteResource {
  id: string
  space?: SimpleSpaceResource
  team?: SimpleTeamResource
  inviter: SimpleUser
  email_hash: string
  role: InviteRole
  message: string | null
  expires_at: string
  status: InviteStatus
}

export interface CreateInvitePayload {
  email: string
  role: InviteRole
  message?: string | null
  expires_in_days?: number
}

export interface AcceptInvitePayload {
  token: string
}

export interface InviteQueryParams {
  page?: number
  per_page?: number
  status?: InviteStatus
}
