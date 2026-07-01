import type { ApiResponse, BaseQueryParams } from '~/types'
import type { AssetResource, AssetVersionResource } from '~/types/assets'

import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface AssetVersionsQueryParams extends BaseQueryParams {}

export class AssetVersions extends BaseResource<
  AssetVersionResource,
  never,
  never,
  AssetVersionsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string, assetId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/assets/${assetId}/versions`
  }

  async restore(versionId: string): Promise<AssetResource> {
    const response = await this.client.post<ApiResponse<AssetResource>>(
      `${this.basePath}/${versionId}/restore`
    )
    return response.data
  }
}
