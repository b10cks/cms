import type {
  InstanceAuditActionKey,
  InstanceAuditEntry,
  ProviderHealth,
  ProviderTenant,
  ProviderUsageRow,
  ProviderUserDetail,
  ProviderUserListResponse,
  ProviderUserQueryParams,
  SuspendUserPayload,
  TransferOwnershipPayload,
} from '~/types/provider-console'

import type { ApiClient } from '../client'

const BASE = '/mgmt/v1/provider'

/** The provider console's root-only endpoints. User actions need an elevation. */
export class ProviderConsole {
  constructor(private readonly client: ApiClient) {}

  tenants() {
    return this.client.get<ApiResponse<ProviderTenant[]>>(`${BASE}/tenants`)
  }

  users(params: ProviderUserQueryParams = {}) {
    return this.client.get<ProviderUserListResponse>(`${BASE}/users`, { ...params })
  }

  user(id: string) {
    return this.client.get<ApiResponse<ProviderUserDetail>>(`${BASE}/users/${encodeURIComponent(id)}`)
  }

  suspend(id: string, payload: SuspendUserPayload) {
    return this.client.post<ApiResponse<{ ownerless_team_ids: string[] }>>(
      `${BASE}/users/${encodeURIComponent(id)}/suspend`,
      payload
    )
  }

  restore(id: string) {
    return this.client.post<void>(`${BASE}/users/${encodeURIComponent(id)}/restore`)
  }

  signOut(id: string) {
    return this.client.post<void>(`${BASE}/users/${encodeURIComponent(id)}/sign-out`)
  }

  transferOwnership(id: string, payload: TransferOwnershipPayload) {
    return this.client.post<void>(`${BASE}/users/${encodeURIComponent(id)}/transfer-ownership`, payload)
  }

  usage(month: string) {
    return this.client.get<ApiResponse<ProviderUsageRow[]> & { meta: { month: string } }>(
      `${BASE}/usage`,
      { month }
    )
  }

  health(recheck = false) {
    return this.client.get<ApiResponse<ProviderHealth>>(`${BASE}/health`, recheck ? { recheck: 1 } : {})
  }

  audit(params: { page?: number; per_page?: number; action?: InstanceAuditActionKey } = {}) {
    return this.client.get<ApiCollectionResponse<InstanceAuditEntry>>(`${BASE}/audit`, { ...params })
  }
}
