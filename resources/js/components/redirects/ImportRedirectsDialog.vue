<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { ScrollArea } from '~/components/ui/scroll-area'
import type { RedirectDataImportResult } from '~/types/redirects'

const props = defineProps<{
  spaceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useImportRedirectsMutation } = useRedirects(props.spaceId)

const fileInputRef = ref<HTMLInputElement | null>(null)
const selectedFile = ref<File | null>(null)
const errorMessage = ref('')
const importResult = ref<RedirectDataImportResult | null>(null)
const expandedRedirects = ref<Set<string>>(new Set())

const importMutation = useImportRedirectsMutation()

const handleFileChange = (event: Event) => {
  const target = event.target as HTMLInputElement
  selectedFile.value = target.files?.[0] ?? null
}

const handleImport = async () => {
  if (!selectedFile.value) {
    errorMessage.value = t('labels.redirects.importDialog.selectFileError') as string
    return
  }

  errorMessage.value = ''
  importResult.value = null

  try {
    importResult.value = await importMutation.mutateAsync(selectedFile.value)
  } catch (error) {
    errorMessage.value =
      error instanceof Error
        ? error.message
        : (t('composables.redirects.importError', { error: '' }) as string)
  }
}

const resetState = () => {
  selectedFile.value = null
  errorMessage.value = ''
  importResult.value = null
  expandedRedirects.value.clear()

  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
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
          <div class="rounded-lg border-2 border-dashed border-border p-6 text-center">
            <input
              ref="fileInputRef"
              type="file"
              accept=".json,.csv,.xlsx,.xls,.yaml,.yml"
              class="hidden"
              @change="handleFileChange"
            />

            <div class="flex flex-col items-center gap-2">
              <Icon
                name="lucide:upload-cloud"
                class="h-8 w-8 text-muted-foreground"
              />

              <div class="space-y-1">
                <p
                  v-if="!selectedFile"
                  class="text-sm font-medium text-foreground"
                >
                  <button
                    type="button"
                    class="text-primary hover:underline"
                    @click="fileInputRef?.click()"
                  >
                    {{ $t('labels.redirects.importDialog.selectFileAction') }}
                  </button>
                </p>
                <p
                  v-else
                  class="text-sm font-medium text-foreground"
                >
                  {{ selectedFile.name }}
                </p>
                <p class="text-xs text-muted-foreground">
                  {{ $t('labels.redirects.importDialog.supportedFormats') }}
                </p>
              </div>
            </div>
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
            <div class="grid gap-4 md:grid-cols-3">
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
