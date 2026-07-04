<script setup lang="ts">
import { dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { toast } from 'vue-sonner'

import type { AssetsQueryParams } from '~/api/resources/assets'
import AssetsIcon from '~/assets/images/assets.svg?component'
import AssetDetailsDialog from '~/components/assets/AssetDetailsDialog.vue'
import AssetFolder from '~/components/assets/AssetFolder.vue'
import AssetItem from '~/components/assets/AssetItem.vue'
import AssetSelectionBar from '~/components/assets/AssetSelectionBar.vue'
import AssetShortcutsDialog from '~/components/assets/AssetShortcutsDialog.vue'
import BulkTagDialog from '~/components/assets/BulkTagDialog.vue'
import CreateFolderDialog from '~/components/assets/CreateFolderDialog.vue'
import MoveToFolderDialog from '~/components/assets/MoveToFolderDialog.vue'
import UploadDialog from '~/components/assets/UploadDialog.vue'
import Icon from '~/components/Icon.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Alert } from '~/components/ui/alert'
import { Badge } from '~/components/ui/badge'
import { Breadcrumb, BreadcrumbItem } from '~/components/ui/breadcrumb'
import { Button } from '~/components/ui/button'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '~/components/ui/context-menu'
import { Select, SelectContent, SelectItem, SelectTrigger } from '~/components/ui/select'
import SortSelect from '~/components/ui/SortSelect.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import type { AssetSelectionEntry } from '~/composables/useAssetSelection'
import { getAssetManagerDragItems, type AssetManagerDragItem } from '~/lib/assets/assetDragAndDrop'
import { downloadAssetFiles } from '~/lib/assets/downloadAssets'
import type { AssetFolderResource, AssetResource } from '~/types/assets'

export interface AssetGridProps {
  spaceId: string
  mode?: 'manage' | 'select'
  allowUpload?: boolean
  allowFolderCreation?: boolean
  showFolders?: boolean
  multiSelect?: boolean
  initialFolderId?: string | null
  initialTagId?: string | null
}

const props = withDefaults(defineProps<AssetGridProps>(), {
  mode: 'manage',
  allowUpload: true,
  allowFolderCreation: true,
  showFolders: true,
  multiSelect: true,
  initialFolderId: null,
  initialTagId: null,
})

const emit = defineEmits<{
  selectionChange: [{ folders: AssetFolderResource[]; assets: AssetResource[] }]
  'asset-select': [asset: AssetResource]
  'folder-change': [folderId: string | null]
  'tag-change': [tagId: string | null]
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const { settings } = useSpaceSettings(props.spaceId)
const { useFolderStructure, useDeleteAssetFolderMutation } = useAssetFolders(props.spaceId)
const { useAssetsQuery, useAssetQuery, useDeleteAssetMutation, useUpdateAssetMutation } =
  useAssets(props.spaceId)
const { useAssetTagsQuery } = useAssetTags(props.spaceId)
const { data: allTagsResponse } = useAssetTagsQuery({ per_page: 500 })
const { getBreadcrumbs, getChildrenOfFolder } = useFolderStructure()
const { canMoveItems, moveItemsToFolder } = useAssetLibraryMoves(props.spaceId)
const { getMissingRequiredFields, isCompliant } = useAssetRequirements(props.spaceId)
const { bulkDeleteAssets, fetchAllMatchingAssets } = useAssetBulkOperations(props.spaceId)
const { mutateAsync: updateAsset } = useUpdateAssetMutation()
const { mutateAsync: deleteAsset } = useDeleteAssetMutation()
const { mutateAsync: deleteFolder } = useDeleteAssetFolderMutation()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageAssets = computed(() => access.hasAbility('assets.manage'))
const canManageFolders = computed(() => access.hasAbility('asset_folders.manage'))

const folderId = defineModel<string | null>('folderId')
const tagId = defineModel<string | null>('tagId')
const assetId = defineModel<string | null>('assetId', { default: null })

const showUploadDialog = ref(false)
const droppedFiles = ref<File[]>([])
const folderDialogOpen = ref(false)
const dialogParentFolderId = ref<string | null>(null)
const editingFolder = ref<AssetFolderResource | null>(null)
const detailAsset = ref<AssetResource | null>(null)
const rootBreadcrumbRef = ref<HTMLElement | null>(null)
const isRootDropActive = ref(false)
const activeBreadcrumbDropId = ref<string | null>(null)
const activeFolderId = computed(() => folderId.value ?? null)
const currentPage = ref(1)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})
const filters = ref<Record<string, unknown>>({})
const q = ref('')

const moveDialogOpen = ref(false)
const moveDialogItems = ref<AssetManagerDragItem[]>([])
const bulkTagOpen = ref(false)
const bulkTagAssets = ref<AssetResource[]>([])
const shortcutsOpen = ref(false)
const isSelectingAllMatching = ref(false)
const clipboard = ref<AssetManagerDragItem[] | null>(null)
const focusedKey = ref<string | null>(null)
const gridWrapperRef = ref<HTMLElement | null>(null)
const mainRef = ref<HTMLElement | null>(null)

const gridSizes = {
  sm: { cls: 'grid-cols-4 xl:grid-cols-6 2xl:grid-cols-12', icon: 'lucide:grid-3x3' },
  md: { cls: 'grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6', icon: 'lucide:grid-2x2' },
  lg: { cls: 'grid-cols-2 xl:grid-cols-4', icon: 'lucide:square-square' },
} as const

const sortOptions = [
  { value: 'created_at', label: $t('labels.assets.createdAt') },
  { value: 'updated_at', label: $t('labels.assets.updatedAt') },
  { value: 'filename', label: $t('labels.assets.fields.filename') },
  { value: 'size', label: $t('labels.assets.size') },
]

const assetFilters = computed(() => [
  { id: 'extension', label: 'Extension' },
  { id: 'filename', label: 'Filename' },
  {
    id: 'size',
    label: 'Size',
    operators: [
      { value: 'gt' as const, label: '>' },
      { value: 'lt' as const, label: '<' },
      { value: 'eq' as const, label: '=' },
    ],
  },
  {
    id: 'rights_status',
    label: String($t('labels.assets.rights.filterLabel')),
    operators: [{ value: 'eq' as const, label: 'Equals' }],
    items: [
      { value: 'restricted', label: String($t('labels.assets.rights.status.restricted')) },
      { value: 'expired', label: String($t('labels.assets.rights.status.expired')) },
      { value: 'unrestricted', label: String($t('labels.assets.rights.status.unrestricted')) },
    ],
  },
  {
    id: 'expiring_before',
    label: String($t('labels.assets.rights.filterExpiringBefore')),
    datepicker: {},
  },
])

