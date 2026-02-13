import type { ApiClient } from '~/api/client'

import { api } from '@/api'

export function useApiClient() {
  return {
    client: api.client,
  }
}
