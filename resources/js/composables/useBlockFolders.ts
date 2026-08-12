import { api } from '~/api'
import type {
  BlockFolderResource,
  BlockFoldersQueryParams,
  UpsertBlockFolderPayload,
} from '~/api/resources/block-folders'
import { createCrudComposable } from '~/lib/crud-composable'

import { queryKeys } from './useQueryClient'

const useBlockFoldersCrud = createCrudComposable<
  BlockFolderResource,
  ApiCollectionResponse<BlockFolderResource>,
  BlockFoldersQueryParams,
  UpsertBlockFolderPayload,
  UpsertBlockFolderPayload,
  BlockFolderResource[],
  { folderId: string; payload: UpsertBlockFolderPayload }
>({
  i18nKey: 'blockFolders',
  keys: (spaceId) => queryKeys.blockFolders(spaceId),
  resource: (spaceId) => api.forSpace(spaceId).blockFolders,
  defaultParams: { sort: '+name' },
  // The tree helpers below want the folders themselves, not the envelope.
  selectList: (response) => response.data,
  listGate: 'none',
  // Callers have always passed `folderId`; keep their call sites untouched.
  updateVariables: ({ folderId, payload }) => ({ id: folderId, payload }),
  toastValues: (data) => ({ name: data.name }),
  // Blocks carry their folder, so every cached block list is stale too.
  invalidateAlso: (spaceId) => [queryKeys.blocks(spaceId).lists()],
})

export function useBlockFolders(spaceId: MaybeRef<string>) {
  const crud = useBlockFoldersCrud(spaceId)

  const useBlockFoldersQuery = crud.useListQuery
  const useBlockFolderQuery = crud.useDetailQuery

  const useFolderStructure = () => {
    const { data: folders, isLoading, error } = useBlockFoldersQuery()

    const rootFolders = computed(() => {
      if (!folders.value) return []
      return folders.value.filter((folder) => !folder.parent_id)
    })

    const getChildrenOfFolder = (parentId: string | null) => {
      if (!folders.value) return []
      return folders.value.filter((folder) => folder.parent_id === parentId)
    }

    const getBreadcrumbs = (folderId: string): BlockFolderResource[] => {
      if (!folders.value) return []

      const breadcrumbs: BlockFolderResource[] = []
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
    useBlockFoldersQuery,
    useBlockFolderQuery,

    useFolderStructure,

    useCreateBlockFolderMutation: crud.useCreateMutation,
    useUpdateBlockFolderMutation: crud.useUpdateMutation,
    useDeleteBlockFolderMutation: crud.useDeleteMutation,
  }
}
