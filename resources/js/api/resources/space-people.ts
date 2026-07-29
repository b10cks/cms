import type { ApiClient } from '../client'

export class SpacePeople {
  constructor(
    private readonly client: ApiClient,
    private readonly spaceId: string
  ) {}

  public async list(params: PeopleQueryParams = {}): Promise<PeopleCollectionResponse> {
    return this.client.get<PeopleCollectionResponse>(
      `/mgmt/v1/spaces/${this.spaceId}/people`,
      params as Record<string, unknown>
    )
  }
}
