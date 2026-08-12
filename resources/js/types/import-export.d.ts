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

/**
 * Shape every feature import result shares, as consumed by the generic
 * `ImportDialog`. `deleted` and `ignored_fields` are optional because not
 * every importer produces them.
 */
export interface ImportDialogResult<TItem, TDeleted = never> {
  changes: TItem[]
  errors: ImportError[]
  ignored_fields?: string[]
  deleted?: TDeleted[]
  summary: ImportSummary & { total_deleted?: number }
}

/** One selectable import strategy tile. All copy is pre-translated. */
export interface ImportDialogMode<TMode extends string = string> {
  value: TMode
  icon: string
  label: string
  description: string
  /** Shown below the tiles while this mode is selected. */
  warning?: string
}

/** Pre-translated copy for the generic `ImportDialog`. */
export interface ImportDialogLabels {
  title: string
  description: string
  formats: string
  selectFileError: string
  fallbackError: string
  submit: string
  modeLabel?: string
  summaryTitle: string
  summaryDescription: string
  summarySuccess: string
  summaryChanges: string
  summaryDeleted?: string
  summaryErrors: string
  changesTitle: string
  deletedTitle?: string
  /** Omitted when the feature should not surface ignored fields at all. */
  ignoredFieldsTitle?: string
  errorsTitle: string
}

/** Pre-translated copy for the generic `ExportDialog`. */
export interface ExportDialogLabels {
  title: string
  description: string
  formatLabel: string
  submit: string
  fallbackError: string
}
