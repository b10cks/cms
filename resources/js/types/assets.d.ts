interface AssetFieldOverride {
  key: string
  enabled?: boolean | null
  required?: boolean | null
}

interface AssetFolderSettings {
  field_overrides?: AssetFieldOverride[]
  additional_fields?: SpaceAssetField[]
}

interface AssetFolderFieldState extends SpaceAssetField {
  enabled: boolean
  custom?: boolean
  inherited?: boolean
  source?: 'space' | 'folder'
}

interface AssetFolderResource {
  id: string
  name: string
  description: string | null
  icon: string | null
  color: string | null
  parent_id: string | null
  settings: AssetFolderSettings
  effective_asset_fields?: SpaceAssetField[]
  children_count?: number
  assets_count?: number
  created_at: string
  updated_at: string
}

interface AssetVideoThumbnail {
  path: string
  full_path: string
  position: number
  position_formatted: string
  dominant_color?: string
  /** Set when the frame was uploaded by an editor rather than generated */
  custom?: boolean
}

interface AssetColorA11y {
  /** 'dark' = treat the image as a dark surface (use light overlays/text) */
  scheme: 'dark' | 'light'
  /** WCAG relative luminance of the dominant color (0–1) */
  luminance: number
  /** Contrast ratio of white text against the dominant color */
  contrast_white: number
  /** Contrast ratio of black text against the dominant color */
  contrast_black: number
}

interface AssetMediaMetadata {
  width?: number
  height?: number
  thumbnails?: AssetVideoThumbnail[]
  /** Generated frames stashed while a custom poster is active (paths only) */
  generated_thumbnails?: Omit<AssetVideoThumbnail, 'full_path'>[]
  duration?: number
  dominant_color?: string
  palette?: string[]
  animated?: boolean
  a11y?: AssetColorA11y
}

interface AssetResource {
  id: string
  filename: string
  extension: string
  mime_type: string
  size: number
  checksum: string | null
  full_path: string
  folder_id: string | null
  folder?: AssetFolderResource
  metadata: Record<string, unknown> & AssetMediaMetadata
  data: Record<string, unknown> & { focus?: { x: number; y: number } }
  tags: string[]
  license_expires_at: string | null
  rights_status: AssetRightsStatus
  linked_contents_count: number
  effective_asset_fields?: SpaceAssetField[]
  url: string
  /** Delivery URL for the poster frame; null when the asset has none */
  poster_url: string | null
  created_at: string
  updated_at: string
}

type AssetRightsStatus = 'unrestricted' | 'restricted' | 'expired'

interface AssetVersionResource {
  id: string
  external_id: string | null
  asset_id: string
  version_number: number
  filename: string
  extension: string
  mime_type: string
  size: number
  checksum: string | null
  full_path: string | null
  metadata: Record<string, unknown> & AssetMediaMetadata
  created_by?: { id: string; name: string } | null
  created_at: string | null
}

interface AssetUploadDuplicate {
  code: 'duplicate_asset'
  message: string
  existing_asset: AssetResource
}

interface LinkedAssetContentResource {
  id: string
  name: string | null
  full_slug: string
  language_iso: string
  published_at: string | null
  updated_at: string
  block: {
    id: string
    name: string
    icon: string
    color: string | null
    slug: string
  } | null
  usage: {
    current: boolean
    published: boolean
  }
}

interface AssetDeleteConflict {
  message: string
  code: 'asset_in_use'
  linked_contents_count: number
  can_force_delete: boolean
}

interface AssetValue {
  id: string
  type: 'asset'
  full_path: string
  extension: string
  mime_type: string
  size: number
  filename: string
  metadata?: AssetMediaMetadata
  data: Record<string, unknown> & { focus?: { x: number; y: number } }
}

interface AssetTagResource {
  id: string
  external_id: string | null
  name: string
  icon: string | null
  color: string | null
  assets_count?: number
  created_at: string
  updated_at: string
}

type AssetCollectionType = 'manual' | 'smart'

type AssetCollectionMatch = 'all' | 'any'

