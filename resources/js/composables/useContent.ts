import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { ContentsQueryParams } from '~/api/resources/contents'
import type { ContentResource, CreateContentPayload, UpdateContentPayload } from '~/types/contents'

import { queryKeys } from './useQueryClient'

export function useContent(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const invalidateContentFamily = (content: ContentResource) => {
    const familyContentIds = new Set<string>()

    if (content.id) {
      familyContentIds.add(content.id)
    }

    if (content.i18n_canonical_id) {
      familyContentIds.add(content.i18n_canonical_id)
    }

    content.language_versions.forEach((version) => {
      if (version.content_id) {
        familyContentIds.add(version.content_id)
      }
    })

    familyContentIds.forEach((contentId) => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.contents(spaceId).detail(contentId),
      })
      queryClient.invalidateQueries({
        queryKey: queryKeys.contentVersions(spaceId, contentId).lists(),
      })
    })
  }

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
  const useContentQuery = (id: MaybeRef<string | null | undefined>) => {
    const resolvedId = computed(() => toValue(id) || '')

    return useQuery({
      queryKey: computed(() => queryKeys.contents(spaceId).detail(resolvedId.value)),
      queryFn: async () => {
        const contentId = toValue(id)
        if (!contentId) {
          throw new Error('Content ID is required')
        }

        const response = await spaceAPI.value.contents.get(contentId)
        return response.data
      },
      enabled: computed(() => !!toValue(id)),
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
        invalidateContentFamily(data)

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
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        invalidateContentFamily(data)

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
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        invalidateContentFamily(data)

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
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        invalidateContentFamily(data)

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
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        invalidateContentFamily(data)

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

  const useBulkCreateContentMutation = () => {
    return useMutation({
      mutationFn: async (payload: {
        items: Array<{
          name: string
          slug: string
          block_id: string
          parent_id?: string | null
          temp_id?: string
        }>
      }) => {
        const response = await spaceAPI.value.contents.bulkCreate(payload)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.bulkCreateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useMoveContentMutation = () => {
    return useMutation({
      mutationFn: async ({
        id,
        payload,
      }: {
        id: string
        payload: { parent_id?: string | null; position?: number }
      }) => {
        const response = await spaceAPI.value.contents.move(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        invalidateContentFamily(data)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.moveError', {
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
    useBulkCreateContentMutation,
    useMoveContentMutation,
  }
}
