import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface AssetFoldersQueryParams extends BaseQueryParams {
  filter?: {
    parent_id?: string | null
    name?: string
  }
}

export interface EnsureAssetFolderPathsPayload {
  parent_id: string | null
  paths: string[]
}

export interface EnsureAssetFolderPathsResult {
  /** Requested path string to the id of the folder it resolved to. */
  paths: Record<string, string | null>
  /** Every folder created or matched, so the caller can refresh its cache. */
  folders: AssetFolderResource[]
  /** Segment names the server had to change (truncated, purified, placeholder). */
  renamed: Array<{ from: string; to: string }>
}

export class AssetFolders extends BaseResource<
  AssetFolderResource,
  UpsertAssetFolderPayload,
  UpsertAssetFolderPayload,
  AssetFoldersQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/asset-folders`
  }

  public async ensurePaths(
    payload: EnsureAssetFolderPathsPayload
  ): Promise<EnsureAssetFolderPathsResult> {
    return this.client.post<EnsureAssetFolderPathsResult>(this.getPath('ensure-paths'), payload)
  }
}
