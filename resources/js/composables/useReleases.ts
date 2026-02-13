import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type {
  AssignVersionsRequest,
  CreateReleaseRequest,
  Release,
  ReleaseState,
  UpdateReleaseRequest,
} from '~/types/releases'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useReleases(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useReleasesQuery = (params: MaybeRef<any> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.releases(spaceId).list(params)),
      queryFn: async () => {
        return await spaceAPI.value.releases.index(toValue(params))
      },
    })
  }

  const useReleaseQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.releases(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.releases.getDetail(toValue(id))
        return response.data
      },
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
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.releases(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.releases(spaceId).detail(data.id),
        })

        const versionCount = (data as any).versions?.length || 1
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
