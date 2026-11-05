import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { StepUpCredential } from '~/api/resources/personal-access-tokens'
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

  /** Resolves to the signed URL that starts the provider's OAuth flow. */
  const useStartSocialLinkMutation = () => {
    return useMutation({
      mutationFn: async ({
        provider,
        returnPath,
        credential,
      }: {
        provider: string
        returnPath: string
        credential?: StepUpCredential
      }) => {
        const response = await api.users.startSocialLink(provider, returnPath, credential)
        return response.data.url
      },
      onError: (error: Error & { status?: number }, { provider }) => {
        // 423 asks for the step-up and 403 is a wrong credential. The caller prompts for both.
        if (error.status === 423 || error.status === 403) return

        toast.error(t('labels.account.social.toast.linkFailed', { provider }) as string)
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
    useStartSocialLinkMutation,
    useUnlinkSocialProviderMutation,
  }
}
