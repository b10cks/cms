import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface MigrationsQueryParams extends BaseQueryParams {
  state?: MigrationState
  sort?: 'created_at' | 'state' | 'progress'
  order?: 'asc' | 'desc'
}

export class Migrations extends BaseResource<
  MigrationResource,
  CreateMigrationPayload,
  never,
  MigrationsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/migrations`
  }
}
