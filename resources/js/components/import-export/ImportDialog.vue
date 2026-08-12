<script
  setup
  lang="ts"
  generic="TItem, TDeleted, TMode extends string"
>
import type { Ref } from 'vue'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import FileDropZone from '~/components/ui/FileDropZone.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import type {
  ImportDialogLabels,
  ImportDialogMode,
  ImportDialogResult,
  ImportError,
} from '~/types/import-export'

const props = withDefaults(
  defineProps<{
    /** Comma separated extension list handed to the drop zone. */
    accept: string
    labels: ImportDialogLabels
    /** Runs the feature mutation; its resolved value drives the summary step. */
    submit: (file: File, mode: TMode) => Promise<ImportDialogResult<TItem, TDeleted>>
    pending: boolean
    /** Strategy tiles. Omit for importers that take no mode. */
    modes?: ImportDialogMode<TMode>[]
    itemKey: (item: TItem) => string
    changeCount: (item: TItem) => string
    deletedLabel?: (item: TDeleted) => string
    /** `list` stacks full-width rows, `tags` wraps compact chips. */
    deletedVariant?: 'list' | 'tags'
    errorTitle?: (error: ImportError, index: number) => string
    errorBody?: (error: ImportError) => string
    contentClass?: string
  }>(),
  {
    modes: undefined,
    deletedLabel: undefined,
    deletedVariant: 'list',
    errorTitle: (error: ImportError, index: number) => error.id || String(error.row ?? index + 1),
    errorBody: (error: ImportError) => error.message,
    contentClass: 'max-w-3xl',
  }
)

const emit = defineEmits<{
  /** Fired when the dialog clears itself, so wrappers can reset extra options. */
  reset: []
}>()

const open = defineModel<boolean>('open', { default: false })

const defaultMode = () => props.modes?.[0]?.value as TMode

const selectedFile = ref<File | null>(null)
const importMode = ref(defaultMode()) as Ref<TMode>
const errorMessage = ref('')
const importResult = ref(null) as Ref<ImportDialogResult<TItem, TDeleted> | null>
const expanded = ref<Set<string>>(new Set())

const showSummary = computed(() => importResult.value !== null)
const activeMode = computed(() => props.modes?.find((mode) => mode.value === importMode.value))

const handleImport = async () => {
  if (!selectedFile.value) {
    errorMessage.value = props.labels.selectFileError
    return
  }

  errorMessage.value = ''
  importResult.value = null

  try {
    importResult.value = await props.submit(selectedFile.value, importMode.value)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : props.labels.fallbackError
  }
}

const resetState = () => {
  selectedFile.value = null
  importMode.value = defaultMode()
  errorMessage.value = ''
  importResult.value = null
  expanded.value.clear()
  emit('reset')
}

