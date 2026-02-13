import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { ChangePasswordPayload, UpdateUserPayload } from '~/api/resources/users'
import type { User } from '~/types/users'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useUser() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

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
    return useMutation({
      mutationFn: async (payload: UpdateUserPayload) => {
        const response = await api.users.updateMe(payload)
        return response.data
      },
      onSuccess: (data) => {
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
    return useMutation({
      mutationFn: async (file: File) => {
        const response = await api.users.uploadAvatar(file)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.users.me() })
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

  return {
    useUserQuery,
    useUpdateUserMutation,
    useChangePasswordMutation,
    useUploadAvatarMutation,
  }
}
