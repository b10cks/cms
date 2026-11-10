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

/** How a unit's translation stands, see `TranslationStatus` on the server. */
export type ContentTranslationUnitState =
  | 'missing'
  | 'empty'
  | 'stale'
  | 'same_as_source'
  | 'current'
  | 'inherited'
  | 'not_in_translation'

export interface ContentTranslationUnitStatus {
  status: ContentTranslationUnitState
  /** For `stale`: the source text the saved translation was written against. */
  previous_source?: string
}

export interface ContentTranslationStatus {
  locale: string
  source_locale: string
  translation_id: string | null
  /** Keyed by unit ID: `title`, `body.<block id>.text`, `seo.title`, `prices.rows.<row id>.label`. */
  units: Record<string, ContentTranslationUnitStatus>
}

export interface ContentTranslationMemoryMatch {
  /** 1 for the same source text, else the share of its words the pair holds. */
  score: number
  source: string
  target: string
  origin: 'human' | 'ai_accepted'
  content_id: string | null
  /** Name of the entry the pair came from, null when it is gone. */
  content_name: string | null
  field_path: string | null
}

/** Plain-text help for translating one unit by hand. */
export interface ContentTranslationAssist {
  unit: string
  type: string
  source: string
  target: string
  memory: ContentTranslationMemoryMatch[]
  locales: Array<{ locale: string; text: string }>
}
