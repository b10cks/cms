<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { debounce } from 'perfect-debounce'
import { toast } from 'vue-sonner'
import type { AssetsQueryParams } from '~/api/resources/assets'
import SearchFilter from '~/components/SearchFilter.vue'
import { Button } from '~/components/ui/button'
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

export interface AssetListViewProps {
  spaceId: string
  mode?: 'manage' | 'select'
  multiSelect?: boolean
  initialFolderId?: string | null
  initialTagId?: string | null
}

const props = withDefaults(defineProps<AssetListViewProps>(), {
  mode: 'manage',
  multiSelect: true,
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
const { settings } = useSpaceSettings(props.spaceId)
const { useSpaceQuery } = useSpaces()
const { useAssetsQuery, useDeleteAssetMutation, useUpdateAssetMutation } = useAssets(props.spaceId)

const { useFolderStructure } = useAssetFolders(props.spaceId)
const { getBreadcrumbs, getChildrenOfFolder } = useFolderStructure()

const breadcrumbs = computed(() => {
  if (!folderId.value) return []
  return getBreadcrumbs(folderId.value)
})

const { data: space } = useSpaceQuery(props.spaceId)
const { mutate: updateAsset } = useUpdateAssetMutation()
const { mutate: deleteAsset } = useDeleteAssetMutation()

const assetFields = computed(() => space.value?.settings?.asset_fields || [])
const spaceLanguages = computed(() => space.value?.settings?.languages || [])

const folderId = defineModel<string>('folderId')
const tagId = defineModel<string>('tagId')
const currentPage = ref(1)
const perPage = ref(25)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})
const editingAssetId = ref<string | null>(null)
const editingAssetData = ref<AssetResource | null>(null)
const pendingChanges = ref<Set<string>>(new Set())
const selectedAssets = ref<Map<string, AssetResource>>(new Map())
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
    per_page: perPage.value,
  }
})

const { data: assetResponse, refetch: refetchAssets } = useAssetsQuery(assetQueryParams)
watch([folderId, tagId], async () => {
  currentPage.value = 1
  await refetchAssets()
})

watch(
  [currentPage, perPage, sortBy],
  async () => {
    await refetchAssets()
  },
  { deep: true }
)

const pageSizeOptions = [25, 50, 100, 500]
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
])

// Computed values
const assets = computed(() => assetResponse.value?.data || [])
const meta = computed(() => assetResponse.value?.meta)

const languageTabs = computed(() => {
  return [
    { code: '_default', name: 'Default' },
    ...spaceLanguages.value.map((lang) => ({ code: lang.code, name: lang.name })),
  ]
})

const visibleLanguageTabs = computed(() => {
  return languageTabs.value.filter((lang) =>
    settings.value.assets.visibleLanguages?.includes(lang.code)
  )
})

const visibleFieldsList = computed(() => {
  return assetFields.value.filter((field) =>
    settings.value.assets.visibleFields?.includes(field.key)
  )
})

const getFieldValue = (asset: AssetResource, fieldKey: string, lang: string): string => {
  if (!asset.data?.fields) return ''
  const langFields = (asset.data.fields as Record<string, Record<string, unknown>>)[lang] || {}
  return (langFields[fieldKey] as string) || ''
}

const setFieldValue = (asset: AssetResource, fieldKey: string, lang: string, value: string) => {
  if (!asset.data) asset.data = {}
  if (!asset.data.fields) asset.data.fields = {}
  const fields = asset.data.fields as Record<string, Record<string, unknown>>
  if (!fields[lang]) fields[lang] = {}
  fields[lang][fieldKey] = value
}

const isAssetEditing = (assetId: string): boolean => {
  return editingAssetId.value === assetId
}
const hasAssetPendingChanges = (assetId: string): boolean => {
  return pendingChanges.value.has(assetId)
}

const handleFieldChange = (assetId: string) => {
  if (editingAssetId.value === assetId) {
    pendingChanges.value.add(assetId)
    if (settings.value.assets.autoSave) {
      autoSaveAsset(assetId)
    }
  }
}

const autoSaveAsset = debounce(async (assetId: string) => {
  if (!editingAssetData.value || editingAssetData.value.id !== assetId) return

  try {
    await updateAsset({
      id: assetId,
      payload: {
        data: editingAssetData.value.data,
      },
    })

    pendingChanges.value.delete(assetId)
    await refetchAssets()
  } catch (error) {
    toast.error(String($t('messages.assets.saveError')))
    console.error('Error updating asset:', error)
  }
}, 1500)

const handleSaveAsset = async (assetId: string) => {
  const editedAsset = editingAssetData.value
  if (!editedAsset || editedAsset.id !== assetId) return

  try {
    await updateAsset({
      id: assetId,
      payload: {
        data: editedAsset.data,
      },
    })

    pendingChanges.value.delete(assetId)
    await refetchAssets()
  } catch (error) {
    toast.error(String($t('messages.assets.updateError')))
    console.error('Error updating asset:', error)
  }
}

const handleDiscardChanges = (assetId: string) => {
  editingAssetId.value = null
  editingAssetData.value = null
  pendingChanges.value.delete(assetId)
}

const handleGridFieldChange = (
  asset: AssetResource,
  fieldKey: string,
  lang: string,
  value: string
) => {
  if (!isAssetEditing(asset.id)) {
    editingAssetId.value = asset.id
    editingAssetData.value = JSON.parse(JSON.stringify(asset))
  }
  if (editingAssetData.value) {
    setFieldValue(editingAssetData.value, fieldKey, lang, value)
  }
  pendingChanges.value.add(asset.id)

  if (settings.value.assets.autoSave) {
    autoSaveAsset(asset.id)
  }
}

