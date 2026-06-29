<script setup lang="ts">
import { api } from '~/api'
import Icon from '~/components/Icon.vue'
import IconPreview from '~/components/icons/IconPreview.vue'
import IconTagsInput from '~/components/icons/IconTagsInput.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { InputField } from '~/components/ui/form'
import { ScrollArea } from '~/components/ui/scroll-area'
import { parseSvgDimensions, replaceColorsWithCurrentColor } from '~/utils/svg'

const props = defineProps<{
  spaceId: string
  initialFiles?: File[]
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { uploadIcon } = useIcons(props.spaceId)

const KEY_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

interface QueueItem {
  id: string
  file: File
  body: string
  originalBody: string
  key: string
  name: string
  width: number
  height: number
  status: 'pending' | 'uploading' | 'done' | 'error'
  progress: number
  error: string | null
  keyTaken: boolean
  useCurrentColor: boolean
}

const queue = ref<QueueItem[]>([])
const sharedTags = ref<string[]>([])
const isDragging = ref(false)
const isUploading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const slugify = (value: string): string =>
  value
    .toLowerCase()
    .replace(/\.[^.]+$/, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')

const humanize = (value: string): string =>
  value
    .replace(/\.[^.]+$/, '')
    .replace(/[-_]+/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
    .trim()

const readAsText = (file: File): Promise<string> =>
  new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result ?? ''))
    reader.onerror = () => reject(new Error('Failed to read file'))
    reader.readAsText(file)
  })

const addFiles = async (files: File[]) => {
  for (const file of files) {
    if (!file.name.toLowerCase().endsWith('.svg')) continue

    const body = await readAsText(file)
    const baseKey = slugify(file.name) || 'icon'
    const { width, height } = parseSvgDimensions(body)

    queue.value.push({
      id: `${file.name}-${queue.value.length}-${file.size}`,
      file,
      body,
      originalBody: body,
      key: baseKey,
      name: humanize(file.name) || baseKey,
      width,
      height,
      status: 'pending',
      progress: 0,
      error: null,
      keyTaken: false,
      useCurrentColor: false,
    })
  }
}

const onFileChange = (event: Event) => {
  const target = event.target as HTMLInputElement
  if (target.files) {
    addFiles(Array.from(target.files))
  }
  target.value = ''
}

const onDrop = (event: DragEvent) => {
  event.preventDefault()
  isDragging.value = false
  if (event.dataTransfer?.files) {
    addFiles(Array.from(event.dataTransfer.files))
  }
}

const removeItem = (id: string) => {
  queue.value = queue.value.filter((item) => item.id !== id)
}

const duplicateKeys = computed(() => {
  const counts = new Map<string, number>()
  for (const item of queue.value) {
    counts.set(item.key, (counts.get(item.key) ?? 0) + 1)
  }
  return new Set([...counts.entries()].filter(([, n]) => n > 1).map(([key]) => key))
})

const itemError = (item: QueueItem): string | null => {
  if (item.status === 'error' && item.error) return item.error
  if (!KEY_PATTERN.test(item.key)) return t('labels.icons.invalidKey') as string
  if (duplicateKeys.value.has(item.key)) return t('labels.icons.duplicateKeyInBatch') as string
  if (item.keyTaken) return t('labels.icons.keyTaken') as string
  return null
}

// Best-effort live check that a key is not already in the registry.
const checkKeyTaken = async (item: QueueItem) => {
  if (!KEY_PATTERN.test(item.key)) return
  try {
    const response = await api.forSpace(props.spaceId).icons.index({ key: item.key, per_page: 50 })
    item.keyTaken = response.data.some((icon) => icon.key === item.key)
  } catch {
    item.keyTaken = false
  }
}

const toggleCurrentColor = (item: QueueItem) => {
  item.useCurrentColor = !item.useCurrentColor
  item.body = item.useCurrentColor
    ? replaceColorsWithCurrentColor(item.originalBody)
    : item.originalBody
}

const canSubmit = computed(
  () =>
    !isUploading.value &&
    queue.value.length > 0 &&
    queue.value.every((item) => item.status === 'done' || itemError(item) === null)
)

