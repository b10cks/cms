<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { AssetsQueryParams } from '~/api/resources/assets'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
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
        // Generate filename with timestamp
        const timestamp = new Date().toISOString().split('T')[0]
        const extension = format.value === 'excel' ? 'xlsx' : format.value
        const filename = `assets-export-${timestamp}.${extension}`

        // Download the file
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = filename
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        URL.revokeObjectURL(url)

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
            :disabled="isExporting"
          >
            <Icon
              v-if="isExporting"
              name="lucide:loader-2"
              class="animate-spin"
            />
            {{ isExporting ? 'Exporting...' : 'Export' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
