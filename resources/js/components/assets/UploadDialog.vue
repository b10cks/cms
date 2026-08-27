<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import AssetComplianceIndicator from '~/components/assets/AssetComplianceIndicator.vue'
import Icon from '~/components/Icon.vue'
import { Alert, AlertDescription } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Spinner } from '~/components/ui/spinner'
import { useAssetUploadBatch, type StagedUploadFile } from '~/composables/useAssetUploadBatch'
import { queryKeys } from '~/composables/useQueryClient'
import {
  normalizeFolderSegment,
  readTreeFromFileList,
  readDroppedTree,
  snapshotDropEntries,
  type DroppedFile,
  type DroppedTree,
} from '~/lib/dropped-tree'
import { buildUploadTree } from '~/lib/upload-tree'

import UploadDetailsDialog from './UploadDetailsDialog.vue'
import UploadFileRow from './UploadFileRow.vue'
import UploadTreeItem from './UploadTreeItem.vue'

const { t } = useI18n()
const { formatFileSize } = useFormat()
const { getFileType, getFileIcon } = useFileUtils()
const ulid = useUlid()
const { alert } = useAlertDialog()
const queryClient = useQueryClient()

const props = defineProps<{
  spaceId: string
  folderId?: string
  open: boolean
  initialTree?: DroppedTree | null
  /** Whether dropped folders may be mirrored (requires asset_folders.manage). */
  allowFolderUpload?: boolean
  onUploadComplete?: () => void
}>()

const emit = defineEmits(['update:open'])

/** Matches the server's `filesystems.max_upload_size` default of 500 MB. */
const MAX_UPLOAD_FILE_BYTES = 500 * 1024 * 1024
/** At this many files the grid with previews gives way to the compact list. */
const COMPACT_THRESHOLD = 50
/** Above this many files plus folders the upload asks for a confirmation. */
const LARGE_BATCH_THRESHOLD = 500

const { uploadAsset } = useAssets(props.spaceId)
const { ensureAssetFieldData, getMissingRequiredFields } = useAssetRequirements(props.spaceId)
const {
  enqueue,
  retryItem,
  items: batchItems,
  isRunning: isBatchRunning,
} = useAssetUploadBatch()

const files = ref<StagedUploadFile[]>([])
const treeDirectories = ref<string[]>([])
/** Folder path to folder id for every path `ensure-paths` has already answered. */
const ensuredPaths = ref(new Map<string, string | null>())
const skippedCount = ref(0)
const unreadableFileCount = ref(0)
const unreadableFolderCount = ref(0)
const renamedNames = ref<Array<{ from: string; to: string }>>([])

/**
 * Folder paths the user collapsed. Tracking what was closed rather than what is
 * open keeps folders from a second drop expanded without seeding anything, and
 * it lives only as long as the dialog does.
 */
const collapsedPaths = ref(new Set<string>())

/**
 * Only the batch can retry an item, so the button has to follow the batch
 * rather than the staged copy this dialog holds. The two diverge: a new batch
 * replaces the previous one, and a file this dialog still shows as failed may
 * no longer be in it.
 */
const retryableIds = computed(
  () =>
    new Set(
      batchItems.value
        .filter((item) => item.status === 'error' && !item.permanentError)
        .map((item) => item.id)
    )
)

const isCompact = computed(() => files.value.length >= COMPACT_THRESHOLD)

/** Anything to show at all: an empty-folder drop stages folders and no file. */
const hasStaged = computed(() => files.value.length > 0 || treeDirectories.value.length > 0)

/** A drop that carried folders is shown as a tree, whatever the file count. */
const hasFolders = computed(() => treeDirectories.value.length > 0)

const revokeFilePreviews = (items: StagedUploadFile[]) => {
  for (const file of items) {
    if (file.preview) {
      URL.revokeObjectURL(file.preview)
      file.preview = undefined
    }
  }
}

