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

declare interface ContentVersionResource extends ContentVersionListResource {
  content: Record<string, never>
  assets?: string[] | null
  relations?: Record<string, never> | null
}

declare interface ContentVersionActionPayload {
  versionId: string
}
export interface ContentBlock {
  id: string
  icon: string
  name: string
  slug: string
}

export interface ContentSettings {
  disablePreview: boolean
}

export interface ContentResource {
  id: string
  slug: string
  full_slug: string
  parent_id: string | null
  children_count?: number
  block_id: string
  block?: ContentBlock
  i18n_parent_id: string | null
  i18n_parent?: ContentMenuTranslation
  i18n_translations?: ContentMenuTranslation[]
  i18n_siblings?: ContentMenuTranslation[]
  name: string
  content: object
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
  content?: object
  settings?: Partial<ContentSettings>
  description?: string
}

export interface UpdateContentPayload {
  parent_id?: string | null
  name?: string
  slug?: string
  content?: object
  settings?: Partial<ContentSettings>
  description?: string
  message?: string
  scheduled_at?: string | null
}
