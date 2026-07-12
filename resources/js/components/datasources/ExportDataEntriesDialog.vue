<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import SelectField from '~/components/ui/form/SelectField.vue'
import { buildTimestampedExportFilename, downloadBlob } from '~/lib/import-export'
import type { DataEntryImportExportFormat } from '~/types/data-sources'

const props = defineProps<{
  spaceId: string
  dataSourceId: string
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useExportDataEntriesMutation } = useDataEntries(props.spaceId, props.dataSourceId)

const format = ref<DataEntryImportExportFormat>('csv')
const exportMutation = useExportDataEntriesMutation()

const formatOptions = computed(() => [
  { value: 'csv' as const, label: 'CSV' },
  { value: 'excel' as const, label: 'Excel' },
  { value: 'json' as const, label: 'JSON' },
  { value: 'yaml' as const, label: 'YAML' },
])

const handleExport = async () => {
  try {
    const blob = await exportMutation.mutateAsync({ as: format.value })
    downloadBlob(blob, buildTimestampedExportFilename('data-entries-export', format.value))
    open.value = false
  } catch {
    // Error surfaced via toast in the mutation.
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="(value) => (open = value)"
  >
    <DialogContent class="max-w-md">
      <DialogHeaderCombined
        :title="$t('labels.dataEntries.exportDialog.title')"
        :description="$t('labels.dataEntries.exportDialog.description')"
      />

      <form
        class="space-y-4"
        @submit.prevent="handleExport"
      >
        <SelectField
          name="data-entry-export-format"
          :label="$t('labels.dataEntries.exportDialog.formatLabel')"
          v-model="format"
          :options="formatOptions"
          :disabled="exportMutation.isPending.value"
        />

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            :disabled="exportMutation.isPending.value"
            @click="open = false"
          >
            {{ $t('alertDialog.cancel') }}
          </Button>
          <Button
            type="submit"
            :loading="exportMutation.isPending.value"
          >
            {{ exportMutation.isPending.value ? $t('labels.loading') : $t('labels.assets.export') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
