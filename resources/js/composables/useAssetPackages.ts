import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import type { MaybeRef } from 'vue'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AssetPackagesQueryParams } from '~/api/resources/asset-packages'
import type {
  AssetPackageResource,
  CreateAssetPackagePayload,
} from '~/types/asset-distribution'

import { queryKeys } from './useQueryClient'

const POLL_INTERVAL = 2500
const MAX_POLL_ATTEMPTS = 240 // ~10 minutes

const isInProgress = (state: AssetPackageResource['state']) =>
  state === 'pending' || state === 'building'

const triggerUrlDownload = (url: string) => {
  const link = document.createElement('a')
  link.href = url
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
}

export function useAssetPackages(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useAssetPackagesQuery = (
    params: MaybeRef<AssetPackagesQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assetPackages(spaceId).list(params)),
      queryFn: async () => {
        return await spaceAPI.value.assetPackages.index(toValue(params))
      },
      enabled: computed(() => Boolean(toValue(spaceId)) && toValue(enabled)),
      refetchInterval: (query) => {
        const packages = query.state.data?.data ?? []
        return packages.some((pkg) => isInProgress(pkg.state)) ? POLL_INTERVAL : false
      },
    })
  }

  const useCreateAssetPackageMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateAssetPackagePayload) => {
        const response = await spaceAPI.value.assetPackages.create(payload)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetPackages(spaceId).lists() })
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetPackages.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteAssetPackageMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.assetPackages.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetPackages(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.assetPackages(spaceId).detail(id) })
        toast.success(t('composables.assetPackages.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetPackages.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  /** Fetch the signed URL for a completed package and start the browser download. */
  const downloadPackage = async (id: string): Promise<void> => {
    const { url } = await spaceAPI.value.assetPackages.download(id)
    triggerUrlDownload(url)
  }

  /**
   * Poll a freshly created package until it completes, then download it.
   * Used by the "download many assets as zip" flow.
   */
  const waitForPackageAndDownload = async (
    packageId: string,
    onProgress?: (progress: number) => void
  ): Promise<void> => {
    for (let attempt = 0; attempt < MAX_POLL_ATTEMPTS; attempt++) {
      const { data: pkg } = await spaceAPI.value.assetPackages.get(packageId)

      if (pkg.state === 'completed') {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetPackages(spaceId).lists() })
        await downloadPackage(packageId)
        return
      }

      if (pkg.state === 'failed') {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetPackages(spaceId).lists() })
        throw new Error(pkg.error || 'Package build failed')
      }

      onProgress?.(pkg.progress)
      await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL))
    }

    throw new Error('Package build timed out')
  }

  /**
   * Bundle a selection into a server-built zip and download it once ready.
   * Used instead of the per-file download loop for larger selections.
   */
  const downloadSelectionAsPackage = async (assetIds: string[]): Promise<void> => {
    if (!assetIds.length) return

    const toastId = toast.loading(
      t('composables.assetPackages.preparing', { count: assetIds.length }) as string
    )

    try {
      const response = await spaceAPI.value.assetPackages.create({
        source_type: 'selection',
        asset_ids: assetIds,
      })
      queryClient.invalidateQueries({ queryKey: queryKeys.assetPackages(spaceId).lists() })

      await waitForPackageAndDownload(response.data.id, (progress) => {
        toast.loading(
          t('composables.assetPackages.building', { progress }) as string,
          { id: toastId }
        )
      })

      toast.success(t('composables.assetPackages.ready') as string, { id: toastId })
    } catch (error: any) {
      toast.error(
        t('composables.assetPackages.failed', {
          error: error?.message || 'Unknown error',
        }) as string,
        { id: toastId }
      )
    }
  }

  return {
    useAssetPackagesQuery,
    useCreateAssetPackageMutation,
    useDeleteAssetPackageMutation,
    downloadPackage,
    waitForPackageAndDownload,
    downloadSelectionAsPackage,
  }
}
