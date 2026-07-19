import type { ApiCollectionResponse, ApiResponse } from '~/types'

import type { ApiClient } from '../client'

export class Usage {
  private client: ApiClient
  private basePath: string

  constructor(client: ApiClient, spaceId: string) {
    this.client = client
    this.basePath = `/mgmt/v1/spaces/${spaceId}/usage`
  }

  public async history(): Promise<ApiCollectionResponse<SubscriptionPeriod>> {
    return this.client.get<ApiCollectionResponse<SubscriptionPeriod>>(`${this.basePath}/history`)
  }

  public async timeseries(periodId: string): Promise<ApiResponse<UsageTimeseries>> {
    return this.client.get<ApiResponse<UsageTimeseries>>(
      `${this.basePath}/history/${periodId}/timeseries`
    )
  }
}
