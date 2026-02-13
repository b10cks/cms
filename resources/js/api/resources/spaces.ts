import type { BaseQueryParams } from '~/types'

import { BaseResource } from './base-resource'

export interface SpaceQueryParams extends BaseQueryParams {
  name?: string
  slug?: string
  archived?: boolean
  team_id?: string
  created_at?: string
  updated_at?: string
}

export class Spaces extends BaseResource<
  SpaceResource,
  CreateSpacePayload,
  UpdateSpacePayload,
  SpaceQueryParams
> {
  protected basePath = '/mgmt/v1/spaces'

  public async archive(id: string): Promise<void> {
    return this.client.post(`${this.basePath}/${id}/archive`)
  }
}
