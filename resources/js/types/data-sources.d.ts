export interface DataSourceSettings {
  dimensions_translatable?: boolean
  default_dimension_locale?: string
  cache_ttl?: number | null
}

export interface DataSourceDimension {
  key: string
  label: string
}

export interface DataSourceResource {
  id: string
  name: string
  slug: string
  description: string | null
  dimensions: DataSourceDimension[]
  settings: DataSourceSettings
  is_active: boolean
  entries_count?: number
  created_at: string
  updated_at: string
}

export interface CreateDataSourcePayload {
  name: string
  slug: string
  description?: string | null
  dimensions: DataSourceDimension[]
  settings?: DataSourceSettings
  is_active?: boolean
}

export interface UpdateDataSourcePayload {
  name?: string
  slug?: string
  description?: string | null
  dimensions?: DataSourceDimension[]
  settings?: DataSourceSettings
  is_active?: boolean
}

export interface DataEntryResource {
  id: string
  key: string
  value: string | null
  dimensions: Record<string, string | null>
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface CreateDataEntryPayload {
  key: string
  value?: string | null
  dimensions?: Record<string, string | null>
  is_active?: boolean
}

export interface UpdateDataEntryPayload {
  key?: string
  value?: string | null
  dimensions?: Record<string, string | null>
  is_active?: boolean
}

export interface DataEntryQueryParams {
  key?: string
  dimensions?: Record<string, string>
  is_active?: boolean
  sort?: string
  page?: number
  per_page?: number
}

export type DataEntryImportExportFormat = 'csv' | 'excel' | 'json' | 'yaml'

export type DataEntryImportMode = 'addition' | 'replacement'

export interface DataEntryChange {
  field: string
  old: unknown
  new: unknown
}

export interface DataEntryImportResult {
  successes: Array<{ id: string; key: string }>
  changes: Array<{ id: string; key: string; changes: DataEntryChange[] }>
  ignored_fields: string[]
  errors: Array<{ row?: number; id?: string; message: string }>
  deleted: Array<{ id: string; key: string }>
  summary: {
    total_success: number
    total_changes: number
    total_errors: number
    total_deleted: number
  }
}
