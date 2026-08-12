import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface BackupsQueryParams extends BaseQueryParams {
  name?: string
  state?: 'pending' | 'active' | 'expired' | 'failed'
}

export class Backups extends BaseResource<
  BackupResource,
  CreateBackupPayload,
  UpdateBackupPayload,
  BackupsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/backups`
  }
}
