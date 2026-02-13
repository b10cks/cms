import type { ApiCollectionResponse, ApiResponse, BaseQueryParams } from '~/types'
import type {
  TeamResource,
  CreateTeamPayload,
  UpdateTeamPayload,
  TeamHierarchyItem,
  TeamUserResource,
  AddTeamUserPayload,
  UpdateTeamUserPayload,
  TeamUserQueryParams,
} from '~/types/teams'

import { BaseResource } from './base-resource'

export interface TeamsQueryParams extends BaseQueryParams {
  name?: string
  type?: string
  parent_id?: string | null
  created_at?: string
  updated_at?: string
}

export class Teams extends BaseResource<
  TeamResource,
  CreateTeamPayload,
  UpdateTeamPayload,
  TeamsQueryParams
> {
  protected basePath: string = '/mgmt/v1/teams'

  public async getHierarchy(): Promise<ApiCollectionResponse<TeamHierarchyItem>> {
    return this.client.get<ApiCollectionResponse<TeamHierarchyItem>>('/mgmt/v1/teams/hierarchy')
  }

  public async addUser(
    teamId: string,
    payload: AddTeamUserPayload
  ): Promise<ApiResponse<TeamUserResource>> {
    return this.client.post<ApiResponse<TeamUserResource>>(
      `/mgmt/v1/teams/${teamId}/users`,
      payload
    )
  }

  public async updateUser(
    teamId: string,
    userId: string,
    payload: UpdateTeamUserPayload
  ): Promise<ApiResponse<TeamUserResource>> {
    return this.client.patch<ApiResponse<TeamUserResource>>(
      `/mgmt/v1/teams/${teamId}/users/${userId}`,
      payload
    )
  }

  public async removeUser(teamId: string, userId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/teams/${teamId}/users/${userId}`)
  }

  public async getUsers(
    teamId: string,
    params: TeamUserQueryParams = {}
  ): Promise<ApiCollectionResponse<TeamUserResource>> {
    return this.client.get<ApiCollectionResponse<TeamUserResource>>(
      `/mgmt/v1/teams/${teamId}/users`,
      params as Record<string, unknown>
    )
  }
}
