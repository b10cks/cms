declare interface SimpleReleaseResource {
  id: string
  name: string
  published_at: string | null
  created_at: string
}

declare interface ContentVersionListResource {
  id: string
  author?: {
    id: string
    name: string
    initials: string
    avatar?: string
  } | null
  message: string | null
  parent_id: string | null
  release_id: string | null
  published_at: string | null
  scheduled_at: string | null
  release: SimpleReleaseResource | null
  created_at: string
}

declare interface ContentVersionDiffEntry {
  path: string
  old_value: unknown
  new_value: unknown
  type: string
}

declare interface ContentVersionDiff {
  entries: ContentVersionDiffEntry[]
}

declare interface ContentVersionResource extends ContentVersionListResource {
  content: Record<string, never>
  assets?: string[] | null
  relations?: Record<string, never> | null
  diff: ContentVersionDiff
}

declare interface ContentVersionActionPayload {
  versionId: string
}

export type ContentI18nMode = 'overlay' | 'independent'

export interface ContentLanguageVersion {
  language_iso: string
  label: string
  exists: boolean
  content_id: string | null
  is_default: boolean
  is_current: boolean
  status: 'missing' | 'draft' | 'published'
  published_at: string | null
  fallback_language: string | null
}

export interface ContentBlock {
  id: string
  icon: string
  name: string
  slug: string
  type: 'root' | 'nestable' | 'single' | 'universal'
  color?: string | null
}

export interface ContentSettings {
  disablePreview: boolean
  i18n_mode_override?: 'inherit' | 'overlay' | 'independent'
}

export interface ContentResource {
  id: string
  slug: string
  full_slug: string
  parent_id: string | null
  children_count?: number
  block_id: string
  block?: ContentBlock
  block_schema?: Record<string, SchemaType>
  block_editor?: EditorPage[]
  language_iso: string
  i18n_parent_id: string | null
  i18n_canonical_id: string
  effective_i18n_mode: ContentI18nMode
  language_versions: ContentLanguageVersion[]
  i18n_parent?: ContentMenuTranslation
  i18n_translations?: ContentMenuTranslation[]
  i18n_siblings?: ContentMenuTranslation[]
  name: string
  content: Record<string, unknown>
  settings: ContentSettings
  published_version_id: string | null
  published_version?: ContentVersionListResource | null
  current_version_id: string | null
  current_version?: ContentVersionListResource | null
  description: string
  first_published_at: string | null
  published_at: string | null
  created_at: string
  updated_at: string
}

export interface CreateContentPayload {
  parent_id?: string | null
  block_id: string
  name: string
  slug: string
  language_iso?: string
  i18n_parent_id?: string | null
  content?: Record<string, unknown>
  settings?: Partial<ContentSettings>
  description?: string
}

export interface UpdateContentPayload {
  parent_id?: string | null
  block_id?: string
  name?: string
  slug?: string
  language_iso?: string
  i18n_parent_id?: string | null
  content?: Record<string, unknown>
  settings?: Partial<ContentSettings>
  description?: string
  message?: string
  scheduled_at?: string | null
}
