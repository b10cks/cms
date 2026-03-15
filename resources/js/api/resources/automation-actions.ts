import type { BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface AutomationActionsQueryParams extends BaseQueryParams {
  q?: string
  name?: string
  type?: AutomationActionType
  is_active?: boolean
}

export class AutomationActions extends BaseResource<
  AutomationActionResource,
  CreateAutomationActionPayload,
  UpdateAutomationActionPayload,
  AutomationActionsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/automation-actions`
  }
}
