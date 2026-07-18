import type { ApiCollectionResponse } from '~/types'

export type PersonKind = 'member' | 'invite'

export type PersonState = 'active' | 'pending' | 'expired'

export type PersonSegment = 'all' | 'members' | 'pending'

export interface PersonUser {
  id: string
  firstname: string
  lastname: string
  name: string
  email: string
  avatar?: string | null
  initials?: string
}

export interface PersonSpaceMembership {
  space: {
    id: string
    name: string
  }
  role: string | null
  joined_at: string
}

export interface PersonResource {
  kind: PersonKind
  id: string
  user_id: string | null
  invite_id: string | null
  user: PersonUser | null
  email: string
  role: string | null
  state: PersonState
  can_assign_role: boolean
  can_remove: boolean
  membership_origin: 'team' | 'space' | null
  space_memberships: PersonSpaceMembership[]
  joined_at: string | null
  invited_at: string | null
  expires_at: string | null
  created_at: string | null
}

export interface PeopleCounts {
  members: number
  pending: number
  total: number
}

export interface PeopleCollectionResponse extends ApiCollectionResponse<PersonResource> {
  counts: PeopleCounts
}

export interface PeopleQueryParams {
  segment?: PersonSegment
  name?: string
  email?: string
  role?: string
  sort?: string
  page?: number
  per_page?: number
}
