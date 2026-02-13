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
