export type ImportExportFormat = 'csv' | 'excel' | 'json' | 'xliff' | 'yaml'

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
