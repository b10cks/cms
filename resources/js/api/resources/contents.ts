import type { ApiResponse, BaseQueryParams } from '~/types'
import type { ContentResource, CreateContentPayload, UpdateContentPayload } from '~/types/contents'

import type { ApiClient } from '../client'

// src/api/resources/contents.ts
import { BaseResource } from './base-resource'

export interface ContentsQueryParams extends BaseQueryParams {
  filter?: {
    parent_id?: string | null
    block_id?: string
    published?: boolean
  }
}

export class Contents extends BaseResource<
  ContentResource,
  CreateContentPayload,
  UpdateContentPayload,
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
    payload: UpdateContentPayload
  ): Promise<ApiResponse<ContentResource>> {
    return this.client.post<ApiResponse<ContentResource>>(
      `${this.basePath}/${contentId}/publish`,
      payload
    )
  }

  /**
   * Schedule a content item for publishing
   */
  public async schedule(
    contentId: string,
    payload: UpdateContentPayload
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
    payload: UpdateContentPayload
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
}
