<script setup lang="ts">
import { dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { toast } from 'vue-sonner'

import type { AssetsQueryParams } from '~/api/resources/assets'
import AssetsIcon from '~/assets/images/assets.svg?component'
import AssetDetailsDialog from '~/components/assets/AssetDetailsDialog.vue'
import AssetFolder from '~/components/assets/AssetFolder.vue'
import AssetItem from '~/components/assets/AssetItem.vue'
import CreateFolderDialog from '~/components/assets/CreateFolderDialog.vue'
import UploadDialog from '~/components/assets/UploadDialog.vue'
import Icon from '~/components/Icon.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Alert } from '~/components/ui/alert'
import { Badge } from '~/components/ui/badge'
import { Breadcrumb, BreadcrumbItem } from '~/components/ui/breadcrumb'
import { Button } from '~/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger } from '~/components/ui/select'
import SortSelect from '~/components/ui/SortSelect.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import { getAssetManagerDragItems, type AssetManagerDragItem } from '~/lib/assets/assetDragAndDrop'
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
const { useAssetsQuery, useDeleteAssetMutation, useUpdateAssetMutation } = useAssets(props.spaceId)
const { getBreadcrumbs, getChildrenOfFolder } = useFolderStructure()
const { canMoveItems, moveItemsToFolder } = useAssetLibraryMoves(props.spaceId)
const { getMissingRequiredFields, isCompliant } = useAssetRequirements(props.spaceId)
const { mutateAsync: updateAsset } = useUpdateAssetMutation()
const { mutateAsync: deleteAsset } = useDeleteAssetMutation()
const { mutateAsync: deleteFolder } = useDeleteAssetFolderMutation()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageAssets = computed(() => access.hasAbility('assets.manage'))
const canManageFolders = computed(() => access.hasAbility('asset_folders.manage'))

const folderId = defineModel<string | null>('folderId')
const tagId = defineModel<string | null>('tagId')

const showUploadDialog = ref(false)
const droppedFiles = ref<File[]>([])
const folderDialogOpen = ref(false)
const dialogParentFolderId = ref<string | null>(null)
const editingFolder = ref<AssetFolderResource | null>(null)
const detailAsset = ref<AssetResource | null>(null)
const rootBreadcrumbRef = ref<HTMLElement | null>(null)
const isRootDropActive = ref(false)
const activeFolderId = computed(() => folderId.value ?? null)
const currentPage = ref(1)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})
const selectedAssets = ref<Map<string, AssetResource>>(new Map())
const selectedFolders = ref<Map<string, AssetFolderResource>>(new Map())
const filters = ref<Record<string, unknown>>({})
const q = ref('')

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

const assets = computed(() => assetResponse.value?.data || [])

const nonCompliantAssets = computed(() => {
  return assets.value.filter((asset) => !isCompliant(asset))
})

const hasSelection = computed(() => {
  return selectedAssets.value.size > 0 || selectedFolders.value.size > 0
})

const selectionCount = computed(() => {
  return selectedAssets.value.size + selectedFolders.value.size
})

const getSelectedDragItems = (): AssetManagerDragItem[] => {
  return [
    ...Array.from(selectedFolders.value.keys()).map((id) => ({ id, type: 'folder' as const })),
    ...Array.from(selectedAssets.value.keys()).map((id) => ({ id, type: 'asset' as const })),
  ]
}

const getDragItemsFor = (type: 'asset' | 'folder', id: string): AssetManagerDragItem[] => {
  const isSelected = type === 'asset' ? selectedAssets.value.has(id) : selectedFolders.value.has(id)

  if (!isSelected || !hasSelection.value) {
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

const clearSelection = () => {
  if (props.mode !== 'manage') {
    return
  }

  selectedAssets.value.clear()
  selectedFolders.value.clear()
  emitSelectionChange()
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

  if (selected) {
    selectedAssets.value.set(asset.id, asset)
  } else {
    selectedAssets.value.delete(asset.id)
  }

  emitSelectionChange()
}

const handleFolderSelect = (folder: AssetFolderResource, selected: boolean) => {
  if (props.mode !== 'manage' || !props.multiSelect) {
    return
  }

  if (selected) {
    selectedFolders.value.set(folder.id, folder)
  } else {
    selectedFolders.value.delete(folder.id)
  }

  emitSelectionChange()
}

const handleFolderClick = (folder: AssetFolderResource) => {
  folderId.value = folder.id
  emit('folder-change', folder.id)
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
  emitSelectionChange()
}

const handleAssetDelete = async (asset: AssetResource) => {
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
  emitSelectionChange()
}

const deleteSelection = async () => {
  if (props.mode !== 'manage' || !selectedAssets.value.size) {
    return
  }

  for (const asset of Array.from(selectedAssets.value.values())) {
    const deleted = await attemptAssetDelete(asset)

    if (deleted) {
      selectedAssets.value.delete(asset.id)
      emitSelectionChange()
    }
  }
}

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
    },
  })

  detailAsset.value = null
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

