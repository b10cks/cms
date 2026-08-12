import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { MaybeRefOrGetter } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AutomationsQueryParams } from '~/api/resources/automations'
import { createCrudComposable } from '~/lib/crud-composable'
import { toastError, type Translate } from '~/lib/toast-error'

import { queryKeys } from './useQueryClient'

const useAutomationsCrud = createCrudComposable<
  AutomationResource,
  ApiCollectionResponse<AutomationResource>,
  AutomationsQueryParams,
  CreateAutomationPayload,
  UpdateAutomationPayload
>({
  i18nKey: 'automations',
  keys: (spaceId) => queryKeys.automations(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).automations,
  toastValues: (data) => ({ name: data.name }),
})

export function useAutomations(spaceIdSource: MaybeRefOrGetter<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const crud = useAutomationsCrud(spaceIdSource)

  const spaceId = crud.spaceId
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

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
      onError: (error: Error) =>
        toastError(t as Translate, 'composables.automations.triggerError', error),
    })
  }

  return {
    useAutomationsQuery: crud.useListQuery,
    useAutomationQuery: crud.useDetailQuery,
    useAutomationTriggerCatalogQuery,
    useCreateAutomationMutation: crud.useCreateMutation,
    useUpdateAutomationMutation: crud.useUpdateMutation,
    useDeleteAutomationMutation: crud.useDeleteMutation,
    useTriggerAutomationMutation,
  }
}
