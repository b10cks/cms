import type { ApiResponse, BaseQueryParams } from '~/types'

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

  public async updateOnboarding(
    id: string,
    dismissed: boolean
  ): Promise<ApiResponse<SpaceResource>> {
    return this.client.patch<ApiResponse<SpaceResource>>(`${this.basePath}/${id}/onboarding`, {
      dismissed,
    })
  }
}
