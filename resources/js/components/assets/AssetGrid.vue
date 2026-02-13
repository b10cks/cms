<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { AssetsQueryParams } from '~/api/resources/assets'
import AssetsIcon from '~/assets/images/assets.svg?component'
import AssetDetailsDialog from '~/components/assets/AssetDetailsDialog.vue'
import AssetFolder from '~/components/assets/AssetFolder.vue'
import AssetItem from '~/components/assets/AssetItem.vue'
import CreateFolderDialog from '~/components/assets/CreateFolderDialog.vue'
import UploadDialog from '~/components/assets/UploadDialog.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Breadcrumb, BreadcrumbItem } from '~/components/ui/breadcrumb'
import { Button } from '~/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger } from '~/components/ui/select'
import SortSelect from '~/components/ui/SortSelect.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'

export interface AssetGridProps {
  spaceId: string
  mode?: 'manage' | 'select' // 'manage' for full functionality, 'select' for selection only
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
  // For manage mode
  selectionChange: [{ folders: AssetFolderResource[]; assets: AssetResource[] }]
  // For select mode
  'asset-select': [asset: AssetResource]
  // Common events
  'folder-change': [folderId: string | null]
  'tag-change': [tagId: string | null]
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { settings } = useSpaceSettings(props.spaceId)

const { useFolderStructure, useDeleteAssetFolderMutation, useUpdateAssetFolderMutation } =
  useAssetFolders(props.spaceId)
const { mutate: deleteFolder } = useDeleteAssetFolderMutation()
const { mutate: updateFolder } = useUpdateAssetFolderMutation()
const { getBreadcrumbs, getChildrenOfFolder } = useFolderStructure()

const { useAssetsQuery, useDeleteAssetMutation, useUpdateAssetMutation } = useAssets(props.spaceId)
const { mutate: updateAsset } = useUpdateAssetMutation()
const { mutate: deleteAsset } = useDeleteAssetMutation()

onMounted(() => {
  document.addEventListener('dragstart', (event: DragEvent) => {
    if (!event.dataTransfer) return

    try {
      const dragData = JSON.parse(event.dataTransfer.getData('application/json') || '{}')

      if (dragData.selected) {
        if (dragData.type === 'asset') {
          const selectedAssetIds = Array.from(selectedAssets.value.keys()).filter(
            (id) => id !== dragData.id
          )

          event.dataTransfer.setData(
            'application/json+selected-assets',
            JSON.stringify(selectedAssetIds)
          )
        } else if (dragData.type === 'folder') {
          const selectedFolderIds = Array.from(selectedFolders.value.keys()).filter(
            (id) => id !== dragData.id
          )

          event.dataTransfer.setData(
            'application/json+selected-folders',
            JSON.stringify(selectedFolderIds)
          )
        }
      }
    } catch (_) {
      /* empty */
    }
  })

  document.addEventListener('dragover', (e: DragEvent) => {
    e.preventDefault()
    e.stopPropagation()

    document.body.classList.add('drag-over')
  })

  document.addEventListener('dragleave', (e: DragEvent) => {
    if (!e.relatedTarget || e.relatedTarget === document.body) {
      document.body.classList.remove('drag-over')
    }
  })

  document.addEventListener('drop', (e: DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    document.body.classList.remove('drag-over')

    if (e.dataTransfer?.files && e.dataTransfer.files.length > 0) {
      droppedFiles.value = Array.from(e.dataTransfer.files)
      showUploadDialog.value = true
    }
  })
})

const gridSizes = {
  sm: { cls: 'grid-cols-4 xl:grid-cols-6 2xl:grid-cols-12', icon: 'lucide:grid-3x3' },
  md: { cls: 'grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6', icon: 'lucide:grid-2x2' },
  lg: { cls: 'grid-cols-2 xl:grid-cols-4', icon: 'lucide:square-square' },
}

const selectedGridSize = computed(() => {
  return gridSizes[settings.value.assets.gridSize] || gridSizes.md
})

const folderId = defineModel<string>('folderId')
const tagId = defineModel<string>('tagId')
const showUploadDialog = ref(false)
const showCreateFolderDialog = ref(false)
const currentPage = ref(1)
const perPage = ref(12)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})
const droppedFiles = ref<File[]>([])

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

// Define sort options
const sortOptions = [
  { value: 'created_at', label: $t('labels.assets.createdAt') },
  { value: 'updated_at', label: $t('labels.assets.updatedAt') },
  { value: 'filename', label: $t('labels.assets.fields.filename') },
  { value: 'size', label: $t('labels.assets.size') },
]

const detailAsset = ref<AssetResource | null>(null)
const selectedAssets = ref<Map<string, AssetResource>>(new Map())
const selectedFolders = ref<Map<string, AssetFolderResource>>(new Map())

const breadcrumbs = computed(() => {
  if (!folderId.value) return []
  return getBreadcrumbs(folderId.value)
})

const folders = computed(() => {
  if (tagId.value) return []
  return getChildrenOfFolder(folderId.value)
})

