export interface TeamSettings {
  [key: string]: unknown
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
  color?: string | null
  type: string
  parent_id?: string | null
  children: TeamHierarchyItem[]
  user_count?: number
  spaces_count?: number
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
  role: string
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
