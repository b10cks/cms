import type { ApiResponse, BaseQueryParams } from '~/types'
import type {
  AssignVersionsRequest,
  CreateReleaseRequest,
  Release,
  ReleaseDetail,
  UpdateReleaseRequest,
} from '~/types/releases'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface ReleasesQueryParams extends BaseQueryParams {}

export class Releases extends BaseResource<
  Release,
  CreateReleaseRequest,
  UpdateReleaseRequest,
  ReleasesQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/releases`
  }

  public async getDetail(id: string): Promise<ApiResponse<ReleaseDetail>> {
    return this.client.get<ApiResponse<ReleaseDetail>>(`${this.basePath}/${id}`)
  }

  public async commit(id: string): Promise<ApiResponse<Release>> {
    return this.client.post<ApiResponse<Release>>(`${this.basePath}/${id}/commit`)
  }

  public async cancel(id: string): Promise<ApiResponse<Release>> {
    return this.client.post<ApiResponse<Release>>(`${this.basePath}/${id}/cancel`)
  }

  public async publish(id: string): Promise<ApiResponse<Release>> {
    return this.client.post<ApiResponse<Release>>(`${this.basePath}/${id}/publish`)
  }

  public async assignVersions(
    id: string,
    payload: AssignVersionsRequest
  ): Promise<ApiResponse<ReleaseDetail>> {
    return this.client.post<ApiResponse<ReleaseDetail>>(
      `${this.basePath}/${id}/versions/assign`,
      payload
    )
  }

  public async removeVersions(
    id: string,
    payload: AssignVersionsRequest
  ): Promise<ApiResponse<ReleaseDetail>> {
    return this.client.delete<ApiResponse<ReleaseDetail>>(
      `${this.basePath}/${id}/versions/remove`,
      { body: payload }
    )
  }
}
