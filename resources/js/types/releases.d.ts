export type ReleaseState = 'draft' | 'scheduled' | 'published' | 'pending'

export interface Release {
  id: string
  external_id: string | null
  name: string
  description: string | null
  settings: Record<string, any>
  owner_id: string | null
  publish_at: string
  committed_at: string | null
  published_at: string | null
  versions_count: number
  created_at: string
  updated_at: string
}

export interface ReleaseDetail extends Release {
  versions: ContentVersionListResource[]
}

export interface CreateReleaseRequest {
  name: string
  description?: string
  settings?: Record<string, any>
  owner_id?: string
  external_id?: string
  publish_at: string
}

export interface UpdateReleaseRequest {
  name?: string
  description?: string | null
  settings?: Record<string, any> | null
  publish_at?: string
}

export interface AssignVersionsRequest {
  version_ids: string[]
}
