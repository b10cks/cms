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

  /** Returns the restored asset, unwrapped like every single asset response (see unwrapAsset). */
  async restore(versionId: string): Promise<AssetResource> {
    return this.client.post<AssetResource>(`${this.basePath}/${versionId}/restore`)
  }
}
