type PersonKind = 'member' | 'invite'

type PersonState = 'active' | 'pending' | 'expired'

type PersonSegment = 'all' | 'members' | 'pending' | 'expiring'

interface PersonUser {
  id: string
  firstname: string
  lastname: string
  name: string
  email: string
  avatar?: string | null
  initials?: string
}

interface PersonSpaceMembership {
  space: {
    id: string
    name: string
  }
  role: string | null
  joined_at: string
  expires_at: string | null
}

interface PersonResource {
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
  membership_origin: 'team' | 'space' | 'inherited' | null
  /** The ancestor team an inherited role comes from. */
  inherited_from: { id: string; name: string } | null
  /** On space lists: the team role that reaches this space. */
  inherited_role: string | null
  space_memberships: PersonSpaceMembership[]
  /** How the membership came about (`invite`, `owner`, `saml`, `direct`). */
  source: string | null
  last_active_at: string | null
  /** When the access ends; `expires_at` is the invite link's own expiry. */
  access_expires_at: string | null
  joined_at: string | null
  invited_at: string | null
  expires_at: string | null
  created_at: string | null
}

interface PeopleCounts {
  members: number
  pending: number
  expiring: number
  total: number
}

interface PeopleCollectionResponse extends ApiCollectionResponse<PersonResource> {
  counts: PeopleCounts
}

interface PeopleQueryParams {
  segment?: PersonSegment
  name?: string
  email?: string
  role?: string
  sort?: string
  page?: number
  per_page?: number
}