type AssetCollectionCondition =
  | { field: 'filename'; operator: 'contains' | 'equals'; value: string }
  | { field: 'extension'; operator: 'equals'; value: string }
  | { field: 'extension'; operator: 'in'; value: string[] }
  | { field: 'mime_type'; operator: 'equals' | 'prefix'; value: string }
  | { field: 'mime_type'; operator: 'in'; value: string[] }
  | { field: 'size'; operator: 'gt' | 'lt' | 'gte' | 'lte'; value: number }
  | { field: 'folder'; operator: 'equals'; value: string }
  | { field: 'folder'; operator: 'null'; value?: null }
  | { field: 'tags'; operator: 'any' | 'all'; value: string[] }
  | { field: 'rights_status'; operator: 'equals'; value: AssetRightsStatus }
  | { field: 'license_expires_at'; operator: 'before' | 'after'; value: string }
  | { field: 'created_at'; operator: 'before' | 'after'; value: string }
  | { field: 'updated_at'; operator: 'before' | 'after'; value: string }
  | { field: 'orientation'; operator: 'equals'; value: 'landscape' | 'portrait' | 'square' }
  | { field: 'untagged'; operator: 'equals'; value: true }

type AssetCollectionConditionField = AssetCollectionCondition['field']

interface AssetCollectionRules {
  match: AssetCollectionMatch
  conditions: AssetCollectionCondition[]
}

interface AssetCollectionResource {
  id: string
  external_id: string | null
  name: string
  description: string | null
  icon: string | null
  color: string | null
  type: AssetCollectionType
  rules: AssetCollectionRules | null
  settings: Record<string, unknown> | null
  cover_asset_id: string | null
  assets_count?: number | null
  created_by_id: string | null
  created_at: string
  updated_at: string
}

interface CreateAssetCollectionPayload {
  name: string
  description?: string | null
  icon?: string | null
  color?: string | null
  type: AssetCollectionType
  rules?: AssetCollectionRules | null
  settings?: Record<string, unknown> | null
  cover_asset_id?: string | null
}

interface UpdateAssetCollectionPayload {
  name?: string
  description?: string | null
  icon?: string | null
  color?: string | null
  rules?: AssetCollectionRules | null
  settings?: Record<string, unknown> | null
  cover_asset_id?: string | null
}

interface UpsertAssetFolderPayload {
  name?: string
  description?: string | null
  icon?: string | null
  color?: string | null
  parent_id?: string | null
  settings?: AssetFolderSettings
}

interface UpsertAssetTagPayload {
  name: string
  icon?: string | null
  color?: string | null
}

interface UploadAssetPayload {
  file: File
  folder_id?: string | null
  tags?: string[]
  metadata?: Record<string, unknown>
  data?: Record<string, unknown>
}

interface UpdateAssetPayload {
  filename?: string
  folder_id?: string | null
  tags?: string[]
  metadata?: Record<string, unknown>
  data?: Record<string, unknown>
  license_expires_at?: string | null
  rights_status?: AssetRightsStatus
}

type AssetTypes = 'image' | 'document' | 'video' | 'audio' | 'other'

interface UploadFile extends UploadAssetPayload {
  id: string
  preview?: string
  type: AssetTypes
}

type ExportTypes = import('./import-export').ImportExportFormat

interface AssetChange {
  field: string
  language: string
  old: string | null
  new: string
}

interface ImportSuccess {
  id: string
  filename: string
}

interface ImportedAssetChanges {
  id: string
  filename: string
  changes: AssetChange[]
}

interface AssetDataImportResult {
  /**
   * List of successfully imported assets (unchanged or changed)
   */
  successes: ImportSuccess[]

  /**
   * List of assets with their modifications
   */
  changes: ImportedAssetChanges[]

  /**
   * Fields and language combinations that were ignored
   * (not configured in space.settings.asset_fields or languages)
   */
  ignored_fields: string[]

  /**
   * Errors encountered during import
   * Import continues on errors (non-blocking)
   */
  errors: import('./import-export').ImportError[]

  /**
   * Summary statistics of the import operation
   */
  summary: import('./import-export').ImportSummary
}
