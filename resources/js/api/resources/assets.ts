import type { ApiResponse, BaseQueryParams } from '~/types'
import type { ExportTypes } from '~/types/assets'

import { getXsrfHeaders } from '~/lib/csrf'

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
}

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

    return this.client.post<ApiResponse<AssetResource>>(this.basePath, formData, {
      headers: {
        // Remove Content-Type header so browser can set it with boundary
        'Content-Type': undefined,
      },
    })
  }

  /**
   * Export assets metadata to a file format
   * @param params Query parameters including filters and format
   * @returns Blob of the exported data
   */
  public async export(params: AssetsQueryParams & { as: ExportTypes }): Promise<Blob> {
    // Use native fetch for proper blob handling
    if (typeof window === 'undefined') {
      throw new Error('Export is only available in the browser')
    }

    const authHeaders = this.client.getAuthHeaders()
    const baseURL = (this.client as any).baseURL || ''
    const url = `${baseURL}${this.basePath}/export`

    await this.client.ensureCsrfCookie()
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        ...authHeaders,
        ...getXsrfHeaders(),
        'Content-Type': 'application/json',
      },
      credentials: 'include',
      body: JSON.stringify(params),
    })

    if (!response.ok) {
      throw new Error(`Export failed with status ${response.status}: ${response.statusText}`)
    }

    return await response.blob()
  }

  /**
   * Import assets metadata from a file
   * @param file The file containing asset data to import
   * @returns Import result with successes, changes, and errors
   */
  public async import(file: File): Promise<AssetDataImportResult> {
    // Use native fetch for proper FormData handling with Laravel/PHP backend
    if (typeof window === 'undefined') {
      throw new Error('Import is only available in the browser')
    }

    const formData = new FormData()
    formData.append('file', file)

    const authHeaders = this.client.getAuthHeaders()
    const baseURL = (this.client as any).baseURL || ''
    const url = `${baseURL}${this.basePath}/import`

    await this.client.ensureCsrfCookie()
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        ...authHeaders,
        ...getXsrfHeaders(),
      },
      credentials: 'include',
      body: formData,
    })

    if (!response.ok) {
      throw new Error(`Import failed with status ${response.status}: ${response.statusText}`)
    }

    const data = await response.json()
    return data.data || data
  }
}
