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
import { InputField } from '~/components/ui/form'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { isClient } from '~/lib/env'
import type { AssetResource, LinkedAssetContentResource } from '~/types/assets'

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

const assetCopy = ref<AssetResource | null>(null)
const imageContainer = ref<HTMLElement | null>(null)
const imageRef = useTemplateRef('imageRef')
const isDraggingFocus = ref(false)
const selectedPanel = ref<'details' | 'linked'>('details')
const selectedLanguage = ref<string>('_default')
const linkedContentsPage = ref(1)
const linkedContentsItems = ref<LinkedAssetContentResource[]>([])
const effectiveFields = computed(() => {
  return getEffectiveFieldsForTarget(assetCopy.value)
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

const formatKey = (key: string): string => {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

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
</script>

<template>
  <Dialog
    :open="!!asset"
    @update:open="onOpenChange"
  >
    <DialogContent
      v-if="asset && assetCopy"
      class="max-w-11/12!"
    >
      <DialogHeader>
        <p
          v-if="mode === 'normal'"
          class="text-sm"
        >
          /<span
            v-for="crumb in asset.folder_id ? getBreadcrumbs(asset.folder_id) : []"
            :key="crumb.id"
            >{{ crumb.name }}/</span
          >
        </p>
        <DialogTitle>{{ asset.filename }}</DialogTitle>
        <p>.{{ asset.extension }}</p>
      </DialogHeader>
      <div class="grid gap-6 py-4 md:grid-cols-12">
        <div
          class="checkerboard flex flex-col items-center justify-center rounded-xl p-4 md:col-span-8"
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
            class="mt-4 flex w-full gap-2"
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
          </div>
        </div>
        <div class="space-y-4 md:col-span-4">
          <InputField
            v-model="assetCopy.filename"
            name="filename"
            :label="$t('labels.assets.fields.name')"
            required
            :disabled="props.readOnly"
          />

          <Tabs
            v-if="mode === 'normal'"
            :model-value="selectedPanel"
            @update:model-value="selectedPanel = $event as 'details' | 'linked'"
            class="space-y-4"
          >
            <TabsList class="grid w-full grid-cols-2">
              <TabsTrigger value="details">
                {{ $t('labels.assets.fields.metadata') }}
              </TabsTrigger>
              <TabsTrigger value="linked">
                {{ $t('labels.assets.linkedContents') }}
              </TabsTrigger>
            </TabsList>

            <TabsContent value="details">
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
                      <dt class="font-semibold">
                        {{ String($t(`labels.assets.metadata.${key}`) || formatKey(key)) }}:
                      </dt>
                      <dd class="wrap-break-word">{{ value }}</dd>
                    </template>
                  </dl>
                </div>
              </div>
              <div
                v-if="effectiveFields.length > 0 && languageTabs.length > 1"
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
                    v-for="field in effectiveFields"
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
                v-else-if="effectiveFields.length > 0"
                class="space-y-3"
              >
                <InputField
                  v-for="field in effectiveFields"
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

            <TabsContent value="linked">
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