const assetQueryParams = computed<AssetsQueryParams>(() => {
  return {
    ...filters.value,
    folder: folderId.value ?? undefined,
    tags: tagId.value ?? undefined,
    q: q.value || undefined,
    sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
    page: currentPage.value,
    per_page: settings.value.assets.pageSize || 12,
  }
})

const { data: assetResponse } = useAssetsQuery(assetQueryParams)

const shouldLoadDeepLinkedAsset = computed(() => {
  return props.mode === 'manage' && Boolean(assetId.value) && assetId.value !== detailAsset.value?.id
})
const { data: deepLinkedAsset } = useAssetQuery(
  computed(() => assetId.value ?? ''),
  shouldLoadDeepLinkedAsset
)

watch(deepLinkedAsset, (asset) => {
  if (asset && asset.id === assetId.value) {
    detailAsset.value = asset
  }
})

watch(detailAsset, (asset) => {
  assetId.value = asset?.id ?? null
})

const selectedGridSize = computed(() => {
  const key = settings.value.assets.gridSize as keyof typeof gridSizes
  return gridSizes[key] || gridSizes.md
})

const imageSize = computed(() => {
  switch (settings.value.assets.gridSize) {
    case 'sm':
      return 129
    case 'lg':
      return 436
    default:
      return 284
  }
})

const breadcrumbs = computed(() => {
  if (!folderId.value) {
    return []
  }

  return getBreadcrumbs(folderId.value)
})

const folders = computed(() => {
  if (tagId.value) {
    return []
  }

  return getChildrenOfFolder(activeFolderId.value)
})

const visibleFolders = computed(() => {
  return props.showFolders && settings.value.assets.gridFolders ? folders.value : []
})

const assets = computed(() => assetResponse.value?.data || [])

const orderedEntries = computed<AssetSelectionEntry[]>(() => {
  return [
    ...visibleFolders.value.map((folder) => ({ type: 'folder' as const, data: folder })),
    ...assets.value.map((asset) => ({ type: 'asset' as const, data: asset })),
  ]
})

const selection = useAssetSelection(orderedEntries)
const {
  selectedAssets,
  selectedFolders,
  hasSelection,
  selectionCount,
  keyOf,
  isSelected,
} = selection

const entryByKey = computed(() => {
  const map = new Map<string, AssetSelectionEntry>()
  for (const entry of orderedEntries.value) {
    map.set(keyOf(entry), entry)
  }
  return map
})

const tagMap = computed(() => {
  const map = new Map()
  for (const tag of allTagsResponse.value?.data ?? []) {
    map.set(tag.id, tag)
  }
  return map
})

const resolvedTagsFor = (tagIds: string[] | null | undefined) => {
  return (tagIds ?? []).map((id) => tagMap.value.get(id)).filter(Boolean)
}

const nonCompliantAssets = computed(() => {
  return assets.value.filter((asset) => !isCompliant(asset))
})

const cutKeys = computed(() => {
  return new Set((clipboard.value ?? []).map((item) => `${item.type}:${item.id}`))
})

const offPageSelectedCount = computed(() => {
  const pageAssetIds = new Set(assets.value.map((asset) => asset.id))
  const pageFolderIds = new Set(visibleFolders.value.map((folder) => folder.id))

  let count = 0
  for (const id of selectedAssets.value.keys()) {
    if (!pageAssetIds.has(id)) count += 1
  }
  for (const id of selectedFolders.value.keys()) {
    if (!pageFolderIds.has(id)) count += 1
  }
  return count
})

const totalMatchingAssets = computed(() => assetResponse.value?.meta?.total ?? 0)

const canSelectAllMatching = computed(() => {
  if (!assets.value.length || totalMatchingAssets.value <= assets.value.length) {
    return false
  }

  return assets.value.every((asset) => selectedAssets.value.has(asset.id))
})

const getSelectedDragItems = (): AssetManagerDragItem[] => {
  return selection.selectedDragItems.value
}

const getDragItemsFor = (type: 'asset' | 'folder', id: string): AssetManagerDragItem[] => {
  const selected = type === 'asset' ? selectedAssets.value.has(id) : selectedFolders.value.has(id)

  if (!selected || !hasSelection.value) {
    return [{ id, type }]
  }

  return getSelectedDragItems()
}

const assetItemProps = computed(() => {
  return {
    mode: props.mode,
    draggable: props.mode === 'manage' && canManageAssets.value,
    showCheckbox: props.mode === 'manage' && props.multiSelect,
    canEdit: canManageAssets.value,
    canDelete: canManageAssets.value,
  }
})

const emitSelectionChange = () => {
  if (props.mode !== 'manage') {
    return
  }

  emit('selectionChange', {
    folders: Array.from(selectedFolders.value.values()),
    assets: Array.from(selectedAssets.value.values()),
  })
}

watch([selectedAssets, selectedFolders], emitSelectionChange, { deep: true })

const clearSelection = () => {
  if (props.mode !== 'manage') {
    return
  }

  selection.clear()
}

/* ------------------------------------------------------------------ */
/* Click / focus interaction                                           */
/* ------------------------------------------------------------------ */

const modifiersFromEvent = (event: MouseEvent) => ({
  meta: event.metaKey || event.ctrlKey,
  shift: event.shiftKey,
})

const handleAssetClick = (asset: AssetResource, event: MouseEvent) => {
  if (props.mode !== 'manage') {
    return
  }

  const entry: AssetSelectionEntry = { type: 'asset', data: asset }

  if (!props.multiSelect) {
    selection.selectOnly(entry)
  } else {
    selection.handleItemPointer(entry, modifiersFromEvent(event))
  }

  focusedKey.value = keyOf(entry)
}

const handleFolderClick = (folder: AssetFolderResource, event: MouseEvent) => {
  if (props.mode !== 'manage') {
    navigateToFolder(folder.id)
    return
  }

  const entry: AssetSelectionEntry = { type: 'folder', data: folder }

  if (!props.multiSelect) {
    selection.selectOnly(entry)
  } else {
    selection.handleItemPointer(entry, modifiersFromEvent(event))
  }

  focusedKey.value = keyOf(entry)
}

