import type { AiResolvedSkill, AiScope } from './ai'

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
  name: string
  enabled: boolean
  terms_count?: number
  updated_at: string | null
}

export interface AiGlossaryTermResource {
  id: string
  source_locale: string
  term: string
  translations: Record<string, string>
  case_sensitive: boolean
  do_not_translate: boolean
  forbidden: string[]
  note: string | null
}

export type AiGlossaryTermPayload = Partial<Omit<AiGlossaryTermResource, 'id'>>

export interface AiImportSummary {
  summary: { total_success: number; total_errors: number }
  errors: Array<{ row: number; message: string }>
}

export type AiGlossaryExportFormat = 'csv' | 'xlsx' | 'tbx'

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

  public documents() {
    return this.client.get<{ data: AiKnowledgeDocumentResource[] }>(`${this.base}/knowledge`)
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

  public glossaries() {
    return this.client.get<{ data: AiGlossaryResource[] }>(`${this.base}/glossaries`)
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

  public terms(glossaryId: string, search: string) {
    return this.client.get<{ data: AiGlossaryTermResource[] }>(
      `${this.base}/glossaries/${glossaryId}/terms`,
      { search: search || undefined, per_page: 200 }
    )
  }

  public createTerm(glossaryId: string, payload: AiGlossaryTermPayload) {
    return this.client.post<{ data: AiGlossaryTermResource }>(
      `${this.base}/glossaries/${glossaryId}/terms`,
      payload
    )
  }

  public updateTerm(glossaryId: string, termId: string, payload: AiGlossaryTermPayload) {
    return this.client.patch<{ data: AiGlossaryTermResource }>(
      `${this.base}/glossaries/${glossaryId}/terms/${termId}`,
      payload
    )
  }

  public deleteTerm(glossaryId: string, termId: string) {
    return this.client.delete(`${this.base}/glossaries/${glossaryId}/terms/${termId}`)
  }

  public importTerms(glossaryId: string, file: File) {
    const form = new FormData()
    form.append('file', file)

    return this.client.post<{ data: AiImportSummary }>(
      `${this.base}/glossaries/${glossaryId}/import`,
      form
    )
  }

  public exportUrl(glossaryId: string, format: AiGlossaryExportFormat) {
    return `${this.client.getBaseUrl()}${this.base}/glossaries/${glossaryId}/export?format=${format}`
  }
}
