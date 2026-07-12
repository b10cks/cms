<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import FileDropZone from '~/components/ui/FileDropZone.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import type { DataEntryImportMode, DataEntryImportResult } from '~/types/data-sources'

const props = defineProps<{
  spaceId: string
  dataSourceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportDataEntriesMutation } = useDataEntries(props.spaceId, props.dataSourceId)

const selectedFile = ref<File | null>(null)
const importMode = ref<DataEntryImportMode>('addition')
const errorMessage = ref('')
const importResult = ref<DataEntryImportResult | null>(null)
const expandedEntries = ref<Set<string>>(new Set())

const importMutation = useImportDataEntriesMutation()

const handleImport = async () => {
  if (!selectedFile.value) {
    errorMessage.value = t('labels.dataEntries.importDialog.selectFileError') as string
    return
  }

  errorMessage.value = ''
  importResult.value = null

  try {
    importResult.value = await importMutation.mutateAsync({
      file: selectedFile.value,
      mode: importMode.value,
    })
  } catch (error) {
    errorMessage.value =
      error instanceof Error
        ? error.message
        : (t('composables.dataEntries.importError', { error: '' }) as string)
  }
}

const resetState = () => {
  selectedFile.value = null
  importMode.value = 'addition'
  errorMessage.value = ''
  importResult.value = null
  expandedEntries.value.clear()
}

const handleOpenChange = (value: boolean) => {
  open.value = value

  if (!value && !importMutation.isPending.value) {
    resetState()
  }
}

const toggleExpanded = (entryId: string) => {
  if (expandedEntries.value.has(entryId)) {
    expandedEntries.value.delete(entryId)
    return
  }
  expandedEntries.value.add(entryId)
}

const isExpanded = (entryId: string) => expandedEntries.value.has(entryId)

