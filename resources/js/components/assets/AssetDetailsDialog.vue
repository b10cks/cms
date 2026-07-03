<script setup lang="ts">
import { deepClone } from '@vue/devtools-shared'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { ComboboxField, DateTimeField, InputField, SelectField } from '~/components/ui/form'
import IconName from '~/components/ui/IconName.vue'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { isClient } from '~/lib/env'
import { buildIlumUrl } from '~/lib/ilum'
import { runtimeConfig } from '~/lib/runtime-config'
import type {
  AssetResource,
  AssetVersionResource,
  LinkedAssetContentResource,
} from '~/types/assets'

const RIGHTS_FIELD_KEYS = [
  'copyright_holder',
  'license_type',
  'license_notes',
  'usage_restrictions',
]

const { t } = useI18n()
const { formatFileSize, formatDateTime } = useFormat()
const { getFileIcon } = useFileUtils()
const props = withDefaults(
  defineProps<{
    asset: AssetResource | null
    mode?: 'normal' | 'reduced'
    folderId: string | null
    spaceId: string
    readOnly?: boolean
  }>(),
  {
    mode: 'normal',
    readOnly: false,
  }
)

const { useFolderStructure } = useAssetFolders(props.spaceId)
const { useAssetTagsQuery } = useAssetTags(props.spaceId)
const { data: allTagsResponse } = useAssetTagsQuery({ per_page: 500 })
const tagOptions = computed(() =>
  (allTagsResponse.value?.data ?? []).map((tag) => ({
    value: tag.id,
    label: tag.name,
    icon: tag.icon,
    color: tag.color,
  }))
)
const { useAssetLinkedContentsQuery } = useAssets(props.spaceId)
const { getBreadcrumbs } = useFolderStructure()
const { getFileType } = useFileUtils()
const {
  languageTabs,
  ensureAssetFieldData,
  getEffectiveFieldsForTarget,
  getFieldValue: getAssetFieldValue,
  isFieldRequiredForLanguage,
  setFieldValue: setAssetFieldValue,
} = useAssetRequirements(props.spaceId)

const ilumBaseUrl = (runtimeConfig.public.ilum.baseURL || '').replace(/\/$/, '')

const { alert } = useAlertDialog()
const { useReplaceAssetFileMutation } = useAssets(props.spaceId)
const { mutate: replaceFile, isPending: isReplacing } = useReplaceAssetFileMutation()
const replaceProgress = ref(0)
const { useAssetVersionsQuery, useRestoreAssetVersionMutation } = useAssetVersions(
  props.spaceId,
  computed(() => props.asset?.id ?? null)
)
const { mutate: restoreVersion, isPending: isRestoringVersion } = useRestoreAssetVersionMutation()
const restoringVersionId = ref<string | null>(null)
const replaceFileInputRef = useTemplateRef<HTMLInputElement>('replaceFileInput')

const triggerReplaceFile = () => replaceFileInputRef.value?.click()

const onReplaceFileSelected = (event: Event) => {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file || !assetCopy.value) return
  const targetId = assetCopy.value.id
  replaceProgress.value = 0
  replaceFile(
    {
      id: targetId,
      file,
      onProgress: (p) => {
        replaceProgress.value = p
      },
    },
    {
      onSuccess: (updated) => {
        if (updated) assetCopy.value = deepClone(updated)
        replaceProgress.value = 0
        if (replaceFileInputRef.value) replaceFileInputRef.value.value = ''
      },
      onError: () => {
        replaceProgress.value = 0
        if (replaceFileInputRef.value) replaceFileInputRef.value.value = ''
      },
    }
  )
}

