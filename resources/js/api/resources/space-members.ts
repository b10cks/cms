import type { ApiCollectionResponse } from '~/types'
import type {
  SpaceMemberQueryParams,
  SpaceMemberResource,
  UpdateSpaceMemberPayload,
} from '~/types/spaces'

import type { ApiClient } from '../client'

export class SpaceMembers {
  constructor(
    private readonly client: ApiClient,
    private readonly spaceId: string
  ) {}

  public async list(
    params: SpaceMemberQueryParams = {}
  ): Promise<ApiCollectionResponse<SpaceMemberResource>> {
    return this.client.get<ApiCollectionResponse<SpaceMemberResource>>(
      `/mgmt/v1/spaces/${this.spaceId}/members`,
      params as Record<string, unknown>
    )
  }

  public async update(userId: string, payload: UpdateSpaceMemberPayload): Promise<void> {
    return this.client.patch(`/mgmt/v1/spaces/${this.spaceId}/members/${userId}`, payload)
  }

  public async remove(userId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/spaces/${this.spaceId}/members/${userId}`)
  }
}
