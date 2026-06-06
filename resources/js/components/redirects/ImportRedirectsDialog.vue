<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import FileDropZone from '~/components/ui/FileDropZone.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import type { RedirectDataImportResult, RedirectImportMode } from '~/types/redirects'

const props = defineProps<{
  spaceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportRedirectsMutation } = useRedirects(props.spaceId)

const selectedFile = ref<File | null>(null)
const importMode = ref<RedirectImportMode>('addition')
const errorMessage = ref('')
const importResult = ref<RedirectDataImportResult | null>(null)
const expandedRedirects = ref<Set<string>>(new Set())

const importMutation = useImportRedirectsMutation()

const handleImport = async () => {
  if (!selectedFile.value) {
    errorMessage.value = t('labels.redirects.importDialog.selectFileError') as string
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
        : (t('composables.redirects.importError', { error: '' }) as string)
  }
}

const resetState = () => {
  selectedFile.value = null
  importMode.value = 'addition'
  errorMessage.value = ''
  importResult.value = null
  expandedRedirects.value.clear()
}

const handleOpenChange = (value: boolean) => {
  open.value = value

  if (!value && !importMutation.isPending.value) {
    resetState()
  }
}

const toggleExpanded = (redirectId: string) => {
  if (expandedRedirects.value.has(redirectId)) {
    expandedRedirects.value.delete(redirectId)
    return
  }

  expandedRedirects.value.add(redirectId)
}

const isExpanded = (redirectId: string) => expandedRedirects.value.has(redirectId)

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
          :title="$t('labels.redirects.importDialog.title')"
          :description="$t('labels.redirects.importDialog.description')"
        />

        <div class="space-y-4 py-4">
          <FileDropZone
            v-model="selectedFile"
            accept=".json,.csv,.xlsx,.xls,.yaml,.yml"
            :hint="$t('labels.redirects.importDialog.supportedFormats')"
            @error="errorMessage = $event"
          />

          <div class="space-y-2">
            <p class="text-sm font-medium text-foreground">
              {{ $t('labels.redirects.importDialog.importMode.label') }}
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
                    {{ $t('labels.redirects.importDialog.importMode.addition') }}
                  </span>
                </div>
                <p class="mt-1 text-xs">
                  {{ $t('labels.redirects.importDialog.importMode.additionDescription') }}
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
                    {{ $t('labels.redirects.importDialog.importMode.replacement') }}
                  </span>
                </div>
                <p class="mt-1 text-xs">
                  {{ $t('labels.redirects.importDialog.importMode.replacementDescription') }}
                </p>
              </button>
            </div>
            <p
              v-if="importMode === 'replacement'"
              class="rounded-md bg-destructive/10 p-2 text-xs text-destructive"
            >
              {{ $t('labels.redirects.importDialog.importMode.replacementWarning') }}
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
          :title="$t('labels.redirects.importDialog.summaryTitle')"
          :description="$t('labels.redirects.importDialog.summaryDescription')"
        />

        <ScrollArea class="h-[28rem] w-full pr-4">
          <div
            v-if="importResult"
            class="space-y-6 p-1"
          >
            <div :class="['grid gap-4', importResult.summary.total_deleted > 0 ? 'md:grid-cols-4' : 'md:grid-cols-3']">
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.redirects.importDialog.summary.success') }}
                </div>
                <div class="text-2xl font-semibold text-foreground">
                  {{ importResult.summary.total_success }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.redirects.importDialog.summary.changes') }}
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
                  {{ $t('labels.redirects.importDialog.summary.deleted') }}
                </div>
                <div class="text-2xl font-semibold text-destructive">
                  {{ importResult.summary.total_deleted }}
                </div>
              </div>
              <div class="rounded-lg border p-4">
                <div class="text-sm font-medium text-muted-foreground">
                  {{ $t('labels.redirects.importDialog.summary.errors') }}
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
                {{ $t('labels.redirects.importDialog.modifiedRedirects') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="redirect in importResult.changes"
                  :key="redirect.id"
                  class="rounded-lg border"
                >
                  <button
                    type="button"
                    class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/40"
                    @click="toggleExpanded(redirect.id)"
                  >
                    <span class="font-medium text-foreground">{{ redirect.source }}</span>
                    <span class="flex items-center gap-2 text-xs text-muted-foreground">
                      {{ $t('labels.redirects.importDialog.changeCount', { count: redirect.changes.length }) }}
                      <Icon
                        :name="isExpanded(redirect.id) ? 'lucide:chevron-down' : 'lucide:chevron-right'"
                        class="h-4 w-4"
                      />
                    </span>
                  </button>
                  <div
                    v-if="isExpanded(redirect.id)"
                    class="border-t p-3"
                  >
                    <div class="space-y-2 text-sm">
                      <div
                        v-for="(change, index) in redirect.changes"
                        :key="`${redirect.id}-${index}`"
                        class="rounded-md bg-muted/50 p-2"
                      >
                        <div class="font-medium text-foreground">{{ change.field }}</div>
                        <div class="text-muted-foreground">
                          {{
                            change.old ?? $t('labels.redirects.importDialog.emptyValue')
                          }}
                          ->
                          {{
                            change.new ?? $t('labels.redirects.importDialog.emptyValue')
                          }}
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
                {{ $t('labels.redirects.importDialog.deletedRedirects') }}
              </h3>
              <div class="flex flex-col gap-1">
                <div
                  v-for="redirect in importResult.deleted"
                  :key="redirect.id"
                  class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                >
                  {{ redirect.source }}
                </div>
              </div>
            </div>

            <div
              v-if="importResult.ignored_fields.length > 0"
              class="space-y-2"
            >
              <h3 class="font-medium text-foreground">
                {{ $t('labels.redirects.importDialog.ignoredFields') }}
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
                {{ $t('labels.redirects.importDialog.errorsTitle') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="(error, index) in importResult.errors"
                  :key="`${index}-${error.message}`"
                  class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
                >
                  <div class="font-medium">
                    {{ error.id || $t('labels.redirects.importDialog.rowLabel', { row: error.row ?? index + 1 }) }}
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
