<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import SelectField from '~/components/ui/form/SelectField.vue'
import { buildTimestampedExportFilename, downloadBlob } from '~/lib/import-export'
import type { RedirectImportExportFormat } from '~/types/redirects'

const props = defineProps<{
  spaceId: string
  filters: Record<string, unknown>
}>()

const open = defineModel<boolean>('open', { default: false })

const { t } = useI18n()
const { useExportRedirectsMutation } = useRedirects(props.spaceId)

const format = ref<RedirectImportExportFormat>('csv')
const exportMutation = useExportRedirectsMutation()

const formatOptions = computed(() => [
  { value: 'csv' as const, label: 'CSV' },
  { value: 'excel' as const, label: 'Excel' },
  { value: 'json' as const, label: 'JSON' },
  { value: 'yaml' as const, label: 'YAML' },
])

const handleExport = async () => {
  try {
    const blob = await exportMutation.mutateAsync({
      ...props.filters,
      as: format.value,
    })

    downloadBlob(blob, buildTimestampedExportFilename('redirects-export', format.value))
    open.value = false
  } catch {
    // Mutation error state is surfaced through the shared toast handler.
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
        :title="$t('labels.redirects.exportDialog.title')"
        :description="$t('labels.redirects.exportDialog.description')"
      />

      <form
        class="space-y-4"
        @submit.prevent="handleExport"
      >
        <SelectField
          name="redirect-export-format"
          :label="$t('labels.redirects.exportDialog.formatLabel')"
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
