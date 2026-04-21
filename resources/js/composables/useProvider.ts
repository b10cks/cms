import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useProvider() {
  const queryClient = useQueryClient()
  const { isAuthenticated } = useAuth()

  const useProviderStatsQuery = (params: MaybeRef<ProviderStatsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.provider.stats(toValue(params))),
      queryFn: async () => {
        const response = await api.provider.getStats(toValue(params))
        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated)),
    })
  }

  const useProviderNotesQuery = (
    params: MaybeRef<Record<string, unknown>> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.provider.notesList(toValue(params))),
      queryFn: async () => {
        const response = await api.provider.listNotes(toValue(params))
        return response
      },
      enabled: computed(() => !!toValue(isAuthenticated) && !!toValue(enabled)),
    })
  }

  const useCreateProviderNoteMutation = () => {
    return useMutation({
      mutationFn: async (payload: ProviderNotePayload) => {
        const response = await api.provider.create(payload)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.provider.notes() })
        toast.success('Provider note created')
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to create provider note')
      },
    })
  }

  const useUpdateProviderNoteMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: Partial<ProviderNotePayload> }) => {
        const response = await api.provider.update(id, payload)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.provider.notes() })
        toast.success('Provider note updated')
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to update provider note')
      },
    })
  }

  const useDeleteProviderNoteMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await api.provider.delete(id)
        return id
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.provider.notes() })
        toast.success('Provider note deleted')
      },
      onError: (error: Error) => {
        toast.error(error.message || 'Failed to delete provider note')
      },
    })
  }

  return {
    useProviderStatsQuery,
    useProviderNotesQuery,
    useCreateProviderNoteMutation,
    useUpdateProviderNoteMutation,
    useDeleteProviderNoteMutation,
  }
}
