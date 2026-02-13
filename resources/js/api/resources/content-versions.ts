import type { BaseQueryParams } from '~/types'
import type { ContentVersionListResource } from '~/types/contents'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface ContentVersionsQueryParams extends BaseQueryParams {
  created_by?: string
  created_at?: string
  isPublished?: boolean | string
  isCurrentVersion?: boolean | string
}

export class ContentVersions extends BaseResource<
  ContentVersionListResource,
  null,
  null,
  ContentVersionsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string, contentId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/contents/${contentId}/versions`
  }

  async current(versionId: string): Promise<boolean> {
    await this.client.post(`${this.basePath}/${versionId}/current`)
    return true
  }

  async publish(versionId: string): Promise<boolean> {
    await this.client.post(`${this.basePath}/${versionId}/publish`)
    return true
  }
}
