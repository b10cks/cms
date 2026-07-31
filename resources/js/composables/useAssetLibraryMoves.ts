import type { AssetManagerDragItem } from '~/lib/assets/assetDragAndDrop'

export function useAssetLibraryMoves(spaceId: MaybeRef<string>) {
  const { useFolderStructure, useUpdateAssetFolderMutation } = useAssetFolders(spaceId)
  const { useUpdateAssetMutation } = useAssets(spaceId)
  const { isDescendantOf, folders, isLoading } = useFolderStructure()
  const { mutateAsync: updateFolder } = useUpdateAssetFolderMutation()
  const { mutateAsync: updateAsset } = useUpdateAssetMutation()

  const normalizeFolderIdsForMove = (folderIds: string[]): string[] => {
    const uniqueFolderIds = Array.from(new Set(folderIds))

    return uniqueFolderIds.filter((folderId) => {
      return !uniqueFolderIds.some(
        (candidateId) => candidateId !== folderId && isDescendantOf(folderId, candidateId)
      )
    })
  }

  const getMoveValidation = (items: AssetManagerDragItem[], targetFolderId: string | null) => {
    const normalizedFolderIds = normalizeFolderIdsForMove(
      items.filter((item) => item.type === 'folder').map((item) => item.id)
    )

    // Without the folder list there is no lineage for isDescendantOf to walk,
    // and it would wave a folder straight into its own subtree. Fail closed:
    // refuse folder-into-folder moves until the list has resolved. Moves to
    // the root cannot cycle, so they (and asset moves) stay allowed.
    const lineageReady = !isLoading.value && (folders.value?.length ?? 0) > 0

    const invalidFolderIds = targetFolderId
      ? lineageReady
        ? normalizedFolderIds.filter(
            (folderId) => folderId === targetFolderId || isDescendantOf(targetFolderId, folderId)
          )
        : normalizedFolderIds
      : []

    return {
      invalidFolderIds,
      normalizedFolderIds,
      valid: invalidFolderIds.length === 0,
    }
  }

  const canMoveItems = (items: AssetManagerDragItem[], targetFolderId: string | null): boolean => {
    if (!items.length) {
      return false
    }

    return getMoveValidation(items, targetFolderId).valid
  }

  const moveItemsToFolder = async (
    items: AssetManagerDragItem[],
    targetFolderId: string | null
  ) => {
    const { invalidFolderIds, normalizedFolderIds, valid } = getMoveValidation(
      items,
      targetFolderId
    )

    if (!valid) {
      throw new Error(`invalid-folder-move:${invalidFolderIds.join(',')}`)
    }

    const assetIds = Array.from(
      new Set(items.filter((item) => item.type === 'asset').map((item) => item.id))
    )

    await Promise.all([
      ...normalizedFolderIds.map((id) =>
        updateFolder({ id, payload: { parent_id: targetFolderId } })
      ),
      ...assetIds.map((id) => updateAsset({ id, payload: { folder_id: targetFolderId } })),
    ])
  }

  return {
    canMoveItems,
    getMoveValidation,
    moveItemsToFolder,
    normalizeFolderIdsForMove,
  }
}
