import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { ChangePasswordPayload } from '~/api/resources/users'
import type { User } from '~/types/users'

import { useAuth } from './useAuth'
import { queryKeys } from './useQueryClient'

export function useUser() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const { setUser } = useAuth()

  /**
   * Seed the auth state and the `users.me` cache with a user the server just
   * returned. Invalidating instead would fire a redundant `GET /users/me` and,
   * because the key is a prefix of the token and social-link keys, refetch
   * those too.
   */
  const cacheUser = (data: User) => {
    setUser(data)
    queryClient.setQueryData<User>(queryKeys.users.me(), data)
  }

  const useUserQuery = () => {
    return useQuery({
      queryKey: queryKeys.users.me(),
      queryFn: async () => {
        const response = await api.users.getMe()
        return response.data
      },
    })
  }

  const useUpdateUserMutation = () => {
    return useMutation<User, Error, { firstname?: string; lastname?: string }>({
      mutationFn: async (payload: { firstname?: string; lastname?: string }) => {
        const response = await api.users.updateMe(payload)
        return response.data
      },
      onSuccess: (data) => {
        cacheUser(data)
        toast.success(t('labels.account.profile.toast.updated') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.account.profile.toast.updateFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useChangePasswordMutation = () => {
    return useMutation({
      mutationFn: async (payload: ChangePasswordPayload) => {
        await api.users.changePassword(payload)
      },
      onSuccess: () => {
        toast.success(t('labels.account.security.toast.passwordChanged') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.account.security.toast.passwordChangeFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useSocialLinksQuery = () => {
    return useQuery({
      queryKey: queryKeys.users.socialLinks(),
      queryFn: async () => {
        const response = await api.users.socialLinks()
        return response.data
      },
    })
  }

  const useUnlinkSocialProviderMutation = () => {
    return useMutation({
      mutationFn: async (provider: string) => {
        await api.users.unlinkSocialProvider(provider)
      },
      onSuccess: async (_data, provider) => {
        await queryClient.invalidateQueries({ queryKey: queryKeys.users.socialLinks() })
        toast.success(t('labels.account.social.toast.unlinked', { provider }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.account.social.toast.unlinkFailed', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useUserQuery,
    useUpdateUserMutation,
    useChangePasswordMutation,
    cacheUser,
    useSocialLinksQuery,
    useUnlinkSocialProviderMutation,
  }
}
