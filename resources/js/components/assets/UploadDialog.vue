<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { ScrollArea } from '~/components/ui/scroll-area'
import UploadDetailsDialog from './UploadDetailsDialog.vue'

const { formatFileSize } = useFormat()
const { getFileType, getFileIcon } = useFileUtils()
const ulid = useUlid()

const props = defineProps<{
  spaceId: string
  folderId?: string
  open: boolean
  initialFiles?: File[] // New prop for dropped files
  onUploadComplete?: () => void
}>()

const emit = defineEmits(['update:open'])

interface UploadFileWithProgress extends UploadFile {
  progress: number
  status: 'pending' | 'uploading' | 'error' | 'complete'
  errorMessage?: string
}

const { uploadAsset } = useAssets(props.spaceId)
const files = ref<UploadFileWithProgress[]>([])
const detailsOpen = ref(false)
const selectedFile = ref<UploadFile | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const isUploading = ref(false)

// Process initial files if provided
watch(
  () => props.initialFiles,
  (newFiles) => {
    if (newFiles && newFiles.length > 0) {
      handleFilesAdded(newFiles)
    }
  },
  { immediate: true }
)

// Track if any upload is still in progress
const hasUploadInProgress = computed(() => {
  return files.value.some((file) => file.status === 'uploading')
})

// Common function to process files whether from input or dropped
const handleFilesAdded = (newFilesArray: File[]) => {
  const newFiles = newFilesArray.map((file) => {
    const fileType = getFileType(file.type)
    const fileId = ulid()

    let preview: string | undefined
    if (fileType === 'image') {
      preview = URL.createObjectURL(file)
    }

    return {
      id: fileId,
      file,
      preview,
      data: {},
      metadata: {},
      folder_id: props.folderId,
      tags: [],
      type: fileType,
      progress: 0,
      status: 'pending' as const,
    }
  })

  files.value = [...files.value, ...newFiles]
}

const handleFileChange = (e: Event) => {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    handleFilesAdded(Array.from(target.files))
  }

  // Reset the input so the same file can be selected again
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
}

const removeFile = (id: string) => {
  const fileToRemove = files.value.find((file) => file.id === id)
  if (fileToRemove?.preview) {
    URL.revokeObjectURL(fileToRemove.preview)
  }
  files.value = files.value.filter((file) => file.id !== id)
}

const openFileDetails = (file: UploadFileWithProgress) => {
  selectedFile.value = file
  detailsOpen.value = true
}

const updateFileProgress = (id: string, progress: number) => {
  const fileIndex = files.value.findIndex((file) => file.id === id)
  if (fileIndex !== -1) {
    files.value[fileIndex].progress = progress
  }
}

const updateFileStatus = (
  id: string,
  status: 'pending' | 'uploading' | 'error' | 'complete',
  errorMessage?: string
) => {
  const fileIndex = files.value.findIndex((file) => file.id === id)
  if (fileIndex !== -1) {
    files.value[fileIndex].status = status
    if (errorMessage) {
      files.value[fileIndex].errorMessage = errorMessage
    }
  }
}

const handleUpload = async () => {
  isUploading.value = true

  // Upload files sequentially to avoid overwhelming the server
  for (const file of files.value) {
    // Skip already completed uploads
    if (file.status === 'complete') continue

    updateFileStatus(file.id, 'uploading')

    try {
      // Upload with progress tracking
      const result = await uploadAsset(file, (progress) => {
        updateFileProgress(file.id, progress)
      })

      if (result) {
        updateFileStatus(file.id, 'complete')

        // Release object URL if it exists
        if (file.preview) {
          URL.revokeObjectURL(file.preview)
        }
      } else {
        updateFileStatus(file.id, 'error', 'Upload failed')
      }
    } catch (error) {
      updateFileStatus(file.id, 'error', error instanceof Error ? error.message : 'Upload failed')
    }
  }

  isUploading.value = false

  // Check if all files are completed successfully
  const allCompleted = files.value.every((file) => file.status === 'complete')
  if (allCompleted) {
    // Reset state and close dialog
    if (props.onUploadComplete) {
      props.onUploadComplete()
    }
    emit('update:open', false)
    files.value = []
  }
}

const handleBrowseClick = () => {
  if (fileInputRef.value) {
    fileInputRef.value.click()
  }
}

