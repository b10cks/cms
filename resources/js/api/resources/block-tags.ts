import type { BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface BlockTagResource {
  name: string
  icon?: string | null
  color?: string | null
  blocks_count?: number
  created_at?: string
  updated_at?: string
}

export interface UpsertBlockTagPayload {
  name: string
  icon?: string | null
  color?: string | null
}

export interface BlockTagsQueryParams extends BaseQueryParams {
  name?: string
  icon?: string
  color?: string
  blocks_count?: number | string
  q?: string
  created_at?: string
  updated_at?: string
}

export class BlockTags extends BaseResource<
  BlockTagResource,
  UpsertBlockTagPayload,
  UpsertBlockTagPayload,
  BlockTagsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/block-tags`
  }
}