const assetCopy = ref<AssetResource | null>(null)
const imageContainer = ref<HTMLElement | null>(null)
const imageRef = useTemplateRef('imageRef')
const videoRef = useTemplateRef<HTMLVideoElement>('videoRef')
const isDraggingFocus = ref(false)
const selectedPanel = ref<'details' | 'rights' | 'versions' | 'linked'>('details')
const selectedLanguage = ref<string>('_default')
const linkedContentsPage = ref(1)
const linkedContentsItems = ref<LinkedAssetContentResource[]>([])
const effectiveFields = computed(() => {
  return getEffectiveFieldsForTarget(assetCopy.value)
})
const generalFields = computed(() => {
  return effectiveFields.value.filter((field) => !RIGHTS_FIELD_KEYS.includes(field.key))
})
const rightsFields = computed(() => {
  return effectiveFields.value.filter((field) => RIGHTS_FIELD_KEYS.includes(field.key))
})
const licenseTypeOptions = computed(() => [
  { value: 'proprietary', label: String(t('labels.assets.rights.licenseTypes.proprietary')) },
  { value: 'cc0', label: String(t('labels.assets.rights.licenseTypes.cc0')) },
  { value: 'cc-by', label: String(t('labels.assets.rights.licenseTypes.ccBy')) },
  { value: 'cc-by-sa', label: String(t('labels.assets.rights.licenseTypes.ccBySa')) },
  { value: 'custom', label: String(t('labels.assets.rights.licenseTypes.custom')) },
])
const licenseExpiresAtDate = computed({
  get: () =>
    assetCopy.value?.license_expires_at ? assetCopy.value.license_expires_at.slice(0, 10) : '',
  set: (value: string) => {
    if (!assetCopy.value) return
    assetCopy.value.license_expires_at = value || null
  },
})
const rightsStatusBadgeVariant = computed(() => {
  switch (assetCopy.value?.rights_status) {
    case 'expired':
      return 'destructive'
    case 'restricted':
      return 'warning'
    default:
      return null
  }
})
const shouldLoadLinkedContents = computed(() => {
  return selectedPanel.value === 'linked' && props.mode === 'normal' && Boolean(props.asset?.id)
})
const { data: linkedContentsResponse, isFetching: isFetchingLinkedContents } =
  useAssetLinkedContentsQuery(
    computed(() => props.asset?.id ?? null),
    linkedContentsPage,
    shouldLoadLinkedContents
  )
const linkedContentsMeta = computed(() => linkedContentsResponse.value?.meta ?? null)
const hasMoreLinkedContents = computed(() => {
  const meta = linkedContentsMeta.value
  return meta ? meta.current_page < meta.last_page : false
})
const linkedContentsSummary = computed(() => {
  if (!props.asset) {
    return ''
  }

  return props.asset.linked_contents_count === 1
    ? String(t('labels.assets.usedInSingleContent'))
    : String(
        t('labels.assets.usedInMultipleContents', { count: props.asset.linked_contents_count })
      )
})

const shouldLoadVersions = computed(() => {
  return selectedPanel.value === 'versions' && props.mode === 'normal' && Boolean(props.asset?.id)
})
const { data: versionsResponse, isFetching: isFetchingVersions } = useAssetVersionsQuery(
  {},
  shouldLoadVersions
)
const versionItems = computed<AssetVersionResource[]>(() => versionsResponse.value?.data ?? [])

watch(
  () => props.asset,
  (newAsset) => {
    if (newAsset) {
      assetCopy.value = deepClone(newAsset)
      selectedPanel.value = 'details'
      selectedLanguage.value = '_default'
      linkedContentsPage.value = 1
      linkedContentsItems.value = []
    } else {
      assetCopy.value = null
      linkedContentsItems.value = []
    }
  },
  { immediate: true }
)

watch(
  linkedContentsResponse,
  (response) => {
    if (!response) {
      return
    }

    linkedContentsItems.value = [...linkedContentsItems.value]

    response.data.forEach((item) => {
      if (!linkedContentsItems.value.some((existing) => existing.id === item.id)) {
        linkedContentsItems.value.push(item)
      }
    })
  },
  { immediate: true }
)

const emit = defineEmits<{
  close: []
  'update:asset': [asset: AssetResource]
}>()

// Get field value for current language
const getFieldValue = (fieldKey: string): string => {
  if (!assetCopy.value) {
    return ''
  }

  return getAssetFieldValue(assetCopy.value, fieldKey, selectedLanguage.value)
}

// Set field value for current language
const setFieldValue = (fieldKey: string, value: string | number) => {
  if (!assetCopy.value) {
    return
  }

  ensureAssetFieldData(assetCopy.value)
  setAssetFieldValue(assetCopy.value, fieldKey, selectedLanguage.value, String(value))
}

// Rights & Licensing fields are not language-tabbed; they always read/write
// the default language bucket regardless of which language is selected in
// the metadata tab.
const getRightsFieldValue = (fieldKey: string): string => {
  if (!assetCopy.value) {
    return ''
  }

  return getAssetFieldValue(assetCopy.value, fieldKey, '_default')
}

const setRightsFieldValue = (fieldKey: string, value: string | number) => {
  if (!assetCopy.value) {
    return
  }

  ensureAssetFieldData(assetCopy.value)
  setAssetFieldValue(assetCopy.value, fieldKey, '_default', String(value))
}

