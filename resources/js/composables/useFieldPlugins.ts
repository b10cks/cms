import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { FieldPluginsQueryParams } from '~/api/resources/field-plugins'
import type { CreateFieldPluginPayload, UpdateFieldPluginPayload } from '~/types/field-plugins'

import { queryKeys } from './useQueryClient'

export function useFieldPlugins(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useFieldPluginsQuery = (
    params: MaybeRef<FieldPluginsQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.fieldPlugins(spaceId).list(params)),
      queryFn: async () => {
        return await spaceAPI.value.fieldPlugins.index({
          sort: '+name',
          ...toValue(params),
        })
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(enabled)),
      placeholderData: keepPreviousData,
    })
  }

  const useFieldPluginQuery = (id: MaybeRef<string>, enabled: MaybeRef<boolean> = true) => {
    return useQuery({
      queryKey: computed(() => queryKeys.fieldPlugins(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.fieldPlugins.get(toValue(id))
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(id) && !!toValue(enabled)),
    })
  }

  const useCreateFieldPluginMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateFieldPluginPayload) => {
        const response = await spaceAPI.value.fieldPlugins.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.fieldPlugins(spaceId).lists() })
        toast.success(t('composables.fieldPlugins.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.fieldPlugins.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateFieldPluginMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateFieldPluginPayload }) => {
        // `handle` is immutable — content already stored against a plugin is keyed
        // by it. The type omits it, but a cast would otherwise put it on the wire
        // and leave the server as the only gatekeeper.
        const { handle: _handle, ...updatable } = payload as UpdateFieldPluginPayload & {
          handle?: string
        }
        const response = await spaceAPI.value.fieldPlugins.update(id, updatable)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.fieldPlugins(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.fieldPlugins(spaceId).detail(data.id),
        })
        toast.success(t('composables.fieldPlugins.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.fieldPlugins.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteFieldPluginMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.fieldPlugins.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.fieldPlugins(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.fieldPlugins(spaceId).detail(id) })
        toast.success(t('composables.fieldPlugins.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.fieldPlugins.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useFieldPluginsQuery,
    useFieldPluginQuery,
    useCreateFieldPluginMutation,
    useUpdateFieldPluginMutation,
    useDeleteFieldPluginMutation,
  }
}
