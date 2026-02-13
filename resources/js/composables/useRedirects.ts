import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { RedirectsQueryParams } from '~/api/resources/redirects'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useRedirects(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useRedirectsQuery = (params: MaybeRef<RedirectsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.redirects(spaceId).list(params)),
      queryFn: async () => {
        const response = await spaceAPI.value.redirects.index({
          sort: 'source',
          ...toValue(params),
        })
        return response
      },
    })
  }

  const useRedirectQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.redirects(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.redirects.get(toValue(id))
        return response.data
      },
    })
  }

  const useCreateRedirectMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateRedirectPayload) => {
        const response = await spaceAPI.value.redirects.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.redirects(spaceId).lists() })
        toast.success(t('composables.redirects.createSuccess', { source: data.source }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.redirects.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateRedirectMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateRedirectPayload }) => {
        const response = await spaceAPI.value.redirects.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.redirects(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.redirects(spaceId).detail(data.id),
        })
        toast.success(t('composables.redirects.updateSuccess', { source: data.source }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.redirects.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteRedirectMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.redirects.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.redirects(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.redirects(spaceId).detail(id) })
        toast.success(t('composables.redirects.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.redirects.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useResetRedirectStatsMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await spaceAPI.value.redirects.reset(id)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.redirects(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.redirects(spaceId).detail(data.id),
        })
        toast.success(
          t('composables.redirects.resetStatsSuccess', { source: data.source }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.redirects.resetStatsError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useRedirectsQuery,
    useRedirectQuery,

    // Mutations
    useCreateRedirectMutation,
    useUpdateRedirectMutation,
    useDeleteRedirectMutation,
    useResetRedirectStatsMutation,
  }
}
