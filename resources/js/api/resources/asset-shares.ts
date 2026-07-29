import type {
  AssetShareResource,
  CreateAssetSharePayload,
  UpdateAssetSharePayload,
} from '~/types/asset-distribution'

import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface AssetSharesQueryParams extends BaseQueryParams {
  q?: string
  source_type?: 'collection' | 'selection' | 'folder'
  collection_id?: string
  folder_id?: string
}

export class AssetShares extends BaseResource<
  AssetShareResource,
  CreateAssetSharePayload,
  UpdateAssetSharePayload,
  AssetSharesQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/asset-shares`
  }

  public async revoke(id: string): Promise<ApiResponse<AssetShareResource>> {
    return this.client.post<ApiResponse<AssetShareResource>>(`${this.basePath}/${id}/revoke`)
  }
}