const handleAssetView = (asset: AssetResource) => {
  if (props.mode === 'select') {
    emit('asset-select', asset)
    return
  }

  detailAsset.value = asset
}

const handleAssetSelect = (asset: AssetResource, selected?: boolean) => {
  if (props.mode === 'select') {
    emit('asset-select', asset)
    return
  }

  if (typeof selected !== 'boolean') {
    return
  }

  selection.setSelected({ type: 'asset', data: asset }, selected)
}

const handleFolderSelect = (folder: AssetFolderResource, selected: boolean) => {
  if (props.mode !== 'manage' || !props.multiSelect) {
    return
  }

  selection.setSelected({ type: 'folder', data: folder }, selected)
}

const handleEntryContextMenu = (entry: AssetSelectionEntry) => {
  if (props.mode !== 'manage') {
    return
  }

  if (!isSelected(entry)) {
    selection.selectOnly(entry)
  }

  focusedKey.value = keyOf(entry)
}

const handleFocusIn = (event: FocusEvent) => {
  const option = (event.target as HTMLElement).closest('[role="option"]') as HTMLElement | null

  if (option?.dataset.key) {
    focusedKey.value = option.dataset.key
  }
}

const navigateToFolder = (id: string | null) => {
  folderId.value = id
  emit('folder-change', id)
}

const navigateToParent = () => {
  if (!folderId.value) {
    return
  }

  const crumbs = breadcrumbs.value
  navigateToFolder(crumbs.length > 1 ? crumbs[crumbs.length - 2].id : null)
}

const openCreateFolderDialog = (parentId: string | null = activeFolderId.value) => {
  editingFolder.value = null
  dialogParentFolderId.value = parentId
  folderDialogOpen.value = true
}

const openEditFolderDialog = (folder: AssetFolderResource) => {
  editingFolder.value = folder
  dialogParentFolderId.value = folder.parent_id
  folderDialogOpen.value = true
}

/* ------------------------------------------------------------------ */
/* Bulk actions                                                        */
/* ------------------------------------------------------------------ */

/**
 * Actions triggered from an item's menu act on the whole selection when the
 * item is part of it (Finder semantics), otherwise only on the item itself.
 */
const assetsForAction = (asset: AssetResource): AssetResource[] => {
  if (selectedAssets.value.has(asset.id) && selectionCount.value > 1) {
    return Array.from(selectedAssets.value.values())
  }

  return [asset]
}

const itemsForMoveAction = (entry: AssetSelectionEntry): AssetManagerDragItem[] => {
  if (isSelected(entry) && selectionCount.value > 1) {
    return getSelectedDragItems()
  }

  return [{ id: entry.data.id, type: entry.type }]
}

const openMoveDialog = (items: AssetManagerDragItem[]) => {
  if (!items.length) {
    return
  }

  moveDialogItems.value = items
  moveDialogOpen.value = true
}

const openBulkTagDialog = (assetsToTag: AssetResource[]) => {
  if (!assetsToTag.length) {
    return
  }

  bulkTagAssets.value = assetsToTag
  bulkTagOpen.value = true
}

const handleMoved = () => {
  clearSelection()
  clipboard.value = null
}

const handleCopyUrl = async (asset: AssetResource) => {
  const urls = assetsForAction(asset).map((item) => item.full_path)

  await navigator.clipboard.writeText(urls.join('\n'))
  toast.success(String($t('messages.assets.urlCopied', { count: urls.length })))
}

const handleDownload = async (assetsToDownload: AssetResource[]) => {
  if (!assetsToDownload.length) {
    return
  }

  toast.info(String($t('messages.assets.downloadStarted', { count: assetsToDownload.length })))

  const { failed } = await downloadAssetFiles(assetsToDownload)

  if (failed.length) {
    toast.error(String($t('messages.assets.downloadFailed', { count: failed.length })))
  }
}

const promptForceDelete = async (
  asset: AssetResource,
  linkedContentsCount: number
): Promise<boolean> => {
  return await alert.confirm(
    String(
      $t('messages.assets.forceDeleteConfirmation', {
        name: asset.filename,
        count: linkedContentsCount,
      })
    ),
    {
      title: String($t('labels.assets.forceDeleteTitle')),
      confirmLabel: String($t('actions.forceDelete')),
      cancelLabel: String($t('alertDialog.cancel')),
      variant: 'destructive',
    }
  )
}

const attemptAssetDelete = async (asset: AssetResource): Promise<boolean> => {
  try {
    await deleteAsset({ id: asset.id })
    return true
  } catch (error: any) {
    if (error?.status !== 409 || error?.data?.code !== 'asset_in_use') {
      throw error
    }

    const forceDeleteConfirmed = await promptForceDelete(asset, error.data.linked_contents_count)

    if (!forceDeleteConfirmed) {
      return false
    }

    await deleteAsset({ id: asset.id, force: true })
    return true
  }
}

