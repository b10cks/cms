<script setup lang="ts">
import type { AssetsQueryParams } from '~/api/resources/assets'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { buildTimestampedExportFilename, downloadBlob } from '~/lib/import-export'
import type { ExportTypes } from '~/types/assets'

import SelectField from '../ui/form/SelectField.vue'

const props = defineProps<{
  spaceId: string
  filters: Record<string, unknown>
  folderId?: string | null
  tagId?: string | null
}>()

const { useExportAssetsMutation } = useAssets(props.spaceId)

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const open = defineModel<boolean>('open')

const format = ref<ExportTypes>('csv')
const isExporting = ref(false)
const errorMessage = ref<string>('')

const { mutate: exportAssets } = useExportAssetsMutation()

const handleExport = async () => {
  isExporting.value = true
  errorMessage.value = ''

  try {
    const params: AssetsQueryParams & { as: ExportTypes } = {
      ...props.filters,
      folder: props.folderId ?? undefined,
      tags: props.tagId ?? undefined,
      as: format.value,
    }

    exportAssets(params, {
      onSuccess: (blob) => {
        downloadBlob(blob, buildTimestampedExportFilename('assets-export', format.value))

        open.value = false
      },
      onError: (error) => {
        errorMessage.value = error instanceof Error ? error.message : 'Export failed'
      },
    })
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Export failed'
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
        title="Export Assets"
        description="Export asset metadata in your preferred format"
      />

      <form
        @submit.prevent="handleExport"
        class="space-y-4"
      >
        <div class="space-y-2">
          <SelectField
            name="format"
            label="Export Format"
            v-model="format"
            :options="[
              { value: 'csv', label: 'CSV' },
              { value: 'excel', label: 'Excel' },
              { value: 'json', label: 'JSON' },
              { value: 'xliff', label: 'XLIFF' },
              { value: 'yaml', label: 'YAML' },
            ]"
          >
          </SelectField>
        </div>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="open = false"
            :disabled="isExporting"
          >
            Cancel
          </Button>
          <Button
            type="submit"
            :loading="isExporting"
          >
            {{ isExporting ? 'Exporting...' : 'Export' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
