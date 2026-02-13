import type { ApiClient } from '../client'

export class ContentMenu {
  private client: ApiClient
  private readonly basePath: string

  constructor(client: ApiClient, spaceId: string) {
    this.client = client
    this.basePath = `/mgmt/v1/spaces/${spaceId}/content-menu`
  }

  public async get(): Promise<ContentMenuResponse> {
    return this.client.get<ContentMenuResponse>(this.basePath)
  }
}
