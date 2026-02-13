import type { ApiResponse, BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface BlockVersionsQueryParams extends BaseQueryParams {
  created_by?: string
  created_at?: string
}

export class BlockVersions extends BaseResource<
  BlockVersion,
  null,
  UpdateBlockVersionPayload,
  BlockVersionsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string, blockId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/blocks/${blockId}/versions`
  }

  async restore(versionId: string): Promise<BlockVersion> {
    const response = await this.client.post<ApiResponse<BlockVersion>>(
      `${this.basePath}/${versionId}/restore`
    )
    return response.data
  }
}
