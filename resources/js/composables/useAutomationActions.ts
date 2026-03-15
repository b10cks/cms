import type { ComputedRef, MaybeRef } from 'vue'

import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { AutomationActionsQueryParams } from '~/api/resources/automation-actions'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>

export function useAutomationActions(spaceIdRef: MaybeRefOrComputed<string>) {
  const queryClient = useQueryClient()
  const { t } = useI18n()

  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useAutomationActionsQuery = (
    paramsRef: MaybeRefOrComputed<AutomationActionsQueryParams> = {}
  ) => {
    const params = computed(() => unref(paramsRef))

    return useQuery({
      queryKey: computed(() => queryKeys.automationActions(spaceId.value).list(params.value)),
      queryFn: async () => {
        return await spaceAPI.value.automationActions.index(params.value)
      },
      enabled: computed(() => !!spaceId.value),
    })
  }

  const useAutomationActionQuery = (idRef: MaybeRefOrComputed<string>) => {
    const id = computed(() => unref(idRef))

    return useQuery({
      queryKey: computed(() => queryKeys.automationActions(spaceId.value).detail(id.value)),
      queryFn: async () => {
        const response = await spaceAPI.value.automationActions.get(id.value)
        return response.data
      },
      enabled: computed(() => !!spaceId.value && !!id.value),
    })
  }

  const useCreateAutomationActionMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateAutomationActionPayload) => {
        const response = await spaceAPI.value.automationActions.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.automationActions(spaceId.value).lists() })
        toast.success(t('composables.automationActions.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.automationActions.createError', { error: error.message || 'Unknown error' }) as string
        )
      },
    })
  }

  const useUpdateAutomationActionMutation = () => {
    return useMutation({
      mutationFn: async ({
        id,
        payload,
      }: {
        id: string
        payload: UpdateAutomationActionPayload
      }) => {
        const response = await spaceAPI.value.automationActions.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.automationActions(spaceId.value).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.automationActions(spaceId.value).detail(data.id),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.automations(spaceId.value).lists() })
        toast.success(t('composables.automationActions.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.automationActions.updateError', { error: error.message || 'Unknown error' }) as string
        )
      },
    })
  }

  const useDeleteAutomationActionMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.automationActions.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.automationActions(spaceId.value).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.automationActions(spaceId.value).detail(id) })
        toast.success(t('composables.automationActions.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.automationActions.deleteError', { error: error.message || 'Unknown error' }) as string
        )
      },
    })
  }

  return {
    useAutomationActionsQuery,
    useAutomationActionQuery,
    useCreateAutomationActionMutation,
    useUpdateAutomationActionMutation,
    useDeleteAutomationActionMutation,
  }
}
