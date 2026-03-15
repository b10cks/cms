import type { ApiResponse, BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface AutomationExecutionsQueryParams extends BaseQueryParams {
  q?: string
  automation_id?: string
  status?: AutomationExecutionStatus
}

export class AutomationExecutions extends BaseResource<
  AutomationExecutionResource,
  never,
  never,
  AutomationExecutionsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/automation-executions`
  }

  public async replay(id: string): Promise<ApiResponse<AutomationExecutionResource>> {
    return this.custom<ApiResponse<AutomationExecutionResource>>('POST', `${id}/replay`)
  }
}
