import type { MaybeRefOrGetter } from 'vue'

import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { SpaceQueryParams } from '~/api/resources/spaces'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useSpaces() {
  const { t } = useI18n()
  const { isAuthenticated } = useAuth()
  const queryClient = useQueryClient()

  const useSpacesQuery = (params: MaybeRefOrGetter<SpaceQueryParams>) => {
    return useQuery({
      queryKey: queryKeys.spaces.list(params),
      queryFn: async () => {
        const response = await api.spaces.index({
          sort: '+name',
          ...toValue(params),
        })
        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated)),
    })
  }

  const useSpaceQuery = (id: string) => {
    return useQuery({
      queryKey: queryKeys.spaces.detail(id),
      queryFn: async () => {
        const response = await api.spaces.get(id)
        return response.data
      },
      enabled: !!id,
    })
  }

  const useCreateSpaceMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateSpacePayload) => {
        const response = await api.spaces.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.lists() })
        toast.success(t('composables.spaces.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.spaces.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateSpaceMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateSpacePayload }) => {
        const response = await api.spaces.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.detail(data.id) })
        toast.success(t('composables.spaces.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.spaces.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteSpaceMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await api.spaces.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.lists() })
        queryClient.removeQueries({ queryKey: queryKeys.spaces.detail(id) })
        toast.success(t('composables.spaces.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.spaces.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useArchiveSpaceMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await api.spaces.archive(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.lists() })
        queryClient.removeQueries({ queryKey: queryKeys.spaces.detail(id) })
        toast.success(t('composables.spaces.archiveSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.spaces.archiveError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useSpacesQuery: useSpacesQuery,
    useSpaceQuery: useSpaceQuery,
    useCurrentSpaceQuery() {
      const route = useRoute()
      const spaceId = (route.params?.space as string) || null
      if (!spaceId) {
        return { data: null }
      }

      return useSpaceQuery(spaceId as string)
    },

    // Mutations
    useCreateSpaceMutation,
    useUpdateSpaceMutation,
    useArchiveSpaceMutation,
    useDeleteSpaceMutation,
  }
}