const hasSelection = computed(() => {
  return selectedAssets.value.size > 0 || selectedFolders.value.size > 0
})

const selectionCount = computed(() => {
  return selectedAssets.value.size + selectedFolders.value.size
})

const handleAssetView = (asset: AssetResource) => {
  if (props.mode === 'manage') {
    detailAsset.value = asset
  } else {
    emit('asset-select', asset)
  }
}

// Event handlers
const handleAssetSelect = (asset: AssetResource, selected?: boolean) => {
  if (props.mode === 'select') {
    emit('asset-select', asset)
  } else if (props.mode === 'manage' && typeof selected === 'boolean') {
    if (selected) {
      selectedAssets.value.set(asset.id, asset)
    } else {
      selectedAssets.value.delete(asset.id)
    }
    emitSelectionChange()
  }
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

  if (confirmed) {
    try {
      await deleteAsset(asset.id)
      selectedAssets.value.delete(asset.id)
      emitSelectionChange()
    } catch (error) {
      console.error('Error deleting asset:', error)
    }
  }
}

const handleFolderSelect = (folder: AssetFolderResource, selected: boolean) => {
  if (props.mode === 'manage' && props.multiSelect) {
    if (selected) {
      selectedFolders.value.set(folder.id, folder)
    } else {
      selectedFolders.value.delete(folder.id)
    }
    emitSelectionChange()
  }
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

  if (confirmed) {
    try {
      await deleteFolder(folder.id)
      selectedFolders.value.delete(folder.id)
      emitSelectionChange()
    } catch (error) {
      console.error('Error deleting folder:', error)
    }
  }
}

const handleFolderClick = (folder: AssetFolderResource) => {
  folderId.value = folder.id
  emit('folder-change', folder.id)
}

const handleFolderCreate = (folder: AssetFolderResource | null) => {
  showCreateFolderDialog.value = true
  folderId.value = folder.id || null
}

// Selection management (manage mode only)
const deleteSelection = () => {
  if (props.mode !== 'manage') return

  selectedAssets.value.forEach((asset) => {
    deleteAsset(asset.id)
  })
  selectedAssets.value.clear()
  emitSelectionChange()
}

const clearSelection = () => {
  if (props.mode !== 'manage') return

  selectedAssets.value.clear()
  selectedFolders.value.clear()
  emitSelectionChange()
}

const emitSelectionChange = () => {
  if (props.mode === 'manage') {
    emit('selectionChange', {
      folders: Array.from(selectedFolders.value.values()),
      assets: Array.from(selectedAssets.value.values()),
    })
  }
}

// Keyboard navigation
const handleKeyNavigation = (
  event: KeyboardEvent,
  items: unknown[],
  currentIndex: number,
  selector: string
) => {
  if (props.mode !== 'manage') return

  const containerEl = event.currentTarget as HTMLElement
  const focusableItems = containerEl.querySelectorAll(selector) as NodeListOf<HTMLElement>

  let nextIndex = currentIndex

  if (event.key === 'ArrowRight') {
    nextIndex = Math.min(currentIndex + 1, items.length - 1)
  } else if (event.key === 'ArrowLeft') {
    nextIndex = Math.max(currentIndex - 1, 0)
  } else if (event.key === 'ArrowDown') {
    const itemsPerRow = getItemsPerRow(containerEl)
    nextIndex = Math.min(currentIndex + itemsPerRow, items.length - 1)
  } else if (event.key === 'ArrowUp') {
    const itemsPerRow = getItemsPerRow(containerEl)
    nextIndex = Math.max(currentIndex - itemsPerRow, 0)
  } else {
    return
  }

  if (nextIndex !== currentIndex && focusableItems[nextIndex]) {
    event.preventDefault()
    focusableItems[nextIndex].focus()
  }
}

const getItemsPerRow = (container: HTMLElement): number => {
  const containerWidth = container.clientWidth
  const sampleItem = container.querySelector('[role="option"]') as HTMLElement
  if (!sampleItem) return 3

  const itemWidth = sampleItem.offsetWidth + parseInt(getComputedStyle(sampleItem).marginLeft) * 2
  return Math.max(1, Math.floor(containerWidth / itemWidth))
}

async function saveAsset(asset: AssetResource) {
  try {
    // Assuming updateAsset is a function that updates the asset
    await updateAsset({ id: asset.id, payload: asset })
    detailAsset.value = null // Close the details dialog
  } catch (error) {
    console.error('Error saving asset:', error)
  }
}

// Drag and drop operation handler
async function handleItemsMove(
  targetFolderId: string,
  itemsToMove: { type: string; ids: string[] }[]
) {
  try {
    const movePromises = []

    for (const items of itemsToMove) {
      if (items.type === 'asset') {
        for (const assetId of items.ids) {
          movePromises.push(updateAsset({ id: assetId, payload: { folder_id: targetFolderId } }))
        }
      } else if (items.type === 'folder') {
        for (const folderId of items.ids) {
          if (folderId !== targetFolderId) {
            movePromises.push(
              updateFolder({ id: folderId, payload: { parent_id: targetFolderId } })
            )
          }
        }
      }
    }

    await Promise.all(movePromises)
    clearSelection()
  } catch (error) {
    console.error('Error moving items:', error)
  }
}

