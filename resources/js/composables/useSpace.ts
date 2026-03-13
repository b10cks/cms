import type { MaybeRefOrGetter } from 'vue'

import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { SpaceQueryParams } from '~/api/resources/spaces'
import type { ApiResponse } from '~/types'

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

  const useSpaceQuery = (id: MaybeRefOrGetter<string | null | undefined>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.spaces.detail(toValue(id) || '')),
      queryFn: async () => {
        const resolvedId = toValue(id)

        if (!resolvedId) {
          throw new Error('Space ID is required')
        }

        const response = await api.spaces.get(resolvedId)
        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated) && !!toValue(id)),
    })
  }

  const useCreateSpaceMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateSpacePayload) => {
        // Return the full response so callers can access top-level fields like checkout_url
        return api.spaces.create(payload) as Promise<ApiResponse<SpaceResource> & { checkout_url?: string | null }>
      },
      onSuccess: (response) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.spaces.lists() })
        toast.success(t('composables.spaces.createSuccess', { name: response.data.name }) as string)
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
      const spaceId = computed(() => (route.params?.space as string) || null)

      return useSpaceQuery(spaceId)
    },

    // Mutations
    useCreateSpaceMutation,
    useUpdateSpaceMutation,
    useArchiveSpaceMutation,
    useDeleteSpaceMutation,
  }
}
