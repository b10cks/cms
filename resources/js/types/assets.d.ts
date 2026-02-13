interface AssetFolderResource {
  id: string
  name: string
  description: string | null
  icon: string | null
  color: string | null
  parent_id: string | null
  children_count?: number
  assets_count?: number
  created_at: string
  updated_at: string
}

interface AssetResource {
  id: string
  filename: string
  extension: string
  mime_type: string
  size: number
  full_path: string
  folder_id: string | null
  folder?: AssetFolderResource
  metadata: Record<string, unknown>
  data: Record<string, unknown> & { focus?: { x: number; y: number } }
  tags: string[]
  url: string
  created_at: string
  updated_at: string
}

interface AssetValue {
  id: string
  type: 'asset'
  full_path: string
  extension: string
  mime_type: string
  size: number
  filename: string
  data: Record<string, unknown> & { focus?: { x: number; y: number } }
}

interface AssetTagResource {
  id: string
  name: string
  slug: string
  icon: string | null
  color: string | null
  description: string | null
  space_id: string
  assets_count?: number
  assets?: AssetResource[] | number | null
  created_at: string
  updated_at: string
}

interface UpsertAssetFolderPayload {
  name?: string
  description?: string | null
  icon?: string | null
  color?: string | null
  parent_id?: string | null
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
  folder_id?: string | null
  tags?: string[]
  metadata?: Record<string, unknown>
  data?: Record<string, unknown>
}

type AssetTypes = 'image' | 'document' | 'video' | 'audio' | 'other'

interface UploadFile extends UploadAssetPayload {
  id: string
  preview?: string
  type: AssetTypes
}

export type ExportTypes = 'csv' | 'excel' | 'json' | 'xliff' | 'yaml'

export interface AssetChange {
  field: string
  language: string
  old: string | null
  new: string
}

export interface ImportSuccess {
  id: string
  filename: string
}

export interface ImportedAssetChanges {
  id: string
  filename: string
  changes: AssetChange[]
}

export interface ImportError {
  row?: number
  id?: string
  message: string
}

export interface ImportSummary {
  total_success: number
  total_changes: number
  total_errors: number
}

export interface AssetDataImportResult {
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
  errors: ImportError[]

  /**
   * Summary statistics of the import operation
   */
  summary: ImportSummary
}