const submit = async () => {
  isUploading.value = true

  for (const item of queue.value) {
    if (item.status === 'done') continue
    if (itemError(item)) continue

    item.status = 'uploading'
    item.error = null

    try {
      await uploadIcon(
        {
          // When currentColor replacement is active, send the modified SVG as body
          // so the server parses the transformed content instead of the original file.
          file: item.useCurrentColor ? undefined : item.file,
          body: item.useCurrentColor ? item.body : undefined,
          key: item.key,
          name: item.name,
          tags: sharedTags.value.length ? sharedTags.value : undefined,
        },
        (progress) => {
          item.progress = progress
        }
      )
      item.status = 'done'
      item.progress = 100
    } catch (error) {
      item.status = 'error'
      item.error = error instanceof Error ? error.message : String(error)
    }
  }

  isUploading.value = false

  // The upload mutation invalidates the icon queries itself, so success only needs
  // to reset and close once every item landed.
  if (queue.value.every((item) => item.status === 'done')) {
    reset()
    open.value = false
  }
}

const reset = () => {
  queue.value = []
  sharedTags.value = []
}

watch(open, (value) => {
  if (!value) {
    reset()
    return
  }

  if (props.initialFiles?.length) {
    addFiles(props.initialFiles)
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="!max-w-2xl">
      <DialogHeader>
        <DialogTitle>{{ t('labels.icons.uploadTitle') }}</DialogTitle>
      </DialogHeader>

      <div
        :class="[
          'cursor-pointer rounded-lg border-2 border-dashed p-6 text-center transition-colors',
          isDragging ? 'border-primary bg-primary/5' : 'border-input hover:border-primary',
        ]"
        @click="fileInput?.click()"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop="onDrop"
      >
        <Icon
          name="lucide:upload"
          size="28"
          class="mx-auto mb-2 text-muted"
        />
        <p class="text-sm font-medium text-primary">{{ t('labels.icons.dropHint') }}</p>
        <p class="text-xs text-muted">{{ t('labels.icons.dropSubHint') }}</p>
        <input
          ref="fileInput"
          type="file"
          accept=".svg,image/svg+xml"
          multiple
          class="hidden"
          @change="onFileChange"
        />
      </div>

      <ScrollArea
        v-if="queue.length"
        class="max-h-[40dvh]"
      >
        <div class="flex flex-col gap-2 pr-2">
          <div
            v-for="item in queue"
            :key="item.id"
            class="flex items-start gap-3 rounded-lg border border-input bg-surface p-2"
          >
            <div class="flex shrink-0 flex-col items-center gap-0.5">
              <div class="flex size-12 items-center justify-center rounded border border-input bg-background text-primary">
                <IconPreview
                  :body="item.body"
                  :width="item.width"
                  :height="item.height"
                  size="24"
                />
              </div>
              <span class="text-[10px] leading-none text-muted">{{ item.width }}×{{ item.height }}</span>
            </div>
            <div class="grid min-w-0 flex-1 grid-cols-2 gap-2">
              <InputField
                v-model="item.key"
                name="key"
                :label="t('labels.icons.key')"
                :error="itemError(item) ?? undefined"
                :disabled="item.status === 'uploading' || item.status === 'done'"
                @blur="checkKeyTaken(item)"
              />
              <InputField
                v-model="item.name"
                name="name"
                :label="t('labels.icons.name')"
                :disabled="item.status === 'uploading' || item.status === 'done'"
              />
            </div>
            <div class="flex shrink-0 items-center gap-2 pt-6">
              <Icon
                v-if="item.status === 'done'"
                name="lucide:check"
                class="text-green-600"
              />
              <Icon
                v-else-if="item.status === 'uploading'"
                name="lucide:loader-circle"
                class="animate-spin text-muted"
              />
              <template v-else>
                <button
                  type="button"
                  :title="t('labels.icons.useCurrentColor')"
                  :class="[
                    'rounded p-1 transition-colors',
                    item.useCurrentColor
                      ? 'text-primary bg-primary/10'
                      : 'text-muted hover:text-primary',
                  ]"
                  @click="toggleCurrentColor(item)"
                >
                  <Icon name="lucide:pipette" />
                </button>
                <button
                  type="button"
                  class="text-muted hover:text-destructive"
                  @click="removeItem(item.id)"
                >
                  <Icon name="lucide:x" />
                </button>
              </template>
            </div>
          </div>
        </div>
      </ScrollArea>

      <IconTagsInput
        v-if="queue.length"
        v-model="sharedTags"
        :space-id="spaceId"
        name="tags"
        :label="t('labels.icons.tagsForBatch')"
      />

      <DialogFooter>
        <Button
          variant="outline"
          :disabled="isUploading"
          @click="open = false"
        >
          {{ t('actions.cancel') }}
        </Button>
        <Button
          variant="primary"
          :disabled="!canSubmit"
          @click="submit"
        >
          {{ t('actions.icons.upload', { count: queue.length }) }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
