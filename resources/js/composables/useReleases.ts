import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type {
  AssignVersionsRequest,
  CreateReleaseRequest,
  Release,
  ReleaseState,
  UpdateReleaseRequest,
} from '~/types/releases'

import { queryKeys } from './useQueryClient'

export function useReleases(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useReleasesQuery = (params: MaybeRef<any> = {}, enabled: MaybeRef<boolean> = true) => {
    return useQuery({
      queryKey: computed(() => queryKeys.releases(spaceId).list(params)),
      queryFn: async () => {
        return await spaceAPI.value.releases.index(toValue(params))
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(enabled)),
      placeholderData: keepPreviousData,
    })
  }

  const useReleaseQuery = (id: MaybeRef<string>, enabled: MaybeRef<boolean> = true) => {
    return useQuery({
      queryKey: computed(() => queryKeys.releases(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.releases.getDetail(toValue(id))
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(id) && !!toValue(enabled)),
    })
  }

  const useCreateReleaseMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateReleaseRequest) => {
        const response = await spaceAPI.value.releases.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })

        toast.success(t('composables.releases.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.releases.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateReleaseMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateReleaseRequest }) => {
        const response = await spaceAPI.value.releases.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.releases(spaceId).detail(data.id),
        })

        toast.success(t('composables.releases.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.releases.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useCommitReleaseMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await spaceAPI.value.releases.commit(id)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.releases(spaceId).detail(data.id),
        })

        toast.success(t('composables.releases.commitSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.releases.commitError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useCancelReleaseMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await spaceAPI.value.releases.cancel(id)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.releases(spaceId).detail(data.id),
        })

        toast.success(t('composables.releases.cancelSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.releases.cancelError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const usePublishReleaseMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await spaceAPI.value.releases.publish(id)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.releases(spaceId).detail(data.id),
        })
        // Publishing a release publishes every content version it holds.
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).details() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })

        toast.success(t('composables.releases.publishSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.releases.publishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteReleaseMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.releases.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.releases(spaceId).detail(id) })

        toast.success(t('composables.releases.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.releases.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useAssignVersionsMutation = () => {
    return useMutation({
      mutationFn: async ({
        releaseId,
        payload,
      }: {
        releaseId: string
        payload: AssignVersionsRequest
      }) => {
        const response = await spaceAPI.value.releases.assignVersions(releaseId, payload)
        return response.data
      },
      onSuccess: (data, variables) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.releases(spaceId).detail(data.id),
        })

        // The request is the only reliable count: the response carries the
        // release, not the versions that were just assigned.
        const versionCount = variables.payload.version_ids.length
        toast.success(
          t('composables.releases.assignVersionsSuccess', {
            count: versionCount,
            name: data.name,
          }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.releases.assignVersionsError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useRemoveVersionsMutation = () => {
    return useMutation({
      mutationFn: async ({
        releaseId,
        payload,
      }: {
        releaseId: string
        payload: AssignVersionsRequest
      }) => {
        const response = await spaceAPI.value.releases.removeVersions(releaseId, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.releases(spaceId).detail(data.id),
        })

        toast.success(
          t('composables.releases.removeVersionsSuccess', { name: data.name }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.releases.removeVersionsError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  function getReleaseState(release: Release): ReleaseState {
    if (release.published_at) {
      return 'published'
    }

    if (!release.committed_at) {
      return 'draft'
    }

    // No publish date at all: committed and ready to go, whether the field is
    // null, undefined or an empty string.
    if (!release.publish_at) {
      return 'pending'
    }

    const publishAt = new Date(release.publish_at)
    const now = new Date()

    return publishAt <= now ? 'pending' : 'scheduled'
  }

  return {
    // Queries
    useReleasesQuery,
    useReleaseQuery,
    getReleaseState,

    // Mutations
    useCreateReleaseMutation,
    useUpdateReleaseMutation,
    useCommitReleaseMutation,
    useCancelReleaseMutation,
    usePublishReleaseMutation,
    useDeleteReleaseMutation,
    useAssignVersionsMutation,
    useRemoveVersionsMutation,
  }
}
