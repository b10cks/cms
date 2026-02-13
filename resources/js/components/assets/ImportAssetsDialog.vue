<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { ScrollArea } from '~/components/ui/scroll-area'
import type { AssetDataImportResult } from '~/types/assets'

const props = defineProps<{
  spaceId: string
}>()
const { useImportAssetsMutation } = useAssets(props.spaceId)

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const open = defineModel<boolean>('open')

const fileInputRef = ref<HTMLInputElement | null>(null)
const isImporting = ref(false)
const errorMessage = ref<string>('')
const importResult = ref<AssetDataImportResult | null>(null)
const selectedFile = ref<File | null>(null)
const expandedAssets = ref<Set<string>>(new Set())

const { mutate: importAssets } = useImportAssetsMutation()

const handleFileChange = (e: Event) => {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    selectedFile.value = target.files[0]
  }
}

const handleImport = async () => {
  if (!selectedFile.value) {
    errorMessage.value = 'Please select a file to import'
    return
  }

  isImporting.value = true
  errorMessage.value = ''
  importResult.value = null

  try {
    importAssets(selectedFile.value, {
      onSuccess: (result) => {
        importResult.value = result
      },
      onError: (error) => {
        errorMessage.value = error instanceof Error ? error.message : 'Import failed'
      },
    })
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Import failed'
  } finally {
    isImporting.value = false
  }
}

const handleClose = () => {
  if (!isImporting) {
    open.value = false
    selectedFile.value = null
    importResult.value = null
    errorMessage.value = ''
    if (fileInputRef.value) {
      fileInputRef.value.value = ''
    }
  }
}

const handleDone = () => {
  open.value = false
  selectedFile.value = null
  importResult.value = null
  errorMessage.value = ''
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
}

const toggleAssetExpanded = (assetId: string) => {
  if (expandedAssets.value.has(assetId)) {
    expandedAssets.value.delete(assetId)
  } else {
    expandedAssets.value.add(assetId)
  }
}

const isAssetExpanded = (assetId: string) => {
  return expandedAssets.value.has(assetId)
}

