import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useTokens(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useTokensQuery = (params: MaybeRef<TokenQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.tokens(spaceId).list(params)),
      queryFn: async () => {
        const response = await spaceAPI.value.tokens.index({
          ...toValue(params),
          sort: '+name',
        })
        return response.data
      },
    })
  }

  // Mutation to create a token
  const useCreateTokenMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateTokenRequest) => {
        return await spaceAPI.value.tokens.create(payload)
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.tokens(spaceId).lists() })
        toast.success(t('composables.tokens.createSuccess', { name: data.data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.tokens.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  // Mutation to delete a token
  const useDeleteTokenMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.tokens.delete(id)
        return id
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.tokens(spaceId).lists() })
        toast.success(t('composables.tokens.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.tokens.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useTokensQuery,

    // Mutations
    useCreateTokenMutation,
    useDeleteTokenMutation,
  }
}