const clearFiles = () => {
  revokeFilePreviews(files.value)
  files.value = []
  treeDirectories.value = []
  collapsedPaths.value = new Set()
  ensuredPaths.value = new Map()
  skippedCount.value = 0
  unreadableFileCount.value = 0
  unreadableFolderCount.value = 0
  renamedNames.value = []
}

const detailsOpen = ref(false)
const selectedFile = ref<StagedUploadFile | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const isStarting = ref(false)

const hasUploadInProgress = computed(() => {
  return files.value.some((file) => file.status === 'uploading')
})

const isUploading = computed(() => isStarting.value || hasUploadInProgress.value)

/** Exactly what the next Upload click would hand to the batch. */
const stagedForUpload = computed(() =>
  files.value.filter((file) => !file.enqueued && (file.status === 'pending' || file.permanentError))
)

/** Paths `ensure-paths` has not answered yet; re-sending the rest is pure contention. */
const pendingDirectories = computed(() =>
  treeDirectories.value.filter((path) => !ensuredPaths.value.has(path))
)

/**
 * Whether an Upload click would do anything. `isUploading` is not enough: items
 * queued behind another group's lanes are neither starting nor uploading, which
 * would re-enable the button for a click that only re-runs the confirmations
 * and re-posts `ensure-paths`.
 */
const hasWorkToUpload = computed(
  () => stagedForUpload.value.length > 0 || pendingDirectories.value.length > 0
)

const filesWithMissingRequirements = computed(() => {
  return stagedForUpload.value.filter(
    (file) =>
      file.status === 'pending' &&
      getMissingRequiredFields(file, props.folderId ?? file.folder_id ?? null).length > 0
  )
})

const totalSize = computed(() => files.value.reduce((sum, file) => sum + file.file.size, 0))

const mergeRenamed = (renamed: Array<{ from: string; to: string }>) => {
  const merged = new Map(renamedNames.value.map((entry) => [entry.from, entry.to]))

  for (const entry of renamed) {
    merged.set(entry.from, entry.to)
  }

  renamedNames.value = [...merged].map(([from, to]) => ({ from, to }))
}

const predictRenames = (directories: string[]) => {
  const renamed: Array<{ from: string; to: string }> = []

  for (const directory of directories) {
    for (const segment of directory.split('/')) {
      const normalized = normalizeFolderSegment(segment)

      if (normalized !== segment) {
        renamed.push({ from: segment, to: normalized })
      }
    }
  }

  mergeRenamed(renamed)
}

const addFiles = (dropped: DroppedFile[]) => {
  const compact = files.value.length + dropped.length >= COMPACT_THRESHOLD

  const next = dropped.map(({ file, path }): StagedUploadFile => {
    const fileType = getFileType(file.type)
    const oversize = file.size > MAX_UPLOAD_FILE_BYTES

    return {
      id: ulid(),
      file,
      // Compact mode exists to avoid decoding hundreds of thumbnails, so no
      // object URLs are created once the list is headed there.
      preview:
        !compact && fileType === 'image' ? URL.createObjectURL(file) : undefined,
      data: {},
      metadata: {},
      folder_id: props.folderId,
      folderPath: path,
      tags: [],
      type: fileType,
      progress: 0,
      status: oversize ? 'error' : 'pending',
      errorMessage: oversize
        ? String(
            t('labels.assets.fileTooLarge', {
              size: formatFileSize(MAX_UPLOAD_FILE_BYTES),
            })
          )
        : undefined,
      permanentError: oversize || undefined,
    }
  })

  next.forEach((file) => ensureAssetFieldData(file))
  files.value = [...files.value, ...next]
}

const ingestTree = (tree: DroppedTree) => {
  treeDirectories.value = [...new Set([...treeDirectories.value, ...tree.directories])]
  skippedCount.value += tree.skipped
  unreadableFileCount.value += tree.unreadableFiles
  unreadableFolderCount.value += tree.unreadableDirectories
  predictRenames(tree.directories)
  addFiles(tree.files)
}

