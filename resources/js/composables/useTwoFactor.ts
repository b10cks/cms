import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type {
  TwoFactorConfirmPayload,
  TwoFactorDisablePayload,
  TwoFactorVerifyPayload,
} from '~/api/resources/two-factor'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useTwoFactor() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const useTwoFactorStatusQuery = () => {
    return useQuery({
      queryKey: queryKeys.twoFactor.status(),
      queryFn: async () => {
        return await api.twoFactor.status()
      },
    })
  }

  const useTwoFactorSetupMutation = () => {
    return useMutation({
      mutationFn: async () => {
        return await api.twoFactor.setup()
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.twoFactor.setup.error', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useTwoFactorConfirmMutation = () => {
    return useMutation({
      mutationFn: async (payload: TwoFactorConfirmPayload) => {
        return await api.twoFactor.confirm(payload)
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.twoFactor.status() })
        toast.success(t('labels.twoFactor.setup.confirmed') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.twoFactor.setup.confirmError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useTwoFactorVerifyMutation = () => {
    return useMutation({
      mutationFn: async (payload: TwoFactorVerifyPayload) => {
        return await api.twoFactor.verify(payload)
      },
    })
  }

  const useTwoFactorDisableMutation = () => {
    return useMutation({
      mutationFn: async (payload: TwoFactorDisablePayload) => {
        return await api.twoFactor.disable(payload)
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.twoFactor.status() })
        toast.success(t('labels.twoFactor.disabled') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.twoFactor.disableError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useRegenerateBackupCodesMutation = () => {
    return useMutation({
      mutationFn: async () => {
        return await api.twoFactor.regenerateBackupCodes()
      },
      onSuccess: () => {
        toast.success(t('labels.twoFactor.backupCodes.regenerated') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('labels.twoFactor.backupCodes.regenerateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useTwoFactorStatusQuery,
    useTwoFactorSetupMutation,
    useTwoFactorConfirmMutation,
    useTwoFactorVerifyMutation,
    useTwoFactorDisableMutation,
    useRegenerateBackupCodesMutation,
  }
}
