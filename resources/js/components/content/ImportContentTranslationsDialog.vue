<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import FileDropZone from '~/components/ui/FileDropZone.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Switch } from '~/components/ui/switch'
import type {
  ContentTranslationImportMode,
  ContentTranslationImportResult,
} from '~/types/content-translations'

const props = defineProps<{
  spaceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportContentTranslationsMutation } = useContent(props.spaceId)
const importMutation = useImportContentTranslationsMutation()

const selectedFile = ref<File | null>(null)
const importMode = ref<ContentTranslationImportMode>('draft')
const createMissing = ref(false)
const errorMessage = ref('')
const importResult = ref<ContentTranslationImportResult | null>(null)
const expanded = ref<Set<string>>(new Set())

const showSummary = computed(() => importResult.value !== null)

const rowKey = (contentId: string, language: string) => `${contentId}:${language}`

const handleImport = async () => {
  if (!selectedFile.value) {
    errorMessage.value = t('labels.contents.translationImport.selectFileError') as string
    return
  }

  errorMessage.value = ''
  importResult.value = null

  try {
    importResult.value = await importMutation.mutateAsync({
      file: selectedFile.value,
      mode: importMode.value,
      createMissing: createMissing.value,
    })
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Import failed'
  }
}

const resetState = () => {
  selectedFile.value = null
  importMode.value = 'draft'
  createMissing.value = false
  errorMessage.value = ''
  importResult.value = null
  expanded.value.clear()
}

const handleOpenChange = (value: boolean) => {
  open.value = value

  if (!value && !importMutation.isPending.value) {
    resetState()
  }
}

const toggleExpanded = (key: string) => {
  if (expanded.value.has(key)) {
    expanded.value.delete(key)
    return
  }
  expanded.value.add(key)
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogContent class="max-w-3xl">
      <template v-if="!showSummary">
        <DialogHeaderCombined
          :title="t('labels.contents.translationImport.title')"
          :description="t('labels.contents.translationImport.description')"
        />

        <div class="space-y-4 py-4">
          <FileDropZone
            v-model="selectedFile"
            accept=".xlf,.xliff,.xml,.csv,.xlsx,.xls,.json,.yaml,.yml"
            :hint="t('labels.contents.translationImport.supportedFormats')"
            @error="errorMessage = $event"
          />

          <div class="space-y-2">
            <p class="text-sm font-medium text-foreground">
              {{ t('labels.contents.translationImport.mode.label') }}
            </p>
            <div class="grid grid-cols-2 gap-2">
              <button
                type="button"
                :class="[
                  'rounded-lg border p-3 text-left transition-colors',
                  importMode === 'draft'
                    ? 'border-primary bg-primary/5 text-foreground'
                    : 'border-border text-muted-foreground hover:border-muted-foreground',
                ]"
                @click="importMode = 'draft'"
              >
                <div class="flex items-center gap-2">
                  <Icon
                    name="lucide:file-pen-line"
                    class="h-4 w-4 shrink-0"
                  />
                  <span class="text-sm font-medium">
                    {{ t('labels.contents.translationImport.mode.draft') }}
                  </span>
                </div>
                <p class="mt-1 text-xs">
                  {{ t('labels.contents.translationImport.mode.draftDescription') }}
                </p>
              </button>
              <button
                type="button"
                :class="[
                  'rounded-lg border p-3 text-left transition-colors',
                  importMode === 'publish'
                    ? 'border-primary bg-primary/5 text-foreground'
                    : 'border-border text-muted-foreground hover:border-muted-foreground',
                ]"
                @click="importMode = 'publish'"
              >
                <div class="flex items-center gap-2">
                  <Icon
                    name="lucide:globe"
                    class="h-4 w-4 shrink-0"
                  />
                  <span class="text-sm font-medium">
                    {{ t('labels.contents.translationImport.mode.publish') }}
                  </span>
                </div>
                <p class="mt-1 text-xs">
                  {{ t('labels.contents.translationImport.mode.publishDescription') }}
                </p>
              </button>
            </div>
          </div>

          <label class="flex items-start gap-3 rounded-lg border p-3">
            <Switch v-model="createMissing" />
            <span class="space-y-1">
              <span class="block text-sm font-medium text-foreground">
                {{ t('labels.contents.translationImport.createMissing.label') }}
              </span>
              <span class="block text-xs text-muted-foreground">
                {{ t('labels.contents.translationImport.createMissing.description') }}
              </span>
            </span>
          </label>

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
              {{ t('alertDialog.cancel') }}
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
              {{ importMutation.isPending.value ? t('labels.loading') : t('labels.contents.translationImport.submit') }}
            </Button>
          </DialogFooter>
        </div>
      </template>

      <template v-else>
        <DialogHeaderCombined
          :title="t('labels.contents.translationImport.summaryTitle')"
          :description="t('labels.contents.translationImport.summaryDescription')"
        />

        <ScrollArea class="h-[28rem] w-full pr-4">
          <div
            v-if="importResult"
            class="space-y-6 p-1"
          >
            <div class="grid gap-4 md:grid-cols-3">
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ t('labels.contents.translationImport.summary.success') }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_success }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ t('labels.contents.translationImport.summary.changes') }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_changes }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ t('labels.contents.translationImport.summary.errors') }}
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
                {{ t('labels.contents.translationImport.modifiedContent') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="entry in importResult.changes"
                  :key="rowKey(entry.content_id, entry.language)"
                  class="rounded-lg border"
                >
                  <button
                    type="button"
                    class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/40"
                    @click="toggleExpanded(rowKey(entry.content_id, entry.language))"
                  >
                    <span class="font-medium text-foreground">
                      {{ entry.name || entry.content_id }}
                      <span class="ml-2 rounded bg-muted px-1.5 py-0.5 text-xs uppercase text-muted-foreground">
                        {{ entry.language }}
                      </span>
                    </span>
                    <span class="flex items-center gap-2 text-xs text-muted-foreground">
                      {{ t('labels.contents.translationImport.changeCount', { count: entry.changes.length }) }}
                      <Icon
                        :name="
                          expanded.has(rowKey(entry.content_id, entry.language))
                            ? 'lucide:chevron-down'
                            : 'lucide:chevron-right'
                        "
                        class="h-4 w-4"
                      />
                    </span>
                  </button>
                  <div
                    v-if="expanded.has(rowKey(entry.content_id, entry.language))"
                    class="border-t p-3"
                  >
                    <div class="flex flex-wrap gap-2">
                      <span
                        v-for="(change, index) in entry.changes"
                        :key="`${entry.content_id}-${entry.language}-${index}`"
                        class="rounded-md bg-muted/50 px-2 py-1 text-xs text-foreground"
                      >
                        {{ change.label }}
                      </span>
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
                {{ t('labels.contents.translationImport.ignoredFields') }}
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
                {{ t('labels.contents.translationImport.errorsTitle') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="(error, index) in importResult.errors"
                  :key="`${index}-${error.message}`"
                  class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
                >
                  <div class="font-medium">{{ error.id || error.message }}</div>
                  <div v-if="error.id">{{ error.message }}</div>
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
            {{ t('actions.close') }}
          </Button>
        </DialogFooter>
      </template>
    </DialogContent>
  </Dialog>
</template>
