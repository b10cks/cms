import type { ApiCollectionResponse, ApiResponse } from '~/types'

import type { ApiClient } from '../client'

export class Subscriptions {
  private client: ApiClient
  private basePath: string

  private spacePath: string

  constructor(client: ApiClient, spaceId: string) {
    this.client = client
    this.spacePath = `/mgmt/v1/spaces/${spaceId}`
    this.basePath = `${this.spacePath}/subscriptions`
  }

  public async index(): Promise<ApiCollectionResponse<SubscriptionResource>> {
    return this.client.get<ApiCollectionResponse<SubscriptionResource>>(this.basePath)
  }

  public async current(): Promise<ApiResponse<SubscriptionResource | null>> {
    return this.client.get<ApiResponse<SubscriptionResource | null>>(`${this.basePath}/current`)
  }

  public async checkout(
    planId: string,
    interval: BillingInterval = 'month'
  ): Promise<CheckoutResponse> {
    return this.client.post<CheckoutResponse>(`${this.basePath}/checkout`, {
      plan_id: planId,
      interval,
    })
  }

  public async reinit(): Promise<CheckoutResponse> {
    return this.client.post<CheckoutResponse>(`${this.basePath}/reinit`, {})
  }

  public async cancel(): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(`${this.basePath}/cancel`, {})
  }

  public async resume(): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(`${this.basePath}/resume`, {})
  }

  /** Plans available to this space: public plans plus granted custom plans. */
  public async plans(): Promise<ApiCollectionResponse<PlanResource>> {
    return this.client.get<ApiCollectionResponse<PlanResource>>(`${this.spacePath}/plans`)
  }
}
