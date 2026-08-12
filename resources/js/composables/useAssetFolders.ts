import { api } from '~/api'
import type { AssetFoldersQueryParams } from '~/api/resources/asset-folders'
import { createCrudComposable } from '~/lib/crud-composable'

import { queryKeys } from './useQueryClient'

/**
 * The resource nests `parent_id` under `filter`, but callers have always passed
 * it flat and the server accepts both — typing only the nested form here would
 * break existing call sites.
 */
type AssetFolderListParams = AssetFoldersQueryParams & { parent_id?: string | null }

const useAssetFoldersCrud = createCrudComposable<
  AssetFolderResource,
  ApiCollectionResponse<AssetFolderResource>,
  AssetFolderListParams,
  UpsertAssetFolderPayload,
  UpsertAssetFolderPayload,
  AssetFolderResource[]
>({
  i18nKey: 'assetFolders',
  keys: (spaceId) => queryKeys.assetFolders(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).assetFolders,
  defaultParams: { sort: '+name' },
  // The tree helpers below want the folders themselves, not the envelope.
  selectList: (response) => response.data,
  listGate: 'none',
  // The default `space+id` detail gate matters here: without it a component
  // rendering before its id resolves would request `/asset-folders/`.
  toastValues: (data) => ({ name: data.name }),
  // A move changes which folder the assets are browsed under.
  invalidateAlso: (spaceId, operation) =>
    operation === 'update' ? [queryKeys.assets(spaceId).lists()] : [],
})

export function useAssetFolders(spaceId: MaybeRef<string>) {
  const crud = useAssetFoldersCrud(spaceId)

  const useAssetFoldersQuery = crud.useListQuery
  const useAssetFolderQuery = crud.useDetailQuery

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

    useCreateAssetFolderMutation: crud.useCreateMutation,
    useUpdateAssetFolderMutation: crud.useUpdateMutation,
    useDeleteAssetFolderMutation: crud.useDeleteMutation,
  }
}