const filters = ref<Record<string, unknown>>({})
const q = ref<string>('')

const assetQueryParams = computed<AssetsQueryParams>(() => {
  return {
    ...filters.value,
    folder: folderId.value ?? undefined,
    tags: tagId.value ?? undefined,
    q: q.value ?? undefined,
    sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
    page: currentPage.value,
    per_page: perPage.value || 12,
  }
})

const { data: assetResponse, refetch: refetchAssets } = useAssetsQuery(assetQueryParams)

watch([folderId, tagId], async () => {
  clearSelection()
  currentPage.value = 1
})

watch([currentPage, sortBy], async () => {
  await refetchAssets()
})

onMounted(async () => {
  await refetchAssets()
})

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

// Asset item props based on mode
const assetItemProps = computed(() => {
  return {
    mode: props.mode,
    draggable: props.mode === 'manage',
    showCheckbox: props.mode === 'manage' && props.multiSelect,
  }
})
</script>

<template>
  <main class="flex flex-col gap-6">
    <header class="flex h-5 items-center justify-between">
      <Breadcrumb class="flex gap-2">
        <BreadcrumbItem @click="folderId = null">
          <button
            class="flex cursor-pointer items-center gap-2 hover:text-primary"
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
          v-if="allowUpload"
          variant="primary"
          @click="showUploadDialog = true"
        >
          <Icon name="lucide:upload" />
          {{ $t('actions.assets.upload') }}
        </Button>
        <Button
          v-if="allowFolderCreation"
          @click="handleFolderCreate(null)"
        >
          <Icon name="lucide:folder-plus" />
          {{ $t('actions.assetFolders.create') }}
        </Button>
      </div>
    </header>

    <div
      v-if="hasSelection"
      class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4"
    >
      <div class="flex items-center gap-2">
        <Badge variant="secondary">{{
          $t('labels.selectionCount', { count: selectionCount })
        }}</Badge>
      </div>
      <div class="flex items-center gap-2">
        <Button size="sm">
          <Icon name="lucide:folder-input" />
          {{ $t('actions.move') }}
        </Button>
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
      v-if="showFolders && folders.length > 0"
      class="grid grow gap-6"
    >
      <button @click="settings.assets.gridFolders = !settings.assets.gridFolders">
        <h2 class="flex items-center gap-2 text-2xl">
          <Icon
            name="lucide:folder"
            size="1.25rem"
          />
          <span class="font-semibold text-primary">Folders</span>
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
          (e) =>
            handleKeyNavigation(
              e,
              folders,
              Array.from(folders).findIndex(
                (f) =>
                  (e.target as HTMLElement).closest('[role=option]')?.getAttribute('data-id') ===
                  f.id
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
          :draggable="true"
          data-id="folder.id"
          @select="handleFolderSelect"
          @click="handleFolderClick"
          @delete="handleFolderDelete"
          @create="handleFolderCreate"
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
          v-if="assetResponse.data.length === 0"
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
            v-if="allowUpload"
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
            (e) =>
              handleKeyNavigation(
                e,
                assetResponse.data,
                Array.from(assetResponse.data).findIndex(
                  (a) =>
                    (e.target as HTMLElement).closest('[role=option]')?.getAttribute('data-id') ===
                    a.id
                ),
                '[role=option]'
              )
          "
        >
          <AssetItem
            v-for="asset in assetResponse?.data"
            :key="asset.id"
            :asset="asset"
            :selected="mode === 'manage' ? selectedAssets.has(asset.id) : undefined"
            :size="imageSize"
            v-bind="assetItemProps"
            data-id="asset.id"
            @select="handleAssetSelect"
            @view="handleAssetView"
            @delete="handleAssetDelete"
          />
        </div>

        <TablePaginationFooter
          v-if="assetResponse?.meta"
          :meta="assetResponse.meta"
          :current-page="currentPage"
          :per-page="perPage"
          :page-size-options="[12, 24, 48, 96, 120]"
          @update:current-page="(val) => (currentPage = val)"
          @update:per-page="(val) => (perPage = val)"
        />
      </div>
    </section>

    <UploadDialog
      v-if="allowUpload"
      v-model:open="showUploadDialog"
      :folder-id="folderId"
      :space-id="spaceId"
      :initial-files="droppedFiles"
      @update:open="
        (open) => {
          if (!open) droppedFiles = []
        }
      "
    />

    <CreateFolderDialog
      v-if="allowFolderCreation"
      v-model:open="showCreateFolderDialog"
      :parent-folder-id="folderId"
      :space-id="spaceId"
    />

    <AssetDetailsDialog
      v-if="mode === 'manage'"
      v-model:asset="detailAsset"
      :folder-id="folderId"
      :space-id="spaceId"
      @update:asset="saveAsset"
      @close="detailAsset = null"
    />
  </main>
</template>
