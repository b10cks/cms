import type { BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

export interface PersonalAccessTokenQueryParams extends BaseQueryParams {
  per_page?: number
}

export interface PersonalAccessTokenCreatePayload {
  name: string
  expires_at: string
}

export class PersonalAccessTokens {
  private client: ApiClient
  private basePath: string = '/mgmt/v1/users/me/tokens'

  constructor(client: ApiClient) {
    this.client = client
  }

  public async index(
    query: PersonalAccessTokenQueryParams = {}
  ): Promise<PersonalAccessTokenListResponse> {
    return this.client.get<PersonalAccessTokenListResponse>(
      this.basePath,
      query as Record<string, unknown>
    )
  }

  public async create(
    payload: PersonalAccessTokenCreatePayload
  ): Promise<PersonalAccessTokenCreateResponse> {
    return this.client.post<PersonalAccessTokenCreateResponse>(this.basePath, payload)
  }

  public async delete(id: number | string): Promise<void> {
    return this.client.delete(`${this.basePath}/${id}`)
  }
}
