import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { ComputedRef, MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AutomationsQueryParams } from '~/api/resources/automations'

import { queryKeys } from './useQueryClient'

type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>

export function useAutomations(spaceIdRef: MaybeRefOrComputed<string>) {
  const queryClient = useQueryClient()
  const { t } = useI18n()

  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useAutomationsQuery = (paramsRef: MaybeRefOrComputed<AutomationsQueryParams> = {}) => {
    const params = computed(() => unref(paramsRef))

    return useQuery({
      queryKey: computed(() => queryKeys.automations(spaceId.value).list(params.value)),
      queryFn: async () => {
        return await spaceAPI.value.automations.index(params.value)
      },
      enabled: computed(() => !!spaceId.value),
      placeholderData: keepPreviousData,
    })
  }

  const useAutomationQuery = (idRef: MaybeRefOrComputed<string>) => {
    const id = computed(() => unref(idRef))

    return useQuery({
      queryKey: computed(() => queryKeys.automations(spaceId.value).detail(id.value)),
      queryFn: async () => {
        const response = await spaceAPI.value.automations.get(id.value)
        return response.data
      },
      enabled: computed(() => !!spaceId.value && !!id.value),
    })
  }

  const useAutomationTriggerCatalogQuery = () => {
    return useQuery({
      queryKey: computed(() => queryKeys.automations(spaceId.value).list({ triggerCatalog: true })),
      queryFn: async () => {
        const response = await spaceAPI.value.automations.triggerCatalog()
        return response.data
      },
      enabled: computed(() => !!spaceId.value),
      staleTime: 5 * 60 * 1000,
    })
  }

  const useCreateAutomationMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateAutomationPayload) => {
        const response = await spaceAPI.value.automations.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.automations(spaceId.value).lists() })
        toast.success(t('composables.automations.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.automations.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateAutomationMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateAutomationPayload }) => {
        const response = await spaceAPI.value.automations.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.automations(spaceId.value).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.automations(spaceId.value).detail(data.id),
        })
        toast.success(t('composables.automations.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.automations.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteAutomationMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.automations.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.automations(spaceId.value).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.automations(spaceId.value).detail(id) })
        toast.success(t('composables.automations.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.automations.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useTriggerAutomationMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload?: TriggerAutomationPayload }) => {
        const response = await spaceAPI.value.automations.trigger(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.automations(spaceId.value).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.automations(spaceId.value).detail(data.id),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.automationActions(spaceId.value).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.automationExecutions(spaceId.value).lists(),
        })
        toast.success(t('composables.automations.triggerSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.automations.triggerError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useAutomationsQuery,
    useAutomationQuery,
    useAutomationTriggerCatalogQuery,
    useCreateAutomationMutation,
    useUpdateAutomationMutation,
    useDeleteAutomationMutation,
    useTriggerAutomationMutation,
  }
}
