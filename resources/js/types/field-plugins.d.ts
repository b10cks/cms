export type FieldPluginStatus = 'draft' | 'dev' | 'published'

export interface FieldPluginManifestOption {
  key: string
  label?: string | null
  default?: string | null
}

export interface FieldPluginManifest {
  options?: FieldPluginManifestOption[]
  height?: number
}

export interface FieldPluginResource {
  id: string
  external_id: string | null
  name: string
  handle: string
  description: string | null
  status: FieldPluginStatus
  dev_mode: boolean
  dev_url: string | null
  code?: string | null
  code_hash: string | null
  code_size: number | null
  published_at: string | null
  sandbox_url: string | null
  manifest: FieldPluginManifest | null
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface CreateFieldPluginPayload {
  external_id?: string
  name: string
  handle: string
  description?: string | null
  dev_mode?: boolean
  dev_url?: string | null
  code?: string | null
  manifest?: FieldPluginManifest | null
  is_active?: boolean
}

export type UpdateFieldPluginPayload = Partial<Omit<CreateFieldPluginPayload, 'handle'>>