const handleKeyNavigation = (
  event: KeyboardEvent,
  items: unknown[],
  currentIndex: number,
  selector: string
) => {
  if (props.mode !== 'manage') {
    return
  }

  const containerElement = event.currentTarget as HTMLElement
  const focusableItems = containerElement.querySelectorAll(selector) as NodeListOf<HTMLElement>
  let nextIndex = currentIndex

  if (event.key === 'ArrowRight') {
    nextIndex = Math.min(currentIndex + 1, items.length - 1)
  } else if (event.key === 'ArrowLeft') {
    nextIndex = Math.max(currentIndex - 1, 0)
  } else if (event.key === 'ArrowDown') {
    nextIndex = Math.min(currentIndex + getItemsPerRow(containerElement), items.length - 1)
  } else if (event.key === 'ArrowUp') {
    nextIndex = Math.max(currentIndex - getItemsPerRow(containerElement), 0)
  } else {
    return
  }

  if (nextIndex !== currentIndex && focusableItems[nextIndex]) {
    event.preventDefault()
    focusableItems[nextIndex].focus()
  }
}

const getItemsPerRow = (container: HTMLElement): number => {
  const sampleItem = container.querySelector('[role="option"]') as HTMLElement | null
  if (!sampleItem) {
    return 3
  }

  const itemWidth = sampleItem.offsetWidth + parseInt(getComputedStyle(sampleItem).marginLeft) * 2
  return Math.max(1, Math.floor(container.clientWidth / itemWidth))
}

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
})

onUnmounted(() => {
  document.removeEventListener('dragover', handleDocumentDragOver)
  document.removeEventListener('dragleave', handleDocumentDragLeave)
  document.removeEventListener('drop', handleDocumentDrop)
  document.body.classList.remove('drag-over')
})
</script>

<template>
  <main class="flex flex-col gap-6">
    <header class="flex h-5 items-center justify-between">
      <Breadcrumb class="flex gap-2">
        <BreadcrumbItem @click="folderId = null">
          <button
            ref="rootBreadcrumbRef"
            :class="[
              'flex cursor-pointer items-center gap-2 rounded-md py-1 transition-colors hover:text-primary',
              isRootDropActive ? 'bg-input/70 ring-1 ring-border' : '',
            ]"
            @click="folderId = null"
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
              class="flex cursor-pointer items-center gap-2 hover:text-primary"
              @click="folderId = id"
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

    <div
      v-if="hasSelection && (canManageAssets || canManageFolders)"
      class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4"
    >
      <div class="flex items-center gap-2">
        <Badge variant="secondary">
          {{ $t('labels.selectionCount', { count: selectionCount }) }}
        </Badge>
      </div>
      <div class="flex items-center gap-2">
        <Button
          variant="destructive"
          size="sm"
          @click="deleteSelection"
        >
          <Icon name="lucide:trash-2" />
          {{ $t('actions.deleteSelected') }}
        </Button>
        <Button
          variant="outline"
          size="sm"
          @click="clearSelection"
        >
          <Icon name="lucide:x" />
          {{ $t('actions.clear') }}
        </Button>
      </div>
    </div>

    <section
      v-if="showFolders && folders.length"
      class="grid grow gap-6"
    >
      <button @click="settings.assets.gridFolders = !settings.assets.gridFolders">
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
        @keydown="
          (event) =>
            handleKeyNavigation(
              event,
              folders,
              Array.from(folders).findIndex(
                (folder) =>
                  (event.target as HTMLElement)
                    .closest('[role=option]')
                    ?.getAttribute('data-id') === folder.id
              ),
              '[role=option]'
            )
        "
      >
        <AssetFolder
          v-for="folder in folders"
          :key="folder.id"
          :folder="folder"
          :selected="selectedFolders.has(folder.id)"
          :draggable="mode === 'manage' && canManageFolders"
          :can-edit="canManageFolders"
          :can-delete="canManageFolders"
          :can-create-children="canManageFolders"
          :drag-items="getDragItemsFor('folder', folder.id)"
          :can-receive-drop="(items) => canMoveItems(items, folder.id)"
          :on-items-drop="(items) => handleItemsMove(folder.id, items)"
          :data-id="folder.id"
          @select="handleFolderSelect"
          @click="handleFolderClick"
          @edit="openEditFolderDialog"
          @delete="handleFolderDelete"
          @create="(folder) => openCreateFolderDialog(folder.id)"
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
        <div class="ml-auto flex gap-2">
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
          @keydown="
            (event) =>
              handleKeyNavigation(
                event,
                assets,
                Array.from(assets).findIndex(
                  (asset) =>
                    (event.target as HTMLElement)
                      .closest('[role=option]')
                      ?.getAttribute('data-id') === asset.id
                ),
                '[role=option]'
              )
          "
        >
          <AssetItem
            v-for="asset in assets"
            :key="asset.id"
            :asset="asset"
            :selected="mode === 'manage' ? selectedAssets.has(asset.id) : undefined"
            :size="imageSize"
            :drag-items="getDragItemsFor('asset', asset.id)"
            :compliance-issues="getMissingRequiredFields(asset)"
            :data-id="asset.id"
            v-bind="assetItemProps"
            @select="handleAssetSelect"
            @view="handleAssetView"
            @delete="handleAssetDelete"
          />
        </div>

        <TablePaginationFooter
          v-if="assetResponse.meta"
          :meta="assetResponse.meta"
          :current-page="currentPage"
          :per-page="settings.assets.pageSize"
          :page-size-options="[12, 24, 48, 96, 120]"
          @update:current-page="(value) => (currentPage = value)"
          @update:per-page="(value) => (settings.assets.pageSize = value)"
        />
      </div>
    </section>

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
