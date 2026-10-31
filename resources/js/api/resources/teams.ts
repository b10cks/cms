import type {
  CreateTeamSpaceRolePayload,
  RoleCatalogEntry,
  UpdateTeamSpaceRolePayload,
} from '~/types/authorization'
import type {
  AccessMatrix,
  TeamAuditEntry,
  TeamMovePreview,
  TeamOverview,
} from '~/types/team-workspace'
import type {
  CreateTeamPayload,
  UpdateTeamPayload,
  TeamHierarchyItem,
  TeamSamlProviderPayload,
  TeamSamlProviderResource,
  TeamSamlProviderResponse,
  TeamResource,
  TeamUserResource,
  UpdateTeamUserPayload,
} from '~/types/teams'

import { BaseResource } from './base-resource'

export interface TeamsQueryParams extends BaseQueryParams {
  name?: string
  type?: string
  parent_id?: string | null
  created_at?: string
  updated_at?: string
  include_space_context?: boolean
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

  public async deleteAvatar(teamId: string): Promise<ApiResponse<TeamResource>> {
    return this.client.delete<ApiResponse<TeamResource>>(`/mgmt/v1/teams/${teamId}/avatar`)
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

  public async getPeople(
    teamId: string,
    params: PeopleQueryParams = {}
  ): Promise<PeopleCollectionResponse> {
    return this.client.get<PeopleCollectionResponse>(
      `/mgmt/v1/teams/${teamId}/people`,
      params as Record<string, unknown>
    )
  }

  public async getSpaceRoles(teamId: string): Promise<ApiCollectionResponse<RoleCatalogEntry>> {
    return this.client.get<ApiCollectionResponse<RoleCatalogEntry>>(
      `/mgmt/v1/teams/${teamId}/roles/space`
    )
  }

  public async createSpaceRole(
    teamId: string,
    payload: CreateTeamSpaceRolePayload
  ): Promise<ApiResponse<RoleCatalogEntry>> {
    return this.client.post<ApiResponse<RoleCatalogEntry>>(
      `/mgmt/v1/teams/${teamId}/roles/space`,
      payload
    )
  }

  public async updateSpaceRole(
    teamId: string,
    roleId: string,
    payload: UpdateTeamSpaceRolePayload
  ): Promise<ApiResponse<RoleCatalogEntry>> {
    return this.client.patch<ApiResponse<RoleCatalogEntry>>(
      `/mgmt/v1/teams/${teamId}/roles/space/${roleId}`,
      payload
    )
  }

  public async deleteSpaceRole(teamId: string, roleId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/teams/${teamId}/roles/space/${roleId}`)
  }

  public async getSamlProvider(teamId: string): Promise<TeamSamlProviderResponse> {
    return this.client.get<TeamSamlProviderResponse>(`/mgmt/v1/teams/${teamId}/saml-provider`)
  }

  public async upsertSamlProvider(
    teamId: string,
    payload: TeamSamlProviderPayload
  ): Promise<ApiResponse<TeamSamlProviderResource>> {
    return this.client.put<ApiResponse<TeamSamlProviderResource>>(
      `/mgmt/v1/teams/${teamId}/saml-provider`,
      payload
    )
  }

  public async deleteSamlProvider(teamId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/teams/${teamId}/saml-provider`)
  }

  public async getOverview(teamId: string): Promise<ApiResponse<TeamOverview>> {
    return this.client.get<ApiResponse<TeamOverview>>(`/mgmt/v1/teams/${teamId}/overview`)
  }

  public async getMovePreview(
    teamId: string,
    parentId: string | null
  ): Promise<ApiResponse<TeamMovePreview>> {
    return this.client.get<ApiResponse<TeamMovePreview>>(
      `/mgmt/v1/teams/${teamId}/move-preview`,
      parentId ? { parent_id: parentId } : {}
    )
  }

  public async getAccessMatrix(
    teamId: string,
    filterTeamId: string | null = null
  ): Promise<ApiResponse<AccessMatrix>> {
    return this.client.get<ApiResponse<AccessMatrix>>(
      `/mgmt/v1/teams/${teamId}/access-matrix`,
      filterTeamId ? { team_id: filterTeamId } : {}
    )
  }

  public async getAuditLogs(
    teamId: string,
    params: { page?: number; per_page?: number } = {}
  ): Promise<ApiCollectionResponse<TeamAuditEntry>> {
    return this.client.get<ApiCollectionResponse<TeamAuditEntry>>(
      `/mgmt/v1/teams/${teamId}/audit-logs`,
      params
    )
  }

  /** `accessUntil` is a `YYYY-MM-DD` day; `null` keeps the membership open-ended. */
  public async setUserExpiry(
    teamId: string,
    userId: string,
    accessUntil: string | null
  ): Promise<void> {
    return this.client.put(`/mgmt/v1/teams/${teamId}/users/${userId}/expiry`, {
      access_until: accessUntil,
    })
  }
}
