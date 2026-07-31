import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { ComputedRef, MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { BackupsQueryParams } from '~/api/resources/backups'

import { queryKeys } from './useQueryClient'

type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>

export function useBackups(spaceIdRef: MaybeRefOrComputed<string>) {
  const queryClient = useQueryClient()
  const { t } = useI18n()

  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useBackupsQuery = (
    paramsRef: MaybeRefOrComputed<BackupsQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    const params = computed(() => unref(paramsRef))

    return useQuery({
      queryKey: computed(() => queryKeys.backups(spaceId.value).list(params.value)),
      queryFn: async () => {
        const response = await spaceAPI.value.backups.index({
          sort: 'created_at',
          order: 'desc',
          ...params.value,
        })
        return response
      },
      enabled: computed(() => !!spaceId.value && !!toValue(enabled)),
      placeholderData: keepPreviousData,
    })
  }

  const useBackupQuery = (idRef: MaybeRefOrComputed<string>, enabled: MaybeRef<boolean> = true) => {
    const id = computed(() => unref(idRef))

    return useQuery({
      queryKey: computed(() => queryKeys.backups(spaceId.value).detail(id.value)),
      queryFn: async () => {
        const response = await spaceAPI.value.backups.get(id.value)
        return response.data
      },
      enabled: computed(() => !!spaceId.value && !!id.value && !!toValue(enabled)),
      refetchInterval: (query) => {
        const data = query.state.data as BackupResource | undefined
        if (data?.state === 'pending') {
          return 2000
        }
        return false
      },
    })
  }

  const useCreateBackupMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateBackupPayload) => {
        const response = await spaceAPI.value.backups.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.backups(spaceId.value).lists() })
        toast.success(t('composables.backups.createSuccess', { name: data.name }))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.backups.createError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  const useUpdateBackupMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateBackupPayload }) => {
        const response = await spaceAPI.value.backups.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.backups(spaceId.value).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.backups(spaceId.value).detail(data.id),
        })
        toast.success(t('composables.backups.updateSuccess', { name: data.name }))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.backups.updateError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  const useDeleteBackupMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.backups.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.backups(spaceId.value).lists() })
        // useBackupQuery polls while the backup is pending — drop the entry or it polls a 404.
        queryClient.removeQueries({ queryKey: queryKeys.backups(spaceId.value).detail(id) })
        toast.success(t('composables.backups.deleteSuccess'))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.backups.deleteError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  return {
    useBackupsQuery,
    useBackupQuery,
    useCreateBackupMutation,
    useUpdateBackupMutation,
    useDeleteBackupMutation,
  }
}
