export interface RoleCatalogEntry {
  id: string
  scope: 'team' | 'space'
  key: string
  name: string
  description?: string | null
  level: number
  is_system: boolean
  team_id?: string | null
  abilities: string[]
  is_read_only: boolean
  created_at?: string | null
  updated_at?: string | null
}

export interface AuthorizationTeamContext {
  id: string
  role_keys: string[]
  abilities: string[]
}

export interface AuthorizationSpaceContext {
  id: string
  team_role_keys: string[]
  space_role_key?: string | null
  abilities: string[]
  plan?: {
    id?: string | null
    name?: string | null
    status: string
  } | null
}

export interface AuthorizationPayload {
  user_id: string
  is_root: boolean
  teams: Array<{ id: string; name: string }>
  spaces: Array<{ id: string; name: string; team_id?: string | null }>
  team?: AuthorizationTeamContext | null
  space?: AuthorizationSpaceContext | null
  roles: {
    team: RoleCatalogEntry[]
    space: RoleCatalogEntry[]
  }
}

export interface AuthorizationQueryParams {
  team_id?: string
  space_id?: string
}

export interface CreateTeamSpaceRolePayload {
  key: string
  name: string
  description?: string
  level: number
  abilities: string[]
}

export interface UpdateTeamSpaceRolePayload {
  key?: string
  name?: string
  description?: string
  level?: number
  abilities?: string[]
}
