export interface TeamSettings {
  [key: string]: unknown
}

export interface TeamSamlLinks {
  login_url: string
  acs_url: string
  sls_url: string
  metadata_url: string
  sp_entity_id: string
}

export interface TeamSamlProviderPayload {
  enabled: boolean
  idp_entity_id: string
  sso_url: string
  slo_url?: string | null
  idp_x509_cert: string
  sp_x509_cert?: string | null
  sp_private_key?: string | null
  name_id_format: string
  attribute_mapping: {
    email: string
    first_name?: string | null
    last_name?: string | null
    external_id?: string | null
  }
  role_attribute?: string | null
  role_mapping?: Record<string, string>
  default_role: string
  allow_jit: boolean
  strict: boolean
  sign_authn_requests: boolean
  sign_logout_requests: boolean
  want_assertions_signed: boolean
  want_messages_signed: boolean
  want_assertions_encrypted: boolean
  digest_algorithm: string
  signature_algorithm: string
}

export interface TeamSamlProviderResource extends TeamSamlProviderPayload {
  id: string
  team_id: string
  has_sp_private_key: boolean
  last_login_at?: string | null
  created_at: string
  updated_at: string
  links: TeamSamlLinks
}

export interface TeamSamlProviderResponse {
  data: TeamSamlProviderResource | null
  defaults: Omit<TeamSamlProviderPayload, 'sp_private_key'> & { links: TeamSamlLinks }
}

export interface TeamParent {
  id: string
  name: string
}

export interface TeamChild {
  id: string
  name: string
}

export interface TeamResource {
  id: string
  name: string
  icon?: string | null
  avatar?: string | null
  color?: string | null
  description?: string | null
  type: string
  parent_id?: string | null
  parent?: TeamParent
  children?: TeamChild[]
  settings: TeamSettings
  user_count?: number
  spaces_count?: number
  children_count?: number
  can_view_detail: boolean
  can_create_space: boolean
  can_update: boolean
  can_delete: boolean
  can_manage_members: boolean
  can_create_child: boolean
  can_create_blueprint: boolean
  created_at: string
  updated_at: string
}

export interface CreateTeamPayload {
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  type: string
  parent_id?: string | null
  settings?: TeamSettings
}

export interface UpdateTeamPayload {
  name?: string
  icon?: string | null
  color?: string | null
  description?: string | null
  type?: string
  parent_id?: string | null
  settings?: TeamSettings
}

export interface TeamHierarchyItem {
  id: string
  name: string
  icon?: string | null
  avatar?: string | null
  color?: string | null
  description?: string | null
  type: string
  parent_id?: string | null
  parent?: TeamParent
  children: TeamHierarchyItem[]
  user_count?: number
  spaces_count?: number
  children_count?: number
  can_view_detail?: boolean
  can_create_space?: boolean
  can_update?: boolean
  can_delete?: boolean
  can_manage_members?: boolean
  can_create_child?: boolean
  can_create_blueprint?: boolean
}

export interface TeamUserResource {
  id: string
  user: {
    id: string
    firstname: string
    lastname: string
    email: string
    avatar?: string | null
  }
  role: string | null
  membership_origin: 'team' | 'space'
  can_assign_team_role: boolean
  can_remove: boolean
  space_memberships: Array<{
    space: {
      id: string
      name: string
    }
    role: string | null
    joined_at: string
  }>
  joined_at: string
}

export interface AddTeamUserPayload {
  user_id: string
  role: string
}

export interface UpdateTeamUserPayload {
  role: string
}

export interface TeamUserQueryParams {
  role?: string
  sort?: string
  page?: number
  per_page?: number
}
