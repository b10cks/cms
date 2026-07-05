import type { BaseQueryParams } from '~/types'
import type {
  AssetPackageResource,
  AssetPackageState,
  CreateAssetPackagePayload,
  DownloadUrlResponse,
} from '~/types/asset-distribution'

import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface AssetPackagesQueryParams extends BaseQueryParams {
  state?: AssetPackageState
}

export class AssetPackages extends BaseResource<
  AssetPackageResource,
  CreateAssetPackagePayload,
  never,
  AssetPackagesQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/asset-packages`
  }

  public async download(id: string): Promise<DownloadUrlResponse> {
    return this.client.get<DownloadUrlResponse>(`${this.basePath}/${id}/download`)
  }
}
