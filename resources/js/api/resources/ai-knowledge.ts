import type { AiResolvedSkill, AiScope } from './ai'

import type { AiGlossaryFinding } from '~/lib/aiRun'
import { requestImportStream } from '~/lib/import-export'
import type { ImportDialogResult, ImportProgress } from '~/types/import-export'

import type { ApiClient } from '../client'

/** Skills, knowledge and glossaries live on a space or a team. */
export interface AiOwner {
  kind: 'space' | 'team'
  id: string
}

export interface AiSkillFile {
  path: string
  content: string
}

export interface AiPublishedRevision {
  id: string
  revision: number
  published_at: string | null
}

export interface AiSkillResource {
  id: string
  scope: AiScope
  name: string
  description: string | null
  enabled: boolean
  files: AiSkillFile[]
  published_revision: (AiPublishedRevision & { hash: string }) | null
  has_unpublished_changes: boolean
  updated_at: string | null
}

export interface AiSkillPayload {
  files?: AiSkillFile[]
  enabled?: boolean
}

export type AiKnowledgeType = 'style' | 'reference'

export interface AiKnowledgeDocumentResource {
  id: string
  scope: AiScope
  owner_id: string
  slug: string
  title: string
  type: AiKnowledgeType
  enabled: boolean
  draft?: string
  source_name: string | null
  extraction_status: 'pending' | 'done' | 'failed' | null
  published_revision: AiPublishedRevision | null
  has_unpublished_changes?: boolean
  updated_at: string | null
}

export interface AiKnowledgeDocumentPayload {
  title?: string
  slug?: string
  type?: AiKnowledgeType
  enabled?: boolean
  draft?: string
}

export interface AiGlossaryResource {
  id: string
  scope: AiScope
  owner_id: string
  /** The space or team it belongs to. */
  owner_name?: string | null
  name: string
  enabled: boolean
  /** Rows, one per meaning. */
  terms_count?: number
  updated_at: string | null
}

export interface AiGlossarySheetImportWarning {
  code: 'unused_locales'
  locales: string[]
  message: string
}

/**
 * What a glossary import did. `added`, `updated`, `unchanged` and `warnings`
 * come from a CSV or XLSX in the sheet format. TBX and the older term format count only successes.
 */
export type AiGlossarySheetImportResult = ImportDialogResult<never> & {
  summary: { added?: number; updated?: number; unchanged?: number; total_success: number }
  warnings?: AiGlossarySheetImportWarning[]
}

export type AiGlossaryExportFormat = 'csv' | 'xlsx' | 'tbx'

export interface AiGlossaryConceptRowLanguage {
  /** The wording to write. Null with `avoid` set is a ban in this language. */
  use: string | null
  /** Wording never to write. A `*` at the end covers longer forms. */
  avoid: string[]
  /** How a source may also spell the term. It counts as the term itself. */
  also: string[]
}

/** `fallback`: a base language serves this locale (`de` serves `de-AT`). */
export type AiGlossaryLocaleCoverage = 'own' | 'fallback' | 'missing'

export interface AiGlossaryConceptRow {
  id: string
  key: string | null
  /** The wording stays as written, in exact case. A target language's own `use` still wins. */
  keep_exact: boolean
  /** One per row. At most 200 characters on a write, longer older notes stay readable. */
  note: string | null
  languages: Record<string, AiGlossaryConceptRowLanguage>
  coverage: Record<string, AiGlossaryLocaleCoverage>
}

export interface AiGlossaryAvailableLocale {
  code: string
  default: boolean
  enabled: boolean
  /** Stored, but no enabled language can use it. */
  unused: boolean
}

export interface AiGlossaryFacet {
  value: string
  count: number
}

export interface AiGlossaryConceptRowsMeta extends LaravelMeta {
  read_only: boolean
  default_locale: string | null
  available_locales: AiGlossaryAvailableLocale[]
  /** The whole glossary, whatever the filters. */
  facets: {
    total: number
    locales: AiGlossaryFacet[]
    /** Rows the `missing` filter returns for each locale in use. */
    missing: AiGlossaryFacet[]
  }
}

export interface AiGlossaryConceptRowFilters {
  search?: string
  /** Rows with no term for this locale. A base language counts as a term for its regions. */
  missing?: string
  page?: number
  per_page?: number
}

