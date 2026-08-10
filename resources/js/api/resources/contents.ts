import { requestExportBlob, requestImportJson } from '~/lib/import-export'
import type {
  ContentResource,
  ContentSerialPreview,
  ContentTreeOperationPayload,
  ContentTreeOperationResult,
  CreateContentPayload,
  UpdateContentPayload,
} from '~/types/contents'
import type {
  ContentTranslationExportFormat,
  ContentTranslationImportMode,
  ContentTranslationImportResult,
} from '~/types/content-translations'

type ForceableContentPayload = UpdateContentPayload & {
  force?: boolean
}

import type { ApiClient } from '../client'
// src/api/resources/contents.ts
import { BaseResource } from './base-resource'

/**
 * The management content filters are read off the top level of the query
 * string (`ContentFilter::fromRequest()` dispatches on `$request->all()`), so
 * there is no `filter` wrapper — a nested one is silently ignored.
 * `parent_id` takes the literal string `null` to mean "root level".
 */
export interface ContentsQueryParams extends BaseQueryParams {
  parent_id?: string | 'null'
  block_id?: string
  published?: boolean
}

export class Contents extends BaseResource<
  ContentResource,
  CreateContentPayload & { force?: boolean },
  ForceableContentPayload,
  ContentsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/contents`
  }

  /**
   * Publish a content item
   */
  public async publish(
    contentId: string,
    payload: ForceableContentPayload
  ): Promise<ApiResponse<ContentResource>> {
    return this.client.post<ApiResponse<ContentResource>>(
      `${this.basePath}/${contentId}/publish`,
      payload
    )
  }

  /**
   * Create a content item and publish it in one request.
   *
   * For entries that do not exist server side yet — a language version the
   * editor has only drafted locally — so publishing them needs no prior save.
   */
  public async createAndPublish(
    payload: ForceableContentPayload
  ): Promise<ApiResponse<ContentResource>> {
    return this.client.post<ApiResponse<ContentResource>>(`${this.basePath}/publish`, payload)
  }

  /**
   * Schedule a content item for publishing
   */
  public async schedule(
    contentId: string,
    payload: ForceableContentPayload
  ): Promise<ApiResponse<ContentResource>> {
    return this.client.post<ApiResponse<ContentResource>>(
      `${this.basePath}/${contentId}/schedule`,
      payload
    )
  }

  /**
   * Unpublish a content item
   */
  public async unpublish(
    contentId: string,
    payload: ForceableContentPayload
  ): Promise<ApiResponse<ContentResource>> {
    return this.client.post<ApiResponse<ContentResource>>(
      `${this.basePath}/${contentId}/unpublish`,
      payload
    )
  }

  /**
   * Duplicate a content item
   */
  public async duplicate(
    contentId: string,
    payload?: {
      name?: string
      parent_id?: string | null
    }
  ): Promise<ApiResponse<ContentResource>> {
    return this.client.post<ApiResponse<ContentResource>>(
      `${this.basePath}/${contentId}/duplicate`,
      payload
    )
  }

  /**
   * Get the preview URL for a content item
   */
  public getPreviewUrl(
    contentId: string,
    options?: {
      lang?: string
      env?: string
    }
  ): string {
    const queryParams = new URLSearchParams()

    if (options?.lang) {
      queryParams.append('lang', options.lang)
    }

    if (options?.env) {
      queryParams.append('env', options.env)
    }

    const queryString = queryParams.toString()
    return queryString
      ? `${this.basePath}/${contentId}/preview?${queryString}`
      : `${this.basePath}/${contentId}/preview`
  }

  /**
   * Bulk create multiple content items
   */
  public async bulkCreate(payload: {
    items: Array<{
      name: string
      slug: string
      block_id: string
      parent_id?: string | null
      temp_id?: string
    }>
  }): Promise<
    ApiResponse<
      Array<{ temp_id?: string; id: string; name: string; slug: string; parent_id: string | null }>
    >
  > {
    return this.client.post<
      ApiResponse<
        Array<{
          temp_id?: string
          id: string
          name: string
          slug: string
          parent_id: string | null
        }>
      >
    >(`${this.basePath}/bulk-create`, payload)
  }

  /**
   * Move a content item to a new parent
   */
  public async move(
    contentId: string,
    payload: {
      parent_id?: string | null
      position?: number
    }
  ): Promise<ApiResponse<ContentResource>> {
    return this.client.post<ApiResponse<ContentResource>>(
      `${this.basePath}/${contentId}/move`,
      payload
    )
  }

  public async treeOperations(payload: {
    operations: ContentTreeOperationPayload[]
  }): Promise<ApiResponse<ContentTreeOperationResult>> {
    return this.client.post<ApiResponse<ContentTreeOperationResult>>(
      `${this.basePath}/tree-operations`,
      payload
    )
  }

  /**
   * The values a new entry would receive for its generated fields.
   *
   * A peek, not a reservation — the number is only taken when the entry is
   * actually created, so this can go stale if someone else creates one first.
   */
  public async serialPreview(payload: {
    block_id: string
    parent_id?: string | null
    language_iso?: string | null
    name?: string | null
    i18n_parent_id?: string | null
    except_content_id?: string | null
  }): Promise<ContentSerialPreview> {
    return this.client.post<ContentSerialPreview>(`${this.basePath}/serial-preview`, payload)
  }

  /**
   * Export content translations in the given format (optionally filtered).
   */
  public async exportTranslations(
    params: { as: ContentTranslationExportFormat } & Record<string, unknown>
  ): Promise<Blob> {
    return requestExportBlob({
      client: this.client,
      endpoint: `${this.basePath}/export`,
      payload: params,
    })
  }

  /**
   * Import content translations from a file, as a draft or published.
   */
  public async importTranslations(
    file: File,
    options: { mode: ContentTranslationImportMode; createMissing: boolean }
  ): Promise<ContentTranslationImportResult> {
    const data = await requestImportJson<
      ContentTranslationImportResult | { data: ContentTranslationImportResult }
    >({
      client: this.client,
      endpoint: `${this.basePath}/import`,
      file,
      extraFields: {
        import_mode: options.mode,
        create_missing: options.createMissing ? '1' : '0',
      },
    })

    return 'data' in data ? data.data : data
  }
}