watch(
  () => props.initialTree,
  (tree) => {
    if (tree && (tree.files.length || tree.directories.length)) {
      ingestTree(tree)
    }
  },
  { immediate: true }
)

watch(isCompact, (compact) => {
  if (compact) {
    revokeFilePreviews(files.value)
  }
})

/**
 * The dropped structure, folders and files nested as they were dropped. Nodes
 * hold the very same staged objects, so a row follows its file's status.
 */
const uploadTree = computed(() => buildUploadTree(files.value, treeDirectories.value))

const toggleFolder = (path: string) => {
  if (collapsedPaths.value.has(path)) {
    collapsedPaths.value.delete(path)
  } else {
    collapsedPaths.value.add(path)
  }
}

const handleFileChange = (e: Event) => {
  const target = e.target as HTMLInputElement

  if (target.files && target.files.length > 0) {
    ingestTree(readTreeFromFileList(target.files))
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

const openFileDetails = (file: StagedUploadFile) => {
  selectedFile.value = {
    ...file,
    data: structuredClone(file.data || {}),
    metadata: structuredClone(file.metadata || {}),
    tags: [...(file.tags || [])],
  }
  detailsOpen.value = true
}

// Mutates the staged object in place: the batch holds a reference to the very
// same object once enqueued, so replacing it would split the two views.
const handleFileDetailsSave = (file: UploadFile) => {
  const currentFile = selectedFile.value
  if (!currentFile) {
    return
  }

  const existing = files.value.find((stagedFile) => stagedFile.id === currentFile.id)
  if (!existing) {
    return
  }

  existing.file = file.file
  existing.preview = file.preview
  existing.type = file.type
  existing.data = file.data
  existing.metadata = file.metadata
  existing.tags = file.tags
  existing.folder_id = file.folder_id

  selectedFile.value = existing
}

const statusLabel = (file: StagedUploadFile) => {
  if (file.errorMessage) {
    return file.errorMessage
  }

  return file.cancelled
    ? String(t('labels.assets.batch.cancelled'))
    : String(t('labels.assets.unknown'))
}

const handleUpload = async () => {
  if (isStarting.value || !hasWorkToUpload.value) {
    return
  }

  const toEnqueue = stagedForUpload.value

  if (filesWithMissingRequirements.value.length) {
    const count = filesWithMissingRequirements.value.length
    const confirmed = await alert.confirm(
      `${t('labels.assets.uploadRequirementsSummary', { count })} ${t('messages.assets.uploadRequirementsConfirm')}`,
      {
        title: String(t('labels.assets.uploadRequirementsTitle')),
        confirmLabel: String(t('actions.continue')),
      }
    )

    if (!confirmed) {
      return
    }
  }

  if (toEnqueue.length + pendingDirectories.value.length > LARGE_BATCH_THRESHOLD) {
    const confirmed = await alert.confirm(
      String(
        pendingDirectories.value.length
          ? t('messages.assets.largeBatchWithFoldersConfirm', {
              files: toEnqueue.length,
              folders: pendingDirectories.value.length,
            })
          : t('messages.assets.largeBatchConfirm', { count: toEnqueue.length })
      ),
      {
        title: String(t('labels.assets.uploadAssets')),
        confirmLabel: String(t('actions.continue')),
      }
    )

    if (!confirmed) {
      return
    }
  }

  isStarting.value = true

  try {
    // Only the paths the server has not answered yet: a second click must not
    // take the per-space lock again just to be told the same folder ids.
    if (pendingDirectories.value.length) {
      const result = await api.forSpace(props.spaceId).assetFolders.ensurePaths({
        parent_id: props.folderId ?? null,
        paths: [...pendingDirectories.value],
      })

      mergeRenamed(result.renamed)

      for (const [path, folderId] of Object.entries(result.paths)) {
        ensuredPaths.value.set(path, folderId)
      }

      queryClient.invalidateQueries({
        queryKey: queryKeys.assetFolders(props.spaceId).lists(),
      })
    }

    for (const file of toEnqueue) {
      if (!file.folderPath || file.status !== 'pending') {
        continue
      }

      const folderId = ensuredPaths.value.get(file.folderPath)

      if (folderId) {
        file.folder_id = folderId
      } else {
        file.status = 'error'
        file.errorMessage = String(t('labels.assets.batch.folderUnavailable'))
        file.permanentError = true
      }
    }
  } catch (error) {
    toast.error(
      String(
        t('messages.assets.folderCreateFailed', {
          error: error instanceof Error ? error.message : 'Unknown error',
        })
      )
    )
    isStarting.value = false
    return
  }

  toEnqueue.forEach((file) => {
    file.enqueued = true
  })

  if (toEnqueue.length) {
    enqueue(toEnqueue, {
      upload: (payload, onProgress, options) =>
        uploadAsset(payload, onProgress, { ...options, silent: true }),
      onSettled: () => {
        queryClient.invalidateQueries({
          queryKey: queryKeys.assets(props.spaceId).lists(),
        })
      },
    })
  } else if (treeDirectories.value.length && !files.value.length) {
    // A drop of empty folders only: the tree is mirrored, nothing to upload.
    toast.success(String(t('messages.assets.foldersCreated')))
    props.onUploadComplete?.()
    emit('update:open', false)
    clearFiles()
  }

  isStarting.value = false
}

watch(
  () =>
    files.value.length > 0 &&
    !isStarting.value &&
    files.value.every((file) => file.status === 'complete'),
  (allComplete) => {
    if (!allComplete) {
      return
    }

    props.onUploadComplete?.()
    emit('update:open', false)
    clearFiles()
  }
)

const handleBrowseClick = () => {
  if (fileInputRef.value) {
    fileInputRef.value.click()
  }
}

// Closing while a batch runs is fine: the docked panel keeps showing it.
const onOpenChange = (open: boolean) => {
  if (!open) {
    selectedFile.value = null
    clearFiles()
  }
  emit('update:open', open)
}

onBeforeUnmount(() => {
  revokeFilePreviews(files.value)
})

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
      if (fileType === 'image' && !isCompact.value) {
        preview = URL.createObjectURL(newFile)
      }

      if (selectedFile.value.preview) {
        URL.revokeObjectURL(selectedFile.value.preview)
      }

      const existing = files.value.find((file) => file.id === selectedFile.value?.id)

      if (existing) {
        existing.file = newFile
        existing.preview = preview
        existing.type = fileType
        existing.progress = 0
        existing.status = 'pending'
        existing.errorMessage = undefined
        existing.permanentError = undefined
      }

      selectedFile.value = {
        ...selectedFile.value,
        file: newFile,
        preview,
        type: fileType,
        progress: 0,
        status: 'pending',
        errorMessage: undefined,
      }
    }
  }
  specificFileInput.click()
}

