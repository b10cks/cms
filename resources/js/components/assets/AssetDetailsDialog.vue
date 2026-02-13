<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'

import { deepClone } from '@vue/devtools-shared'
import { toast } from 'vue-sonner'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { isClient } from '~/lib/env'

const { formatFileSize, formatDateTime } = useFormat()
const { getFileIcon } = useFileUtils()
const props = withDefaults(
  defineProps<{
    asset: AssetResource
    mode?: 'normal' | 'reduced'
    folderId: string | null
    spaceId: string
  }>(),
  {
    mode: 'normal',
  }
)

const { useFolderStructure } = useAssetFolders(props.spaceId)
const { getBreadcrumbs } = useFolderStructure()
const { getFileType } = useFileUtils()

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId)

const assetFields = computed(() => space.value?.settings?.asset_fields || [])
const languages = computed(() => space.value?.settings?.languages || [])
const defaultLanguage = computed(() => space.value?.settings?.default_language || '_default')

const assetCopy = ref<AssetResource | null>(null)
const imageContainer = ref<HTMLElement | null>(null)
const imageRef = useTemplateRef('imageRef')
const isDraggingFocus = ref(false)
const selectedLanguage = ref<string>('_default')

watch(
  () => props.asset,
  (newAsset) => {
    if (newAsset) {
      assetCopy.value = deepClone(newAsset)
      selectedLanguage.value = '_default'
    } else {
      assetCopy.value = null
    }
  },
  { immediate: true }
)

const emit = defineEmits(['close', 'update:asset'])

// Computed properties for language tabs
const languageTabs = computed(() => {
  const tabs = [{ code: '_default', name: 'Default' }]
  if (languages.value && languages.value.length > 0) {
    tabs.push(...languages.value)
  }
  return tabs
})

// Get fields for current language
const currentLanguageFields = computed(() => {
  if (!assetCopy.value?.data?.fields) {
    return []
  }
  const languageData = assetCopy.value.data.fields[selectedLanguage.value]
  return assetFields.value.filter((field) => {
    return languageData && field.key in languageData
  })
})

// Get or create fields object for a language
const getLanguageFieldsData = (languageCode: string) => {
  if (!assetCopy.value?.data) {
    assetCopy.value!.data = {}
  }
  if (!assetCopy.value.data.fields) {
    assetCopy.value.data.fields = {}
  }
  if (!assetCopy.value.data.fields[languageCode]) {
    assetCopy.value.data.fields[languageCode] = {}
  }
  return assetCopy.value.data.fields[languageCode] as Record<string, unknown>
}

// Get field value for current language
const getFieldValue = (fieldKey: string): unknown => {
  const languageData = getLanguageFieldsData(selectedLanguage.value)
  return languageData[fieldKey] || ''
}

// Set field value for current language
const setFieldValue = (fieldKey: string, value: unknown) => {
  const languageData = getLanguageFieldsData(selectedLanguage.value)
  languageData[fieldKey] = value
}

const formatKey = (key: string): string => {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

const copyAssetUrl = () => {
  navigator.clipboard
    .writeText(assetCopy.value!.url)
    .then(() => {
      toast.success('URL copied to clipboard')
    })
    .catch(() => {
      toast.error('Failed to copy URL')
    })
}

const openAssetInNewWindow = () => {
  window.open(assetCopy.value!.url, '_blank', 'noopener,noreferrer')
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
  emit('update:asset', assetCopy.value)
}

const onOpenChange = (open: boolean) => {
  if (!open) {
    emit('close')
  }
}
</script>

<template>
  <Dialog
    :open="!!asset"
    @update:open="onOpenChange"
  >
    <DialogContent
      v-if="asset && assetCopy"
      class="!max-w-11/12"
    >
      <DialogHeader>
        <p
          v-if="mode === 'normal'"
          class="text-sm"
        >
          /<span
            v-for="crumb in getBreadcrumbs(asset.folder_id)"
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
                :alt="String(assetCopy?.data?.altText || asset.filename)"
                height="600"
                width="600"
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
                v-if="assetCopy.data?.focus"
                class="absolute inset-0 cursor-crosshair"
                @mousedown="startDragging"
              />
            </div>
          </div>
          <div
            v-else
            class="flex h-[300px] w-full flex-col items-center justify-center gap-4"
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
              v-if="getFileType(asset.mime_type) === 'image'"
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
          />

          <div
            v-if="mode === 'normal'"
            class="rounded-lg bg-surface p-3 text-sm"
          >
            <dl class="grid grid-cols-2 gap-2">
              <dt class="font-semibold">{{ $t('labels.assets.fields.type') }}:</dt>
              <dd class="truncate">{{ asset.mime_type || $t('labels.assets.unknown') }}</dd>
              <dt class="font-semibold">{{ $t('labels.assets.size') }}:</dt>
              <dd class="truncate">{{ formatFileSize(asset.size) }}</dd>
              <dt class="font-semibold">{{ $t('labels.assets.createdAt') }}:</dt>
              <dd class="truncate">{{ formatDateTime(asset.created_at) }}</dd>
              <dt class="font-semibold">{{ $t('labels.assets.updatedAt') }}:</dt>
              <dd class="truncate">{{ formatDateTime(asset.updated_at) }}</dd>
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
                  <dd class="break-words">{{ value }}</dd>
                </template>
              </dl>
            </div>
          </div>

          <div
            v-if="assetFields.length > 0 && languageTabs.length > 1"
            class="space-y-3"
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
                v-for="field in assetFields"
                :key="`${selectedLanguage}-${field.key}`"
                :model-value="getFieldValue(field.key) as string"
                :label="String(field.label)"
                :name="field.key"
                :required="field.required"
                @update:model-value="setFieldValue(field.key, $event)"
              />
            </div>
          </div>
          <div
            v-else-if="assetFields.length > 0"
            class="space-y-3"
          >
            <InputField
              v-for="field in assetFields"
              :key="field.key"
              :model-value="getFieldValue(field.key) as string"
              :label="String(field.label)"
              :name="field.key"
              :required="field.required"
              @update:model-value="setFieldValue(field.key, $event)"
            />
          </div>
        </div>
      </div>
      <DialogFooter>
        <Button
          variant="outline"
          @click="onOpenChange(false)"
        >
          {{ $t('alertDialog.cancel') }}
        </Button>
        <Button @click="handleFinish">{{ $t('actions.saveClose') }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
