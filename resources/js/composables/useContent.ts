import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { ContentsQueryParams } from '~/api/resources/contents'
import type { CreateContentPayload, UpdateContentPayload } from '~/types/contents'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useContent(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useContentsQuery = (params: MaybeRef<ContentsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.contents(spaceId).list(params)),
      queryFn: async () => {
        const response = await spaceAPI.value.contents.index(toValue(params))
        return response
      },
    })
  }

  // Query to fetch a single content item
  const useContentQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.contents(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.contents.get(toValue(id))
        return response.data
      },
    })
  }

  // Query to fetch children of a specific content item
  const useContentChildrenQuery = (parentId: MaybeRef<string | null>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.contents(spaceId).list({ parent: parentId })),
      queryFn: async () => {
        const response = await spaceAPI.value.contents.index({
          filter: {
            parent_id: toValue(parentId),
          },
        })
        return response.data
      },
    })
  }

  const useCreateContentMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateContentPayload) => {
        const response = await spaceAPI.value.contents.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })

        toast.success(t('composables.content.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateContentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateContentPayload }) => {
        const response = await spaceAPI.value.contents.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contents(spaceId).detail(data.id),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, data.id).lists(),
        })

        toast.success(t('composables.content.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const usePublishContentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateContentPayload }) => {
        const response = await spaceAPI.value.contents.publish(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contents(spaceId).detail(data.id),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, data.id).lists(),
        })

        toast.success(t('composables.content.publishSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.publishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useScheduleContentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateContentPayload }) => {
        const response = await spaceAPI.value.contents.schedule(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contents(spaceId).detail(data.id),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, data.id).lists(),
        })

        toast.success(t('composables.content.scheduleSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.scheduleError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUnpublishContentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateContentPayload }) => {
        const response = await spaceAPI.value.contents.unpublish(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contents(spaceId).detail(data.id),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.contentVersions(spaceId, data.id).lists(),
        })

        toast.success(t('composables.content.unpublishSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.unpublishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDuplicateContentMutation = () => {
    return useMutation({
      mutationFn: async ({
        id,
        options,
      }: {
        id: string
        options?: { name?: string; parent_id?: string | null }
      }) => {
        const response = await spaceAPI.value.contents.duplicate(id, options)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })

        toast.success(t('composables.content.duplicateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.duplicateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteContentMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.contents.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.contents(spaceId).detail(id) })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })

        toast.success(t('composables.content.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useContentsQuery,
    useContentQuery,
    useContentChildrenQuery,

    // Mutations
    useCreateContentMutation,
    useUpdateContentMutation,
    usePublishContentMutation,
    useScheduleContentMutation,
    useUnpublishContentMutation,
    useDuplicateContentMutation,
    useDeleteContentMutation,
  }
}
