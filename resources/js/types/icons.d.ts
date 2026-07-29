interface IconResource {
  id: string
  external_id: string | null
  key: string
  name: string
  description: string | null
  body: string
  width: number
  height: number
  tags: string[]
  created_at: string
  updated_at: string
}

/**
 * Value stored by the `icon` content field.
 *
 * Always a fully-qualified icon name: `b10cks:{key}` for space registry icons,
 * or `{collection}:{name}` for any public Iconify icon (e.g. `mdi:home`).
 * The SVG body is never stored here — it is fetched from the Iconify-compatible
 * delivery API at render time.
 */
type IconValue = string

interface UploadIconPayload {
  file?: File
  body?: string
  key?: string
  name?: string
  description?: string | null
  tags?: string[]
  external_id?: string | null
  width?: number
  height?: number
}

interface UpdateIconPayload {
  file?: File
  body?: string
  key?: string
  name?: string
  description?: string | null
  tags?: string[]
  external_id?: string | null
  width?: number
  height?: number
}

type IconImportMode = 'addition' | 'replacement'

interface IconImportChange {
  field: string
  old: string | number | string[] | null
  new: string | number | string[] | null
}

interface ImportedIconChanges {
  id: string
  key: string
  changes: IconImportChange[]
}

interface IconImportRef {
  id: string
  key: string
}

interface IconDataImportResult {
  successes: IconImportRef[]
  changes: ImportedIconChanges[]
  ignored_fields: string[]
  errors: import('./import-export').ImportError[]
  deleted: IconImportRef[]
  summary: import('./import-export').ImportSummary & { total_deleted: number }
}
