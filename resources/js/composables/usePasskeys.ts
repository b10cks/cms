import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { StepUpCredential } from '~/api/resources/personal-access-tokens'
import { createPasskey, getPasskey, isPasskeyCancellation, passkeysSupported } from '~/lib/passkeys'

import { queryKeys } from './useQueryClient'

type MutationError = Error & { status?: number }

/** 423 asks for a step-up, 403 is a wrong credential: the caller prompts for both. */
const isStepUpError = (error: MutationError) => error.status === 423 || error.status === 403

export function usePasskeys() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const invalidate = () => queryClient.invalidateQueries({ queryKey: queryKeys.passkeys() })

  const usePasskeysQuery = () => {
    return useQuery({
      queryKey: queryKeys.passkeys(),
      queryFn: async () => (await api.passkeys.index()).data,
    })
  }

  /** Asks the API for creation options (step-up first), lets the browser create the passkey, stores it. */
  const useAddPasskeyMutation = () => {
    return useMutation({
      mutationFn: async ({ name, credential }: { name: string; credential?: StepUpCredential }) => {
        const options = await api.passkeys.creationOptions(credential)
        const passkey = await createPasskey(options)

        return (await api.passkeys.store({ name, credential: passkey })).data
      },
      onSuccess: (passkey) => {
        void invalidate()
        toast.success(t('labels.account.passkeys.toast.added', { name: passkey.name }) as string)
      },
      onError: (error: MutationError) => {
        if (isStepUpError(error) || isPasskeyCancellation(error)) return

        toast.error(t('labels.account.passkeys.toast.addFailed', { error: error.message }) as string)
      },
    })
  }

  const useRenamePasskeyMutation = () => {
    return useMutation({
      mutationFn: async ({ id, name }: { id: string; name: string }) =>
        (await api.passkeys.rename(id, name)).data,
      onSuccess: () => void invalidate(),
      onError: (error: MutationError) => {
        toast.error(
          t('labels.account.passkeys.toast.renameFailed', { error: error.message }) as string
        )
      },
    })
  }

  const useDeletePasskeyMutation = () => {
    return useMutation({
      mutationFn: async ({ id, credential }: { id: string; credential?: StepUpCredential }) =>
        api.passkeys.delete(id, credential),
      onSuccess: () => {
        void invalidate()
        toast.success(t('labels.account.passkeys.toast.removed') as string)
      },
      onError: (error: MutationError) => {
        if (isStepUpError(error)) return

        toast.error(
          t('labels.account.passkeys.toast.removeFailed', { error: error.message }) as string
        )
      },
    })
  }

  /**
   * Proves the signed-in user is present with one of their passkeys. For a
   * few minutes afterwards the API accepts step-up requests without a TOTP
   * code or password, so the caller simply retries without a credential.
   */
  const useConfirmPasskeyMutation = () => {
    return useMutation({
      mutationFn: async () => {
        const options = await api.passkeys.confirmOptions()
        await api.passkeys.confirm(await getPasskey(options))
      },
      onError: (error: MutationError) => {
        if (isPasskeyCancellation(error)) return

        toast.error(
          t('labels.account.passkeys.toast.confirmFailed', { error: error.message }) as string
        )
      },
    })
  }

  return {
    supported: passkeysSupported(),
    usePasskeysQuery,
    useAddPasskeyMutation,
    useRenamePasskeyMutation,
    useDeletePasskeyMutation,
    useConfirmPasskeyMutation,
  }
}