const handleGridFilenameChange = (asset: AssetResource, value: string) => {
  if (!isAssetEditing(asset.id)) {
    editingAssetId.value = asset.id
    editingAssetData.value = JSON.parse(JSON.stringify(asset))
  }
  if (editingAssetData.value) {
    editingAssetData.value.filename = value
  }
  handleFieldChange(asset.id)
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

  if (confirmed) {
    try {
      await deleteAsset(asset.id)
      await refetchAssets()
    } catch (error) {
      toast.error(String($t('messages.assets.deleteError')))
      console.error('Error deleting asset:', error)
    }
  }
}

// Visibility toggles
const toggleLanguageVisibility = (langCode: string) => {
  if (settings.value.assets.visibleLanguages?.includes(langCode)) {
    settings.value.assets.visibleLanguages = settings.value.assets.visibleLanguages.filter(
      (code) => code !== langCode
    )
  } else {
    settings.value.assets.visibleLanguages = settings.value.assets.visibleLanguages || []
    settings.value.assets.visibleLanguages.push(langCode)
  }
}

const toggleFieldVisibility = (fieldKey: string) => {
  if (settings.value.assets.visibleFields?.includes(fieldKey)) {
    settings.value.assets.visibleFields = settings.value.assets.visibleFields.filter(
      (key) => key !== fieldKey
    )
  } else {
    settings.value.assets.visibleFields = settings.value.assets.visibleFields || []
    settings.value.assets.visibleFields.push(fieldKey)
  }
}

onMounted(async () => {
  if (props.initialFolderId) {
    folderId.value = props.initialFolderId
  }
  if (props.initialTagId) {
    tagId.value = props.initialTagId
  }
  await refetchAssets()
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
            <template
              v-for="lang in languageTabs"
              :key="lang.code"
            >
              <DropdownMenuCheckboxItem
                :model-value="settings.assets.visibleLanguages?.includes(lang.code)"
                @select="toggleLanguageVisibility(lang.code)"
              >
                {{ lang.name }}
              </DropdownMenuCheckboxItem>
            </template>
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
            <template
              v-for="field in assetFields"
              :key="field.key"
            >
              <DropdownMenuCheckboxItem
                :model-value="settings.assets.visibleFields?.includes(field.key)"
                @update:model-value="toggleFieldVisibility(field.key)"
              >
                {{ field.label }}
              </DropdownMenuCheckboxItem>
            </template>
          </DropdownMenuContent>
        </DropdownMenu>
        <div class="flex items-center gap-2">
          <Switch
            id="autosave"
            v-model="settings.assets.autoSave"
          />
          <label
            for="autosave"
            class="text-muted-foreground text-sm"
          >
            {{ $t('labels.datasets.autoSave') }}
          </label>
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
            <TableHead class="min-w-24"> Thumbnail </TableHead>
            <TableHead class="min-w-40"> {{ $t('labels.assets.fields.filename') }} </TableHead>
            <template
              v-for="lang in visibleLanguageTabs"
              :key="lang.code"
            >
              <TableHead class="min-w-64">{{ lang.name }}</TableHead>
            </template>

            <TableHead class="w-24" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableEmpty
            v-if="!assets || assets.length === 0"
            :colspan="visibleLanguageTabs.length + 3"
          >
            {{ $t('labels.assets.noAssetsFound') }}
          </TableEmpty>

          <template v-else>
            <TableRow
              v-for="asset in assets"
              :key="asset.id"
              :data-asset-id="asset.id"
            >
              <TableCell class="w-24">
                <div class="checkerboard relative size-20 shrink-0 overflow-hidden rounded-md">
                  <NuxtImg
                    v-if="asset.mime_type?.startsWith('image/')"
                    :src="asset.full_path"
                    :alt="asset.filename"
                    width="160"
                    height="160"
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
                <InputField
                  :label="$t('labels.assets.fields.filename')"
                  :name="`grid-filename-${asset.id}`"
                  :model-value="
                    isAssetEditing(asset.id) ? editingAssetData!.filename : asset.filename
                  "
                  @update:model-value="(value: string) => handleGridFilenameChange(asset, value)"
                />
              </TableCell>
              <template
                v-for="lang in visibleLanguageTabs"
                :key="`${asset.id}-${lang.code}`"
              >
                <TableCell class="space-y-3">
                  <div
                    v-for="field in visibleFieldsList"
                    :key="`grid-${field.key}`"
                    class="flex flex-col gap-1"
                  >
                    <InputField
                      :label="field.label"
                      :name="`grid-${field.key}-${asset.id}-${lang.code}`"
                      :model-value="
                        getFieldValue(
                          isAssetEditing(asset.id) ? editingAssetData! : asset,
                          field.key,
                          lang.code
                        )
                      "
                      :placeholder="field.label"
                      @update:model-value="
                        (value: string) => handleGridFieldChange(asset, field.key, lang.code, value)
                      "
                    />
                  </div>
                </TableCell>
              </template>
              <TableCell>
                <div class="flex w-full justify-end gap-1">
                  <template v-if="!settings.assets.autoSave">
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
                      <span class="sr-only">Save</span>
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
                      <span class="sr-only">Cancel</span>
                    </Button>
                  </template>
                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button
                        size="icon"
                        variant="ghost"
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
          </template>
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
  </main>
</template>
