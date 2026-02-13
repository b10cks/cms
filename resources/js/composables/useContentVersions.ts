import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { ContentVersionsQueryParams } from '~/api/resources/content-versions'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useContentVersions(spaceId: MaybeRef<string>, contentId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))
  const versionsAPI = computed(() => spaceAPI.value.contentVersions(toValue(contentId)))

  const useContentVersionsQuery = (params: MaybeRef<ContentVersionsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.contentVersions(spaceId, contentId).list(params)),
      queryFn: async () => {
        const response = await versionsAPI.value.index({
          ...toValue(params),
          sort: '-created_at',
        })
        return response.data
      },
    })
  }

  const useContentVersionQuery = (versionId: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.contentVersions(spaceId, contentId).detail(versionId)),
      queryFn: async () => {
        const response = await versionsAPI.value.get(toValue(versionId))
        return response.data
      },
    })
  }

  // Mutation to set a version as the current version
  const useSetCurrentVersionMutation = () => {
    return useMutation({
      mutationFn: async (versionId: string) => {
        await versionsAPI.value.current(versionId)
        return { id: versionId }
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, contentId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, contentId).detail(data.id),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contents(spaceId).detail(contentId),
        })
        toast.success(t('composables.contentVersions.setCurrentSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.contentVersions.setCurrentError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateVersionMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: never }) => {
        const response = await versionsAPI.value.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, contentId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, contentId).detail(data.id),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contents(spaceId).detail(contentId),
        })
        toast.success(t('composables.contentVersions.setCurrentSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.contentVersions.setCurrentError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  // Mutation to publish a version
  const usePublishVersionMutation = () => {
    return useMutation({
      mutationFn: async (versionId: string) => {
        await versionsAPI.value.publish(versionId)
        return { id: versionId }
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, contentId).lists(),
        })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, contentId).detail(data.id),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contents(spaceId).detail(contentId),
        })
        toast.success(t('composables.contentVersions.publishSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.contentVersions.publishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useContentVersionsQuery,
    useContentVersionQuery,

    // Mutations
    useUpdateVersionMutation,
    useSetCurrentVersionMutation,
    usePublishVersionMutation,
  }
}
