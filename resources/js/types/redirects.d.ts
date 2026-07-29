interface RedirectResource {
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

interface CreateRedirectPayload {
  external_id?: string | null
  source: string
  target: string
  status_code: number
}

interface UpdateRedirectPayload {
  external_id?: string | null
  source?: string
  target?: string
  status_code?: number
}

type RedirectImportExportFormat = Extract<import('./import-export').ImportExportFormat, 'csv' | 'excel' | 'json' | 'yaml'>

type RedirectImportMode = 'addition' | 'replacement'

interface RedirectChange {
  field: string
  old: string | number | null
  new: string | number | null
}

interface ImportedRedirectChanges {
  id: string
  source: string
  changes: RedirectChange[]
}

interface RedirectImportSuccess {
  id: string
  source: string
}

interface DeletedRedirect {
  id: string
  source: string
}

interface RedirectDataImportResult {
  successes: RedirectImportSuccess[]
  changes: ImportedRedirectChanges[]
  ignored_fields: string[]
  errors: import('./import-export').ImportError[]
  deleted: DeletedRedirect[]
  summary: import('./import-export').ImportSummary & { total_deleted: number }
}
