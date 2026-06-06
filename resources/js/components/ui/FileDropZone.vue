<script setup lang="ts">
import Icon from '~/components/Icon.vue'

const props = defineProps<{
  accept?: string
  hint?: string
}>()

const emit = defineEmits<{
  error: [message: string]
}>()

const modelValue = defineModel<File | null>({ default: null })

const { t } = useI18n()
const fileInputRef = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)

const acceptedExtensions = computed(() =>
  (props.accept ?? '')
    .split(',')
    .map((s) => s.trim().toLowerCase())
    .filter(Boolean)
)

const setFile = (file: File | null | undefined) => {
  if (!file) return
  if (
    acceptedExtensions.value.length > 0 &&
    !acceptedExtensions.value.some((ext) => file.name.toLowerCase().endsWith(ext))
  ) {
    emit('error', t('components.fileDropZone.unsupportedFileError') as string)
    return
  }
  modelValue.value = file
}

const handleFileChange = (event: Event) => {
  const target = event.target as HTMLInputElement
  setFile(target.files?.[0])
}

const handleDragOver = (event: DragEvent) => {
  event.preventDefault()
  isDragging.value = true
}

const handleDragLeave = (event: DragEvent) => {
  // Only clear when leaving the drop zone itself, not a child element
  if (!(event.currentTarget as HTMLElement).contains(event.relatedTarget as Node | null)) {
    isDragging.value = false
  }
}

const handleDrop = (event: DragEvent) => {
  event.preventDefault()
  isDragging.value = false
  setFile(event.dataTransfer?.files?.[0])
}
</script>

<template>
  <div
    :class="[
      'cursor-pointer rounded-lg border-2 border-dashed p-6 text-center transition-colors',
      isDragging
        ? 'border-primary bg-primary/5'
        : 'border-border hover:border-muted-foreground',
    ]"
    @click="fileInputRef?.click()"
    @dragover="handleDragOver"
    @dragleave="handleDragLeave"
    @drop="handleDrop"
  >
    <input
      ref="fileInputRef"
      type="file"
      :accept="accept"
      class="hidden"
      @change="handleFileChange"
    />

    <div class="flex flex-col items-center gap-2">
      <Icon
        :name="isDragging ? 'lucide:download' : 'lucide:upload-cloud'"
        :class="['h-8 w-8 transition-colors', isDragging ? 'text-primary' : 'text-muted-foreground']"
      />

      <div class="space-y-1">
        <p class="text-sm font-medium text-foreground">
          <span v-if="modelValue">{{ modelValue.name }}</span>
          <span v-else-if="isDragging">{{ $t('components.fileDropZone.dropFileAction') }}</span>
          <span v-else>
            <span class="text-primary">{{ $t('components.fileDropZone.selectFileAction') }}</span>
            {{ $t('components.fileDropZone.orDragDrop') }}
          </span>
        </p>
        <p
          v-if="hint"
          class="text-xs text-muted-foreground"
        >
          {{ hint }}
        </p>
      </div>
    </div>
  </div>
</template>
