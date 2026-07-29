import { api } from '@/api'

export function useApiClient() {
  return {
    client: api.client,
  }
}
