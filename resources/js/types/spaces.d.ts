interface SpaceEnvironment {
  url: string
  name: string
}

interface SpaceLanguage {
  code: string
  name: string
  fallback_language?: string | null
}

/**
 * Maps a URL path segment to the CMS language it renders, decoupling site
 * URLs from content languages. One language may serve several segments
 * (e.g. `de` under `at-de`, `ch-de` and `de-de` for market-style setups).
 * When no site locales are configured, the `slug_strategy` applies.
 */
interface SpaceSiteLocale {
  segment: string
  language: string
  name?: string | null
}

interface SpaceAssetField {
  key: string
  label: string
  required: boolean
}

interface SpaceSitemapType {
  block: string
  path: string
}

interface SpaceSitemapSettings {
  types: SpaceSitemapType[]
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
  site_locales?: SpaceSiteLocale[]
  filter_hidden_blocks?: boolean
  content_sorting?: boolean
  onboarding_dismissed_at?: string | null
  sitemap?: SpaceSitemapSettings
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
  billing_interval?: BillingInterval
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

export interface SpaceMemberResource {
  id: string
  user: {
    id: string
    firstname: string
    lastname: string
    name: string
    email: string
    avatar?: string | null
  }
  role: string | null
  can_assign_space_role: boolean
  can_remove: boolean
  joined_at: string
}

export interface UpdateSpaceMemberPayload {
  role: string | null
}

export interface SpaceMemberQueryParams {
  role?: string
  name?: string
  email?: string
  sort?: string
  page?: number
  per_page?: number
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
