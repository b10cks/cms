import type {
  CreateFieldPluginPayload,
  FieldPluginResource,
  UpdateFieldPluginPayload,
} from '~/types/field-plugins'

import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface FieldPluginsQueryParams extends BaseQueryParams {
  name?: string
  handle?: string
  is_active?: boolean
}

export class FieldPlugins extends BaseResource<
  FieldPluginResource,
  CreateFieldPluginPayload,
  UpdateFieldPluginPayload,
  FieldPluginsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/field-plugins`
  }
}