const showSummary = computed(() => importResult.value !== null)
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="max-w-3xl">
      <template v-if="!showSummary">
        <DialogHeaderCombined
          :title="$t('labels.dataEntries.importDialog.title')"
          :description="$t('labels.dataEntries.importDialog.description')"
        />

        <div class="space-y-4 py-4">
          <FileDropZone
            v-model="selectedFile"
            accept=".json,.csv,.xlsx,.xls,.yaml,.yml"
            :hint="$t('labels.dataEntries.importDialog.supportedFormats')"
            @error="errorMessage = $event"
          />

          <div class="space-y-2">
            <p class="text-sm font-medium text-foreground">
              {{ $t('labels.dataEntries.importDialog.importMode.label') }}
            </p>
            <div class="grid grid-cols-2 gap-2">
              <button
                type="button"
                :class="[
                  'rounded-lg border p-3 text-left transition-colors',
                  importMode === 'addition'
                    ? 'border-primary bg-primary/5 text-foreground'
                    : 'border-border text-muted-foreground hover:border-muted-foreground',
                ]"
                @click="importMode = 'addition'"
              >
                <div class="flex items-center gap-2">
                  <Icon
                    name="lucide:plus-circle"
                    class="h-4 w-4 shrink-0"
                  />
                  <span class="text-sm font-medium">
                    {{ $t('labels.dataEntries.importDialog.importMode.addition') }}
                  </span>
                </div>
                <p class="mt-1 text-xs">
                  {{ $t('labels.dataEntries.importDialog.importMode.additionDescription') }}
                </p>
              </button>
              <button
                type="button"
                :class="[
                  'rounded-lg border p-3 text-left transition-colors',
                  importMode === 'replacement'
                    ? 'border-primary bg-primary/5 text-foreground'
                    : 'border-border text-muted-foreground hover:border-muted-foreground',
                ]"
                @click="importMode = 'replacement'"
              >
                <div class="flex items-center gap-2">
                  <Icon
                    name="lucide:replace"
                    class="h-4 w-4 shrink-0"
                  />
                  <span class="text-sm font-medium">
                    {{ $t('labels.dataEntries.importDialog.importMode.replacement') }}
                  </span>
                </div>
                <p class="mt-1 text-xs">
                  {{ $t('labels.dataEntries.importDialog.importMode.replacementDescription') }}
                </p>
              </button>
            </div>
            <p
              v-if="importMode === 'replacement'"
              class="rounded-md bg-destructive/10 p-2 text-xs text-destructive"
            >
              {{ $t('labels.dataEntries.importDialog.importMode.replacementWarning') }}
            </p>
          </div>

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
              :loading="importMutation.isPending.value"
              :disabled="!selectedFile"
              @click="handleImport"
            >
              {{
                importMutation.isPending.value ? $t('labels.loading') : $t('labels.assets.import')
              }}
            </Button>
          </DialogFooter>
        </div>
      </template>

      <template v-else>
        <DialogHeaderCombined
          :title="$t('labels.dataEntries.importDialog.summaryTitle')"
          :description="$t('labels.dataEntries.importDialog.summaryDescription')"
        />

        <ScrollArea class="h-[28rem] w-full pr-4">
          <div
            v-if="importResult"
            class="space-y-6 p-1"
          >
            <div
              :class="[
                'grid gap-4',
                importResult.summary.total_deleted > 0 ? 'md:grid-cols-4' : 'md:grid-cols-3',
              ]"
            >
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.dataEntries.importDialog.summary.success') }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_success }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.dataEntries.importDialog.summary.changes') }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_changes }}
                </div>
              </div>
              <div
                v-if="importResult.summary.total_deleted > 0"
                class="rounded-lg border p-4"
              >
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.dataEntries.importDialog.summary.deleted') }}
                </div>
                <div class="text-2xl font-semibold text-destructive">
                  {{ importResult.summary.total_deleted }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.dataEntries.importDialog.summary.errors') }}
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
                {{ $t('labels.dataEntries.importDialog.modifiedEntries') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="entry in importResult.changes"
                  :key="entry.id"
                  class="rounded-lg border"
                >
                  <button
                    type="button"
                    class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/40"
                    @click="toggleExpanded(entry.id)"
                  >
                    <span class="font-medium text-foreground">{{ entry.key }}</span>
                    <span class="flex items-center gap-2 text-xs text-muted-foreground">
                      {{
                        $t('labels.dataEntries.importDialog.changeCount', {
                          count: entry.changes.length,
                        })
                      }}
                      <Icon
                        :name="
                          isExpanded(entry.id) ? 'lucide:chevron-down' : 'lucide:chevron-right'
                        "
                        class="h-4 w-4"
                      />
                    </span>
                  </button>
                  <div
                    v-if="isExpanded(entry.id)"
                    class="border-t p-3"
                  >
                    <div class="space-y-2 text-sm">
                      <div
                        v-for="(change, index) in entry.changes"
                        :key="`${entry.id}-${index}`"
                        class="rounded-md bg-muted/50 p-2"
                      >
                        <div class="font-medium text-foreground">{{ change.field }}</div>
                        <div class="text-muted-foreground">
                          {{ change.old ?? $t('labels.dataEntries.importDialog.emptyValue') }}
                          ->
                          {{ change.new ?? $t('labels.dataEntries.importDialog.emptyValue') }}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="importResult.deleted.length > 0"
              class="space-y-2"
            >
              <h3 class="font-medium text-foreground">
                {{ $t('labels.dataEntries.importDialog.deletedEntries') }}
              </h3>
              <div class="flex flex-col gap-1">
                <div
                  v-for="entry in importResult.deleted"
                  :key="entry.id"
                  class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                  {{ entry.key }}
                </div>
              </div>
            </div>

            <div
              v-if="importResult.ignored_fields.length > 0"
              class="space-y-2"
            >
              <h3 class="font-medium text-foreground">
                {{ $t('labels.dataEntries.importDialog.ignoredFields') }}
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
                {{ $t('labels.dataEntries.importDialog.errorsTitle') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="(error, index) in importResult.errors"
                  :key="`${index}-${error.message}`"
                  class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
                >
                  <div class="font-medium">
                    {{
                      error.id ||
                      $t('labels.dataEntries.importDialog.rowLabel', {
                        row: error.row ?? index + 1,
                      })
                    }}
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
