import type { ApiCollectionResponse, ApiResponse } from '~/types'
import type {
  AcceptInvitePayload,
  CreateInvitePayload,
  InviteQueryParams,
  InviteResource,
  PublicInviteResource,
} from '~/types/invites'

import { BaseResource } from './base-resource'

export class Invites extends BaseResource<
  InviteResource,
  CreateInvitePayload,
  never,
  InviteQueryParams
> {
  protected basePath: string = '/mgmt/v1/users/me/invites'

  public async listSpaceInvites(
    spaceId: string,
    params: InviteQueryParams = {}
  ): Promise<ApiCollectionResponse<InviteResource>> {
    return this.client.get<ApiCollectionResponse<InviteResource>>(
      `/mgmt/v1/spaces/${spaceId}/invites`,
      params as Record<string, unknown>
    )
  }

  public async createSpaceInvite(
    spaceId: string,
    payload: CreateInvitePayload
  ): Promise<ApiResponse<InviteResource>> {
    return this.client.post<ApiResponse<InviteResource>>(
      `/mgmt/v1/spaces/${spaceId}/invites`,
      payload
    )
  }

  public async deleteSpaceInvite(spaceId: string, inviteId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/spaces/${spaceId}/invites/${inviteId}`)
  }

  public async resendSpaceInvite(
    spaceId: string,
    inviteId: string
  ): Promise<ApiResponse<InviteResource>> {
    return this.client.post<ApiResponse<InviteResource>>(
      `/mgmt/v1/spaces/${spaceId}/invites/${inviteId}/resend`,
      {}
    )
  }

  public async listTeamInvites(
    teamId: string,
    params: InviteQueryParams = {}
  ): Promise<ApiCollectionResponse<InviteResource>> {
    return this.client.get<ApiCollectionResponse<InviteResource>>(
      `/mgmt/v1/teams/${teamId}/invites`,
      params as Record<string, unknown>
    )
  }

  public async createTeamInvite(
    teamId: string,
    payload: CreateInvitePayload
  ): Promise<ApiResponse<InviteResource>> {
    return this.client.post<ApiResponse<InviteResource>>(
      `/mgmt/v1/teams/${teamId}/invites`,
      payload
    )
  }

  public async deleteTeamInvite(teamId: string, inviteId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/teams/${teamId}/invites/${inviteId}`)
  }

  public async resendTeamInvite(
    teamId: string,
    inviteId: string
  ): Promise<ApiResponse<InviteResource>> {
    return this.client.post<ApiResponse<InviteResource>>(
      `/mgmt/v1/teams/${teamId}/invites/${inviteId}/resend`,
      {}
    )
  }

  public async getPublicInvite(token: string): Promise<ApiResponse<PublicInviteResource>> {
    return this.client.get<ApiResponse<PublicInviteResource>>(`/mgmt/v1/invites/${token}`)
  }

  public async acceptInvite(
    inviteId: string,
    payload: AcceptInvitePayload
  ): Promise<ApiResponse<InviteResource>> {
    return this.client.post<ApiResponse<InviteResource>>(
      `${this.basePath}/${inviteId}/accept`,
      payload
    )
  }

  public async listMyInvites(
    params: InviteQueryParams = {}
  ): Promise<ApiCollectionResponse<InviteResource>> {
    return this.client.get<ApiCollectionResponse<InviteResource>>(
      this.basePath,
      params as Record<string, unknown>
    )
  }

  public async getMyInvite(inviteId: string): Promise<ApiResponse<InviteResource>> {
    return this.client.get<ApiResponse<InviteResource>>(`${this.basePath}/${inviteId}`)
  }
}
