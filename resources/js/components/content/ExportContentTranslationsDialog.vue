<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import SelectField from '~/components/ui/form/SelectField.vue'
import { buildTimestampedExportFilename, downloadBlob } from '~/lib/import-export'
import type { ContentTranslationExportFormat } from '~/types/content-translations'

const props = defineProps<{
  spaceId: string
  filters?: Record<string, unknown>
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useExportContentTranslationsMutation } = useContent(props.spaceId)
const { mutate: exportTranslations } = useExportContentTranslationsMutation()

const format = ref<ContentTranslationExportFormat>('xliff')
const isExporting = ref(false)
const errorMessage = ref('')

const handleExport = () => {
  isExporting.value = true
  errorMessage.value = ''

  exportTranslations(
    { ...props.filters, as: format.value },
    {
      onSuccess: (blob) => {
        downloadBlob(blob, buildTimestampedExportFilename('content-translations', format.value))
        isExporting.value = false
        open.value = false
      },
      onError: (error) => {
        errorMessage.value = error instanceof Error ? error.message : 'Export failed'
        isExporting.value = false
      },
    }
  )
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="(value) => (open = value)"
  >
    <DialogContent>
      <DialogHeaderCombined
        :title="t('labels.contents.translationExport.title')"
        :description="t('labels.contents.translationExport.description')"
      />

      <form
        class="space-y-4"
        @submit.prevent="handleExport"
      >
        <SelectField
          name="format"
          :label="t('labels.contents.translationExport.formatLabel')"
          v-model="format"
          :options="[
            { value: 'xliff', label: 'XLIFF' },
            { value: 'csv', label: 'CSV' },
            { value: 'excel', label: 'Excel' },
            { value: 'json', label: 'JSON' },
            { value: 'yaml', label: 'YAML' },
          ]"
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
            {{ t('alertDialog.cancel') }}
          </Button>
          <Button
            type="submit"
            :loading="isExporting"
          >
            {{ isExporting ? t('labels.loading') : t('labels.contents.translationExport.submit') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
