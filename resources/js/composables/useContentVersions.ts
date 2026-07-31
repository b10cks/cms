import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { ContentVersionsQueryParams } from '~/api/resources/content-versions'

import { queryKeys } from './useQueryClient'

export function useContentVersions(
  spaceId: MaybeRef<string>,
  contentId: MaybeRef<string | null | undefined>
) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))
  const resolvedContentId = computed(() => toValue(contentId) || '')
  const versionsAPI = computed(() => spaceAPI.value.contentVersions(resolvedContentId.value))
  const hasContentId = computed(() => !!toValue(contentId))

  // Mutations are gated exactly like the queries: without a content id the
  // request would address `/contents//versions/{id}/…`.
  const requireContentId = () => {
    if (!hasContentId.value) {
      throw new Error('Content ID is required')
    }
  }

  const invalidateVersionQueries = (versionId?: string) => {
    queryClient.invalidateQueries({
      queryKey: queryKeys.contentVersions(spaceId, resolvedContentId.value).lists(),
    })
    queryClient.invalidateQueries({
      queryKey: queryKeys.contents(spaceId).lists(),
    })
    queryClient.invalidateQueries({
      queryKey: queryKeys.contents(spaceId).detail(resolvedContentId.value),
    })

    if (versionId) {
      queryClient.invalidateQueries({
        queryKey: queryKeys.contentVersions(spaceId, resolvedContentId.value).detail(versionId),
      })
    }
  }

  const useContentVersionsQuery = (params: MaybeRef<ContentVersionsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() =>
        queryKeys.contentVersions(spaceId, resolvedContentId.value).list(toValue(params))
      ),
      queryFn: async () => {
        const response = await versionsAPI.value.index({
          sort: '-created_at',
          ...toValue(params),
        })
        return response.data
      },
      enabled: hasContentId,
      placeholderData: keepPreviousData,
    })
  }

  const useContentVersionQuery = (versionId: MaybeRef<string | null | undefined>) => {
    const resolvedVersionId = computed(() => toValue(versionId) || '')

    return useQuery({
      queryKey: computed(() =>
        queryKeys.contentVersions(spaceId, resolvedContentId.value).detail(resolvedVersionId.value)
      ),
      queryFn: async () => {
        const currentVersionId = toValue(versionId)
        if (!currentVersionId) {
          throw new Error('Version ID is required')
        }

        const response = await versionsAPI.value.get(currentVersionId)
        return response.data
      },
      enabled: computed(() => hasContentId.value && !!toValue(versionId)),
    })
  }

  // Mutation to set a version as the current version
  const useSetCurrentVersionMutation = () => {
    return useMutation({
      mutationFn: async (versionId: string) => {
        requireContentId()
        await versionsAPI.value.current(versionId)
        return { id: versionId }
      },
      onSuccess: (data) => {
        invalidateVersionQueries(data.id)
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
      mutationFn: async ({ id, payload }: { id: string; payload: { message?: string | null } }) => {
        requireContentId()
        const response = await versionsAPI.value.update(id, payload as never)
        return response.data
      },
      onSuccess: (data) => {
        invalidateVersionQueries(data.id)
        toast.success(t('composables.contentVersions.updateSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.contentVersions.updateError', {
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
        requireContentId()
        await versionsAPI.value.publish(versionId)
        return { id: versionId }
      },
      onSuccess: (data) => {
        invalidateVersionQueries(data.id)
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
