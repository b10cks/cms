import type { BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export type BlockType = 'root' | 'nestable' | 'single' | 'universal'

export interface BlocksQueryParams extends BaseQueryParams {
  name?: string
  slug?: string
  folder_id?: string
  type?: BlockType
  tags?: string | string[]
  created_at?: string
  updated_at?: string
}

export class Blocks extends BaseResource<
  BlockResource,
  CreateBlockPayload,
  UpdateBlockPayload,
  BlocksQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/blocks`
  }
}
