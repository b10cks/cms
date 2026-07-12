import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { ComputedRef, MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AutomationExecutionsQueryParams } from '~/api/resources/automation-executions'

import { queryKeys } from './useQueryClient'

type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>

export function useAutomationExecutions(spaceIdRef: MaybeRefOrComputed<string>) {
  const queryClient = useQueryClient()
  const { t } = useI18n()

  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useAutomationExecutionsQuery = (
    paramsRef: MaybeRefOrComputed<AutomationExecutionsQueryParams> = {}
  ) => {
    const params = computed(() => unref(paramsRef))

    return useQuery({
      queryKey: computed(() => queryKeys.automationExecutions(spaceId.value).list(params.value)),
      queryFn: async () => {
        return await spaceAPI.value.automationExecutions.index(params.value)
      },
      enabled: computed(() => !!spaceId.value),
      placeholderData: keepPreviousData,
    })
  }

  const useReplayAutomationExecutionMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await spaceAPI.value.automationExecutions.replay(id)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.automationExecutions(spaceId.value).lists(),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.automations(spaceId.value).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.automationActions(spaceId.value).lists(),
        })
        toast.success(t('composables.automationExecutions.replaySuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.automationExecutions.replayError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useAutomationExecutionsQuery,
    useReplayAutomationExecutionMutation,
  }
}
