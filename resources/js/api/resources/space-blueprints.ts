import { BaseResource } from './base-resource'

export interface SpaceBlueprintQueryParams extends BaseQueryParams {
  name?: string
  team_id?: string
  created_at?: string
  updated_at?: string
}

export interface SpaceBlueprintTeamResource {
  id: string
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  type?: string | null
}

export interface CreateSpaceBlueprintPayload {
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  settings?: Record<string, unknown> | null
  source_space_id?: string | null
  tables?: string[]
  /** Chooses the endpoint: a team blueprint, or a system one when null. */
  team_id?: string | null
}

export interface UpdateSpaceBlueprintPayload {
  name?: string
  icon?: string | null
  color?: string | null
  description?: string | null
  settings?: Record<string, unknown> | null
}

export interface SpaceBlueprintResource {
  id: string
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  team_id: string | null
  team?: SpaceBlueprintTeamResource | null
  created_by?: {
    id: string
    firstname?: string | null
    lastname?: string | null
    email?: string | null
    avatar?: string | null
  } | null
  settings?: Record<string, unknown> | null
  /** The snapshotted source-space rows, keyed by table. */
  snapshot?: Record<string, unknown> | null
  created_at: string
  updated_at: string
}

export class SpaceBlueprints extends BaseResource<
  SpaceBlueprintResource,
  CreateSpaceBlueprintPayload,
  UpdateSpaceBlueprintPayload,
  SpaceBlueprintQueryParams
> {
  protected basePath = '/mgmt/v1/space-blueprints'

  public async index(
    params: SpaceBlueprintQueryParams = {}
  ): Promise<ApiCollectionResponse<SpaceBlueprintResource>> {
    return this.client.get<ApiCollectionResponse<SpaceBlueprintResource>>(
      this.basePath,
      params as Record<string, unknown>
    )
  }

  public async getForTeam(
    teamId: string,
    params: SpaceBlueprintQueryParams = {}
  ): Promise<ApiCollectionResponse<SpaceBlueprintResource>> {
    return this.client.get<ApiCollectionResponse<SpaceBlueprintResource>>(
      `/mgmt/v1/teams/${teamId}/blueprints`,
      params as Record<string, unknown>
    )
  }

  public async getForTeamById(
    teamId: string,
    blueprintId: string
  ): Promise<ApiResponse<SpaceBlueprintResource>> {
    return this.client.get<ApiResponse<SpaceBlueprintResource>>(
      `/mgmt/v1/teams/${teamId}/blueprints/${blueprintId}`
    )
  }

  public async create(
    payload: CreateSpaceBlueprintPayload
  ): Promise<ApiResponse<SpaceBlueprintResource>> {
    return this.client.post<ApiResponse<SpaceBlueprintResource>>(this.basePath, payload)
  }

  public async createForTeam(
    teamId: string,
    payload: CreateSpaceBlueprintPayload
  ): Promise<ApiResponse<SpaceBlueprintResource>> {
    return this.client.post<ApiResponse<SpaceBlueprintResource>>(
      `/mgmt/v1/teams/${teamId}/blueprints`,
      payload
    )
  }

  public async updateForTeam(
    teamId: string,
    blueprintId: string,
    payload: UpdateSpaceBlueprintPayload
  ): Promise<ApiResponse<SpaceBlueprintResource>> {
    return this.client.patch<ApiResponse<SpaceBlueprintResource>>(
      `/mgmt/v1/teams/${teamId}/blueprints/${blueprintId}`,
      payload
    )
  }

  public async deleteForTeam(teamId: string, blueprintId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/teams/${teamId}/blueprints/${blueprintId}`)
  }
}
