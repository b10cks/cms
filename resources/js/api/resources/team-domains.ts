import type {
  JoinableTeamResource,
  TeamDomainResource,
  TeamJoinRequestResource,
  UpdateTeamDomainPayload,
} from '~/types/team-domains'

import { BaseResource } from './base-resource'

/** Verified team domains, the join requests they produce, and the requester's side. */
export class TeamDomains extends BaseResource<TeamDomainResource, never, never, never> {
  protected basePath: string = '/mgmt/v1/teams'

  private teamPath(teamId: string, path: string): string {
    return `${this.basePath}/${encodeURIComponent(teamId)}/${path}`
  }

  public async list(teamId: string): Promise<ApiCollectionResponse<TeamDomainResource>> {
    return this.client.get(this.teamPath(teamId, 'domains'))
  }

  public async add(teamId: string, domain: string): Promise<ApiResponse<TeamDomainResource>> {
    return this.client.post(this.teamPath(teamId, 'domains'), { domain })
  }

  public async verify(teamId: string, domainId: string): Promise<ApiResponse<TeamDomainResource>> {
    return this.client.post(this.teamPath(teamId, `domains/${domainId}/verify`))
  }

  public async updateDomain(
    teamId: string,
    domainId: string,
    payload: UpdateTeamDomainPayload
  ): Promise<ApiResponse<TeamDomainResource>> {
    return this.client.patch(this.teamPath(teamId, `domains/${domainId}`), payload)
  }

  public async remove(teamId: string, domainId: string): Promise<void> {
    return this.client.delete(this.teamPath(teamId, `domains/${domainId}`))
  }

  public async joinRequests(
    teamId: string
  ): Promise<ApiCollectionResponse<TeamJoinRequestResource>> {
    return this.client.get(this.teamPath(teamId, 'join-requests'))
  }

  public async approve(
    teamId: string,
    requestId: string,
    role?: string
  ): Promise<ApiResponse<TeamJoinRequestResource>> {
    return this.client.post(this.teamPath(teamId, `join-requests/${requestId}/approve`), {
      role,
    })
  }

  public async decline(
    teamId: string,
    requestId: string
  ): Promise<ApiResponse<TeamJoinRequestResource>> {
    return this.client.post(this.teamPath(teamId, `join-requests/${requestId}/decline`))
  }

  public async joinable(): Promise<ApiCollectionResponse<JoinableTeamResource>> {
    return this.client.get('/mgmt/v1/users/me/join-requests')
  }

  public async requestToJoin(teamId: string): Promise<ApiResponse<TeamJoinRequestResource>> {
    return this.client.post('/mgmt/v1/users/me/join-requests', { team_id: teamId })
  }

  public async cancelRequest(requestId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/users/me/join-requests/${requestId}`)
  }
}
