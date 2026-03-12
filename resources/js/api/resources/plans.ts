import type { ApiClient } from '../client'
import type { ApiCollectionResponse } from '~/types'

export class Plans {
  private client: ApiClient
  private basePath = '/mgmt/v1/plans'

  constructor(client: ApiClient) {
    this.client = client
  }

  public async index(): Promise<ApiCollectionResponse<PlanResource>> {
    return this.client.get<ApiCollectionResponse<PlanResource>>(this.basePath)
  }
}
