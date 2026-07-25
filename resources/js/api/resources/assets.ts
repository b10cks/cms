import { getXsrfHeaders } from '~/lib/csrf'
import { requestExportBlob, requestImportJson } from '~/lib/import-export'
import type { ApiCollectionResponse, ApiResponse, BaseQueryParams } from '~/types'
import type {
  AssetDataImportResult,
  AssetResource,
  AssetRightsStatus,
  ExportTypes,
  LinkedAssetContentResource,
  UpdateAssetPayload,
  UploadAssetPayload,
} from '~/types/assets'

import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface AssetsQueryParams extends BaseQueryParams {
  q?: string
  tags?: string | string[]
  extension?: string
  folder?: string
  filename?: string
  created_at?: string
  updated_at?: string
  mime_type?: string
  rights_status?: AssetRightsStatus | AssetRightsStatus[]
  expiring_before?: string
}

export interface LinkedAssetContentsQueryParams extends BaseQueryParams {}

export class Assets extends BaseResource<
  AssetResource,
  UploadAssetPayload,
  UpdateAssetPayload,
  AssetsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/assets`
  }

  public async upload(
    payload: UploadAssetPayload,
    onProgress?: (progress: number) => void
  ): Promise<unknown> {
    const formData = new FormData()
    formData.append('file', payload.file)

    if (payload.folder_id) {
      formData.append('folder_id', payload.folder_id)
    }
    if (payload.tags && payload.tags.length > 0) {
      formData.append('tags', JSON.stringify(payload.tags))
    }
    if (payload.metadata) {
      formData.append('metadata', JSON.stringify(payload.metadata))
    }
    if (payload.data) {
      formData.append('data', JSON.stringify(payload.data))
    }

    // If progress tracking is needed, use XMLHttpRequest
    if (onProgress && typeof window !== 'undefined') {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest()

        xhr.upload.addEventListener('progress', (event) => {
          if (event.lengthComputable && onProgress) {
            const percentComplete = Math.round((event.loaded / event.total) * 100)
            onProgress(percentComplete)
          }
        })
        xhr.addEventListener('load', () => {
          if (xhr.status >= 200 && xhr.status < 300) {
            try {
              const response = JSON.parse(xhr.responseText)
              resolve(response)
            } catch {
              reject(new Error('Failed to parse server response'))
            }
          } else {
            reject(new Error(`Upload failed with status ${xhr.status}: ${xhr.statusText}`))
          }
        })
        xhr.addEventListener('error', () => {
          reject(new Error('Network error occurred during upload'))
        })
        xhr.addEventListener('abort', () => {
          reject(new Error('Upload was aborted'))
        })

        xhr.open('POST', this.basePath)
        xhr.withCredentials = true
        const xsrfHeaders = getXsrfHeaders()
        Object.entries(xsrfHeaders).forEach(([key, value]) => {
          xhr.setRequestHeader(key, value)
        })

        xhr.send(formData)
      })
    }

    return this.client.post<ApiResponse<AssetResource>>(this.basePath, formData)
  }

  public async replaceFile(
    assetId: string,
    file: File,
    onProgress?: (progress: number) => void
  ): Promise<AssetResource | null> {
    const formData = new FormData()
    formData.append('file', file)

    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest()

      if (onProgress) {
        xhr.upload.addEventListener('progress', (event) => {
          if (event.lengthComputable) {
            onProgress(Math.round((event.loaded / event.total) * 100))
          }
        })
      }

      xhr.addEventListener('load', () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const response = JSON.parse(xhr.responseText)
            resolve(response.data ?? null)
          } catch {
            reject(new Error('Failed to parse server response'))
          }
        } else {
          reject(new Error(`Replace failed with status ${xhr.status}: ${xhr.statusText}`))
        }
      })
      xhr.addEventListener('error', () => reject(new Error('Network error during file replace')))
      xhr.addEventListener('abort', () => reject(new Error('File replace was aborted')))

      xhr.open('POST', `${this.basePath}/${assetId}/replace-file`)
      xhr.withCredentials = true
      Object.entries(getXsrfHeaders()).forEach(([k, v]) => xhr.setRequestHeader(k, v))
      xhr.send(formData)
    })
  }

  /**
   * Replace the auto-generated video thumbnails with a hand-picked poster.
   */
  public async uploadPoster(assetId: string, file: File): Promise<ApiResponse<AssetResource>> {
    const formData = new FormData()
    formData.append('poster', file)

    return this.client.post<ApiResponse<AssetResource>>(
      `${this.basePath}/${assetId}/poster`,
      formData
    )
  }

  public async getLinkedContents(
    assetId: string,
    query: LinkedAssetContentsQueryParams = {}
  ): Promise<ApiCollectionResponse<LinkedAssetContentResource>> {
    return this.client.get<ApiCollectionResponse<LinkedAssetContentResource>>(
      `${this.basePath}/${assetId}/linked-contents`,
      query as Record<string, unknown>
    )
  }

  public async delete(id: string, options: { force?: boolean } = {}): Promise<void> {
    return this.client.delete(`${this.basePath}/${id}`, {
      query: options.force ? { force: 1 } : {},
    })
  }

  /**
   * Export assets metadata to a file format
   * @param params Query parameters including filters and format
   * @returns Blob of the exported data
   */
  public async export(params: AssetsQueryParams & { as: ExportTypes }): Promise<Blob> {
    return requestExportBlob({
      client: this.client,
      endpoint: `${this.basePath}/export`,
      payload: params,
    })
  }

  /**
   * Import assets metadata from a file
   * @param file The file containing asset data to import
   * @returns Import result with successes, changes, and errors
   */
  public async importData(file: File): Promise<AssetDataImportResult> {
    const data = await requestImportJson<AssetDataImportResult | { data: AssetDataImportResult }>({
      client: this.client,
      endpoint: `${this.basePath}/import`,
      file,
    })

    return 'data' in data ? data.data : data
  }
}