// Show result summary when import completes
const showSummary = computed(() => importResult.value !== null)
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleClose"
  >
    <DialogContent>
      <!-- File Selection View -->
      <template v-if="!showSummary">
        <DialogHeaderCombined
          title="Import Assets"
          description="Import asset metadata from a file"
        />

        <div class="space-y-4 py-4">
          <div class="rounded-lg border-2 border-dashed border-gray-300 p-6 text-center">
            <input
              ref="fileInputRef"
              type="file"
              accept=".json,.csv,.xlsx,.xlf,.xliff"
              @change="handleFileChange"
              class="hidden"
            />
            <div class="flex flex-col items-center gap-2">
              <Icon
                name="lucide:upload-cloud"
                class="h-8 w-8 text-gray-400"
              />
              <div class="space-y-1">
                <p
                  v-if="!selectedFile"
                  class="text-sm font-medium text-gray-700"
                >
                  <button
                    type="button"
                    class="text-blue-600 hover:underline"
                    @click="fileInputRef?.click()"
                  >
                    Click to upload
                  </button>
                  or drag and drop
                </p>
                <p
                  v-else
                  class="text-sm font-medium text-gray-700"
                >
                  {{ selectedFile.name }}
                </p>
                <p class="text-xs text-gray-500">Supported formats: JSON, CSV, Excel, XLIFF</p>
              </div>
            </div>
          </div>

          <div
            v-if="errorMessage"
            class="rounded bg-red-50 p-3 text-sm text-red-600"
          >
            {{ errorMessage }}
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              @click="handleClose"
              :disabled="isImporting"
            >
              Cancel
            </Button>
            <Button
              type="button"
              @click="handleImport"
              :disabled="!selectedFile || isImporting"
            >
              <Icon
                v-if="isImporting"
                name="lucide:loader-2"
                class="mr-2 animate-spin"
              />
              {{ isImporting ? 'Importing...' : 'Import' }}
            </Button>
          </DialogFooter>
        </div>
      </template>

      <!-- Result Summary View -->
      <template v-else>
        <DialogHeaderCombined
          title="Import Summary"
          description="Review the results of your import"
        />

        <ScrollArea class="h-[400px] w-full pr-4">
          <div class="space-y-6 p-4">
            <!-- Summary Statistics -->
            <div class="grid grid-cols-3 gap-4">
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-gray-600">Successful</div>
                <div class="text-2xl font-bold text-green-600">
                  {{ importResult.summary.total_success }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-gray-600">Changes</div>
                <div class="text-2xl font-bold text-blue-600">
                  {{ importResult.summary.total_changes }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-gray-600">Errors</div>
                <div class="text-2xl font-bold text-red-600">
                  {{ importResult.summary.total_errors }}
                </div>
              </div>
            </div>

            <!-- Assets with Changes -->
            <div
              v-if="importResult.changes.length > 0"
              class="space-y-2"
            >
              <h3 class="font-semibold text-gray-900">Modified Assets</h3>
              <div class="space-y-2">
                <div
                  v-for="asset in importResult.changes"
                  :key="asset.id"
                  class="rounded border"
                >
                  <button
                    type="button"
                    @click="toggleAssetExpanded(asset.id)"
                    class="flex w-full items-center justify-between p-3 hover:bg-gray-50"
                  >
                    <span class="font-medium text-gray-900">{{ asset.filename }}</span>
                    <span class="flex items-center gap-2">
                      <span class="text-xs text-gray-500"
                        >{{ asset.changes.length }} change(s)</span
                      >
                      <Icon
                        :name="
                          isAssetExpanded(asset.id) ? 'lucide:chevron-down' : 'lucide:chevron-right'
                        "
                        class="h-4 w-4"
                      />
                    </span>
                  </button>
                  <div
                    v-if="isAssetExpanded(asset.id)"
                    class="border-t p-3"
                  >
                    <div class="space-y-2 text-sm">
                      <div
                        v-for="(change, idx) in asset.changes"
                        :key="idx"
                        class="rounded bg-gray-50 p-2"
                      >
                        <div class="font-medium text-gray-700">
                          {{ change.field }} ({{ change.language }})
                        </div>
                        <div class="mt-1 space-y-1 text-xs">
                          <div v-if="change.old">
                            <span class="font-medium text-gray-600">Old:</span>
                            <span class="text-gray-600">{{ change.old }}</span>
                          </div>
                          <div>
                            <span class="font-medium text-gray-600">New:</span>
                            <span class="text-green-600">{{ change.new }}</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Ignored Fields -->
            <div
              v-if="importResult.ignored_fields.length > 0"
              class="space-y-2"
            >
              <h3 class="font-semibold text-gray-900">Ignored Fields</h3>
              <div class="rounded bg-yellow-50 p-3">
                <p class="text-sm text-gray-700">
                  The following fields were not imported because they are not configured in space
                  settings:
                </p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <span
                    v-for="field in importResult.ignored_fields"
                    :key="field"
                    class="inline-block rounded bg-yellow-100 px-2 py-1 text-xs text-yellow-800"
                  >
                    {{ field }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Errors -->
            <div
              v-if="importResult.errors.length > 0"
              class="space-y-2"
            >
              <h3 class="font-semibold text-gray-900">Errors</h3>
              <div class="space-y-2">
                <div
                  v-for="(error, idx) in importResult.errors"
                  :key="idx"
                  class="rounded bg-red-50 p-3"
                >
                  <div class="text-sm text-red-800">
                    <span
                      v-if="error.row"
                      class="font-medium"
                      >Row {{ error.row }}:</span
                    >
                    <span
                      v-else-if="error.id"
                      class="font-medium"
                      >Asset {{ error.id }}:</span
                    >
                    {{ error.message }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Success Message -->
            <div
              v-if="importResult.successes.length > 0 && importResult.errors.length === 0"
              class="rounded bg-green-50 p-3"
            >
              <p class="text-sm text-green-800">✓ All assets imported successfully!</p>
            </div>
          </div>
        </ScrollArea>

        <DialogFooter>
          <Button @click="handleDone">Done</Button>
        </DialogFooter>
      </template>
    </DialogContent>
  </Dialog>
</template>
