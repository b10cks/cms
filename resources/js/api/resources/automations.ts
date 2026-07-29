import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface AutomationsQueryParams extends BaseQueryParams {
  q?: string
  name?: string
  trigger_type?: AutomationTriggerType
  table?: string
  action_id?: string
  action_type?: AutomationActionType
  is_active?: boolean
}

export class Automations extends BaseResource<
  AutomationResource,
  CreateAutomationPayload,
  UpdateAutomationPayload,
  AutomationsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/automations`
  }

  public async triggerCatalog(): Promise<ApiResponse<AutomationTriggerCatalogResource>> {
    return this.custom<ApiResponse<AutomationTriggerCatalogResource>>('GET', 'trigger-catalog')
  }

  public async trigger(
    id: string,
    payload: TriggerAutomationPayload = {}
  ): Promise<ApiResponse<AutomationResource>> {
    return this.custom<ApiResponse<AutomationResource>>('POST', `${id}/trigger`, payload)
  }
}