const handleOpenChange = (value: boolean) => {
  open.value = value

  if (!value && !props.pending) {
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
    <DialogContent :class="contentClass">
      <template v-if="!showSummary">
        <DialogHeaderCombined
          :title="labels.title"
          :description="labels.description"
        />

        <div class="space-y-4 py-4">
          <FileDropZone
            v-model="selectedFile"
            :accept="accept"
            :hint="labels.formats"
            @error="errorMessage = $event"
          />

          <div
            v-if="modes"
            class="space-y-2"
          >
            <p class="text-sm font-medium text-foreground">{{ labels.modeLabel }}</p>
            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="mode in modes"
                :key="mode.value"
                type="button"
                :class="[
                  'rounded-lg border p-3 text-left transition-colors',
                  importMode === mode.value
                    ? 'border-primary bg-primary/5 text-foreground'
                    : 'border-border text-muted-foreground hover:border-muted-foreground',
                ]"
                @click="importMode = mode.value"
              >
                <div class="flex items-center gap-2">
                  <Icon
                    :name="mode.icon"
                    class="h-4 w-4 shrink-0"
                  />
                  <span class="text-sm font-medium">{{ mode.label }}</span>
                </div>
                <p class="mt-1 text-xs">{{ mode.description }}</p>
              </button>
            </div>
            <p
              v-if="activeMode?.warning"
              class="rounded-md bg-destructive/10 p-2 text-xs text-destructive"
            >
              {{ activeMode.warning }}
            </p>
          </div>

          <slot name="options" />

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
              :disabled="pending"
              @click="handleOpenChange(false)"
            >
              {{ $t('alertDialog.cancel') }}
            </Button>
            <Button
              type="button"
              :loading="pending"
              :disabled="!selectedFile"
              @click="handleImport"
            >
              {{ pending ? $t('labels.loading') : labels.submit }}
            </Button>
          </DialogFooter>
        </div>
      </template>

      <template v-else>
        <DialogHeaderCombined
          :title="labels.summaryTitle"
          :description="labels.summaryDescription"
        />

        <ScrollArea class="h-[28rem] w-full pr-4">
          <div
            v-if="importResult"
            class="space-y-6 p-1"
          >
            <div
              :class="[
                'grid gap-4',
                (importResult.summary.total_deleted ?? 0) > 0 ? 'md:grid-cols-4' : 'md:grid-cols-3',
              ]"
            >
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ labels.summarySuccess }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_success }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ labels.summaryChanges }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_changes }}
                </div>
              </div>
              <div
                v-if="(importResult.summary.total_deleted ?? 0) > 0"
                class="rounded-lg border p-4"
              >
                <div class="text-sm font-medium text-muted-foreground">
                  {{ labels.summaryDeleted }}
                </div>
                <div class="text-2xl font-semibold text-destructive">
                  {{ importResult.summary.total_deleted }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ labels.summaryErrors }}
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
              <h3 class="font-medium text-foreground">{{ labels.changesTitle }}</h3>
              <div class="space-y-2">
                <div
                  v-for="item in importResult.changes"
                  :key="itemKey(item)"
                  class="rounded-lg border"
                >
                  <button
                    type="button"
                    class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/40"
                    @click="toggleExpanded(itemKey(item))"
                  >
                    <span class="font-medium text-foreground">
                      <slot
                        name="label"
                        :item="item"
                      />
                    </span>
                    <span class="flex items-center gap-2 text-xs text-muted-foreground">
                      {{ changeCount(item) }}
                      <Icon
                        :name="
                          expanded.has(itemKey(item))
                            ? 'lucide:chevron-down'
                            : 'lucide:chevron-right'
                        "
                        class="h-4 w-4"
                      />
                    </span>
                  </button>
                  <div
                    v-if="expanded.has(itemKey(item))"
                    class="border-t p-3"
                  >
                    <slot
                      name="details"
                      :item="item"
                    />
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="deletedLabel && importResult.deleted?.length"
              class="space-y-2"
            >
              <h3 class="font-medium text-foreground">{{ labels.deletedTitle }}</h3>
              <div :class="deletedVariant === 'tags' ? 'flex flex-wrap gap-2' : 'flex flex-col gap-1'">
                <span
                  v-for="(item, index) in importResult.deleted"
                  :key="`deleted-${index}`"
                  :class="
                    deletedVariant === 'tags'
                      ? 'rounded-md bg-destructive/10 px-3 py-1 text-xs text-destructive'
                      : 'rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive'
                  "
                >
                  {{ deletedLabel(item) }}
                </span>
              </div>
            </div>

            <div
              v-if="labels.ignoredFieldsTitle && importResult.ignored_fields?.length"
              class="space-y-2"
            >
              <h3 class="font-medium text-foreground">{{ labels.ignoredFieldsTitle }}</h3>
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
              <h3 class="font-medium text-foreground">{{ labels.errorsTitle }}</h3>
              <div class="space-y-2">
                <div
                  v-for="(error, index) in importResult.errors"
                  :key="`${index}-${error.message}`"
                  class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
                >
                  <div class="font-medium">{{ errorTitle(error, index) }}</div>
                  <div v-if="errorBody(error)">{{ errorBody(error) }}</div>
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
