import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type {
  PersonalAccessTokenCreatePayload,
  PersonalAccessTokenQueryParams,
} from '~/api/resources/personal-access-tokens'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function usePersonalAccessTokens() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const useTokensQuery = (params: MaybeRef<PersonalAccessTokenQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.personalAccessTokens.list(params)),
      queryFn: async () => {
        return await api.personalAccessTokens.index({
          per_page: 100,
          ...toValue(params),
        })
      },
    })
  }

  const useCreateTokenMutation = () => {
    return useMutation({
      mutationFn: async (payload: PersonalAccessTokenCreatePayload) => {
        return await api.personalAccessTokens.create(payload)
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.personalAccessTokens.lists() })
        toast.success(
          t('composables.personalAccessTokens.createSuccess', { name: data.token.name }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.personalAccessTokens.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteTokenMutation = () => {
    return useMutation({
      mutationFn: async (id: number) => {
        await api.personalAccessTokens.delete(id)
        return id
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.personalAccessTokens.lists() })
        toast.success(t('composables.personalAccessTokens.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.personalAccessTokens.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useTokensQuery,
    useCreateTokenMutation,
    useDeleteTokenMutation,
  }
}
