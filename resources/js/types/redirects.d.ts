import type { ImportError, ImportExportFormat, ImportSummary } from './import-export'

export interface RedirectResource {
  id: string
  external_id?: string | null
  source: string
  target: string
  status_code: number
  hits: number
  last_used_at: string | null
  created_at: string
  updated_at: string
}

export interface CreateRedirectPayload {
  external_id?: string | null
  source: string
  target: string
  status_code: number
}

export interface UpdateRedirectPayload {
  external_id?: string | null
  source?: string
  target?: string
  status_code?: number
}

export type RedirectImportExportFormat = Extract<ImportExportFormat, 'csv' | 'excel' | 'json' | 'yaml'>

export type RedirectImportMode = 'addition' | 'replacement'

export interface RedirectChange {
  field: string
  old: string | number | null
  new: string | number | null
}

export interface ImportedRedirectChanges {
  id: string
  source: string
  changes: RedirectChange[]
}

export interface RedirectImportSuccess {
  id: string
  source: string
}

export interface DeletedRedirect {
  id: string
  source: string
}

export interface RedirectDataImportResult {
  successes: RedirectImportSuccess[]
  changes: ImportedRedirectChanges[]
  ignored_fields: string[]
  errors: ImportError[]
  deleted: DeletedRedirect[]
  summary: ImportSummary & { total_deleted: number }
}
