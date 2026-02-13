import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { AssetFolderResource, UpsertAssetFolderPayload } from '~/types/assets'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useAssetFolders(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useAssetFoldersQuery = (filters = {}) => {
    return useQuery({
      queryKey: queryKeys.assetFolders(spaceId).list(filters),
      queryFn: async () => {
        const response = await spaceAPI.value.assetFolders.index({
          sort: '+name',
          ...filters,
        })
        return response.data
      },
    })
  }

  const useAssetFolderQuery = (id: string) => {
    return useQuery({
      queryKey: queryKeys.assetFolders(spaceId).detail(id),
      queryFn: async () => {
        const response = await spaceAPI.value.assetFolders.get(id)
        return response.data
      },
    })
  }

  const useCreateAssetFolderMutation = () => {
    return useMutation({
      mutationFn: async (payload: UpsertAssetFolderPayload) => {
        const response = await spaceAPI.value.assetFolders.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetFolders(spaceId).lists() })
        toast.success(t('composables.assetFolders.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetFolders.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateAssetFolderMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpsertAssetFolderPayload }) => {
        const response = await spaceAPI.value.assetFolders.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetFolders(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.assetFolders(spaceId).detail(data.id) })
        toast.success(t('composables.assetFolders.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetFolders.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteAssetFolderMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.assetFolders.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.assetFolders(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.assetFolders(spaceId).detail(id) })
        toast.success(t('composables.assetFolders.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.assetFolders.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useFolderStructure = () => {
    const { data: folders, isLoading, error } = useAssetFoldersQuery()

    const rootFolders = computed(() => {
      if (!folders.value) return []
      return folders.value.filter((folder) => !folder.parent_id)
    })

    const getChildrenOfFolder = (parentId: string | null) => {
      if (!folders.value) return []
      return folders.value.filter((folder) => folder.parent_id === parentId)
    }

    const getBreadcrumbs = (folderId: string): AssetFolderResource[] => {
      if (!folders.value) return []

      const breadcrumbs: AssetFolderResource[] = []
      let currentFolder = folders.value.find((f) => f.id === folderId)

      if (!currentFolder) return []

      breadcrumbs.unshift(currentFolder)

      while (currentFolder?.parent_id) {
        const parentFolder = folders.value.find((f) => f.id === currentFolder?.parent_id)
        if (parentFolder) {
          breadcrumbs.unshift(parentFolder)
          currentFolder = parentFolder
        } else {
          break
        }
      }

      return breadcrumbs
    }

    return {
      folders,
      isLoading,
      error,
      rootFolders,
      getChildrenOfFolder,
      getBreadcrumbs,
    }
  }

  return {
    useAssetFoldersQuery,
    useAssetFolderQuery,

    useFolderStructure,

    useCreateAssetFolderMutation,
    useUpdateAssetFolderMutation,
    useDeleteAssetFolderMutation,
  }
}
