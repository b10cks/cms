import { requestImportJson } from '~/lib/import-export'
import { xhrUpload } from '~/lib/xhr-upload'

import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface IconsQueryParams extends BaseQueryParams {
  q?: string
  key?: string
  tags?: string | string[]
  external_id?: string
  created_at?: string
  updated_at?: string
}

export class Icons extends BaseResource<
  IconResource,
  UploadIconPayload,
  UpdateIconPayload,
  IconsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/icons`
  }

  /**
   * Upload (create) a single icon from an SVG file, optionally tracking progress.
   */
  public async upload(
    payload: UploadIconPayload,
    onProgress?: (progress: number) => void
  ): Promise<ApiResponse<IconResource>> {
    const formData = new FormData()

    if (payload.file) {
      formData.append('file', payload.file)
    }
    if (payload.body !== undefined) {
      formData.append('body', payload.body)
    }
    if (payload.key) {
      formData.append('key', payload.key)
    }
    if (payload.name) {
      formData.append('name', payload.name)
    }
    if (payload.description) {
      formData.append('description', payload.description)
    }
    if (payload.external_id) {
      formData.append('external_id', payload.external_id)
    }
    if (payload.tags) {
      formData.append('tags', JSON.stringify(payload.tags))
    }

    if (onProgress && typeof window !== 'undefined') {
      return xhrUpload<ApiResponse<IconResource>>(this.basePath, formData, { onProgress })
    }

    return this.client.post<ApiResponse<IconResource>>(this.basePath, formData)
  }

  /**
   * Fetch the distinct list of tags used across the space's icons.
   */
  public async tags(): Promise<{ data: string[] }> {
    return this.client.get<{ data: string[] }>(`${this.basePath}/tags`)
  }

  /**
   * Import an Iconify JSON icon set into the space.
   */
  public async importData(
    file: File,
    mode: IconImportMode = 'addition'
  ): Promise<IconDataImportResult> {
    return requestImportJson<IconDataImportResult>({
      client: this.client,
      endpoint: `${this.basePath}/import`,
      file,
      extraFields: { import_mode: mode },
    })
  }
}