/** Omitted fields keep their value. A language needs `use` or `avoid`. */
export interface AiGlossaryConceptLanguagePayload {
  use?: string | null
  avoid?: string[] | null
  also?: string[] | null
}

/** Omitted fields and languages keep their value. A language goes only through `remove_languages`. */
export interface AiGlossaryConceptPayload {
  key?: string | null
  keep_exact?: boolean
  note?: string | null
  languages?: Record<string, AiGlossaryConceptLanguagePayload>
  remove_languages?: string[]
  /** The row as it was read. A change to a field that moved since then is refused with `row_changed`. */
  base?: Pick<AiGlossaryConceptRow, 'keep_exact' | 'note' | 'languages'>
}

export interface AiGlossaryConceptBulkPayload {
  action: 'delete'
  row_ids: string[]
}

/** The body of a 409 or 422 from a concept write. Nothing changed. */
export interface AiGlossaryConceptConflict {
  message: string
  code: 'wording_conflict' | 'key_conflict' | 'term_required' | 'use_avoided' | 'last_language' | 'stale_glossary' | 'row_changed' | 'language_conflict' | 'language_invalid' | 'note_too_long'
  locale?: string
  wording?: string
  /** On `use_avoided`: the Avoid entry that covers the wording. */
  avoid?: string
  key?: string
  concept?: { id: string; key: string | null; term: string }
  /** On `row_changed`: each field that moved. */
  changes?: AiGlossaryConceptRowChange[]
}

export interface AiGlossaryConceptRowChange {
  field: 'use' | 'avoid' | 'also' | 'note' | 'keep_exact'
  locale: string | null
}

export interface AiGlossaryCheckPayload {
  text: string
  source_locale: string
  /** `glossary` reads this one glossary. `space` reads what a run in this space uses. */
  scope?: 'glossary' | 'space'
  target_locale?: string
  output?: string
}

export interface AiGlossaryCheckMatch {
  /** UTF-16 code units, so `text.slice(start, end)` is the match. */
  start: number
  end: number
  text: string
}

/** One activated row, with the fields the `lookup_glossary` tool returns. */
export interface AiGlossaryCheckTerm {
  /** The row's wording, or the Avoid wording the text used. */
  term: string
  /** The line the agent reads, as in the prompt. */
  instruction: string
  /** The text used only Avoid wording of this row. */
  avoided?: boolean
  required?: string
  do_not_translate?: boolean
  forbidden?: string[]
  note?: string
  concept_id?: string
  glossary_id: string
  glossary_name: string | null
  row_id: string
  matches: AiGlossaryCheckMatch[]
}

export interface AiGlossaryCheckResult {
  terms: AiGlossaryCheckTerm[]
  /** Null unless `output` was sent. */
  findings: AiGlossaryFinding[] | null
}

export class AiKnowledge {
  private readonly base: string

  constructor(
    private readonly client: ApiClient,
    owner: AiOwner
  ) {
    this.base = `/mgmt/v1/${owner.kind}s/${owner.id}/ai`
  }

  public skills() {
    return this.client.get<{ data: AiSkillResource[]; resolved: AiResolvedSkill[] }>(
      `${this.base}/skills`
    )
  }

  /** Copy an inherited (built-in or team) skill into an editable draft of this owner. */
  public customizeSkill(name: string) {
    return this.client.post<{ data: AiSkillResource }>(`${this.base}/skills/customize`, { name })
  }

  public skill(id: string) {
    return this.client.get<{ data: AiSkillResource }>(`${this.base}/skills/${id}`)
  }

  public createSkill(payload: AiSkillPayload) {
    return this.client.post<{ data: AiSkillResource }>(`${this.base}/skills`, payload)
  }

  public updateSkill(id: string, payload: AiSkillPayload) {
    return this.client.patch<{ data: AiSkillResource }>(`${this.base}/skills/${id}`, payload)
  }

  public publishSkill(id: string) {
    return this.client.post<{ data: AiSkillResource }>(`${this.base}/skills/${id}/publish`)
  }

  public deleteSkill(id: string) {
    return this.client.delete(`${this.base}/skills/${id}`)
  }

  /** With `inherited`, team layers above the owner are listed too (read-only here). */
  public documents(inherited = false) {
    return this.client.get<{ data: AiKnowledgeDocumentResource[] }>(`${this.base}/knowledge`, {
      inherited: inherited ? 1 : undefined,
    })
  }

