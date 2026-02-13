interface SpaceEnvironment {
  url: string
  name: string
}

interface SpaceLanguage {
  code: string
  name: string
}

interface SpaceAssetField {
  key: string
  label: string
  required: boolean
}

interface SpaceSettings {
  visual_editor?: boolean
  default_block?: string
  environments?: SpaceEnvironment[]
  region?: 'eu' | 'us'
  default_language?: string
  slug_strategy?: 'never' | 'prepend_translations' | 'always_prepend'
  asset_fields?: SpaceAssetField[]
  languages?: SpaceLanguage[]
}

interface SpaceResource {
  id: string
  state: string
  name: string
  slug: string
  icon: string
  color: string
  description: string
  team_id?: string | null
  settings: SpaceSettings
  user_count?: string
  content_updated_at?: string | null
  created_at: string
  updated_at: string
}

interface CreateSpacePayload {
  name: string
  slug: string
  team_id?: string | null
  icon?: string | null
  color?: string | null
  description?: string | null
  settings: SpaceSettings
}

interface UpdateSpacePayload {
  name?: string
  slug?: string
  icon?: string | null
  color?: string | null
  description?: string | null
  settings?: SpaceSettings
}

interface Token {
  id: string
  name: string
  token: string
  abilities: string[]
  expires_at: string | null
  execution_count: number
  last_used_at: string | null
  created_at: string
  updated_at: string
}
