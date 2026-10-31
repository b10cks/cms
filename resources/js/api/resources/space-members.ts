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

  /** Give someone from the space's organisation a direct role here. */
  public async add(userId: string, role: string): Promise<void> {
    return this.client.post(`/mgmt/v1/spaces/${this.spaceId}/members`, { user_id: userId, role })
  }

  /** `accessUntil` is a `YYYY-MM-DD` day; `null` keeps the membership open-ended. */
  public async setExpiry(userId: string, accessUntil: string | null): Promise<void> {
    return this.client.put(`/mgmt/v1/spaces/${this.spaceId}/members/${userId}/expiry`, {
      access_until: accessUntil,
    })
  }

  public async remove(userId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/spaces/${this.spaceId}/members/${userId}`)
  }
}
