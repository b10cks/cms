import type { ElevationDuration, RootElevationState } from '~/types/provider-console'

import type { ApiClient } from '../client'

/** Root elevation and "sign in as". */
export class RootAccess {
  constructor(private readonly client: ApiClient) {}

  elevate(reason: string, duration: ElevationDuration) {
    return this.client.post<ApiResponse<RootElevationState>>('/mgmt/v1/root/elevation', {
      reason,
      duration,
    })
  }

  endElevation() {
    return this.client.delete<ApiResponse<RootElevationState>>('/mgmt/v1/root/elevation')
  }

  impersonate(userId: string, reason: string) {
    return this.client.post<ApiResponse<{ user_id: string; expires_at: string }>>(
      '/auth/v1/impersonate',
      { userId, reason }
    )
  }

  stopImpersonating() {
    return this.client.delete<void>('/auth/v1/impersonate')
  }
}
