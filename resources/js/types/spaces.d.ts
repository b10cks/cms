interface SpaceEnvironment {
  url: string
  name: string
}

interface SpaceLanguage {
  code: string
  name: string
  fallback_language?: string | null
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
  default_environment?: string | null
  region?: 'eu' | 'us'
  default_language?: string
  i18n_mode?: 'overlay' | 'independent'
  slug_strategy?: 'never' | 'prepend_translations' | 'always_prepend'
  asset_fields?: SpaceAssetField[]
  languages?: SpaceLanguage[]
}

interface SpacePlanSummary {
  id: string | null
  name: string | null
  status: SubscriptionStatus
}

interface SpaceResource {
  id: string
  state: string
  name: string
  slug: string
  icon: string
  color: string
  badge?: string | null
  description: string
  team_id?: string | null
  plan?: SpacePlanSummary | null
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
  badge?: string | null
  description?: string | null
  settings: SpaceSettings
  plan_id?: string | null
}

interface UpdateSpacePayload {
  name?: string
  slug?: string
  icon?: string | null
  color?: string | null
  badge?: string | null
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
