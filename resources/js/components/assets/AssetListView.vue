<script setup lang="ts">
import { deepClone } from '@vue/devtools-shared'
import { debounce } from 'perfect-debounce'
import { toast } from 'vue-sonner'

import type { AssetsQueryParams } from '~/api/resources/assets'
import AssetComplianceIndicator from '~/components/assets/AssetComplianceIndicator.vue'
import AddToCollectionDialog from '~/components/assets/AddToCollectionDialog.vue'
import AssetSelectionBar from '~/components/assets/AssetSelectionBar.vue'
import BulkTagDialog from '~/components/assets/BulkTagDialog.vue'
import CreateAssetShareDialog from '~/components/assets/CreateAssetShareDialog.vue'
import CreateFolderDialog from '~/components/assets/CreateFolderDialog.vue'
import MoveToFolderDialog from '~/components/assets/MoveToFolderDialog.vue'
import UploadDialog from '~/components/assets/UploadDialog.vue'
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Alert } from '~/components/ui/alert'
import { Breadcrumb, BreadcrumbItem } from '~/components/ui/breadcrumb'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { InputField } from '~/components/ui/form'
import SortSelect from '~/components/ui/SortSelect.vue'
import { Switch } from '~/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import type { AssetSelectionEntry } from '~/composables/useAssetSelection'
import type { AssetManagerDragItem } from '~/lib/assets/assetDragAndDrop'
import { downloadAssetFiles } from '~/lib/assets/downloadAssets'
import type { AssetShareSource } from '~/types/asset-distribution'

export interface AssetListViewProps {
  spaceId: string
  mode?: 'manage' | 'select'
  multiSelect?: boolean
  allowUpload?: boolean
  allowFolderCreation?: boolean
  initialFolderId?: string | null
  initialTagId?: string | null
}

const props = withDefaults(defineProps<AssetListViewProps>(), {
  mode: 'manage',
  multiSelect: true,
  allowUpload: true,
  allowFolderCreation: true,
  initialFolderId: null,
  initialTagId: null,
})

const emit = defineEmits<{
  selectionChange: [{ assets: AssetResource[] }]
  'asset-select': [asset: AssetResource]
  'folder-change': [folderId: string | null]
  'tag-change': [tagId: string | null]
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const { settings } = useSpaceSettings(props.spaceId)
const { useAssetsQuery, useDeleteAssetMutation, useUpdateAssetMutation } = useAssets(props.spaceId)
const { useFolderStructure } = useAssetFolders(props.spaceId)
const {
  ensureAssetFieldData,
  getFieldValue,
  getEffectiveFields,
  getMissingRequiredFields,
  getVisibleFields,
  getVisibleLanguages,
  isFieldRequiredForLanguage,
  isCompliant,
  languageTabs,
  setFieldValue,
} = useAssetRequirements(props.spaceId)
const { getBreadcrumbs } = useFolderStructure()
const { bulkDeleteAssets, fetchAllMatchingAssets } = useAssetBulkOperations(props.spaceId)
const { mutateAsync: updateAsset } = useUpdateAssetMutation()
const { mutateAsync: deleteAsset } = useDeleteAssetMutation()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageAssets = computed(() => access.hasAbility('assets.manage'))
const canManageFolders = computed(() => access.hasAbility('asset_folders.manage'))

const folderId = defineModel<string | null>('folderId')
const tagId = defineModel<string | null>('tagId')
const collectionId = defineModel<string | null>('collectionId', { default: null })

const canManageCollections = computed(() => access.hasAbility('asset_collections.manage'))
const canShareAssets = computed(() => access.hasAbility('asset_shares.manage'))
const { downloadSelectionAsPackage } = useAssetPackages(props.spaceId)
const { useAssetCollectionQuery, useRemoveAssetsFromCollectionMutation } = useAssetCollections(
  props.spaceId
)
const { data: activeCollection } = useAssetCollectionQuery(collectionId)
const { mutateAsync: removeAssetsFromCollection } = useRemoveAssetsFromCollectionMutation()

const isManualCollectionView = computed(
  () =>
    Boolean(collectionId.value) &&
    activeCollection.value?.id === collectionId.value &&
    activeCollection.value?.type === 'manual'
)

const showUploadDialog = ref(false)
const folderDialogOpen = ref(false)
const currentPage = ref(1)
const perPage = ref(25)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})
const editingAssetId = ref<string | null>(null)
const editingAssetData = ref<AssetResource | null>(null)
const pendingChanges = ref<Set<string>>(new Set())
const filters = ref<Record<string, unknown>>({})
const q = ref('')

