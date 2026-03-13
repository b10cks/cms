import type { ApiClient } from '~/api/client'
import type { ApiResponse } from '~/types'
import type { AuthorizationPayload, AuthorizationQueryParams } from '~/types/authorization'

export class Authorization {
  private readonly basePath = '/mgmt/v1/authorization'

  constructor(private readonly client: ApiClient) {}

  public async get(params: AuthorizationQueryParams = {}): Promise<ApiResponse<AuthorizationPayload>> {
    return this.client.get<ApiResponse<AuthorizationPayload>>(
      this.basePath,
      params as Record<string, unknown>
    )
  }
}