const formatKey = (key: string): string => {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

const colorA11y = computed(() => props.asset?.metadata?.a11y)

const wcagChecks = (ratio: number) => [
  { label: 'AA Large', threshold: 3, passed: ratio >= 3 },
  { label: 'AA', threshold: 4.5, passed: ratio >= 4.5 },
  { label: 'AAA', threshold: 7, passed: ratio >= 7 },
]

const contrastRows = computed(() => {
  if (!colorA11y.value) return []
  return [
    {
      key: 'white',
      label: t('labels.assets.a11y.contrastWhite'),
      swatch: '#ffffff',
      ratio: colorA11y.value.contrast_white,
      checks: wcagChecks(colorA11y.value.contrast_white),
    },
    {
      key: 'black',
      label: t('labels.assets.a11y.contrastBlack'),
      swatch: '#000000',
      ratio: colorA11y.value.contrast_black,
      checks: wcagChecks(colorA11y.value.contrast_black),
    },
  ]
})

const copyAssetUrl = () => {
  if (!assetCopy.value) {
    return
  }

  navigator.clipboard
    .writeText(assetCopy.value.url)
    .then(() => {
      toast.success('URL copied to clipboard')
    })
    .catch(() => {
      toast.error('Failed to copy URL')
    })
}

const openAssetInNewWindow = () => {
  if (!assetCopy.value) {
    return
  }

  window.open(assetCopy.value.url, '_blank', 'noopener,noreferrer')
}

const toggleFocusPoint = () => {
  if (!assetCopy.value) return
  if (assetCopy.value.data?.focus) {
    assetCopy.value.data.focus = undefined
  } else {
    if (!assetCopy.value.data) {
      assetCopy.value.data = {}
    }
    assetCopy.value.data.focus = { x: 50, y: 50 }
  }
}

const startDragging = (event: MouseEvent) => {
  if (!imageRef.value || !assetCopy.value) return
  event.preventDefault()
  isDraggingFocus.value = true

  updateFocusPointPosition(event)
}

const stopDragging = () => {
  isDraggingFocus.value = false
}

const updateFocusPointPosition = (event: MouseEvent) => {
  if (!isDraggingFocus.value || !imageRef.value || !assetCopy.value || !assetCopy.value.data) return
  const rect = (imageRef.value?.$el as HTMLElement)?.getBoundingClientRect()

  let x = ((event.clientX - rect.left) / rect.width) * 100
  let y = ((event.clientY - rect.top) / rect.height) * 100

  x = Math.max(0, Math.min(100, x))
  y = Math.max(0, Math.min(100, y))

  assetCopy.value.data.focus = {
    x: parseFloat(x.toFixed(2)),
    y: parseFloat(y.toFixed(2)),
  }
}

onMounted(() => {
  if (isClient) {
    window.addEventListener('mousemove', handleMouseMove)
    window.addEventListener('mouseup', stopDragging)
  }
})

onUnmounted(() => {
  if (isClient) {
    window.removeEventListener('mousemove', handleMouseMove)
    window.removeEventListener('mouseup', stopDragging)
  }
})

let lastUpdateTime = 0
const throttleTime = 16
const handleMouseMove = (event: MouseEvent) => {
  const now = Date.now()
  if (now - lastUpdateTime >= throttleTime) {
    updateFocusPointPosition(event)
    lastUpdateTime = now
  }
}

const seekVideoTo = (position: number) => {
  if (videoRef.value) {
    videoRef.value.currentTime = position
  }
}

const handleFinish = async () => {
  if (props.readOnly) {
    emit('close')
    return
  }

  if (!assetCopy.value) {
    return
  }

  emit('update:asset', assetCopy.value)
}

const onOpenChange = (open: boolean) => {
  if (!open) {
    emit('close')
  }
}

const loadMoreLinkedContents = () => {
  if (!hasMoreLinkedContents.value || isFetchingLinkedContents.value) {
    return
  }

  linkedContentsPage.value += 1
}

const restoreVersionWithConfirm = async (version: AssetVersionResource) => {
  const confirmed = await alert.confirm(
    String(t('labels.assets.versions.restoreConfirmMessage', { version: version.version_number })),
    {
      title: String(t('labels.assets.versions.restoreConfirmTitle')),
      confirmLabel: String(t('labels.assets.versions.restore')),
      variant: 'destructive',
    }
  )

  if (!confirmed || !assetCopy.value) {
    return
  }

  restoringVersionId.value = version.id
  restoreVersion(version.id, {
    onSuccess: (updated) => {
      if (updated) {
        assetCopy.value = deepClone(updated)
      }
      restoringVersionId.value = null
    },
    onError: () => {
      restoringVersionId.value = null
    },
  })
}
</script>

<template>
  <Dialog
    :open="!!asset"
    @update:open="onOpenChange"
  >
    <DialogContent
      v-if="asset && assetCopy"
      class="max-w-11/12! max-h-[90dvh] grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden"
    >
      <DialogHeader class="min-w-0 pr-8">
        <p
          v-if="mode === 'normal'"
          class="truncate text-sm"
        >
          /<span
            v-for="crumb in asset.folder_id ? getBreadcrumbs(asset.folder_id) : []"
            :key="crumb.id"
            >{{ crumb.name }}/</span
          >
        </p>
        <DialogTitle class="truncate">{{ asset.filename }}</DialogTitle>
        <p>.{{ asset.extension }}</p>
      </DialogHeader>
      <div
        class="grid gap-6 py-4 md:grid-cols-12 md:grid-rows-[minmax(0,1fr)] min-h-0 overflow-y-auto md:overflow-hidden"
      >
        <div
          class="checkerboard flex flex-col items-center justify-center-safe rounded-xl p-4 md:col-span-8 min-h-0 md:overflow-y-auto"
        >
          <div
            v-if="getFileType(asset.mime_type) === 'image'"
            ref="imageContainer"
            class="relative flex w-full items-center justify-center"
          >
            <div class="relative inline-block">
              <NuxtImg
                ref="imageRef"
                :src="asset.full_path"
                crop="fit"
                :alt="String(assetCopy?.data?.altText || asset.filename)"
                :height="600"
                :width="600"
                class="max-h-[calc(60svh)] max-w-full object-contain"
              />
              <div
                v-if="assetCopy.data?.focus"
                class="pointer-events-none absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 transform mix-blend-difference"
                :style="{
                  left: `${assetCopy.data?.focus?.x}%`,
                  top: `${assetCopy.data?.focus?.y}%`,
                }"
                aria-hidden="true"
              >
                <Icon
                  name="lucide:crosshair"
                  size="1.25rem"
                  class="text-primary"
                />
              </div>
              <div
                v-if="!props.readOnly && assetCopy.data?.focus"
                class="absolute inset-0 cursor-crosshair"
                @mousedown="startDragging"
              />
            </div>
          </div>
          <div
            v-else-if="getFileType(asset.mime_type) === 'video'"
            class="flex w-full flex-col gap-3"
          >
            <video
              ref="videoRef"
              controls
              :src="asset.url ?? undefined"
              :poster="
                asset.metadata.thumbnails?.[0]?.full_path
                  ? buildIlumUrl(
                      asset.metadata.thumbnails[0].full_path,
                      { width: 1200, crop: 'fit' },
                      ilumBaseUrl
                    )
                  : undefined
              "
              class="max-h-[calc(60svh)] w-full rounded-lg object-contain"
            />
            <div
              v-if="asset.metadata.thumbnails?.length"
              class="flex gap-2 overflow-x-auto pb-1"
            >
              <button
                v-for="thumb in asset.metadata.thumbnails"
                :key="thumb.position"
                type="button"
                class="group relative shrink-0 overflow-hidden rounded"
                @click="seekVideoTo(thumb.position)"
              >
                <NuxtImg
                  :src="thumb.full_path"
                  :width="120"
                  :height="68"
                  crop="fill"
                  class="pointer-events-none block h-[68px] w-[120px] object-cover"
                />
                <span
                  class="absolute bottom-0 left-0 right-0 bg-black/60 py-0.5 text-center text-xs text-white"
                >
                  {{ thumb.position_formatted }}
                </span>
              </button>
            </div>
          </div>
          <div
            v-else-if="getFileType(asset.mime_type) === 'audio'"
            class="flex w-full flex-col items-center gap-4 py-8"
          >
            <Icon
              name="lucide:file-audio"
              size="3rem"
              class="text-muted"
            />
            <audio
              controls
              :src="asset.url ?? undefined"
              class="w-full"
            />
          </div>
          <div
            v-else-if="asset.mime_type === 'application/pdf'"
            class="w-full"
          >
            <iframe
              :src="asset.url ?? undefined"
              class="h-[60svh] w-full rounded-lg border-0"
              :title="asset.filename"
            />
          </div>
          <div
            v-else
            class="flex h-75 w-full flex-col items-center justify-center gap-4"
          >
            <Icon
              :name="getFileIcon(getFileType(asset.mime_type))"
              size="3rem"
            />
            <div class="text-center">
              <p class="font-semibold">{{ asset.filename }}</p>
              <p class="text-sm text-muted">{{ formatFileSize(asset.size) }}</p>
            </div>
          </div>
          <div
            class="mt-4 flex w-full flex-wrap gap-2"
            aria-label="Asset actions"
          >
            <Button
              variant="outline"
              size="icon"
              class="flex items-center gap-2"
              @click="openAssetInNewWindow"
            >
              <Icon name="lucide:external-link" />
            </Button>
            <Button
              variant="outline"
              size="icon"
              class="flex items-center gap-2"
              @click="copyAssetUrl"
            >
              <Icon name="lucide:link" />
            </Button>
            <Button
              v-if="!props.readOnly && getFileType(asset.mime_type) === 'image'"
              variant="outline"
              class="flex items-center gap-2"
              @click="toggleFocusPoint"
            >
              <Icon :name="assetCopy.data.focus ? 'lucide:x' : 'lucide:crosshair'" />
              <span>{{
                assetCopy.data.focus
                  ? $t('labels.assets.removeFocusPoint')
                  : $t('labels.assets.setFocusPoint')
              }}</span>
            </Button>
            <template v-if="!props.readOnly">
              <input
                ref="replaceFileInput"
                type="file"
                class="hidden"
                @change="onReplaceFileSelected"
              />
              <Button
                variant="outline"
                class="ml-auto flex items-center gap-2"
                :disabled="isReplacing"
                @click="triggerReplaceFile"
              >
                <Icon
                  :name="isReplacing ? 'lucide:loader-circle' : 'lucide:refresh-cw'"
                  :class="{ 'animate-spin': isReplacing }"
                />
                <span>{{
                  isReplacing ? `${replaceProgress}%` : $t('labels.assets.replaceMedia')
                }}</span>
              </Button>
            </template>
          </div>
        </div>
        <div class="flex flex-col gap-4 md:col-span-4 min-h-0 min-w-0">
          <InputField
            v-model="assetCopy.filename"
            name="filename"
            :label="$t('labels.assets.fields.name')"
            required
            :disabled="props.readOnly"
          />

          <ComboboxField
            v-if="tagOptions.length"
            v-model="assetCopy.tags"
            name="asset_tags"
            :label="$t('labels.assetTags.title')"
            :placeholder="$t('labels.assetTags.fields.namePlaceholder')"
            :options="tagOptions"
            :readonly="props.readOnly"
            multiple
            searchable
            :empty-text="$t('labels.assetTags.noTags')"
          >
            <template #option="{ option }">
              <IconName
                :icon="option.icon"
                :color="option.color"
                :name="option.label"
              />
            </template>
            <template #selected="{ option }">
              <IconName
                :icon="option?.icon"
                :color="option?.color"
                :name="option?.label ?? String(option?.value)"
              />
            </template>
          </ComboboxField>

          <Tabs
            v-if="mode === 'normal'"
            class="flex min-h-0 flex-1 flex-col"
            :model-value="selectedPanel"
            @update:model-value="
              selectedPanel = $event as 'details' | 'rights' | 'versions' | 'linked'
            "
          >
            <TabsList class="w-full shrink-0 *:flex-1">
              <TabsTrigger value="details">
                {{ $t('labels.assets.fields.metadata') }}
              </TabsTrigger>
              <TabsTrigger value="rights">
                {{ $t('labels.assets.rights.title') }}
              </TabsTrigger>
              <TabsTrigger value="versions">
                {{ $t('labels.assets.versions.title') }}
              </TabsTrigger>
              <TabsTrigger value="linked">
                {{ $t('labels.assets.linkedContents') }}
              </TabsTrigger>
            </TabsList>

            <TabsContent
              value="details"
              class="min-h-0 flex-1 space-y-4 overflow-y-auto"
            >
              <div class="rounded-lg bg-surface p-3 text-sm">
                <dl class="grid grid-cols-2 gap-2">
                  <dt class="font-semibold">{{ $t('labels.assets.fields.type') }}:</dt>
                  <dd class="truncate">{{ asset.mime_type || $t('labels.assets.unknown') }}</dd>
                  <dt class="font-semibold">{{ $t('labels.assets.size') }}:</dt>
                  <dd class="truncate">{{ formatFileSize(asset.size) }}</dd>
                  <dt class="font-semibold">{{ $t('labels.assets.createdAt') }}:</dt>
                  <dd class="truncate">{{ formatDateTime(asset.created_at) }}</dd>
                  <dt class="font-semibold">{{ $t('labels.assets.updatedAt') }}:</dt>
                  <dd class="truncate">{{ formatDateTime(asset.updated_at) }}</dd>
                  <dt class="font-semibold">{{ $t('labels.assets.linkedContents') }}:</dt>
                  <dd class="truncate">{{ linkedContentsSummary }}</dd>
                </dl>

                <div
                  v-if="asset.metadata && Object.keys(asset.metadata).length"
                  class="mt-4 border-t-2 border-background pt-4"
                >
                  <dl class="grid grid-cols-2 gap-2">
                    <template
                      v-for="(value, key) in asset.metadata"
                      :key="key"
                    >
                      <template v-if="key === 'dominant_color' || key === 'palette'">
                        <dt class="font-semibold">
                          {{ String($t(`labels.assets.metadata.${key}`) || formatKey(key)) }}:
                        </dt>
                        <dd class="flex flex-wrap items-center gap-2">
                          <span
                            v-for="color in Array.isArray(value) ? value : [value]"
                            :key="String(color)"
                            class="inline-flex items-center gap-1"
                          >
                            <span
                              class="inline-block size-4 rounded border border-input"
                              :style="{ backgroundColor: String(color) }"
                            />
                            <span class="text-xs">{{ color }}</span>
                          </span>
                        </dd>
                      </template>
                      <template v-else-if="key !== 'thumbnails' && key !== 'a11y'">
                        <dt class="font-semibold">
                          {{ String($t(`labels.assets.metadata.${key}`) || formatKey(key)) }}:
                        </dt>
                        <dd class="wrap-break-word">{{ value }}</dd>
                      </template>
                    </template>
                  </dl>
                </div>

                <div
                  v-if="colorA11y"
                  class="mt-4 border-t-2 border-background pt-4"
                >
                  <h4 class="mb-2 font-semibold">{{ $t('labels.assets.a11y.title') }}</h4>
                  <dl class="grid grid-cols-2 gap-2">
                    <dt class="font-semibold">{{ $t('labels.assets.a11y.overlay') }}:</dt>
                    <dd>
                      {{
                        colorA11y.scheme === 'dark'
                          ? $t('labels.assets.a11y.overlayLight')
                          : $t('labels.assets.a11y.overlayDark')
                      }}
                    </dd>
                    <template
                      v-for="row in contrastRows"
                      :key="row.key"
                    >
                      <dt class="font-semibold">{{ row.label }}:</dt>
                      <dd class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1">
                          <span
                            class="inline-block size-4 rounded border border-input"
                            :style="{
                              backgroundColor: asset.metadata.dominant_color,
                              color: row.swatch,
                            }"
                            aria-hidden="true"
                            ><span
                              class="flex h-full items-center justify-center text-[0.6rem] leading-none"
                              >A</span
                            ></span
                          >
                          {{ row.ratio }}:1
                        </span>
                        <span
                          v-for="check in row.checks"
                          :key="check.label"
                          class="rounded px-1.5 py-0.5 text-xs font-medium"
                          :class="
                            check.passed ? 'bg-success/15 text-success' : 'bg-muted/20 text-muted'
                          "
                          :title="`≥ ${check.threshold}:1`"
                        >
                          {{ check.label }} {{ check.passed ? '✓' : '✗' }}
                        </span>
                      </dd>
                    </template>
                    <dt class="font-semibold">{{ $t('labels.assets.a11y.luminance') }}:</dt>
                    <dd>{{ colorA11y.luminance }}</dd>
                  </dl>
                </div>
              </div>
              <div
                v-if="generalFields.length > 0 && languageTabs.length > 1"
                class="space-y-3 mt-3"
              >
                <Tabs
                  :model-value="String(selectedLanguage)"
                  @update:model-value="selectedLanguage = String($event)"
                  class="w-full"
                >
                  <TabsList class="w-full">
                    <TabsTrigger
                      v-for="lang in languageTabs"
                      :key="lang.code"
                      :value="lang.code || ''"
                    >
                      {{ lang.name }}
                    </TabsTrigger>
                  </TabsList>
                </Tabs>
                <div class="space-y-3">
                  <InputField
                    v-for="field in generalFields"
                    :key="`${selectedLanguage}-${field.key}`"
                    :model-value="getFieldValue(field.key) as string"
                    :label="String(field.label)"
                    :name="field.key"
                    :required="isFieldRequiredForLanguage(field, selectedLanguage)"
                    :disabled="props.readOnly"
                    @update:model-value="setFieldValue(field.key, $event)"
                  />
                </div>
              </div>
              <div
                v-else-if="generalFields.length > 0"
                class="space-y-3"
              >
                <InputField
                  v-for="field in generalFields"
                  :key="field.key"
                  :model-value="getFieldValue(field.key) as string"
                  :label="String(field.label)"
                  :name="field.key"
                  :required="isFieldRequiredForLanguage(field, '_default')"
                  :disabled="props.readOnly"
                  @update:model-value="setFieldValue(field.key, $event)"
                />
              </div>
            </TabsContent>

            <TabsContent
              value="rights"
              class="min-h-0 flex-1 space-y-4 overflow-y-auto"
            >
              <div
                v-if="rightsStatusBadgeVariant"
                class="rounded-lg bg-surface p-3"
              >
                <Badge :variant="rightsStatusBadgeVariant">
                  {{ $t(`labels.assets.rights.status.${assetCopy.rights_status}`) }}
                </Badge>
                <p
                  v-if="assetCopy.license_expires_at"
                  class="mt-2 text-sm text-muted"
                >
                  {{
                    $t('labels.assets.rights.expiresOn', {
                      date: formatDateTime(assetCopy.license_expires_at),
                    })
                  }}
                </p>
                <p class="mt-1 text-xs text-muted">
                  {{ $t('labels.assets.rights.softWarningHint') }}
                </p>
              </div>

              <DateTimeField
                v-model="licenseExpiresAtDate"
                type="date"
                name="license_expires_at"
                :label="$t('labels.assets.rights.expiresAt')"
                :disabled="props.readOnly"
              />

              <div
                v-if="rightsFields.length > 0"
                class="space-y-3"
              >
                <template
                  v-for="field in rightsFields"
                  :key="field.key"
                >
                  <SelectField
                    v-if="field.key === 'license_type'"
                    :model-value="getRightsFieldValue(field.key)"
                    :label="String(field.label)"
                    :name="field.key"
                    :options="licenseTypeOptions"
                    :required="isFieldRequiredForLanguage(field, '_default')"
                    :disabled="props.readOnly"
                    clearable
                    @update:model-value="setRightsFieldValue(field.key, $event as string)"
                  />
                  <InputField
                    v-else
                    :model-value="getRightsFieldValue(field.key)"
                    :label="String(field.label)"
                    :name="field.key"
                    :required="isFieldRequiredForLanguage(field, '_default')"
                    :disabled="props.readOnly"
                    @update:model-value="setRightsFieldValue(field.key, $event)"
                  />
                </template>
              </div>
              <p
                v-else
                class="text-sm text-muted"
              >
                {{ $t('labels.assets.rights.noFieldsConfigured') }}
              </p>
            </TabsContent>

            <TabsContent
              value="versions"
              class="min-h-0 flex-1 overflow-y-auto"
            >
              <div class="rounded-xl bg-surface p-3">
                <div class="mb-4">
                  <p class="font-semibold">{{ $t('labels.assets.versions.title') }}</p>
                  <p class="text-sm text-muted">{{ $t('labels.assets.versions.description') }}</p>
                </div>

                <div
                  v-if="isFetchingVersions && !versionItems.length"
                  class="text-sm text-muted"
                >
                  {{ $t('labels.assets.versions.loading') }}
                </div>

                <div
                  v-else-if="!versionItems.length"
                  class="text-sm text-muted"
                >
                  {{ $t('labels.assets.versions.empty') }}
                </div>

                <div
                  v-else
                  class="space-y-3"
                >
                  <div
                    v-for="version in versionItems"
                    :key="version.id"
                    class="flex items-center gap-3 rounded-lg border border-input bg-background p-3"
                  >
                    <div
                      class="checkerboard flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded"
                    >
                      <NuxtImg
                        v-if="getFileType(version.mime_type) === 'image' && version.full_path"
                        :src="version.full_path"
                        :width="80"
                        :height="80"
                        crop="fill"
                        class="h-full w-full object-cover"
                      />
                      <Icon
                        v-else
                        :name="getFileIcon(getFileType(version.mime_type))"
                        class="h-4 w-4"
                      />
                    </div>
                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-2">
                        <p class="font-semibold">
                          {{
                            $t('labels.assets.versions.versionNumber', {
                              number: version.version_number,
                            })
                          }}
                        </p>
                        <Badge
                          variant="secondary"
                          size="sm"
                        >
                          {{ formatFileSize(version.size) }}
                        </Badge>
                      </div>
                      <p class="truncate text-sm text-muted">
                        {{ version.created_at ? formatDateTime(version.created_at) : '' }}
                        <template v-if="version.created_by">
                          &middot; {{ version.created_by.name }}
                        </template>
                      </p>
                    </div>
                    <Button
                      v-if="!props.readOnly"
                      variant="outline"
                      size="sm"
                      :disabled="isRestoringVersion"
                      @click="restoreVersionWithConfirm(version)"
                    >
                      <Icon
                        v-if="isRestoringVersion && restoringVersionId === version.id"
                        name="lucide:loader-circle"
                        class="animate-spin"
                      />
                      {{ $t('labels.assets.versions.restore') }}
                    </Button>
                  </div>
                </div>
              </div>
            </TabsContent>

            <TabsContent
              value="linked"
              class="min-h-0 flex-1 overflow-y-auto"
            >
              <div class="rounded-xl bg-surface p-3">
                <div class="mb-4">
                  <p class="font-semibold">{{ $t('labels.assets.linkedContents') }}</p>
                  <p class="text-sm text-muted">{{ linkedContentsSummary }}</p>
                </div>

                <div
                  v-if="isFetchingLinkedContents && !linkedContentsItems.length"
                  class="text-sm text-muted"
                >
                  {{ $t('labels.assets.loadingLinkedContents') }}
                </div>

                <div
                  v-else-if="!linkedContentsItems.length"
                  class="text-sm text-muted"
                >
                  {{ $t('labels.assets.noLinkedContents') }}
                </div>

                <div
                  v-else
                  class="space-y-3"
                >
                  <RouterLink
                    v-for="item in linkedContentsItems"
                    :key="item.id"
                    :to="{
                      name: 'space-content-contentId',
                      params: {
                        space: spaceId,
                        contentId: item.id,
                      },
                    }"
                    class="block rounded-lg border border-input bg-background p-3 transition-colors hover:bg-input"
                  >
                    <div class="flex items-center gap-2">
                      <Icon
                        :name="`lucide:${item.block.icon}`"
                        :style="{ color: item.block.color }"
                        class="shrink-0"
                      />
                      <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                          <p class="truncate font-semibold">
                            {{ item.name || $t('labels.assets.unknown') }}
                          </p>
                          <div class="flex flex-wrap gap-1">
                            <Badge
                              variant="secondary"
                              size="sm"
                            >
                              {{ item.language_iso.toUpperCase() }}
                            </Badge>
                            <Badge
                              v-if="item.usage.current"
                              variant="warning"
                              size="sm"
                            >
                              {{ $t('labels.assets.draft') }}
                            </Badge>
                            <Badge
                              v-if="item.usage.published"
                              variant="success"
                              size="sm"
                            >
                              {{ $t('labels.assets.published') }}
                            </Badge>
                          </div>
                        </div>
                        <p class="truncate text-sm text-muted">
                          {{ item.full_slug }}
                        </p>
                      </div>
                      <Icon
                        name="lucide:arrow-up-right"
                        class="shrink-0 text-muted"
                      />
                    </div>
                  </RouterLink>

                  <Button
                    v-if="hasMoreLinkedContents"
                    variant="outline"
                    class="w-full"
                    :disabled="isFetchingLinkedContents"
                    @click="loadMoreLinkedContents"
                  >
                    {{
                      isFetchingLinkedContents
                        ? $t('labels.assets.loadingLinkedContents')
                        : $t('actions.loadMore')
                    }}
                  </Button>
                </div>
              </div>
            </TabsContent>
          </Tabs>
        </div>
      </div>
      <DialogFooter>
        <Button
          variant="outline"
          @click="onOpenChange(false)"
        >
          {{ props.readOnly ? $t('actions.close') : $t('alertDialog.cancel') }}
        </Button>
        <Button
          v-if="!props.readOnly"
          @click="handleFinish"
        >
          {{ $t('actions.saveClose') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
