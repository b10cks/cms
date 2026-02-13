import type { BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface BlockFolderResource {
  id: string
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  blocks_count?: number
  parent_id?: string | null
  created_at?: string
  updated_at?: string
}

export interface UpsertBlockFolderPayload {
  name: string
  icon?: string | null
  color?: string | null
  parent_id?: string | null
  description?: string | null
}

export interface BlockFoldersQueryParams extends BaseQueryParams {
  name?: string
  icon?: string
  color?: string
  parent_id?: string | null
  blocks_count?: number | string
  q?: string
  created_at?: string
  updated_at?: string
}

export class BlockFolders extends BaseResource<
  BlockFolderResource,
  UpsertBlockFolderPayload,
  UpsertBlockFolderPayload,
  BlockFoldersQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/block-folders`
  }
}