const moveDialogOpen = ref(false)
const moveDialogItems = ref<AssetManagerDragItem[]>([])
const bulkTagOpen = ref(false)
const bulkTagAssets = ref<AssetResource[]>([])
const addToCollectionOpen = ref(false)
const addToCollectionAssetIds = ref<string[]>([])
const shareDialogOpen = ref(false)
const shareSource = ref<AssetShareSource | null>(null)
const isSelectingAllMatching = ref(false)

const sortOptions = [
  { value: 'created_at', label: String($t('labels.assets.createdAt')) },
  { value: 'updated_at', label: String($t('labels.assets.updatedAt')) },
  { value: 'filename', label: String($t('labels.assets.fields.filename')) },
  { value: 'size', label: String($t('labels.assets.size')) },
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

const assetQueryParams = computed<AssetsQueryParams & { collection?: string }>(() => {
  return {
    ...filters.value,
    collection: collectionId.value ?? undefined,
    folder: collectionId.value ? undefined : (folderId.value ?? undefined),
    tags: collectionId.value ? undefined : (tagId.value ?? undefined),
    q: q.value || undefined,
    sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
    page: currentPage.value,
    per_page: perPage.value,
  }
})

const { data: assetResponse, refetch: refetchAssets } = useAssetsQuery(assetQueryParams)

const breadcrumbs = computed(() => {
  if (!folderId.value) {
    return []
  }

  return getBreadcrumbs(folderId.value)
})

const assets = computed(() => assetResponse.value?.data || [])
const meta = computed(() => assetResponse.value?.meta)

const assetEntries = computed<AssetSelectionEntry[]>(() => {
  return assets.value.map((asset) => ({ type: 'asset' as const, data: asset }))
})

const selection = useAssetSelection(assetEntries)
const { selectedAssets, hasSelection, selectionSignature } = selection

const isManageMode = computed(() => props.mode === 'manage')

const allPageAssetsSelected = computed(() => {
  return assets.value.length > 0 && assets.value.every((asset) => selectedAssets.value.has(asset.id))
})

const somePageAssetsSelected = computed(() => {
  return assets.value.some((asset) => selectedAssets.value.has(asset.id))
})

const offPageSelectedCount = computed(() => {
  const pageAssetIds = new Set(assets.value.map((asset) => asset.id))
  let count = 0

  for (const id of selectedAssets.value.keys()) {
    if (!pageAssetIds.has(id)) count += 1
  }

  return count
})

const totalMatchingAssets = computed(() => meta.value?.total ?? 0)

const canSelectAllMatching = computed(() => {
  return allPageAssetsSelected.value && totalMatchingAssets.value > assets.value.length
})

const emitSelectionChange = () => {
  if (!isManageMode.value) {
    return
  }

  emit('selectionChange', { assets: Array.from(selectedAssets.value.values()) })
}

watch(selectionSignature, emitSelectionChange)

const toggleSelectPage = () => {
  if (allPageAssetsSelected.value) {
    for (const asset of assets.value) {
      selectedAssets.value.delete(asset.id)
    }
  } else {
    for (const asset of assets.value) {
      selectedAssets.value.set(asset.id, asset)
    }
  }
}

const handleRowPointer = (asset: AssetResource, event: MouseEvent) => {
  if (!isManageMode.value) {
    emit('asset-select', asset)
    return
  }

  if (!props.multiSelect) {
    selection.selectOnly({ type: 'asset', data: asset })
    return
  }

  selection.handleItemPointer(
    { type: 'asset', data: asset },
    { meta: event.metaKey || event.ctrlKey, shift: event.shiftKey }
  )
}

const openMoveDialog = () => {
  const items = selection.selectedDragItems.value

  if (items.length) {
    moveDialogItems.value = items
    moveDialogOpen.value = true
  }
}

const openBulkTagDialog = () => {
  const selected = Array.from(selectedAssets.value.values())

  if (selected.length) {
    bulkTagAssets.value = selected
    bulkTagOpen.value = true
  }
}

const openAddToCollectionDialog = () => {
  const selected = Array.from(selectedAssets.value.values())

  if (selected.length) {
    addToCollectionAssetIds.value = selected.map((asset) => asset.id)
    addToCollectionOpen.value = true
  }
}

const handleRemoveFromCollection = async () => {
  const selected = Array.from(selectedAssets.value.values())

  if (!collectionId.value || !selected.length) {
    return
  }

  await removeAssetsFromCollection({
    collectionId: collectionId.value,
    assetIds: selected.map((asset) => asset.id),
  })

  for (const asset of selected) {
    selectedAssets.value.delete(asset.id)
  }
}

const openShareDialog = () => {
  const selected = Array.from(selectedAssets.value.values())

  if (selected.length) {
    shareSource.value = {
      source_type: 'selection',
      asset_ids: selected.map((asset) => asset.id),
    }
    shareDialogOpen.value = true
  }
}

const handleBulkDownload = async () => {
  const selected = Array.from(selectedAssets.value.values())

  if (!selected.length) {
    return
  }

  // Larger selections are bundled into a server-built zip instead of a
  // sequential per-file download loop. Package creation needs assets.manage.
  if (selected.length > 3 && canManageAssets.value) {
    await downloadSelectionAsPackage(selected.map((asset) => asset.id))
    return
  }

  toast.info(String($t('messages.assets.downloadStarted', { count: selected.length })))

  const { failed } = await downloadAssetFiles(selected)

  if (failed.length) {
    toast.error(String($t('messages.assets.downloadFailed', { count: failed.length })))
  }
}

const deleteSelectedAssets = async () => {
  const selected = Array.from(selectedAssets.value.values())

  if (!selected.length || !canManageAssets.value) {
    return
  }

  const confirmed = await alert.confirm(
    String($t('messages.assets.bulkDeleteConfirmation', { count: selected.length })),
    {
      title: $t('labels.assets.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  const { deletedIds, conflicts, failed } = await bulkDeleteAssets(selected)

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
  }

  await refetchAssets()
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

const isEditableTarget = (target: EventTarget | null): boolean => {
  const element = target as HTMLElement | null
  return Boolean(
    element?.closest?.('input, textarea, select, [contenteditable="true"], [role="combobox"]')
  )
}

const handleWindowKeydown = (event: KeyboardEvent) => {
  if (
    !isManageMode.value ||
    isEditableTarget(event.target) ||
    document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"]')
  ) {
    return
  }

  const isMeta = event.metaKey || event.ctrlKey

  if (isMeta && event.key.toLowerCase() === 'a' && props.multiSelect) {
    event.preventDefault()
    selection.selectAll()
    return
  }

  if (event.key === 'Escape' && hasSelection.value) {
    selection.clear()
    return
  }

  if (
    (event.key === 'Delete' || (isMeta && event.key === 'Backspace')) &&
    hasSelection.value &&
    canManageAssets.value
  ) {
    event.preventDefault()
    void deleteSelectedAssets()
  }
}

const visibleFieldsList = computed(() => {
  return getVisibleFields(settings.value.assets.visibleFields, folderId.value)
})

const visibleLanguageTabs = computed(() => {
  if (!availableFields.value.length) {
    return []
  }

  return getVisibleLanguages(settings.value.assets.visibleLanguages)
})

const nonCompliantAssets = computed(() => {
  return assets.value.filter((asset) => !isCompliant(asset, asset.folder_id ?? folderId.value))
})

const allLanguageCodes = computed(() => languageTabs.value.map((language) => language.code))
const availableFields = computed(() => getEffectiveFields({ folderId: folderId.value }))
const allFieldKeys = computed(() => availableFields.value.map((field) => field.key))

const pageSizeOptions = [25, 50, 100, 500]

const getEditableAsset = (asset: AssetResource): AssetResource => {
  if (editingAssetData.value?.id === asset.id) {
    return editingAssetData.value
  }

  return asset
}

const beginEditingAsset = (asset: AssetResource) => {
  if (editingAssetData.value?.id === asset.id) {
    return
  }

  editingAssetId.value = asset.id
  editingAssetData.value = deepClone(asset)
  ensureAssetFieldData(editingAssetData.value)
}

const isAssetEditing = (assetId: string): boolean => {
  return editingAssetId.value === assetId
}

const hasAssetPendingChanges = (assetId: string): boolean => {
  return pendingChanges.value.has(assetId)
}

const autoSaveAsset = debounce(async (assetId: string) => {
  if (!editingAssetData.value || editingAssetData.value.id !== assetId) {
    return
  }

  await updateAsset({
    id: assetId,
    payload: {
      filename: editingAssetData.value.filename,
      data: editingAssetData.value.data,
    },
  })

  pendingChanges.value.delete(assetId)
  await refetchAssets()
}, 1500)

const markPendingChange = (assetId: string) => {
  pendingChanges.value.add(assetId)

  if (settings.value.assets.autoSave) {
    void autoSaveAsset(assetId)
  }
}

const handleGridFieldChange = (
  asset: AssetResource,
  fieldKey: string,
  languageCode: string,
  value: string
) => {
  beginEditingAsset(asset)
  if (!editingAssetData.value) {
    return
  }

  setFieldValue(editingAssetData.value, fieldKey, languageCode, value)
  markPendingChange(asset.id)
}

const handleGridFilenameChange = (asset: AssetResource, value: string) => {
  beginEditingAsset(asset)
  if (!editingAssetData.value) {
    return
  }

  editingAssetData.value.filename = value
  markPendingChange(asset.id)
}

const handleSaveAsset = async (assetId: string) => {
  if (!editingAssetData.value || editingAssetData.value.id !== assetId) {
    return
  }

  await updateAsset({
    id: assetId,
    payload: {
      filename: editingAssetData.value.filename,
      data: editingAssetData.value.data,
    },
  })

  pendingChanges.value.delete(assetId)
  editingAssetId.value = null
  editingAssetData.value = null
  await refetchAssets()
}

const handleDiscardChanges = (assetId: string) => {
  editingAssetId.value = null
  editingAssetData.value = null
  pendingChanges.value.delete(assetId)
}

const handleAssetDelete = async (asset: AssetResource) => {
  const confirmed = await alert.confirm(
    String($t('messages.assets.deleteConfirmation', { name: asset.filename })),
    {
      title: String($t('labels.assets.deleteTitle')),
      confirmLabel: String($t('actions.delete')),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  try {
    await deleteAsset({ id: asset.id })
    await refetchAssets()
  } catch (error) {
    const conflict = error as { status?: number; data?: AssetDeleteConflict }

    if (conflict.status === 409 && conflict.data?.code === 'asset_in_use') {
      const forceDeleteConfirmed = await alert.confirm(
        String(
          $t('messages.assets.forceDeleteConfirmation', {
            name: asset.filename,
            count: conflict.data.linked_contents_count,
          })
        ),
        {
          title: String($t('labels.assets.forceDeleteTitle')),
          confirmLabel: String($t('actions.forceDelete')),
          cancelLabel: String($t('alertDialog.cancel')),
          variant: 'destructive',
        }
      )

      if (!forceDeleteConfirmed) {
        return
      }

      await deleteAsset({ id: asset.id, force: true })
      await refetchAssets()
      return
    }

    toast.error(String($t('composables.assets.deleteError', { error: String(error) })))
    console.error('Error deleting asset:', error)
  }
}

const toggleLanguageVisibility = (languageCode: string) => {
  const current = settings.value.assets.visibleLanguages?.length
    ? settings.value.assets.visibleLanguages
    : allLanguageCodes.value

  if (current.includes(languageCode)) {
    settings.value.assets.visibleLanguages = current.filter((code) => code !== languageCode)
    return
  }

  const next = [...current, languageCode]
  settings.value.assets.visibleLanguages = next.length === allLanguageCodes.value.length ? [] : next
}

const toggleFieldVisibility = (fieldKey: string) => {
  const current = settings.value.assets.visibleFields?.length
    ? settings.value.assets.visibleFields
    : allFieldKeys.value

  if (current.includes(fieldKey)) {
    settings.value.assets.visibleFields = current.filter((key) => key !== fieldKey)
    return
  }

  const next = [...current, fieldKey]
  settings.value.assets.visibleFields = next.length === allFieldKeys.value.length ? [] : next
}

watch([folderId, tagId, collectionId], async () => {
  currentPage.value = 1
  selection.clear()
  await refetchAssets()
})

watch(
  [currentPage, perPage, sortBy],
  async () => {
    await refetchAssets()
  },
  { deep: true }
)

onMounted(async () => {
  if (props.initialFolderId) {
    folderId.value = props.initialFolderId
  }

  if (props.initialTagId) {
    tagId.value = props.initialTagId
  }

  window.addEventListener('keydown', handleWindowKeydown)

  await refetchAssets()
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleWindowKeydown)
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
          v-if="allowUpload && canManageAssets"
          variant="primary"
          @click="showUploadDialog = true"
        >
          <Icon name="lucide:upload" />
          {{ $t('actions.assets.upload') }}
        </Button>
        <Button
          v-if="allowFolderCreation && canManageFolders"
          @click="folderDialogOpen = true"
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

    <div class="flex items-center justify-between gap-4">
      <div class="flex items-center gap-2">
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button
              variant="outline"
              size="sm"
              class="gap-2"
            >
              <Icon name="lucide:globe" />
              <span>{{ $t('labels.settings.i18n.languages') }}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuCheckboxItem
              v-for="language in languageTabs"
              :key="language.code"
              :model-value="visibleLanguageTabs.some((item) => item.code === language.code)"
              @select="toggleLanguageVisibility(language.code)"
            >
              {{ language.name }}
            </DropdownMenuCheckboxItem>
          </DropdownMenuContent>
        </DropdownMenu>

        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button
              variant="outline"
              size="sm"
              class="gap-2"
            >
              <Icon name="lucide:columns-3" />
              <span>{{ $t('labels.settings.assetLibrary.metadataFields') }}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuCheckboxItem
              v-for="field in availableFields"
              :key="field.key"
              :model-value="visibleFieldsList.some((item) => item.key === field.key)"
              @select="toggleFieldVisibility(field.key)"
            >
              {{ field.label }}
            </DropdownMenuCheckboxItem>
          </DropdownMenuContent>
        </DropdownMenu>

        <div class="flex items-center gap-2">
          <template v-if="canManageAssets">
            <Switch
              id="autosave"
              v-model="settings.assets.autoSave"
            />
            <label
              for="autosave"
              class="text-sm text-muted-foreground"
            >
              {{ $t('labels.datasets.autoSave') }}
            </label>
          </template>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <SearchFilter
          v-model="filters"
          :filterable-fields="assetFilters"
          @search="q = $event"
          @reset="q = ''"
        />
        <SortSelect
          v-model="sortBy"
          :options="sortOptions"
        />
      </div>
    </div>

    <div class="overflow-hidden rounded-md border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead
              v-if="isManageMode && multiSelect"
              class="w-10"
            >
              <Checkbox
                :model-value="allPageAssetsSelected ? true : somePageAssetsSelected ? 'indeterminate' : false"
                :aria-label="String($t('actions.selectAll'))"
                @update:model-value="toggleSelectPage"
              />
            </TableHead>
            <TableHead class="min-w-24">{{ $t('labels.assets.asset') }}</TableHead>
            <TableHead class="min-w-40">{{ $t('labels.assets.fields.filename') }}</TableHead>
            <TableHead
              v-for="language in visibleLanguageTabs"
              :key="language.code"
              class="min-w-64"
            >
              {{ language.name }}
            </TableHead>
            <TableHead class="w-24" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableEmpty
            v-if="!assets.length"
            :colspan="visibleLanguageTabs.length + (isManageMode && multiSelect ? 4 : 3)"
          >
            {{ $t('labels.assets.noAssetsFound') }}
          </TableEmpty>

          <TableRow
            v-for="asset in assets"
            :key="asset.id"
            :class="{ 'bg-accent/5': selectedAssets.has(asset.id) }"
            :aria-selected="selectedAssets.has(asset.id)"
          >
            <TableCell v-if="isManageMode && multiSelect">
              <Checkbox
                :model-value="selectedAssets.has(asset.id)"
                :aria-label="`Select ${asset.filename}`"
                @click.stop="
                  selection.setSelected(
                    { type: 'asset', data: asset },
                    !selectedAssets.has(asset.id)
                  )
                "
              />
            </TableCell>
            <TableCell
              class="w-24 cursor-pointer select-none"
              @click="handleRowPointer(asset, $event)"
            >
              <div class="checkerboard relative size-20 shrink-0 overflow-hidden rounded-md">
                <NuxtImg
                  v-if="asset.mime_type?.startsWith('image/')"
                  :src="asset.full_path"
                  :alt="asset.filename"
                  :width="160"
                  :height="160"
                  class="h-full w-full object-cover"
                />
                <div
                  v-else
                  class="flex h-full w-full items-center justify-center"
                >
                  <Icon
                    name="lucide:file"
                    size="1.5rem"
                    class="text-muted-foreground"
                  />
                </div>
              </div>
            </TableCell>

            <TableCell class="text-sm font-medium">
              <div class="space-y-2">
                <div class="flex items-center gap-2">
                  <AssetComplianceIndicator
                    :issues="getMissingRequiredFields(asset, asset.folder_id ?? folderId)"
                    severity="error"
                  />
                  <span class="text-xs text-muted-foreground">
                    {{ asset.extension }} • {{ asset.mime_type }}
                  </span>
                </div>
                <p class="text-xs text-muted-foreground">
                  {{
                    asset.linked_contents_count === 1
                      ? $t('labels.assets.usedInSingleContent')
                      : $t('labels.assets.usedInMultipleContents', {
                          count: asset.linked_contents_count,
                        })
                  }}
                </p>
                <InputField
                  :label="$t('labels.assets.fields.filename')"
                  :name="`filename-${asset.id}`"
                  :model-value="getEditableAsset(asset).filename"
                  :disabled="!canManageAssets"
                  @update:model-value="
                    (value: string | number) => handleGridFilenameChange(asset, String(value))
                  "
                />
              </div>
            </TableCell>

            <TableCell
              v-for="language in visibleLanguageTabs"
              :key="`${asset.id}-${language.code}`"
              class="space-y-3"
            >
              <InputField
                v-for="field in visibleFieldsList"
                :key="`${asset.id}-${language.code}-${field.key}`"
                :label="field.label"
                :name="`${asset.id}-${language.code}-${field.key}`"
                :model-value="getFieldValue(getEditableAsset(asset), field.key, language.code)"
                :placeholder="field.label"
                :required="isFieldRequiredForLanguage(field, language.code)"
                :disabled="!canManageAssets"
                @update:model-value="
                  (value: string | number) =>
                    handleGridFieldChange(asset, field.key, language.code, String(value))
                "
              />
            </TableCell>

            <TableCell>
              <div class="flex w-full justify-end gap-1">
                <template v-if="canManageAssets && !settings.assets.autoSave">
                  <Button
                    size="icon"
                    variant="outline"
                    :disabled="!hasAssetPendingChanges(asset.id)"
                    @click="handleSaveAsset(asset.id)"
                  >
                    <Icon
                      name="lucide:check"
                      class="text-green-500"
                    />
                    <span class="sr-only">{{ $t('actions.saveChanges') }}</span>
                  </Button>
                  <Button
                    size="icon"
                    variant="outline"
                    :disabled="!hasAssetPendingChanges(asset.id)"
                    @click="handleDiscardChanges(asset.id)"
                  >
                    <Icon
                      name="lucide:x"
                      class="text-red-500"
                    />
                    <span class="sr-only">{{ $t('alertDialog.cancel') }}</span>
                  </Button>
                </template>

                <DropdownMenu v-if="canManageAssets">
                  <DropdownMenuTrigger as-child>
                    <Button
                      size="icon"
                      variant="ghost"
                      :aria-label="$t('actions.moreActions')"
                    >
                      <Icon
                        name="lucide:more-vertical"
                        size="1rem"
                      />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem @click="handleAssetDelete(asset)">
                      <Icon
                        name="lucide:trash-2"
                        size="1rem"
                        class="mr-2"
                      />
                      {{ $t('actions.delete') }}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="meta"
      :meta="meta"
      :current-page="currentPage"
      :per-page="perPage"
      :page-size-options="pageSizeOptions"
      @update:current-page="currentPage = $event"
      @update:per-page="perPage = $event"
    />

    <UploadDialog
      v-if="allowUpload && canManageAssets"
      v-model:open="showUploadDialog"
      :folder-id="folderId || undefined"
      :space-id="spaceId"
    />

    <CreateFolderDialog
      v-if="allowFolderCreation && canManageFolders"
      v-model:open="folderDialogOpen"
      :parent-folder-id="folderId || null"
      :space-id="spaceId"
    />

    <AssetSelectionBar
      v-if="isManageMode && multiSelect"
      :asset-count="selectedAssets.size"
      :off-page-count="offPageSelectedCount"
      :total-matching="totalMatchingAssets"
      :can-select-all-matching="canSelectAllMatching"
      :is-selecting-all-matching="isSelectingAllMatching"
      :can-manage="canManageAssets"
      :can-add-to-collection="canManageCollections"
      :can-remove-from-collection="canManageCollections && isManualCollectionView"
      :can-share="canShareAssets"
      @move="openMoveDialog"
      @tag="openBulkTagDialog"
      @add-to-collection="openAddToCollectionDialog"
      @remove-from-collection="handleRemoveFromCollection"
      @share="openShareDialog"
      @download="handleBulkDownload"
      @delete="deleteSelectedAssets"
      @clear="selection.clear()"
      @select-all-matching="selectAllMatching"
    />

    <MoveToFolderDialog
      v-if="isManageMode && canManageAssets"
      v-model:open="moveDialogOpen"
      :space-id="spaceId"
      :items="moveDialogItems"
      @moved="selection.clear()"
    />

    <BulkTagDialog
      v-if="isManageMode && canManageAssets"
      v-model:open="bulkTagOpen"
      :space-id="spaceId"
      :assets="bulkTagAssets"
    />

    <AddToCollectionDialog
      v-if="isManageMode && canManageCollections"
      v-model:open="addToCollectionOpen"
      :space-id="spaceId"
      :asset-ids="addToCollectionAssetIds"
    />

    <CreateAssetShareDialog
      v-if="isManageMode && canShareAssets"
      v-model:open="shareDialogOpen"
      :space-id="spaceId"
      :source="shareSource"
    />
  </main>
</template>