const handleAssetDelete = async (asset: AssetResource) => {
  const batch = assetsForAction(asset)

  if (batch.length > 1) {
    await deleteAssets(batch)
    return
  }

  const confirmed = await alert.confirm(
    $t('messages.assets.deleteConfirmation', { name: asset.filename }),
    {
      title: $t('labels.assets.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  const deleted = await attemptAssetDelete(asset)

  if (!deleted) {
    return
  }

  selectedAssets.value.delete(asset.id)
}

const handleFolderDelete = async (folder: AssetFolderResource) => {
  const confirmed = await alert.confirm(
    $t('messages.assetFolders.deleteConfirmation', { name: folder.name }),
    {
      title: $t('labels.assetFolders.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  await deleteFolder(folder.id)
  selectedFolders.value.delete(folder.id)
}

const deleteAssets = async (assetsToDelete: AssetResource[]) => {
  const confirmed = await alert.confirm(
    String($t('messages.assets.bulkDeleteConfirmation', { count: assetsToDelete.length })),
    {
      title: $t('labels.assets.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  const { deletedIds, conflicts, failed } = await bulkDeleteAssets(assetsToDelete)

  for (const id of deletedIds) {
    selectedAssets.value.delete(id)
  }

  if (deletedIds.length) {
    toast.success(String($t('messages.assets.bulkDeleteSuccess', { count: deletedIds.length })))
  }

  if (failed) {
    toast.error(String($t('messages.assets.bulkDeleteFailed', { count: failed })))
  }

  if (conflicts.length) {
    const forceConfirmed = await alert.confirm(
      String($t('messages.assets.bulkForceDeleteConfirmation', { count: conflicts.length })),
      {
        title: String($t('labels.assets.forceDeleteTitle')),
        confirmLabel: String($t('actions.forceDelete')),
        cancelLabel: String($t('alertDialog.cancel')),
        variant: 'destructive',
      }
    )

    if (!forceConfirmed) {
      return
    }

    const forceResult = await bulkDeleteAssets(
      conflicts.map((conflict) => conflict.asset),
      { force: true }
    )

    for (const id of forceResult.deletedIds) {
      selectedAssets.value.delete(id)
    }

    if (forceResult.deletedIds.length) {
      toast.success(
        String($t('messages.assets.bulkDeleteSuccess', { count: forceResult.deletedIds.length }))
      )
    }
  }
}

const deleteSelection = async () => {
  if (props.mode !== 'manage' || !hasSelection.value) {
    return
  }

  const foldersToDelete = Array.from(selectedFolders.value.values())
  const assetsToDelete = Array.from(selectedAssets.value.values())

  if (assetsToDelete.length) {
    await deleteAssets(assetsToDelete)
  }

  if (foldersToDelete.length && canManageFolders.value) {
    const confirmed =
      foldersToDelete.length === 1 ||
      (await alert.confirm(
        String($t('messages.assetFolders.bulkDeleteConfirmation', { count: foldersToDelete.length })),
        {
          title: $t('labels.assetFolders.deleteTitle'),
          confirmLabel: $t('actions.delete'),
          variant: 'destructive',
        }
      ))

    if (foldersToDelete.length === 1) {
      await handleFolderDelete(foldersToDelete[0])
    } else if (confirmed) {
      for (const folder of foldersToDelete) {
        await deleteFolder(folder.id)
        selectedFolders.value.delete(folder.id)
      }
    }
  }
}

const selectAllMatching = async () => {
  if (isSelectingAllMatching.value) {
    return
  }

  isSelectingAllMatching.value = true

  try {
    const { page: _page, per_page: _perPage, ...params } = assetQueryParams.value
    const { assets: allAssets, truncated, total } = await fetchAllMatchingAssets(params)

    for (const asset of allAssets) {
      selectedAssets.value.set(asset.id, asset)
    }

    if (truncated) {
      toast.warning(
        String($t('messages.assets.selectAllTruncated', { count: allAssets.length, total }))
      )
    }
  } finally {
    isSelectingAllMatching.value = false
  }
}

/* ------------------------------------------------------------------ */
/* Clipboard (cut / paste)                                             */
/* ------------------------------------------------------------------ */

const cutSelection = () => {
  if (!hasSelection.value || !canManageAssets.value) {
    return
  }

  clipboard.value = getSelectedDragItems()
  toast.message(String($t('messages.assets.cutHint', { count: clipboard.value.length })))
}

const pasteClipboard = async () => {
  if (!clipboard.value?.length) {
    return
  }

  if (!canMoveItems(clipboard.value, activeFolderId.value)) {
    toast.error(String($t('messages.assetFolders.invalidMoveToChild')))
    return
  }

  try {
    await moveItemsToFolder(clipboard.value, activeFolderId.value)
    toast.success(String($t('messages.assets.pasteSuccess', { count: clipboard.value.length })))
    clipboard.value = null
    clearSelection()
  } catch {
    toast.error(String($t('messages.assetFolders.invalidMoveToChild')))
  }
}

/* ------------------------------------------------------------------ */
/* Item moves (drag & drop, dialogs)                                   */
/* ------------------------------------------------------------------ */

const handleItemsMove = async (targetFolderId: string | null, items: AssetManagerDragItem[]) => {
  if (!canMoveItems(items, targetFolderId)) {
    toast.error(String($t('messages.assetFolders.invalidMoveToChild')))
    return
  }

  try {
    await moveItemsToFolder(items, targetFolderId)
    clearSelection()
  } catch {
    toast.error(String($t('messages.assetFolders.invalidMoveToChild')))
  }
}

const saveAsset = async (asset: AssetResource) => {
  await updateAsset({
    id: asset.id,
    payload: {
      filename: asset.filename,
      folder_id: asset.folder_id,
      metadata: asset.metadata,
      data: asset.data,
      tags: asset.tags,
      license_expires_at: asset.license_expires_at,
    },
  })

  detailAsset.value = null
}

/* ------------------------------------------------------------------ */
/* Keyboard                                                            */
/* ------------------------------------------------------------------ */

let typeaheadBuffer = ''
let typeaheadTimer: ReturnType<typeof setTimeout> | null = null

const anyDialogOpen = () => {
  return Boolean(
    detailAsset.value ||
      showUploadDialog.value ||
      folderDialogOpen.value ||
      moveDialogOpen.value ||
      bulkTagOpen.value ||
      shortcutsOpen.value ||
      document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"]')
  )
}

const isEditableTarget = (target: EventTarget | null): boolean => {
  const element = target as HTMLElement | null
  return Boolean(
    element?.closest?.('input, textarea, select, [contenteditable="true"], [role="combobox"]')
  )
}

const isGridTarget = (target: EventTarget | null): boolean => {
  const element = target as HTMLElement | null

  if (!element || element === document.body) {
    return true
  }

  return Boolean(element.closest?.('[data-asset-grid]'))
}

const entryName = (entry: AssetSelectionEntry): string => {
  return entry.type === 'asset' ? entry.data.filename : entry.data.name
}

const focusEntry = (entry: AssetSelectionEntry) => {
  focusedKey.value = keyOf(entry)

  nextTick(() => {
    const element = gridWrapperRef.value?.querySelector(
      `[data-key="${CSS.escape(keyOf(entry))}"]`
    ) as HTMLElement | null

    element?.focus({ preventScroll: true })
    element?.scrollIntoView({ block: 'nearest' })
  })
}

const focusedEntry = computed(() => {
  return focusedKey.value ? (entryByKey.value.get(focusedKey.value) ?? null) : null
})

const itemsPerRowFor = (entry: AssetSelectionEntry): number => {
  const element = gridWrapperRef.value?.querySelector(
    `[data-key="${CSS.escape(keyOf(entry))}"]`
  ) as HTMLElement | null
  const container = element?.parentElement

  if (!element || !container) {
    return 1
  }

  const options = Array.from(container.querySelectorAll(':scope > [role="option"]')) as HTMLElement[]
  const firstTop = options[0]?.offsetTop

  const count = options.filter((option) => option.offsetTop === firstTop).length
  return Math.max(1, count)
}

const moveFocus = (delta: number, extend: boolean) => {
  const entries = orderedEntries.value

  if (!entries.length) {
    return
  }

  const currentIndex = focusedKey.value
    ? entries.findIndex((entry) => keyOf(entry) === focusedKey.value)
    : -1

  const nextIndex =
    currentIndex === -1
      ? 0
      : Math.min(Math.max(currentIndex + delta, 0), entries.length - 1)

  const entry = entries[nextIndex]

  if (extend && props.multiSelect) {
    selection.selectRangeTo(entry)
  } else {
    selection.selectOnly(entry)
  }

  focusEntry(entry)
}

const handleTypeahead = (char: string) => {
  typeaheadBuffer += char.toLowerCase()

  if (typeaheadTimer) {
    clearTimeout(typeaheadTimer)
  }

  typeaheadTimer = setTimeout(() => {
    typeaheadBuffer = ''
  }, 800)

  const match = orderedEntries.value.find((entry) =>
    entryName(entry).toLowerCase().startsWith(typeaheadBuffer)
  )

  if (match) {
    selection.selectOnly(match)
    focusEntry(match)
  }
}

const handleWindowKeydown = (event: KeyboardEvent) => {
  if (props.mode !== 'manage' || anyDialogOpen() || isEditableTarget(event.target)) {
    return
  }

  const meta = event.metaKey || event.ctrlKey

  if (meta && event.key.toLowerCase() === 'a') {
    if (!props.multiSelect) return
    event.preventDefault()
    selection.selectAll()
    return
  }

  if (event.key === 'Escape') {
    if (clipboard.value) {
      clipboard.value = null
    } else if (hasSelection.value) {
      clearSelection()
    }
    return
  }

  if (meta && event.key.toLowerCase() === 'x' && canManageAssets.value) {
    event.preventDefault()
    cutSelection()
    return
  }

  if (meta && event.key.toLowerCase() === 'v' && canManageAssets.value) {
    event.preventDefault()
    void pasteClipboard()
    return
  }

  if (meta && event.key.toLowerCase() === 'c' && hasSelection.value) {
    const urls = Array.from(selectedAssets.value.values()).map((asset) => asset.full_path)

    if (urls.length) {
      event.preventDefault()
      void navigator.clipboard.writeText(urls.join('\n')).then(() => {
        toast.success(String($t('messages.assets.urlCopied', { count: urls.length })))
      })
    }
    return
  }

  if (
    (event.key === 'Delete' || (meta && event.key === 'Backspace')) &&
    hasSelection.value &&
    canManageAssets.value
  ) {
    event.preventDefault()
    void deleteSelection()
    return
  }

  if (meta && event.key === 'ArrowUp') {
    event.preventDefault()
    navigateToParent()
    return
  }

  if (!isGridTarget(event.target)) {
    return
  }

  if (event.key === 'Backspace' && !meta) {
    event.preventDefault()
    navigateToParent()
    return
  }

  if (event.key === '?' ) {
    event.preventDefault()
    shortcutsOpen.value = true
    return
  }

  if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key) && !meta) {
    event.preventDefault()

    const reference = focusedEntry.value ?? orderedEntries.value[0]

    if (!reference) {
      return
    }

    const perRow = itemsPerRowFor(reference)
    const delta =
      event.key === 'ArrowLeft'
        ? -1
        : event.key === 'ArrowRight'
          ? 1
          : event.key === 'ArrowUp'
            ? -perRow
            : perRow

    moveFocus(delta, event.shiftKey)
    return
  }

  if (event.key === 'Enter' || event.key === ' ') {
    const entry = focusedEntry.value

    if (!entry) {
      return
    }

    event.preventDefault()

    if (entry.type === 'folder') {
      navigateToFolder(entry.data.id)
    } else {
      handleAssetView(entry.data)
    }
    return
  }

  if (event.key.length === 1 && !meta && !event.altKey) {
    handleTypeahead(event.key)
  }
}

/* ------------------------------------------------------------------ */
/* Marquee (rubber-band) selection                                     */
/* ------------------------------------------------------------------ */

const marqueeRect = ref<{ left: number; top: number; width: number; height: number } | null>(null)

let marqueeStart: { x: number; y: number } | null = null
let marqueeAdditive = false
let marqueeBaseAssets: Map<string, AssetResource> | null = null
let marqueeBaseFolders: Map<string, AssetFolderResource> | null = null
let marqueeMoved = false

const pagePoint = (event: MouseEvent) => ({
  x: event.clientX + window.scrollX,
  y: event.clientY + window.scrollY,
})

const handleMarqueeMouseDown = (event: MouseEvent) => {
  if (props.mode !== 'manage' || !props.multiSelect || event.button !== 0) {
    return
  }

  const target = event.target as HTMLElement

  if (
    target.closest(
      '[role="option"], button, a, input, textarea, select, label, [role="combobox"], [data-no-marquee]'
    )
  ) {
    return
  }

  marqueeStart = pagePoint(event)
  marqueeAdditive = event.shiftKey || event.metaKey || event.ctrlKey
  marqueeBaseAssets = new Map(selectedAssets.value)
  marqueeBaseFolders = new Map(selectedFolders.value)
  marqueeMoved = false

  window.addEventListener('mousemove', handleMarqueeMouseMove)
  window.addEventListener('mouseup', handleMarqueeMouseUp)
}

const handleMarqueeMouseMove = (event: MouseEvent) => {
  if (!marqueeStart || !mainRef.value) {
    return
  }

  const current = pagePoint(event)
  const distance = Math.hypot(current.x - marqueeStart.x, current.y - marqueeStart.y)

  if (!marqueeMoved && distance < 4) {
    return
  }

  marqueeMoved = true
  event.preventDefault()

  const rect = {
    left: Math.min(marqueeStart.x, current.x),
    top: Math.min(marqueeStart.y, current.y),
    width: Math.abs(current.x - marqueeStart.x),
    height: Math.abs(current.y - marqueeStart.y),
  }

  const wrapperBounds = mainRef.value.getBoundingClientRect()
  const wrapperPage = {
    left: wrapperBounds.left + window.scrollX,
    top: wrapperBounds.top + window.scrollY,
  }

  marqueeRect.value = {
    left: rect.left - wrapperPage.left,
    top: rect.top - wrapperPage.top,
    width: rect.width,
    height: rect.height,
  }

  const nextAssets = new Map(marqueeAdditive ? marqueeBaseAssets : undefined)
  const nextFolders = new Map(marqueeAdditive ? marqueeBaseFolders : undefined)

  const options = mainRef.value.querySelectorAll('[role="option"][data-key]')

  options.forEach((option) => {
    const bounds = (option as HTMLElement).getBoundingClientRect()
    const optionRect = {
      left: bounds.left + window.scrollX,
      top: bounds.top + window.scrollY,
      width: bounds.width,
      height: bounds.height,
    }

    const intersects =
      optionRect.left < rect.left + rect.width &&
      optionRect.left + optionRect.width > rect.left &&
      optionRect.top < rect.top + rect.height &&
      optionRect.top + optionRect.height > rect.top

    if (!intersects) {
      return
    }

    const entry = entryByKey.value.get((option as HTMLElement).dataset.key ?? '')

    if (!entry) {
      return
    }

    if (entry.type === 'asset') {
      nextAssets.set(entry.data.id, entry.data)
    } else {
      nextFolders.set(entry.data.id, entry.data)
    }
  })

  selectedAssets.value = nextAssets
  selectedFolders.value = nextFolders
}

const handleMarqueeMouseUp = (event: MouseEvent) => {
  window.removeEventListener('mousemove', handleMarqueeMouseMove)
  window.removeEventListener('mouseup', handleMarqueeMouseUp)

  if (!marqueeMoved && marqueeStart) {
    const additive = event.shiftKey || event.metaKey || event.ctrlKey

    if (!additive) {
      clearSelection()
    }
  }

  marqueeStart = null
  marqueeBaseAssets = null
  marqueeBaseFolders = null
  marqueeRect.value = null
  marqueeMoved = false
}

/* ------------------------------------------------------------------ */
/* Breadcrumb drop targets                                             */
/* ------------------------------------------------------------------ */

const breadcrumbCleanups = new Map<string, () => void>()

const setBreadcrumbDropRef = (crumbId: string) => {
  return (value: Element | { $el?: Element } | null) => {
    breadcrumbCleanups.get(crumbId)?.()
    breadcrumbCleanups.delete(crumbId)

    const element =
      value instanceof HTMLElement
        ? value
        : value && '$el' in value && value.$el instanceof HTMLElement
          ? value.$el
          : null

    if (!element) {
      return
    }

    const cleanup = dropTargetForElements({
      element,
      canDrop: ({ source }) => canMoveItems(getAssetManagerDragItems(source.data), crumbId),
      getIsSticky: () => true,
      onDragEnter: () => {
        activeBreadcrumbDropId.value = crumbId
      },
      onDragLeave: () => {
        if (activeBreadcrumbDropId.value === crumbId) {
          activeBreadcrumbDropId.value = null
        }
      },
      onDrop: async ({ source }) => {
        activeBreadcrumbDropId.value = null
        await handleItemsMove(crumbId, getAssetManagerDragItems(source.data))
      },
    })

    breadcrumbCleanups.set(crumbId, cleanup)
  }
}

/* ------------------------------------------------------------------ */
/* File drops / lifecycle                                              */
/* ------------------------------------------------------------------ */

const handleDocumentDragOver = (event: DragEvent) => {
  if (!event.dataTransfer?.types.includes('Files')) {
    return
  }

  event.preventDefault()
  document.body.classList.add('drag-over')
}

const handleDocumentDragLeave = (event: DragEvent) => {
  if (!event.dataTransfer?.types.includes('Files')) {
    return
  }

  if (!event.relatedTarget || event.relatedTarget === document.body) {
    document.body.classList.remove('drag-over')
  }
}

const handleDocumentDrop = (event: DragEvent) => {
  if (!event.dataTransfer?.files?.length) {
    return
  }

  event.preventDefault()
  document.body.classList.remove('drag-over')
  droppedFiles.value = Array.from(event.dataTransfer.files)
  showUploadDialog.value = true
}

watch([folderId, tagId], () => {
  clearSelection()
  focusedKey.value = null
  currentPage.value = 1
})

watch(rootBreadcrumbRef, (element, _, onCleanup) => {
  if (!element) {
    return
  }

  const cleanup = dropTargetForElements({
    element,
    canDrop: ({ source }) => canMoveItems(getAssetManagerDragItems(source.data), null),
    getIsSticky: () => true,
    onDragEnter: () => {
      isRootDropActive.value = true
    },
    onDragLeave: () => {
      isRootDropActive.value = false
    },
    onDrop: async ({ source }) => {
      isRootDropActive.value = false
      await handleItemsMove(null, getAssetManagerDragItems(source.data))
    },
  })

  onCleanup(() => {
    isRootDropActive.value = false
    cleanup()
  })
})

onMounted(() => {
  if (props.initialFolderId) {
    folderId.value = props.initialFolderId
  }

  if (props.initialTagId) {
    tagId.value = props.initialTagId
  }

  document.addEventListener('dragover', handleDocumentDragOver)
  document.addEventListener('dragleave', handleDocumentDragLeave)
  document.addEventListener('drop', handleDocumentDrop)
  window.addEventListener('keydown', handleWindowKeydown)
})

onUnmounted(() => {
  document.removeEventListener('dragover', handleDocumentDragOver)
  document.removeEventListener('dragleave', handleDocumentDragLeave)
  document.removeEventListener('drop', handleDocumentDrop)
  window.removeEventListener('keydown', handleWindowKeydown)
  window.removeEventListener('mousemove', handleMarqueeMouseMove)
  window.removeEventListener('mouseup', handleMarqueeMouseUp)
  document.body.classList.remove('drag-over')
  breadcrumbCleanups.forEach((cleanup) => cleanup())
  breadcrumbCleanups.clear()

  if (typeaheadTimer) {
    clearTimeout(typeaheadTimer)
  }
})
</script>

<template>
  <main
    ref="mainRef"
    class="relative flex flex-col gap-6"
    :class="{ 'select-none': marqueeRect }"
    data-asset-grid
    @mousedown="handleMarqueeMouseDown"
  >
    <header class="flex h-5 items-center justify-between">
      <Breadcrumb class="flex gap-2">
        <BreadcrumbItem @click="navigateToFolder(null)">
          <button
            ref="rootBreadcrumbRef"
            :class="[
              'flex cursor-pointer items-center gap-2 rounded-md py-1 transition-colors hover:text-primary',
              isRootDropActive ? 'bg-input/70 ring-1 ring-border' : '',
            ]"
            @click="navigateToFolder(null)"
          >
            <Icon name="lucide:home" />
            <span>{{ $t('labels.assets.allAssets') }}</span>
          </button>
        </BreadcrumbItem>

        <template
          v-for="{ id, color, icon, name } in breadcrumbs"
          :key="id"
        >
          <li
            role="presentation"
            aria-hidden="true"
            class="flex items-center gap-2"
          >
            /
          </li>
          <BreadcrumbItem>
            <button
              :ref="setBreadcrumbDropRef(id)"
              :class="[
                'flex cursor-pointer items-center gap-2 rounded-md py-1 transition-colors hover:text-primary',
                activeBreadcrumbDropId === id ? 'bg-input/70 ring-1 ring-border' : '',
              ]"
              @click="navigateToFolder(id)"
            >
              <Icon
                :name="`lucide:${icon}`"
                :style="{ color: color || 'inherit' }"
              />
              <span>{{ name }}</span>
            </button>
          </BreadcrumbItem>
        </template>
      </Breadcrumb>

      <div class="flex items-center gap-2">
        <Button
          v-if="allowUpload && canManageAssets"
          variant="primary"
          @click="showUploadDialog = true"
        >
          <Icon name="lucide:upload" />
          {{ $t('actions.assets.upload') }}
        </Button>
        <Button
          v-if="allowFolderCreation && canManageFolders"
          @click="openCreateFolderDialog()"
        >
          <Icon name="lucide:folder-plus" />
          {{ $t('actions.assetFolders.create') }}
        </Button>
      </div>
    </header>

    <Alert
      v-if="nonCompliantAssets.length"
      icon="lucide:circle-alert"
      color="warning"
    >
      {{
        $t('labels.assets.requirementsSummary', {
          count: nonCompliantAssets.length,
        })
      }}
    </Alert>

    <ContextMenu>
      <ContextMenuTrigger
        as-child
        :disabled="mode !== 'manage'"
      >
        <div
          ref="gridWrapperRef"
          class="flex flex-col gap-6"
          @focusin="handleFocusIn"
        >
          <section
            v-if="showFolders && folders.length"
            class="grid grow gap-6"
          >
            <button
              data-no-marquee
              @click="settings.assets.gridFolders = !settings.assets.gridFolders"
            >
              <h2 class="flex items-center gap-2 text-2xl">
                <Icon
                  name="lucide:folder"
                  size="1.25rem"
                />
                <span class="font-semibold text-primary">{{ $t('labels.assetFolders.title') }}</span>
                <Badge>{{ folders.length }}</Badge>
                <Icon
                  name="lucide:chevron-up"
                  class="transition-transform duration-200"
                  :class="{ 'rotate-180': settings.assets.gridFolders }"
                />
              </h2>
            </button>

            <div
              v-if="settings.assets.gridFolders"
              class="grid grid-cols-3 gap-3 rounded-lg bg-surface p-3 xl:grid-cols-2 2xl:grid-cols-3"
              role="listbox"
              aria-label="Folders"
              aria-multiselectable="true"
            >
              <AssetFolder
                v-for="folder in folders"
                :key="folder.id"
                :folder="folder"
                :selected="selectedFolders.has(folder.id)"
                :cut="cutKeys.has(`folder:${folder.id}`)"
                :draggable="mode === 'manage' && canManageFolders"
                :can-edit="canManageFolders"
                :can-delete="canManageFolders"
                :can-create-children="canManageFolders"
                :drag-items="getDragItemsFor('folder', folder.id)"
                :can-receive-drop="(items) => canMoveItems(items, folder.id)"
                :on-items-drop="(items) => handleItemsMove(folder.id, items)"
                :data-id="folder.id"
                :data-key="`folder:${folder.id}`"
                @select="handleFolderSelect"
                @click="handleFolderClick"
                @open="(value) => navigateToFolder(value.id)"
                @edit="openEditFolderDialog"
                @move="(value) => openMoveDialog(itemsForMoveAction({ type: 'folder', data: value }))"
                @delete="handleFolderDelete"
                @create="(value) => openCreateFolderDialog(value.id)"
                @context-menu="(value) => handleEntryContextMenu({ type: 'folder', data: value })"
              />
            </div>
          </section>

          <section class="flex flex-col gap-6">
            <div class="flex items-center">
              <h2 class="flex items-center gap-2 text-2xl">
                <Icon
                  name="lucide:image"
                  size="1.25rem"
                />
                <span class="font-semibold text-primary">{{ $t('labels.assets.assets') }}</span>
                <Badge>{{ assetResponse?.meta?.total || 0 }}</Badge>
              </h2>
              <div
                class="ml-auto flex gap-2"
                data-no-marquee
              >
                <SearchFilter
                  v-model="filters"
                  :filterable-fields="assetFilters"
                  class="lg:min-w-xs 2xl:min-w-md"
                  @search="q = $event"
                  @reset="q = ''"
                />
                <SortSelect
                  v-model="sortBy"
                  :options="sortOptions"
                  :label="String($t('labels.sortBy'))"
                  :placeholder="String($t('labels.sortBy'))"
                />
                <Select v-model="settings.assets.gridSize">
                  <SelectTrigger>
                    <Icon :name="selectedGridSize.icon" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem
                      v-for="(option, key) in gridSizes"
                      :key="key"
                      :value="key"
                    >
                      <Icon :name="option.icon" />
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div
              v-if="assetResponse"
              class="grid flex-1 gap-1"
            >
              <div
                v-if="!assets.length"
                class="flex min-h-[200px] flex-col items-center justify-center rounded-lg bg-surface p-8"
              >
                <AssetsIcon class="mb-4 w-32 text-muted" />
                <h3 class="mb-2 text-center text-xl font-semibold">
                  {{ $t('labels.assets.noAssetsFound') }}
                </h3>
                <p class="mb-4 text-center text-muted">
                  {{
                    tagId
                      ? $t('labels.assets.noAssetsWithTag')
                      : folderId
                        ? $t('labels.assets.folderEmpty')
                        : $t('labels.assets.noAssetsFoundDescription')
                  }}
                </p>
                <Button
                  v-if="allowUpload && canManageAssets"
                  variant="primary"
                  @click="showUploadDialog = true"
                >
                  <Icon name="lucide:upload" />
                  {{ $t('labels.assets.uploadAssets') }}
                </Button>
              </div>

              <div
                v-else
                :class="['grid gap-3 rounded-lg bg-surface p-3', selectedGridSize.cls]"
                role="listbox"
                aria-label="Assets"
                aria-multiselectable="true"
              >
                <AssetItem
                  v-for="asset in assets"
                  :key="asset.id"
                  :asset="asset"
                  :selected="mode === 'manage' ? selectedAssets.has(asset.id) : undefined"
                  :cut="cutKeys.has(`asset:${asset.id}`)"
                  :size="imageSize"
                  :drag-items="getDragItemsFor('asset', asset.id)"
                  :compliance-issues="getMissingRequiredFields(asset)"
                  :resolved-tags="resolvedTagsFor(asset.tags)"
                  :data-id="asset.id"
                  :data-key="`asset:${asset.id}`"
                  v-bind="assetItemProps"
                  @select="handleAssetSelect"
                  @click="handleAssetClick"
                  @view="handleAssetView"
                  @delete="handleAssetDelete"
                  @move="(value) => openMoveDialog(itemsForMoveAction({ type: 'asset', data: value }))"
                  @tag="(value) => openBulkTagDialog(assetsForAction(value))"
                  @download="(value) => handleDownload(assetsForAction(value))"
                  @copy-url="handleCopyUrl"
                  @context-menu="(value) => handleEntryContextMenu({ type: 'asset', data: value })"
                />
              </div>

              <TablePaginationFooter
                v-if="assetResponse.meta"
                :meta="assetResponse.meta"
                :current-page="currentPage"
                :per-page="settings.assets.pageSize"
                :page-size-options="[12, 24, 48, 96, 120]"
                data-no-marquee
                @update:current-page="(value) => (currentPage = value)"
                @update:per-page="(value) => (settings.assets.pageSize = value)"
              />
            </div>
          </section>

          <div
            v-if="marqueeRect"
            class="pointer-events-none absolute z-30 rounded-sm border border-accent bg-accent/10"
            :style="{
              left: `${marqueeRect.left}px`,
              top: `${marqueeRect.top}px`,
              width: `${marqueeRect.width}px`,
              height: `${marqueeRect.height}px`,
            }"
          />
        </div>
      </ContextMenuTrigger>

      <ContextMenuContent v-if="mode === 'manage'">
        <ContextMenuItem
          v-if="allowUpload && canManageAssets"
          @select="showUploadDialog = true"
        >
          <Icon name="lucide:upload" />
          <span>{{ $t('actions.assets.upload') }}</span>
        </ContextMenuItem>
        <ContextMenuItem
          v-if="allowFolderCreation && canManageFolders"
          @select="openCreateFolderDialog()"
        >
          <Icon name="lucide:folder-plus" />
          <span>{{ $t('actions.createFolder') }}</span>
        </ContextMenuItem>
        <ContextMenuSeparator v-if="canManageAssets" />
        <ContextMenuItem
          v-if="canManageAssets"
          :disabled="!clipboard?.length"
          @select="pasteClipboard"
        >
          <Icon name="lucide:clipboard-paste" />
          <span>{{ $t('actions.paste') }}</span>
        </ContextMenuItem>
        <ContextMenuItem
          v-if="multiSelect"
          @select="selection.selectAll()"
        >
          <Icon name="lucide:square-dashed-mouse-pointer" />
          <span>{{ $t('actions.selectAll') }}</span>
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>

    <AssetSelectionBar
      v-if="mode === 'manage' && multiSelect"
      :asset-count="selectedAssets.size"
      :folder-count="selectedFolders.size"
      :off-page-count="offPageSelectedCount"
      :total-matching="totalMatchingAssets"
      :can-select-all-matching="canSelectAllMatching"
      :is-selecting-all-matching="isSelectingAllMatching"
      :can-manage="canManageAssets"
      @move="openMoveDialog(getSelectedDragItems())"
      @tag="openBulkTagDialog(Array.from(selectedAssets.values()))"
      @download="handleDownload(Array.from(selectedAssets.values()))"
      @delete="deleteSelection"
      @clear="clearSelection"
      @select-all-matching="selectAllMatching"
    />

    <UploadDialog
      v-if="allowUpload && canManageAssets"
      v-model:open="showUploadDialog"
      :folder-id="activeFolderId || undefined"
      :space-id="spaceId"
      :initial-files="droppedFiles"
      @update:open="
        (open) => {
          if (!open) {
            droppedFiles = []
          }
        }
      "
    />

    <CreateFolderDialog
      v-if="allowFolderCreation && canManageFolders"
      v-model:open="folderDialogOpen"
      :folder="editingFolder"
      :parent-folder-id="dialogParentFolderId"
      :space-id="spaceId"
    />

    <MoveToFolderDialog
      v-if="mode === 'manage' && canManageAssets"
      v-model:open="moveDialogOpen"
      :space-id="spaceId"
      :items="moveDialogItems"
      @moved="handleMoved"
    />

    <BulkTagDialog
      v-if="mode === 'manage' && canManageAssets"
      v-model:open="bulkTagOpen"
      :space-id="spaceId"
      :assets="bulkTagAssets"
    />

    <AssetShortcutsDialog v-model:open="shortcutsOpen" />

    <AssetDetailsDialog
      v-if="mode === 'manage'"
      v-model:asset="detailAsset"
      :folder-id="activeFolderId"
      :space-id="spaceId"
      :read-only="!canManageAssets"
      @update:asset="saveAsset"
      @close="detailAsset = null"
    />
  </main>
</template>
