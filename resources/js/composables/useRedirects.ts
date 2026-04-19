import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { RedirectsQueryParams } from '~/api/resources/redirects'
import type {
  CreateRedirectPayload,
  RedirectImportExportFormat,
  UpdateRedirectPayload,
} from '~/types/redirects'

import { queryKeys } from './useQueryClient'

export function useRedirects(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useRedirectsQuery = (
    params: MaybeRef<RedirectsQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.redirects(spaceId).list(params)),
      queryFn: async () => {
        const response = await spaceAPI.value.redirects.index({
          sort: 'source',
          ...toValue(params),
        })
        return response
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(enabled)),
    })
  }

  const useRedirectQuery = (id: MaybeRef<string>, enabled: MaybeRef<boolean> = true) => {
    return useQuery({
      queryKey: computed(() => queryKeys.redirects(spaceId).detail(id)),
      queryFn: async () => {
        const response = await spaceAPI.value.redirects.get(toValue(id))
        return response.data
      },
      enabled: computed(() => !!toValue(spaceId) && !!toValue(id) && !!toValue(enabled)),
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

  const useExportRedirectsMutation = () => {
    return useMutation({
      mutationFn: async (params: RedirectsQueryParams & { as: RedirectImportExportFormat }) => {
        return spaceAPI.value.redirects.export(params)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.redirects.exportError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useImportRedirectsMutation = () => {
    return useMutation({
      mutationFn: async (file: File) => {
        return spaceAPI.value.redirects.import(file)
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.redirects(spaceId).lists() })
        toast.success(t('composables.redirects.importSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.redirects.importError', {
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
    useExportRedirectsMutation,
    useImportRedirectsMutation,
  }
}
