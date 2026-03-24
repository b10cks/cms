import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { ComputedRef, MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { MigrationsQueryParams } from '~/api/resources/migrations'

import { queryKeys } from './useQueryClient'

export type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>

export function useMigrations(spaceIdRef: MaybeRefOrComputed<string>) {
  const queryClient = useQueryClient()
  const { t } = useI18n()

  const spaceId = computed(() => unref(spaceIdRef))
  const spaceAPI = computed(() => api.forSpace(spaceId.value))

  const useMigrationsQuery = (
    paramsRef: MaybeRefOrComputed<MigrationsQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    const params = computed(() => unref(paramsRef))

    return useQuery({
      queryKey: computed(() => queryKeys.migrations(spaceId.value).list(params.value)),
      queryFn: async () => {
        const response = await spaceAPI.value.migrations.index({
          sort: 'created_at',
          order: 'desc',
          ...params.value,
        })
        return response
      },
      enabled: computed(() => !!spaceId.value && !!toValue(enabled)),
    })
  }

  const useMigrationQuery = (
    idRef: MaybeRefOrComputed<string>,
    enabled: MaybeRef<boolean> = true
  ) => {
    const id = computed(() => unref(idRef))

    return useQuery({
      queryKey: computed(() => queryKeys.migrations(spaceId.value).detail(id.value)),
      queryFn: async () => {
        const response = await spaceAPI.value.migrations.get(id.value)
        return response.data
      },
      enabled: computed(() => !!spaceId.value && !!id.value && !!toValue(enabled)),
      refetchInterval: (query) => {
        const data = query.state.data as MigrationResource | undefined
        if (data?.state === 'pending' || data?.state === 'processing') {
          return 2000
        }
        return false
      },
    })
  }

  const useCreateMigrationMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateMigrationPayload) => {
        const response = await spaceAPI.value.migrations.create(payload)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.migrations(spaceId.value).lists() })
        toast.success(t('composables.migrations.createSuccess'))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.migrations.createError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  const useDeleteMigrationMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.migrations.delete(id)
        return id
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.migrations(spaceId.value).lists() })
        toast.success(t('composables.migrations.deleteSuccess'))
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.migrations.deleteError', { error: error.message || 'Unknown error' })
        )
      },
    })
  }

  return {
    useMigrationsQuery,
    useMigrationQuery,
    useCreateMigrationMutation,
    useDeleteMigrationMutation,
  }
}
