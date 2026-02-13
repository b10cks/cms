import type { BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface BlockTemplatesQueryParams extends BaseQueryParams {
  name?: string
  block_id?: string
  created_by?: string
  created_at?: string
}

export class BlockTemplates extends BaseResource<
  BlockTemplate,
  CreateBlockTemplatePayload,
  UpdateBlockTemplatePayload,
  BlockTemplatesQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string, blockId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/blocks/${blockId}/templates`
  }
}
