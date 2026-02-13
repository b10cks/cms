import type { ComputedRef, MaybeRef } from 'vue'

import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { BackupsQueryParams } from '~/api/resources/backups'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>

export function useBackups(spaceIdRef: MaybeRefOrComputed<string>) {
  const queryClient = useQueryClient()

  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useBackupsQuery = (paramsRef: MaybeRefOrComputed<BackupsQueryParams> = {}) => {
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
      enabled: computed(() => !!spaceId.value),
    })
  }

  const useBackupQuery = (idRef: MaybeRefOrComputed<string>) => {
    const id = computed(() => unref(idRef))

    return useQuery({
      queryKey: computed(() => queryKeys.backups(spaceId.value).detail(id.value)),
      queryFn: async () => {
        const response = await spaceAPI.value.backups.get(id.value)
        return response.data
      },
      enabled: computed(() => !!spaceId.value && !!id.value),
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
        toast.success(`Backup "${data.name}" created successfully`)
      },
      onError: (error: Error) => {
        toast.error(`Failed to create backup: ${error.message || 'Unknown error'}`)
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
        toast.success(`Backup "${data.name}" updated successfully`)
      },
      onError: (error: Error) => {
        toast.error(`Failed to update backup: ${error.message || 'Unknown error'}`)
      },
    })
  }

  const useDeleteBackupMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.backups.delete(id)
        return id
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.backups(spaceId.value).lists() })
        toast.success(`Backup deleted successfully`)
      },
      onError: (error: Error) => {
        toast.error(`Failed to delete backup: ${error.message || 'Unknown error'}`)
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