const onOpenChange = (open: boolean) => {
  // Prevent closing if upload is in progress
  if (!open && hasUploadInProgress.value) {
    return
  }
  emit('update:open', open)
}

const handleReplaceFile = () => {
  if (!selectedFile.value) return

  const specificFileInput = document.createElement('input')
  specificFileInput.type = 'file'
  specificFileInput.accept = selectedFile.value.file.type
  specificFileInput.onchange = (e) => {
    const target = e.target as HTMLInputElement
    if (target.files && target.files.length > 0 && selectedFile.value) {
      const newFile = target.files[0]
      const fileType = getFileType(newFile.type)

      let preview: string | undefined
      if (fileType === 'image') {
        preview = URL.createObjectURL(newFile)
      }

      if (selectedFile.value.preview) {
        URL.revokeObjectURL(selectedFile.value.preview)
      }

      files.value = files.value.map((f) =>
        f.id === selectedFile.value?.id
          ? {
              ...f,
              file: newFile,
              preview,
              type: fileType,
              progress: 0,
              status: 'pending',
              errorMessage: undefined,
            }
          : f
      )

      selectedFile.value = {
        ...selectedFile.value,
        file: newFile,
        preview,
        type: fileType,
      } as UploadFileWithProgress

      // Add progress tracking properties
      ;(selectedFile.value as UploadFileWithProgress).progress = 0
      ;(selectedFile.value as UploadFileWithProgress).status = 'pending'
      ;(selectedFile.value as UploadFileWithProgress).errorMessage = undefined
    }
  }
  specificFileInput.click()
}

const handleDrop = (e: DragEvent) => {
  e.preventDefault()
  if (e.dataTransfer?.files && e.dataTransfer.files.length > 0) {
    const inputElement = fileInputRef.value
    if (inputElement) {
      const dataTransfer = new DataTransfer()
      for (let i = 0; i < e.dataTransfer.files.length; i++) {
        dataTransfer.items.add(e.dataTransfer.files[i])
      }

      inputElement.files = dataTransfer.files
      const changeEvent = new Event('change', { bubbles: true })
      inputElement.dispatchEvent(changeEvent)
    }
  }
}

const handleDragOver = (e: DragEvent) => {
  e.preventDefault()
}

const getProgressColor = (status: 'pending' | 'uploading' | 'error' | 'complete') => {
  switch (status) {
    case 'uploading':
      return 'bg-accent'
    case 'complete':
      return 'bg-green-500'
    case 'error':
      return 'bg-red-500'
    default:
      return 'bg-gray-300'
  }
}

