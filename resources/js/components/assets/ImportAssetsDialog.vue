<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import FileDropZone from '~/components/ui/FileDropZone.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import type { AssetDataImportResult } from '~/types/assets'

const props = defineProps<{
  spaceId: string
}>()

const open = defineModel<boolean>('open')

const { t } = useI18n()
const { useImportAssetsMutation } = useAssets(props.spaceId)

const selectedFile = ref<File | null>(null)
const errorMessage = ref('')
const importResult = ref<AssetDataImportResult | null>(null)
const expandedAssets = ref<Set<string>>(new Set())

const importMutation = useImportAssetsMutation()

const handleImport = async () => {
  if (!selectedFile.value) {
    errorMessage.value = t('labels.assets.importDialog.selectFileError') as string
    return
  }

  errorMessage.value = ''
  importResult.value = null

  try {
    importResult.value = await importMutation.mutateAsync(selectedFile.value)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : t('labels.assets.importDialog.importError') as string
  }
}

const resetState = () => {
  selectedFile.value = null
  errorMessage.value = ''
  importResult.value = null
  expandedAssets.value.clear()
}

const handleOpenChange = (value: boolean) => {
  open.value = value

  if (!value && !importMutation.isPending.value) {
    resetState()
  }
}

const toggleExpanded = (assetId: string) => {
  if (expandedAssets.value.has(assetId)) {
    expandedAssets.value.delete(assetId)
    return
  }

  expandedAssets.value.add(assetId)
}

const isExpanded = (assetId: string) => expandedAssets.value.has(assetId)

const showSummary = computed(() => importResult.value !== null)
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent>
      <template v-if="!showSummary">
        <DialogHeaderCombined
          :title="$t('labels.assets.importDialog.title')"
          :description="$t('labels.assets.importDialog.description')"
        />

        <div class="space-y-4 py-4">
          <FileDropZone
            v-model="selectedFile"
            accept=".json,.csv,.xlsx,.xls,.xlf,.xliff,.yaml,.yml"
            :hint="$t('labels.assets.importDialog.supportedFormats')"
            @error="errorMessage = $event"
          />

          <div
            v-if="errorMessage"
            class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
          >
            {{ errorMessage }}
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              :disabled="importMutation.isPending.value"
              @click="handleOpenChange(false)"
            >
              {{ $t('alertDialog.cancel') }}
            </Button>
            <Button
              type="button"
              :disabled="!selectedFile || importMutation.isPending.value"
              @click="handleImport"
            >
              <Icon
                v-if="importMutation.isPending.value"
                name="lucide:loader-2"
                class="animate-spin"
              />
              {{ importMutation.isPending.value ? $t('labels.loading') : $t('labels.assets.import') }}
            </Button>
          </DialogFooter>
        </div>
      </template>

      <template v-else>
        <DialogHeaderCombined
          :title="$t('labels.assets.importDialog.summaryTitle')"
          :description="$t('labels.assets.importDialog.summaryDescription')"
        />

        <ScrollArea class="h-[28rem] w-full pr-4">
          <div
            v-if="importResult"
            class="space-y-6 p-1"
          >
            <div class="grid gap-4 md:grid-cols-3">
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.assets.importDialog.summary.success') }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_success }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.assets.importDialog.summary.changes') }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_changes }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.assets.importDialog.summary.errors') }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_errors }}
                </div>
              </div>
            </div>

            <div
              v-if="importResult.changes.length > 0"
              class="space-y-2"
            >
              <h3 class="font-medium text-foreground">
                {{ $t('labels.assets.importDialog.modifiedAssets') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="asset in importResult.changes"
                  :key="asset.id"
                  class="rounded-lg border"
                >
                  <button
                    type="button"
                    class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/40"
                    @click="toggleExpanded(asset.id)"
                  >
                    <span class="font-medium text-foreground">{{ asset.filename }}</span>
                    <span class="flex items-center gap-2 text-xs text-muted-foreground">
                      {{ $t('labels.assets.importDialog.changeCount', { count: asset.changes.length }) }}
                      <Icon
                        :name="isExpanded(asset.id) ? 'lucide:chevron-down' : 'lucide:chevron-right'"
                        class="h-4 w-4"
                      />
                    </span>
                  </button>
                  <div
                    v-if="isExpanded(asset.id)"
                    class="border-t p-3"
                  >
                    <div class="space-y-2 text-sm">
                      <div
                        v-for="(change, index) in asset.changes"
                        :key="`${asset.id}-${index}`"
                        class="rounded-md bg-muted/50 p-2"
                      >
                        <div class="font-medium text-foreground">
                          {{ change.field }}
                          <span
                            v-if="change.language"
                            class="font-normal text-muted-foreground"
                          >({{ change.language }})</span>
                        </div>
                        <div class="text-muted-foreground">
                          {{ change.old ?? $t('labels.assets.importDialog.emptyValue') }}
                          ->
                          {{ change.new ?? $t('labels.assets.importDialog.emptyValue') }}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="importResult.ignored_fields.length > 0"
              class="space-y-2"
            >
              <h3 class="font-medium text-foreground">
                {{ $t('labels.assets.importDialog.ignoredFields') }}
              </h3>
              <div class="flex flex-wrap gap-2">
                <span
                  v-for="field in importResult.ignored_fields"
                  :key="field"
                  class="rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground"
                >
                  {{ field }}
                </span>
              </div>
            </div>

            <div
              v-if="importResult.errors.length > 0"
              class="space-y-2"
            >
              <h3 class="font-medium text-foreground">
                {{ $t('labels.assets.importDialog.errorsTitle') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="(error, index) in importResult.errors"
                  :key="`${index}-${error.message}`"
                  class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
                >
                  <div class="font-medium">
                    {{ error.id || $t('labels.assets.importDialog.rowLabel', { row: error.row ?? index + 1 }) }}
                  </div>
                  <div>{{ error.message }}</div>
                </div>
              </div>
            </div>
          </div>
        </ScrollArea>

        <DialogFooter>
          <Button
            type="button"
            @click="handleOpenChange(false)"
          >
            {{ $t('actions.close') }}
          </Button>
        </DialogFooter>
      </template>
    </DialogContent>
  </Dialog>
</template>
