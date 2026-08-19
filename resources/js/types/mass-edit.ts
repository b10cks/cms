import type { ContentTranslationImportResult } from '~/types/content-translations'

export interface MassEditFieldBlock {
  id: string
  name: string
  slug: string
}

export interface MassEditField {
  key: string
  type: string
  label: string
  translatable: boolean
  blocks: MassEditFieldBlock[]
}

export interface MassEditUnit {
  id: string
  field: string
  type: string
  label: string
  note: string
  source: string
  targets: Record<string, string>
  translatable: boolean
}

export interface MassEditDocument {
  content_id: string
  name: string
  slug: string
  full_slug: string
  source_language: string
  languages: string[]
  units: MassEditUnit[]
}

export interface MassEditRowsResponse {
  data: MassEditDocument[]
  meta: LaravelMeta
}

export interface MassEditRowsParams {
  fields: string
  languages?: string
  block_id?: string
  name?: string
  page?: number
  per_page?: number
}

export interface MassEditSaveDocument {
  content_id: string
  targets: Record<string, Record<string, string>>
}

export interface MassEditSavePayload {
  documents: MassEditSaveDocument[]
  mode?: 'draft' | 'publish'
  create_missing?: boolean
}

/**
 * The applier reports failures per content, and per language when it got that far.
 * Wider than the shared `ImportError`, which only carries a generic `id`.
 */
export interface MassEditSaveError {
  content_id?: string
  language?: string
  id?: string
  message: string
}

export interface MassEditSaveResult extends Omit<ContentTranslationImportResult, 'errors'> {
  errors: MassEditSaveError[]
}
