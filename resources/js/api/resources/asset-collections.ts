import type { ApiCollectionResponse, BaseQueryParams } from '~/types'
import type {
  AssetCollectionResource,
  AssetResource,
  CreateAssetCollectionPayload,
  UpdateAssetCollectionPayload,
} from '~/types/assets'

import type { ApiClient } from '../client'
import type { AssetsQueryParams } from './assets'
import { BaseResource } from './base-resource'

export interface AssetCollectionsQueryParams extends BaseQueryParams {
  q?: string
  name?: string
  type?: 'manual' | 'smart'
}

export class AssetCollections extends BaseResource<
  AssetCollectionResource,
  CreateAssetCollectionPayload,
  UpdateAssetCollectionPayload,
  AssetCollectionsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/asset-collections`
  }

  public async getAssets(
    collectionId: string,
    params: AssetsQueryParams = {}
  ): Promise<ApiCollectionResponse<AssetResource>> {
    return this.client.get<ApiCollectionResponse<AssetResource>>(
      `${this.basePath}/${collectionId}/assets`,
      params as Record<string, unknown>
    )
  }

  public async addAssets(collectionId: string, assetIds: string[]): Promise<void> {
    await this.client.post(`${this.basePath}/${collectionId}/assets`, { asset_ids: assetIds })
  }

  public async removeAssets(collectionId: string, assetIds: string[]): Promise<void> {
    await this.client.delete(`${this.basePath}/${collectionId}/assets`, {
      body: { asset_ids: assetIds },
    })
  }

  public async reorderAssets(collectionId: string, assetIds: string[]): Promise<void> {
    await this.client.patch(`${this.basePath}/${collectionId}/assets/order`, {
      asset_ids: assetIds,
    })
  }
}
