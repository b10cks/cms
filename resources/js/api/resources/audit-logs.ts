import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface AuditLogsQueryParams extends BaseQueryParams {
  created_at?: string
  owner_type?: string
  owner?: string
  operation?: string
  referenced_type?: string
  name?: string
}

export class AuditLogs extends BaseResource<
  AuditLogResource,
  never,
  never,
  AuditLogsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/audit-logs`
  }
}
