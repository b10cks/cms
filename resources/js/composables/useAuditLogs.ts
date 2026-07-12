import { keepPreviousData, useQuery } from '@tanstack/vue-query'

import { api } from '~/api'
import type { AuditLogsQueryParams } from '~/api/resources/audit-logs'

import { queryKeys } from './useQueryClient'

export function useAuditLogs(spaceId: MaybeRef<string>) {
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useAuditLogsQuery = (
    params: MaybeRef<AuditLogsQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.auditLogs(spaceId).list(params)),
      queryFn: async () => {
        const response = await spaceAPI.value.auditLogs.index({
          sort: '-created_at',
          ...toValue(params),
        })
        return response
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(enabled)),
      staleTime: 0,
      gcTime: 0,
      refetchOnMount: 'always',
      placeholderData: keepPreviousData,
    })
  }

  return {
    useAuditLogsQuery,
  }
}
