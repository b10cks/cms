import type { ApiClient } from '../client'

export class Invoices {
  private client: ApiClient
  private basePath: string

  constructor(client: ApiClient, spaceId: string) {
    this.client = client
    this.basePath = `/mgmt/v1/spaces/${spaceId}/invoices`
  }

  public async index(): Promise<ApiCollectionResponse<InvoiceResource>> {
    return this.client.get<ApiCollectionResponse<InvoiceResource>>(this.basePath)
  }
}
