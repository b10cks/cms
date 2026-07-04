import type { AssetManagerDragItem } from '~/lib/assets/assetDragAndDrop'
import type { AssetFolderResource, AssetResource } from '~/types/assets'

export type AssetSelectionEntry =
  | { type: 'folder'; data: AssetFolderResource }
  | { type: 'asset'; data: AssetResource }

export interface AssetSelectionModifiers {
  meta?: boolean
  shift?: boolean
}

/**
 * Finder-style selection engine over an ordered list of folders and assets.
 *
 * - plain click: replace selection with the clicked item
 * - meta/ctrl click: toggle the clicked item
 * - shift click: select the range between the anchor and the clicked item
 *
 * Selection is keyed per type so items surviving pagination stay selected.
 */
export function useAssetSelection(orderedItems: MaybeRefOrGetter<AssetSelectionEntry[]>) {
  const selectedAssets = ref<Map<string, AssetResource>>(new Map())
  const selectedFolders = ref<Map<string, AssetFolderResource>>(new Map())
  const anchorKey = ref<string | null>(null)

  const keyOf = (entry: Pick<AssetSelectionEntry, 'type'> & { data: { id: string } }): string => {
    return `${entry.type}:${entry.data.id}`
  }

  const items = computed(() => toValue(orderedItems))

  const indexOfKey = (key: string): number => {
    return items.value.findIndex((entry) => keyOf(entry) === key)
  }

  const isSelected = (entry: AssetSelectionEntry): boolean => {
    return entry.type === 'asset'
      ? selectedAssets.value.has(entry.data.id)
      : selectedFolders.value.has(entry.data.id)
  }

  const hasSelection = computed(() => {
    return selectedAssets.value.size > 0 || selectedFolders.value.size > 0
  })

  const selectionCount = computed(() => {
    return selectedAssets.value.size + selectedFolders.value.size
  })

  const selectedDragItems = computed<AssetManagerDragItem[]>(() => {
    return [
      ...Array.from(selectedFolders.value.keys()).map((id) => ({ id, type: 'folder' as const })),
      ...Array.from(selectedAssets.value.keys()).map((id) => ({ id, type: 'asset' as const })),
    ]
  })

  const setSelected = (entry: AssetSelectionEntry, selected: boolean) => {
    if (entry.type === 'asset') {
      if (selected) {
        selectedAssets.value.set(entry.data.id, entry.data)
      } else {
        selectedAssets.value.delete(entry.data.id)
      }
    } else if (selected) {
      selectedFolders.value.set(entry.data.id, entry.data)
    } else {
      selectedFolders.value.delete(entry.data.id)
    }

    if (selected) {
      anchorKey.value = keyOf(entry)
    }
  }

  const clear = () => {
    selectedAssets.value.clear()
    selectedFolders.value.clear()
    anchorKey.value = null
  }

  const selectOnly = (entry: AssetSelectionEntry) => {
    clear()
    setSelected(entry, true)
  }

  const toggle = (entry: AssetSelectionEntry) => {
    setSelected(entry, !isSelected(entry))
  }

  const selectAll = () => {
    for (const entry of items.value) {
      if (entry.type === 'asset') {
        selectedAssets.value.set(entry.data.id, entry.data)
      } else {
        selectedFolders.value.set(entry.data.id, entry.data)
      }
    }
  }

  const selectRangeTo = (entry: AssetSelectionEntry, { additive = false } = {}) => {
    const targetIndex = indexOfKey(keyOf(entry))
    const anchorIndex = anchorKey.value ? indexOfKey(anchorKey.value) : -1

    if (targetIndex === -1 || anchorIndex === -1) {
      selectOnly(entry)
      return
    }

    if (!additive) {
      selectedAssets.value.clear()
      selectedFolders.value.clear()
    }

    const [from, to] = anchorIndex <= targetIndex ? [anchorIndex, targetIndex] : [targetIndex, anchorIndex]

    for (const rangeEntry of items.value.slice(from, to + 1)) {
      if (rangeEntry.type === 'asset') {
        selectedAssets.value.set(rangeEntry.data.id, rangeEntry.data)
      } else {
        selectedFolders.value.set(rangeEntry.data.id, rangeEntry.data)
      }
    }
  }

  const handleItemPointer = (entry: AssetSelectionEntry, modifiers: AssetSelectionModifiers = {}) => {
    if (modifiers.shift) {
      selectRangeTo(entry, { additive: Boolean(modifiers.meta) })
      return
    }

    if (modifiers.meta) {
      toggle(entry)
      return
    }

    selectOnly(entry)
  }

  return {
    anchorKey,
    clear,
    handleItemPointer,
    hasSelection,
    isSelected,
    keyOf,
    selectAll,
    selectOnly,
    selectRangeTo,
    selectedAssets,
    selectedDragItems,
    selectedFolders,
    selectionCount,
    setSelected,
    toggle,
  }
}
