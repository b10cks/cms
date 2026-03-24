import { api } from '@/api'
import type { ApiClient } from '~/api/client'

export function useApiClient() {
  return {
    client: api.client,
  }
}
