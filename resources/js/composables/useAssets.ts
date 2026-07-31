import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { useDebounceFn } from '@vueuse/core'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AssetsQueryParams } from '~/api/resources/assets'
import { getXsrfHeaders } from '~/lib/csrf'

import { queryKeys } from './useQueryClient'

export type UploadAssetOutcome =
  | { status: 'success'; asset: AssetResource }
  | { status: 'duplicate'; duplicate: AssetUploadDuplicate }

export function useAssets(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))
  const { client: apiClient } = useApiClient()
  const error = ref<string | null>(null)

  const useAssetsQuery = (params: MaybeRef<AssetsQueryParams & { collection?: string }> = {}) => {
    return useQuery({
      // A collection grid is served by the collection endpoint, so it is cached
      // in the collection namespace — that is the key a collection mutation
      // invalidates, and an asset-list invalidation must not refetch it.
      queryKey: computed(() => {
        const { collection, ...rest } = toValue(params)

        return collection
          ? queryKeys.assetCollections(spaceId).assetsList(collection, rest)
          : queryKeys.assets(spaceId).list(rest)
      }),
      queryFn: async () => {
        const { collection, ...rest } = toValue(params)

        if (collection) {
          return await spaceAPI.value.assetCollections.getAssets(collection, rest)
        }

        return await spaceAPI.value.assets.index({
          sort: '+created_at',
          ...rest,
        })
      },
      placeholderData: keepPreviousData,
    })
  }

  const useAssetQuery = (id: MaybeRef<string>, enabled: MaybeRef<boolean> = true) => {
    return useQuery({
      queryKey: computed(() => queryKeys.assets(spaceId).detail(id)),
      queryFn: async () => {
        // The show endpoint returns the asset without a `data` envelope;
        // `response.data` would resolve to the asset's own `data` attribute.
        const response = await spaceAPI.value.assets.get(toValue(id))
        return ('id' in response ? response : response.data) as AssetResource
      },
      enabled: computed(() => Boolean(toValue(id)) && toValue(enabled)),
    })
  }

  const useAssetLinkedContentsQuery = (
    id: MaybeRef<string | null | undefined>,
    page: MaybeRef<number>,
    enabled: MaybeRef<boolean> = true,
    perPage: MaybeRef<number> = 10
  ) => {
    return useQuery({
      queryKey: computed(() =>
        queryKeys.assets(spaceId).linkedContentsPage(toValue(id) ?? '', toValue(page))
      ),
      queryFn: async () => {
        return await spaceAPI.value.assets.getLinkedContents(toValue(id) ?? '', {
          page: toValue(page),
          per_page: toValue(perPage),
        })
      },
      enabled: computed(() => Boolean(toValue(id)) && toValue(enabled)),
      placeholderData: keepPreviousData,
    })
  }

  // Shared by every upload from this composable instance, so a multi-file drop
  // produces one invalidation and one toast instead of one per file.
  const debouncedInvalidateQueries = useDebounceFn(() => {
    queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
    toast.success(t('composables.assets.uploadSuccess') as string)
  }, 300)

  /**
   * Upload a new asset. When the backend detects a checksum match against an
   * existing asset in the space, the request is not silently accepted -
   * the caller gets a `{ status: 'duplicate' }` outcome back and can decide
   * to re-call with `{ force: true }` to upload anyway.
   */
  const uploadAsset = async (
    payload: UploadAssetPayload,
    onProgress?: (progress: number) => void,
    options: { force?: boolean } = {}
  ): Promise<UploadAssetOutcome | null> => {
    try {
      await apiClient.ensureCsrfCookie()
      const formData = new FormData()
      formData.append('file', payload.file)

      if (payload.folder_id) {
        formData.append('folder_id', payload.folder_id)
      }
      if (payload.tags) {
        formData.append('tags', JSON.stringify(payload.tags))
      }
      if (payload.metadata) {
        formData.append('metadata', JSON.stringify(payload.metadata))
      }
      if (payload.data) {
        formData.append('data', JSON.stringify(payload.data))
      }
      if (options.force) {
        formData.append('force', '1')
      }

      // Use XMLHttpRequest for progress tracking
      const xhr = new XMLHttpRequest()

      const promise = new Promise<UploadAssetOutcome | null>((resolve, reject) => {
        xhr.upload.addEventListener('progress', (event) => {
          if (event.lengthComputable && onProgress) {
            const percentComplete = Math.round((event.loaded / event.total) * 100)
            onProgress(percentComplete)
          }
        })

        xhr.addEventListener('load', () => {
          try {
            const response = JSON.parse(xhr.responseText)

            if (xhr.status >= 200 && xhr.status < 300) {
              const assetData = response.data

              if (assetData) {
                debouncedInvalidateQueries()
                resolve({ status: 'success', asset: assetData })
              } else {
                resolve(null)
              }
            } else if (xhr.status === 409 && response.code === 'duplicate_asset') {
              resolve({
                status: 'duplicate',
                duplicate: {
                  code: 'duplicate_asset',
                  message: response.message,
                  existing_asset: response.existing_asset,
                },
              })
            } else {
              reject(
                new Error(response.message || `Upload failed with status ${xhr.status}: ${xhr.statusText}`)
              )
            }
          } catch {
            reject(new Error('Failed to parse server response'))
          }
        })

        xhr.addEventListener('error', () => {
          reject(new Error('Network error occurred during upload'))
        })

        xhr.addEventListener('abort', () => {
          reject(new Error('Upload was aborted'))
        })

        const apiBaseUrl = ''
        xhr.open('POST', `${apiBaseUrl}/mgmt/v1/spaces/${toValue(spaceId)}/assets`)
        xhr.withCredentials = true

        // Set headers
        xhr.setRequestHeader('accept', 'application/json')
        const xsrfHeaders = getXsrfHeaders()
        Object.entries(xsrfHeaders).forEach(([key, value]) => {
          xhr.setRequestHeader(key, value)
        })
        xhr.send(formData)
      })

      return await promise
    } catch (err) {
      console.error(err)
      const message =
        err instanceof Error ? err.message : (t('composables.assets.uploadError') as string)
      error.value = message
      // Every other write in this file reports its failure; an upload must too.
      // The server message is the useful part, so it is the toast.
      toast.error(message)
      return null
    }
  }

  const useUpdateAssetMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateAssetPayload }) => {
        const response = await spaceAPI.value.assets.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).detail(data.id) })
        toast.success(t('composables.assets.updateSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assets.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteAssetMutation = () => {
    return useMutation({
      mutationFn: async ({ id, force = false }: { id: string; force?: boolean }) => {
        await spaceAPI.value.assets.delete(id, { force })
        return { id }
      },
      onSuccess: ({ id }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
        // linkedContents lives inside the detail subtree, so it goes with it.
        queryClient.removeQueries({ queryKey: queryKeys.assets(spaceId).detail(id) })
        toast.success(t('composables.assets.deleteSuccess') as string)
      },
      onError: (error: Error & { data?: AssetDeleteConflict; status?: number }) => {
        if (error.status === 409 && error.data?.code === 'asset_in_use') {
          return
        }

        toast.error(
          t('composables.assets.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useReplaceAssetFileMutation = () => {
    return useMutation({
      mutationFn: async ({
        id,
        file,
        onProgress,
      }: {
        id: string
        file: File
        onProgress?: (progress: number) => void
      }) => {
        return await spaceAPI.value.assets.replaceFile(id, file, onProgress)
      },
      onSuccess: (data) => {
        if (!data) {
          // An empty response is not a success we can report on.
          toast.error(
            t('composables.assets.replaceError', { error: 'Unknown error' }) as string
          )
          return
        }

        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).detail(data.id) })
        toast.success(t('composables.assets.replaceSuccess') as string)
      },
      onError: (err: Error) => {
        toast.error(
          t('composables.assets.replaceError', { error: err.message || 'Unknown error' }) as string
        )
      },
    })
  }

  const useUploadAssetPosterMutation = () => {
    return useMutation({
      mutationFn: async ({ id, file }: { id: string; file: File }) => {
        return await spaceAPI.value.assets.uploadPoster(id, file)
      },
      onSuccess: (response) => {
        const asset = response?.data

        if (!asset) {
          // An empty response is not a success we can report on.
          toast.error(t('composables.assets.posterError', { error: 'Unknown error' }) as string)
          return
        }

        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).detail(asset.id) })
        toast.success(t('composables.assets.posterSuccess') as string)
      },
      onError: (err: Error) => {
        toast.error(
          t('composables.assets.posterError', { error: err.message || 'Unknown error' }) as string
        )
      },
    })
  }

  const useExportAssetsMutation = () => {
    return useMutation({
      mutationFn: async (params: AssetsQueryParams & { as: ExportTypes }) => {
        return await spaceAPI.value.assets.export(params)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assets.exportError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useImportAssetsMutation = () => {
    return useMutation({
      mutationFn: async (file: File) => {
        return await spaceAPI.value.assets.importData(file)
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
        toast.success(t('composables.assets.importSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assets.importError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // State
    error,

    // Queries
    useAssetQuery,
    useAssetsQuery,
    useAssetLinkedContentsQuery,

    // Mutations
    useUpdateAssetMutation,
    useDeleteAssetMutation,
    useReplaceAssetFileMutation,
    useUploadAssetPosterMutation,
    useExportAssetsMutation,
    useImportAssetsMutation,
    uploadAsset,
  }
}
