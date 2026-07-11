import type { ImportError, ImportExportFormat } from '~/types/import-export'

export type ContentTranslationExportFormat = ImportExportFormat

export type ContentTranslationImportMode = 'draft' | 'publish'

export interface ContentTranslationUnitChange {
  field: string
  label: string
}

export interface ContentTranslationChange {
  content_id: string
  language: string
  name: string | null
  changes: ContentTranslationUnitChange[]
}

export interface ContentTranslationSuccess {
  content_id: string
  language: string
  name: string | null
}

export interface ContentTranslationImportResult {
  successes: ContentTranslationSuccess[]
  changes: ContentTranslationChange[]
  ignored_fields: string[]
  errors: ImportError[]
  deleted: unknown[]
  summary: {
    total_success: number
    total_changes: number
    total_errors: number
    total_deleted: number
  }
}
