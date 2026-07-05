export type AssetShareSourceType = 'collection' | 'selection' | 'folder'

export type AssetPackageState = 'pending' | 'building' | 'completed' | 'failed'

export interface AssetDistributionCreator {
  id: string
  display_name: string
  email?: string
}

export interface AssetPackageResource {
  id: string
  name: string | null
  source_type: AssetShareSourceType
  collection_id: string | null
  folder_id: string | null
  asset_ids: string[] | null
  state: AssetPackageState
  progress: number
  error: string | null
  file_size: number | null
  checksum: string | null
  asset_count: number | null
  is_stale: boolean
  expires_at: string | null
  created_at: string | null
  updated_at: string | null
  created_by?: AssetDistributionCreator | null
}

/** Source descriptor used by the share/package dialogs and entry points. */
export interface AssetShareSource {
  source_type: AssetShareSourceType
  collection_id?: string | null
  folder_id?: string | null
  asset_ids?: string[] | null
}

export interface CreateAssetPackagePayload {
  name?: string | null
  source_type: AssetShareSourceType
  collection_id?: string | null
  folder_id?: string | null
  asset_ids?: string[] | null
}

export interface AssetSharePackageSummary {
  id: string
  state: AssetPackageState
  progress: number
  file_size: number | null
  asset_count: number | null
  is_stale: boolean
  expires_at: string | null
}

export interface AssetShareResource {
  id: string
  token: string
  name: string
  description: string | null
  source_type: AssetShareSourceType
  collection_id: string | null
  folder_id: string | null
  asset_ids: string[] | null
  package_id: string | null
  package?: AssetSharePackageSummary | null
  has_password: boolean
  expires_at: string | null
  download_limit: number | null
  download_count: number
  view_count: number
  allow_individual_downloads: boolean
  settings: Record<string, unknown> | null
  is_revoked: boolean
  is_expired: boolean
  last_accessed_at: string | null
  revoked_at: string | null
  created_at: string | null
  updated_at: string | null
  created_by?: AssetDistributionCreator | null
}

export interface CreateAssetSharePayload {
  name: string
  description?: string | null
  source_type: AssetShareSourceType
  collection_id?: string | null
  folder_id?: string | null
  asset_ids?: string[] | null
  password?: string | null
  expires_at?: string | null
  download_limit?: number | null
  allow_individual_downloads?: boolean
  settings?: Record<string, unknown> | null
}

export interface UpdateAssetSharePayload {
  name?: string
  description?: string | null
  /** Key absent = keep current password, null/empty = clear, value = set. */
  password?: string | null
  expires_at?: string | null
  download_limit?: number | null
  allow_individual_downloads?: boolean
  settings?: Record<string, unknown> | null
}

export interface DownloadUrlResponse {
  url: string
  expires_at: string | null
}

/** `GET /mgmt/v1/shares/{token}` — locked shares only expose name + protected flag. */
export interface PublicShareResource {
  name: string
  protected: boolean
  unlocked: boolean
  description?: string | null
  settings?: Record<string, unknown> | null
  asset_count?: number | null
  allow_individual_downloads?: boolean
  download_limit?: number | null
  download_count?: number
  expires_at?: string | null
  package_state?: AssetPackageState | null
  package_progress?: number | null
}

export interface PublicShareAssetMetadata {
  type?: string
  width?: number
  height?: number
  dominant_color?: string
  thumbnails?: Array<{ path?: string; full_path?: string; position?: number }>
}

export interface PublicShareAsset {
  id: string
  filename: string
  extension: string
  mime_type: string
  size: number
  metadata: PublicShareAssetMetadata
  /** Share-scoped preview endpoint URL; null for non-image assets. */
  preview_url: string | null
}

export interface PublicShareAssetsResponse {
  data: PublicShareAsset[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface ShareUnlockResponse {
  access_token: string
  expires_at: string
}

/** 202 body while the share package is (re)building or after a failed build. */
export interface ShareDownloadBuildingResponse {
  state: 'building' | 'failed'
  progress: number
}