const handleDrop = (e: DragEvent) => {
  e.preventDefault()
  e.stopPropagation()

  if (!e.dataTransfer) {
    return
  }

  const snapshot = snapshotDropEntries(e.dataTransfer)

  readDroppedTree(snapshot)
    .then((tree) => {
      if (tree.directories.length && !props.allowFolderUpload) {
        toast.error(String(t('messages.assets.folderDropDenied')))
        return
      }

      if (tree.files.length || tree.directories.length) {
        ingestTree(tree)
      }
    })
    .catch((error: unknown) => {
      toast.error(
        String(
          t('messages.assets.dropReadFailed', {
            error: error instanceof Error ? error.message : 'Unknown error',
          })
        )
      )
    })
}

const handleDragOver = (e: DragEvent) => {
  e.preventDefault()
  e.stopPropagation()
}

const getProgressColor = (status: StagedUploadFile['status']) => {
  switch (status) {
    case 'uploading':
      return 'bg-accent'
    case 'complete':
      return 'bg-green-500'
    case 'error':
      return 'bg-destructive'
    default:
      return 'bg-muted-background'
  }
}

</script>

<template>
  <Dialog
    :open="open"
    @update:open="onOpenChange"
  >
    <DialogContent class="sm:max-w-2xl">
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

        <Alert
          v-if="filesWithMissingRequirements.length"
          icon="lucide:triangle-alert"
          color="warning"
          class="mb-4"
        >
          <AlertDescription>
            {{
              $t('labels.assets.uploadRequirementsSummary', {
                count: filesWithMissingRequirements.length,
              })
            }}
          </AlertDescription>
        </Alert>

        <Alert
          v-if="renamedNames.length"
          icon="lucide:folder-pen"
          color="info"
          class="mb-4"
        >
          <AlertDescription>
            {{
              $t('labels.assets.batch.renamedFolders', {
                names: renamedNames.map((entry) => `"${entry.from}" → "${entry.to}"`).join(', '),
              })
            }}
          </AlertDescription>
        </Alert>

        <div
          v-if="hasStaged"
          class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted"
        >
          <span v-if="files.length">
            {{ $t('labels.assets.batch.summaryFiles', { count: files.length }) }}
          </span>
          <span v-if="files.length">{{ formatFileSize(totalSize) }}</span>
          <span v-if="treeDirectories.length">
            {{ $t('labels.assets.batch.summaryFolders', { count: treeDirectories.length }) }}
          </span>
          <span v-if="skippedCount">
            {{ $t('labels.assets.batch.summarySkipped', { count: skippedCount }) }}
          </span>
          <span
            v-if="unreadableFileCount"
            class="text-destructive"
          >
            {{ $t('labels.assets.batch.summaryUnreadableFiles', { count: unreadableFileCount }) }}
          </span>
          <span
            v-if="unreadableFolderCount"
            class="text-destructive"
          >
            {{
              $t('labels.assets.batch.summaryUnreadableFolders', { count: unreadableFolderCount })
            }}
          </span>
        </div>

        <div
          v-if="!hasStaged"
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
          v-else-if="hasFolders"
          class="h-64 rounded-xl bg-surface"
          @dragover="handleDragOver"
          @drop="handleDrop"
        >
          <div class="flex flex-col p-2">
            <UploadTreeItem
              v-for="node in uploadTree.folders"
              :key="node.path"
              :node="node"
              :level="0"
              :collapsed="collapsedPaths"
              :show-previews="!isCompact"
              :allow-details="!isCompact"
              :retryable-ids="retryableIds"
              :is-batch-running="isBatchRunning"
              :missing-fields="getMissingRequiredFields"
              @toggle="toggleFolder"
              @remove="removeFile"
              @details="openFileDetails"
              @retry="retryItem"
            />
            <UploadFileRow
              v-for="file in uploadTree.files"
              :key="file.id"
              :file="file"
              :level="0"
              :show-preview="!isCompact"
              :allow-details="!isCompact"
              :can-retry="!isBatchRunning && retryableIds.has(file.id)"
              :missing-fields="getMissingRequiredFields"
              @remove="removeFile"
              @details="openFileDetails"
              @retry="retryItem"
            />
          </div>
        </ScrollArea>

        <ScrollArea
          v-else-if="!isCompact"
          class="h-64 rounded-xl bg-surface"
          @dragover="handleDragOver"
          @drop="handleDrop"
        >
          <div class="grid grid-cols-2 gap-4 p-4 sm:grid-cols-3 md:grid-cols-4">
            <div
              v-for="file in files"
              :key="file.id"
              role="button"
              tabindex="0"
              class="group relative cursor-pointer rounded-lg bg-background shadow-lg transition-all hover:-translate-y-2 hover:bg-input focus:-translate-y-2 focus:bg-input"
              @click="openFileDetails(file)"
              @keydown.enter.prevent="openFileDetails(file)"
              @keydown.space.prevent="openFileDetails(file)"
            >
              <button
                v-if="file.status !== 'uploading' && !file.enqueued"
                class="absolute top-1 right-1 z-10 flex h-6 w-6 cursor-pointer items-center justify-center text-primary/50 hover:text-destructive"
                :aria-label="$t('actions.remove')"
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

                <div
                  v-if="file.status === 'uploading'"
                  class="absolute inset-0 flex items-center justify-center bg-surface/50"
                >
                  <div class="p-2 text-center text-primary">
                    <div class="mb-2 flex items-center justify-center">
                      <Spinner class="size-6" />
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
                  class="bg-opacity-70 absolute inset-0 flex items-center justify-center bg-destructive-background"
                >
                  <div class="p-2 text-center text-primary">
                    <Icon
                      name="lucide:alert-triangle"
                      size="2rem"
                      class="mb-1 h-6 w-6"
                    />
                    <div class="text-xs">
                      {{ statusLabel(file) }}
                    </div>
                    <Button
                      v-if="!isBatchRunning && retryableIds.has(file.id)"
                      size="sm"
                      class="mt-2"
                      @click.stop="retryItem(file.id)"
                    >
                      {{ $t('actions.retry') }}
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
                <div class="flex items-center gap-2 truncate font-semibold">
                  <span class="truncate">{{
                    file.file.name.split('.').slice(0, -1).join('.')
                  }}</span>
                  <AssetComplianceIndicator
                    :issues="getMissingRequiredFields(file)"
                    severity="warning"
                  />
                </div>
                <div class="text-sm text-muted">
                  {{ file.file.name.split('.').pop()?.toUpperCase() }} •
                  {{ formatFileSize(file.file.size) }}
                </div>
              </div>
            </div>
          </div>
        </ScrollArea>

        <ScrollArea
          v-else
          class="h-64 rounded-xl bg-surface"
          @dragover="handleDragOver"
          @drop="handleDrop"
        >
          <div class="flex flex-col p-2">
            <div class="mb-1 flex items-center gap-2 px-2 text-sm font-semibold">
              <Icon
                name="lucide:folder"
                class="shrink-0 text-muted"
                aria-hidden="true"
              />
              <span class="min-w-0 flex-1 truncate">
                {{ $t('labels.assets.batch.currentFolder') }}
              </span>
              <span class="shrink-0 text-xs text-muted">{{ uploadTree.fileCount }}</span>
            </div>
            <UploadFileRow
              v-for="file in uploadTree.files"
              :key="file.id"
              :file="file"
              :level="0"
              :show-preview="false"
              :allow-details="false"
              :can-retry="!isBatchRunning && retryableIds.has(file.id)"
              :missing-fields="getMissingRequiredFields"
              @remove="removeFile"
              @retry="retryItem"
            />
          </div>
        </ScrollArea>
      </div>

      <DialogFooter class="flex items-center justify-between sm:justify-between">
        <Button @click="onOpenChange(false)">
          {{ $t('alertDialog.cancel') }}
        </Button>
        <div class="flex items-center gap-2">
          <Button
            :disabled="isUploading"
            @click="handleBrowseClick"
          >
            {{ $t('labels.assets.addMoreFiles') }}
          </Button>
          <Button
            variant="primary"
            :loading="isUploading"
            :disabled="isUploading || !hasWorkToUpload"
            @click="handleUpload"
          >
            {{
              isUploading
                ? $t('labels.assets.uploading')
                : `${$t('actions.assets.upload')} ${stagedForUpload.length > 0 ? `(${stagedForUpload.length})` : ''}`
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
    @update:file="handleFileDetailsSave"
  />
</template>
