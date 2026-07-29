import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface TokenQueryParams extends BaseQueryParams {
  name?: string
}

export interface CreateTokenRequest {
  name: string
  abilities?: string[]
  expires_at?: string
  execution_limit?: number
}

export interface CreateTokenResponse {
  token: Token
  plain_text_token: string
}

export class Tokens extends BaseResource<
  Token,
  CreateTokenRequest,
  undefined,
  TokenQueryParams,
  CreateTokenResponse
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/tokens`
  }
}
