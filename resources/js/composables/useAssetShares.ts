import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AssetSharesQueryParams } from '~/api/resources/asset-shares'
import type {
  AssetShareResource,
  CreateAssetSharePayload,
  UpdateAssetSharePayload,
} from '~/types/asset-distribution'

import { queryKeys } from './useQueryClient'

export const buildShareUrl = (
  spaceId: string,
  share: Pick<AssetShareResource, 'token'>
): string => {
  return `${window.location.origin}/share/${spaceId}/${share.token}`
}

export function useAssetShares(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const invalidateShares = (id?: string) => {
    queryClient.invalidateQueries({ queryKey: queryKeys.assetShares(spaceId).lists() })
    if (id) {
      queryClient.invalidateQueries({ queryKey: queryKeys.assetShares(spaceId).detail(id) })
    }
  }

  const useAssetSharesQuery = (
    params: MaybeRef<AssetSharesQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetShares(spaceId).list(params)),
      queryFn: async () => {
        return await spaceAPI.value.assetShares.index(toValue(params))
      },
      enabled: computed(() => Boolean(toValue(spaceId)) && toValue(enabled)),
    })
  }

  const useAssetShareQuery = (
    id: MaybeRef<string | null | undefined>,
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetShares(spaceId).detail(toValue(id) ?? '')),
      queryFn: async () => {
        const response = await spaceAPI.value.assetShares.get(toValue(id) ?? '')
        return response.data
      },
      enabled: computed(() => Boolean(toValue(id)) && toValue(enabled)),
    })
  }

  const useCreateAssetShareMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateAssetSharePayload) => {
        const response = await spaceAPI.value.assetShares.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        invalidateShares(data.id)
        toast.success(t('composables.assetShares.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetShares.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateAssetShareMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateAssetSharePayload }) => {
        const response = await spaceAPI.value.assetShares.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        invalidateShares(data.id)
        toast.success(t('composables.assetShares.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetShares.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useRevokeAssetShareMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        const response = await spaceAPI.value.assetShares.revoke(id)
        return response.data
      },
      onSuccess: (data) => {
        invalidateShares(data.id)
        toast.success(t('composables.assetShares.revokeSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetShares.revokeError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteAssetShareMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.assetShares.delete(id)
        return id
      },
      onSuccess: (id) => {
        invalidateShares()
        queryClient.removeQueries({ queryKey: queryKeys.assetShares(spaceId).detail(id) })
        toast.success(t('composables.assetShares.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetShares.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const copyShareLink = async (share: AssetShareResource) => {
    await navigator.clipboard.writeText(buildShareUrl(toValue(spaceId), share))
    toast.success(t('composables.assetShares.linkCopied') as string)
  }

  return {
    useAssetSharesQuery,
    useAssetShareQuery,
    useCreateAssetShareMutation,
    useUpdateAssetShareMutation,
    useRevokeAssetShareMutation,
    useDeleteAssetShareMutation,
    copyShareLink,
  }
}
