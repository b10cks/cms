import { getXsrfHeaders } from '~/lib/csrf'
import type { ApiResponse, BaseQueryParams } from '~/types'
import type { IconResource, UpdateIconPayload, UploadIconPayload } from '~/types/icons'

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
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest()

        xhr.upload.addEventListener('progress', (event) => {
          if (event.lengthComputable && onProgress) {
            onProgress(Math.round((event.loaded / event.total) * 100))
          }
        })
        xhr.addEventListener('load', () => {
          if (xhr.status >= 200 && xhr.status < 300) {
            try {
              resolve(JSON.parse(xhr.responseText))
            } catch {
              reject(new Error('Failed to parse server response'))
            }
          } else {
            let message = `Upload failed with status ${xhr.status}`
            try {
              const body = JSON.parse(xhr.responseText)
              if (body?.message) {
                message = body.message
              } else if (body?.errors) {
                message = Object.values(body.errors).flat().join(' ')
              }
            } catch {
              // keep the default message
            }
            reject(new Error(message))
          }
        })
        xhr.addEventListener('error', () => reject(new Error('Network error occurred during upload')))
        xhr.addEventListener('abort', () => reject(new Error('Upload was aborted')))

        xhr.open('POST', this.basePath)
        xhr.withCredentials = true
        Object.entries(getXsrfHeaders()).forEach(([key, value]) => {
          xhr.setRequestHeader(key, value)
        })

        xhr.send(formData)
      })
    }

    return this.client.post<ApiResponse<IconResource>>(this.basePath, formData)
  }

  /**
   * Fetch the distinct list of tags used across the space's icons.
   */
  public async tags(): Promise<{ data: string[] }> {
    return this.client.get<{ data: string[] }>(`${this.basePath}/tags`)
  }
}
