<script setup lang="ts" generic="TFormat extends ImportExportFormat">
import type { Ref } from 'vue'

import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import SelectField from '~/components/ui/form/SelectField.vue'
import { buildTimestampedExportFilename, downloadBlob } from '~/lib/import-export'
import type { ExportDialogLabels, ImportExportFormat } from '~/types/import-export'

const props = defineProps<{
  labels: ExportDialogLabels
  formats: { value: TFormat; label: string }[]
  /** Runs the feature mutation and resolves with the file to download. */
  submit: (format: TFormat) => Promise<Blob>
  /** Prepended to the generated `<prefix>-<date>.<ext>` filename. */
  filenamePrefix: string
  /** Distinguishes the select when several dialogs share a page. */
  fieldName?: string
}>()

const open = defineModel<boolean>('open', { default: false })

const format = ref(props.formats[0].value) as Ref<TFormat>
const isExporting = ref(false)
const errorMessage = ref('')

const handleExport = async () => {
  isExporting.value = true
  errorMessage.value = ''

  try {
    const blob = await props.submit(format.value)

    downloadBlob(blob, buildTimestampedExportFilename(props.filenamePrefix, format.value))
    open.value = false
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : props.labels.fallbackError
  } finally {
    isExporting.value = false
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="(value) => (open = value)"
  >
    <DialogContent>
      <DialogHeaderCombined
        :title="labels.title"
        :description="labels.description"
      />

      <form
        class="space-y-4"
        @submit.prevent="handleExport"
      >
        <SelectField
          :name="fieldName ?? 'format'"
          :label="labels.formatLabel"
          v-model="format"
          :options="formats"
          :disabled="isExporting"
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
            :disabled="isExporting"
            @click="open = false"
          >
            {{ $t('alertDialog.cancel') }}
          </Button>
          <Button
            type="submit"
            :loading="isExporting"
          >
            {{ isExporting ? $t('labels.loading') : labels.submit }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
