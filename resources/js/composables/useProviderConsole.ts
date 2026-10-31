import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import { toastError } from '~/lib/toast-error'
import type {
  InstanceAuditActionKey,
  ProviderUserQueryParams,
  SuspendUserPayload,
  TransferOwnershipPayload,
} from '~/types/provider-console'

import { queryKeys } from './useQueryClient'

/** Queries and root actions of the provider console (`/provider`). */
export function useProviderConsole() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const refresh = () => queryClient.invalidateQueries({ queryKey: queryKeys.providerConsole.all() })

  const useTenantsQuery = () =>
    useQuery({
      queryKey: queryKeys.providerConsole.tenants(),
      queryFn: async () => (await api.providerConsole.tenants()).data,
    })

  const useUsersQuery = (params: MaybeRef<ProviderUserQueryParams>) =>
    useQuery({
      queryKey: computed(() => queryKeys.providerConsole.userList(toValue(params))),
      queryFn: () => api.providerConsole.users(toValue(params)),
      placeholderData: keepPreviousData,
    })

  const useUserQuery = (id: MaybeRef<string | null>) =>
    useQuery({
      queryKey: computed(() => queryKeys.providerConsole.user(toValue(id) ?? '')),
      queryFn: async () => (await api.providerConsole.user(toValue(id) as string)).data,
      enabled: computed(() => !!toValue(id)),
    })

  const useUsageQuery = (month: MaybeRef<string>) =>
    useQuery({
      queryKey: computed(() => queryKeys.providerConsole.usage(toValue(month))),
      queryFn: async () => (await api.providerConsole.usage(toValue(month))).data,
      placeholderData: keepPreviousData,
    })

  const useHealthQuery = () =>
    useQuery({
      queryKey: queryKeys.providerConsole.health(),
      queryFn: async () => (await api.providerConsole.health()).data,
      refetchInterval: 60_000,
    })

  const useRecheckHealthMutation = () =>
    useMutation({
      mutationFn: async () => (await api.providerConsole.health(true)).data,
      onSuccess: (data) => queryClient.setQueryData(queryKeys.providerConsole.health(), data),
    })

  const useAuditQuery = (
    params: MaybeRef<{ page?: number; per_page?: number; action?: InstanceAuditActionKey }>
  ) =>
    useQuery({
      queryKey: computed(() => queryKeys.providerConsole.audit(toValue(params))),
      queryFn: () => api.providerConsole.audit(toValue(params)),
      placeholderData: keepPreviousData,
    })

  const useSuspendMutation = () =>
    useMutation({
      mutationFn: ({ id, payload }: { id: string; payload: SuspendUserPayload }) =>
        api.providerConsole.suspend(id, payload),
      onSuccess: () => {
        void refresh()
        toast.success(t('providerConsole.users.toasts.suspended'))
      },
      onError: (error) => toastError(t, 'providerConsole.users.toasts.failed', error),
    })

  const useRestoreMutation = () =>
    useMutation({
      mutationFn: (id: string) => api.providerConsole.restore(id),
      onSuccess: () => {
        void refresh()
        toast.success(t('providerConsole.users.toasts.restored'))
      },
      onError: (error) => toastError(t, 'providerConsole.users.toasts.failed', error),
    })

  const useSignOutMutation = () =>
    useMutation({
      mutationFn: (id: string) => api.providerConsole.signOut(id),
      onSuccess: () => {
        void refresh()
        toast.success(t('providerConsole.users.toasts.signedOut'))
      },
      onError: (error) => toastError(t, 'providerConsole.users.toasts.failed', error),
    })

  const useTransferOwnershipMutation = () =>
    useMutation({
      mutationFn: ({ id, payload }: { id: string; payload: TransferOwnershipPayload }) =>
        api.providerConsole.transferOwnership(id, payload),
      onSuccess: () => {
        void refresh()
        toast.success(t('providerConsole.users.toasts.transferred'))
      },
      onError: (error) => toastError(t, 'providerConsole.users.toasts.failed', error),
    })

  return {
    useTenantsQuery,
    useUsersQuery,
    useUserQuery,
    useUsageQuery,
    useHealthQuery,
    useRecheckHealthMutation,
    useAuditQuery,
    useSuspendMutation,
    useRestoreMutation,
    useSignOutMutation,
    useTransferOwnershipMutation,
  }
}
