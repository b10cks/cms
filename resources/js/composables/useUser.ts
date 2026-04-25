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
        setUser(data)
        queryClient.setQueryData<User>(queryKeys.users.me(), data)
        queryClient.invalidateQueries({ queryKey: queryKeys.users.me() })
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

  const useUploadAvatarMutation = () => {
    return useMutation<{ avatar: string }, Error, File>({
      mutationFn: async (file: File) => {
        const response = await api.users.uploadAvatar(file)
        return response.data
      },
      onSuccess: async () => {
        await queryClient.invalidateQueries({ queryKey: queryKeys.users.me() })
        const refreshedUser = queryClient.getQueryData<User>(queryKeys.users.me())
        if (refreshedUser) {
          setUser(refreshedUser)
        }
        toast.success(t('labels.account.profile.toast.avatarUploaded') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.account.profile.toast.avatarUploadFailed', {
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
    useUploadAvatarMutation,
    useSocialLinksQuery,
    useUnlinkSocialProviderMutation,
  }
}