  public document(id: string) {
    return this.client.get<{ data: AiKnowledgeDocumentResource }>(`${this.base}/knowledge/${id}`)
  }

  public createDocument(payload: AiKnowledgeDocumentPayload) {
    return this.client.post<{ data: AiKnowledgeDocumentResource }>(`${this.base}/knowledge`, payload)
  }

  public updateDocument(id: string, payload: AiKnowledgeDocumentPayload) {
    return this.client.patch<{ data: AiKnowledgeDocumentResource }>(
      `${this.base}/knowledge/${id}`,
      payload
    )
  }

  public publishDocument(id: string) {
    return this.client.post<{ data: AiKnowledgeDocumentResource }>(
      `${this.base}/knowledge/${id}/publish`
    )
  }

  public uploadDocumentSource(id: string, file: File) {
    const form = new FormData()
    form.append('file', file)

    return this.client.post<{ data: AiKnowledgeDocumentResource }>(
      `${this.base}/knowledge/${id}/source`,
      form
    )
  }

  public deleteDocument(id: string) {
    return this.client.delete(`${this.base}/knowledge/${id}`)
  }

  public glossaries(inherited = false) {
    return this.client.get<{ data: AiGlossaryResource[] }>(`${this.base}/glossaries`, {
      inherited: inherited ? 1 : undefined,
    })
  }

  public createGlossary(payload: { name: string; enabled?: boolean }) {
    return this.client.post<{ data: AiGlossaryResource }>(`${this.base}/glossaries`, payload)
  }

  public updateGlossary(id: string, payload: { name?: string; enabled?: boolean }) {
    return this.client.patch<{ data: AiGlossaryResource }>(`${this.base}/glossaries/${id}`, payload)
  }

  public deleteGlossary(id: string) {
    return this.client.delete(`${this.base}/glossaries/${id}`)
  }

  public conceptRows(glossaryId: string, filters: AiGlossaryConceptRowFilters) {
    return this.client.get<{ data: AiGlossaryConceptRow[]; meta: AiGlossaryConceptRowsMeta }>(
      `${this.base}/glossaries/${glossaryId}/concept-rows`,
      { ...filters }
    )
  }

  public conceptRow(glossaryId: string, rowId: string) {
    return this.client.get<{ data: AiGlossaryConceptRow }>(
      `${this.base}/glossaries/${glossaryId}/concept-rows/${encodeURIComponent(rowId)}`
    )
  }

  public createConceptRow(glossaryId: string, payload: AiGlossaryConceptPayload) {
    return this.client.post<{ data: AiGlossaryConceptRow }>(
      `${this.base}/glossaries/${glossaryId}/concept-rows`,
      payload
    )
  }

  /** The returned row's id differs when a term gets its first concept. */
  public updateConceptRow(glossaryId: string, rowId: string, payload: AiGlossaryConceptPayload) {
    return this.client.patch<{ data: AiGlossaryConceptRow }>(
      `${this.base}/glossaries/${glossaryId}/concept-rows/${encodeURIComponent(rowId)}`,
      payload
    )
  }

  public deleteConceptRow(glossaryId: string, rowId: string) {
    return this.client.delete(`${this.base}/glossaries/${glossaryId}/concept-rows/${encodeURIComponent(rowId)}`)
  }

  public bulkConceptRows(glossaryId: string, payload: AiGlossaryConceptBulkPayload) {
    return this.client.post<{ data: { deleted: number } }>(
      `${this.base}/glossaries/${glossaryId}/concept-rows/bulk`,
      payload
    )
  }

  public checkGlossaryText(glossaryId: string, payload: AiGlossaryCheckPayload) {
    return this.client.post<{ data: AiGlossaryCheckResult }>(
      `${this.base}/glossaries/${glossaryId}/glossary-check`,
      payload
    )
  }

  public importTerms(
    glossaryId: string,
    file: File,
    onProgress?: (progress: ImportProgress) => void
  ) {
    return requestImportStream<AiGlossarySheetImportResult>({
      client: this.client,
      endpoint: `${this.base}/glossaries/${glossaryId}/import`,
      file,
      onProgress,
    })
  }

  public exportUrl(glossaryId: string, format: AiGlossaryExportFormat, { template = false } = {}) {
    return `${this.client.getBaseUrl()}${this.base}/glossaries/${glossaryId}/export?format=${format}${template ? '&template=1' : ''}`
  }
}
