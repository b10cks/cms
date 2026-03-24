import type { ApiCollectionResponse, ApiResponse } from '~/types'

import type { ApiClient } from '../client'

export class Subscriptions {
  private client: ApiClient
  private basePath: string

  constructor(client: ApiClient, spaceId: string) {
    this.client = client
    this.basePath = `/mgmt/v1/spaces/${spaceId}/subscriptions`
  }

  public async index(): Promise<ApiCollectionResponse<SubscriptionResource>> {
    return this.client.get<ApiCollectionResponse<SubscriptionResource>>(this.basePath)
  }

  public async current(): Promise<ApiResponse<SubscriptionResource | null>> {
    return this.client.get<ApiResponse<SubscriptionResource | null>>(`${this.basePath}/current`)
  }

  public async checkout(planId: string): Promise<CheckoutResponse> {
    return this.client.post<CheckoutResponse>(`${this.basePath}/checkout`, { plan_id: planId })
  }

  public async reinit(): Promise<CheckoutResponse> {
    return this.client.post<CheckoutResponse>(`${this.basePath}/reinit`, {})
  }

  public async cancel(): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(`${this.basePath}/cancel`, {})
  }
}