// Retry uploading a failed file
const retryUpload = async (file: UploadFileWithProgress) => {
  updateFileStatus(file.id, 'uploading')
  updateFileProgress(file.id, 0)

  try {
    const result = await uploadAsset(file, (progress) => {
      updateFileProgress(file.id, progress)
    })

    if (result) {
      updateFileStatus(file.id, 'complete')

      // Release object URL if it exists
      if (file.preview) {
        URL.revokeObjectURL(file.preview)
      }
    } else {
      updateFileStatus(file.id, 'error', 'Retry failed')
    }
  } catch (error) {
    updateFileStatus(file.id, 'error', error instanceof Error ? error.message : 'Retry failed')
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="onOpenChange"
  >
    <DialogContent class="sm:max-w-[700px]">
      <DialogHeaderCombined
        :title="$t('labels.assets.uploadAssets')"
        :description="$t('labels.assets.uploadDescription')"
      />
      <div>
        <input
          ref="fileInputRef"
          type="file"
          class="hidden"
          multiple
          accept="image/*,video/*,audio/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.*"
          @change="handleFileChange"
        />
        <div
          v-if="files.length === 0"
          class="flex h-64 flex-col items-center justify-center rounded-lg border-2 border-dashed border-elevated/50 p-12 text-center"
          @dragover="handleDragOver"
          @drop="handleDrop"
        >
          <div class="flex flex-col items-center justify-center gap-4">
            <Icon
              name="lucide:upload"
              size="2rem"
              class="text-muted"
            />
            <div class="space-y-2">
              <p class="text-sm font-medium text-muted">
                {{ $t('labels.assets.dragAndDrop') }}
              </p>
              <Button
                variant="primary"
                @click="handleBrowseClick"
                >{{ $t('labels.assets.browseFiles') }}
              </Button>
            </div>
          </div>
        </div>

        <ScrollArea
          v-else
          class="h-64 rounded-xl bg-surface"
          @dragover="handleDragOver"
          @drop="handleDrop"
        >
          <div class="grid grid-cols-2 gap-4 p-4 sm:grid-cols-3 md:grid-cols-4">
            <div
              v-for="file in files"
              :key="file.id"
              role="button"
              class="group relative cursor-pointer rounded-lg bg-background shadow-lg transition-all hover:-translate-y-2 hover:bg-input focus:-translate-y-2 focus:bg-input"
              @click="openFileDetails(file)"
            >
              <button
                v-if="file.status !== 'uploading'"
                class="absolute top-1 right-1 z-10 flex h-6 w-6 cursor-pointer items-center justify-center text-primary/50 hover:text-red-500"
                @click.stop="removeFile(file.id)"
              >
                <Icon name="lucide:trash-2" />
              </button>

              <div class="checkerboard relative aspect-square overflow-hidden rounded-t-lg">
                <img
                  v-if="file.type === 'image' && file.preview"
                  :src="file.preview || '/placeholder.svg'"
                  :alt="file.file.name"
                  class="h-full w-full object-cover"
                />
                <div
                  v-else
                  class="flex h-full items-center justify-center"
                >
                  <Icon
                    :name="getFileIcon(file.type)"
                    class="h-6 w-6"
                  />
                </div>

                <!-- Upload status overlay -->
                <div
                  v-if="file.status === 'uploading'"
                  class="absolute inset-0 flex items-center justify-center bg-surface/50"
                >
                  <div class="p-2 text-center text-primary">
                    <div class="mb-2 flex items-center justify-center">
                      <Icon
                        name="lucide:loader"
                        class="h-6 w-6 animate-spin"
                      />
                    </div>
                    <div class="text-sm">{{ file.progress }}%</div>
                  </div>
                </div>
                <div
                  v-else-if="file.status === 'complete'"
                  class="absolute top-2 left-2"
                >
                  <div class="h-6 w-6 rounded-full bg-green-600 p-1 text-primary">
                    <Icon name="lucide:check" />
                  </div>
                </div>
                <div
                  v-else-if="file.status === 'error'"
                  class="bg-opacity-70 absolute inset-0 flex items-center justify-center bg-red-700"
                >
                  <div class="p-2 text-center text-primary">
                    <Icon
                      name="lucide:alert-triangle"
                      size="2rem"
                      class="mb-1 h-6 w-6"
                    />
                    <div class="text-xs">{{ file.errorMessage || 'Error' }}</div>
                    <Button
                      size="sm"
                      class="mt-2"
                      @click.stop="retryUpload(file)"
                    >
                      Retry
                    </Button>
                  </div>
                </div>
                <div
                  class="absolute inset-x-2 bottom-2 h-1.5 overflow-hidden rounded-full bg-elevated"
                >
                  <div
                    class="h-full transition-all duration-300 ease-in-out"
                    :class="getProgressColor(file.status)"
                    :style="`width: ${file.progress}%`"
                  />
                </div>
              </div>
              <div class="p-2">
                <div class="truncate font-semibold">
                  {{ file.file.name.split('.').slice(0, -1).join('.') }}
                </div>
                <div class="text-sm text-muted">
                  {{ file.file.name.split('.').pop()?.toUpperCase() }} •
                  {{ formatFileSize(file.file.size) }}
                </div>
              </div>
            </div>
          </div>
        </ScrollArea>
      </div>

      <DialogFooter class="flex items-center justify-between sm:justify-between">
        <Button
          :disabled="hasUploadInProgress"
          @click="onOpenChange(false)"
        >
          Cancel
        </Button>
        <div class="flex items-center gap-2">
          <Button
            :disabled="isUploading"
            @click="handleBrowseClick"
          >
            Add More Files
          </Button>
          <Button
            variant="primary"
            :disabled="files.length === 0 || isUploading"
            @click="handleUpload"
          >
            <Icon
              v-if="isUploading"
              name="lucide:loader"
              class="animate-spin"
            />
            {{
              isUploading ? 'Uploading...' : `Upload ${files.length > 0 ? `(${files.length})` : ''}`
            }}
          </Button>
        </div>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <UploadDetailsDialog
    v-if="selectedFile"
    v-model:open="detailsOpen"
    v-model:file="selectedFile"
    :on-replace="handleReplaceFile"
    :folder-id="folderId"
    :space-id="spaceId"
  />
</template>
