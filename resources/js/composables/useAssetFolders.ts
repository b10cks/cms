import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

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
      placeholderData: keepPreviousData,
    })
  }

  const useAssetFolderQuery = (id: string) => {
    return useQuery({
      queryKey: queryKeys.assetFolders(spaceId).detail(id),
      queryFn: async () => {
        const response = await spaceAPI.value.assetFolders.get(id)
        return response.data
      },
      // Without this a component rendering before its id resolves would
      // request `/asset-folders/`.
      enabled: computed(() => Boolean(id)),
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
        // A move changes which folder the assets are browsed under.
        queryClient.invalidateQueries({ queryKey: queryKeys.assets(spaceId).lists() })
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

    const folderMap = computed(() => {
      return new Map((folders.value || []).map((folder) => [folder.id, folder]))
    })

    const rootFolders = computed(() => {
      if (!folders.value) return []
      return folders.value.filter((folder) => !folder.parent_id)
    })

    const getChildrenOfFolder = (parentId: string | null) => {
      if (!folders.value) return []
      // A payload that omits parent_id is a root folder, same as rootFolders sees it.
      return folders.value.filter((folder) => (folder.parent_id ?? null) === parentId)
    }

    const getBreadcrumbs = (folderId: string): AssetFolderResource[] => {
      if (!folders.value) return []

      const breadcrumbs: AssetFolderResource[] = []
      let currentFolder = folderMap.value.get(folderId)

      if (!currentFolder) return []

      // A corrupt parent chain (self-parent, or a → b → a) would otherwise
      // walk forever and grow the trail unboundedly.
      const visited = new Set<string>([currentFolder.id])

      breadcrumbs.unshift(currentFolder)

      while (currentFolder?.parent_id) {
        const parentFolder = folderMap.value.get(currentFolder.parent_id)
        if (!parentFolder || visited.has(parentFolder.id)) {
          break
        }

        visited.add(parentFolder.id)
        breadcrumbs.unshift(parentFolder)
        currentFolder = parentFolder
      }

      return breadcrumbs
    }

    const isDescendantOf = (folderId: string, potentialAncestorId: string): boolean => {
      let currentFolder = folderMap.value.get(folderId)
      // Same cycle guard as getBreadcrumbs — this also backs the move guard,
      // so a cycle here would hang a drag-drop instead of rejecting it.
      const visited = new Set<string>()

      while (currentFolder?.parent_id) {
        if (currentFolder.parent_id === potentialAncestorId) {
          return true
        }

        if (visited.has(currentFolder.id)) {
          return false
        }

        visited.add(currentFolder.id)
        currentFolder = folderMap.value.get(currentFolder.parent_id)
      }

      return false
    }

    return {
      folders,
      folderMap,
      isLoading,
      error,
      rootFolders,
      getChildrenOfFolder,
      getBreadcrumbs,
      isDescendantOf,
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
