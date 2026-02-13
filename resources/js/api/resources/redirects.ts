import type { ApiResponse, BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface RedirectsQueryParams extends BaseQueryParams {
  source?: string
  target?: string
  status_code?: number
}

export class Redirects extends BaseResource<
  RedirectResource,
  CreateRedirectPayload,
  UpdateRedirectPayload,
  RedirectsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/redirects`
  }

  public async reset(id: string): Promise<ApiResponse<RedirectResource>> {
    return this.client.post<ApiResponse<RedirectResource>>(`${this.basePath}/${id}/reset`)
  }
}
