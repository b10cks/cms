<script setup lang="ts">
import { ComboboxField } from '~/components/ui/form'

const { $t } = useI18n()

defineProps<{ value: AssetSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const options = ['image', 'video', 'audio', 'document', 'other', 'all'].map((type) => ({
  value: type as FileTypes,
  label: $t(`labels.assets.fileTypes.${type}`),
}))
</script>

<template>
  <div class="flex flex-col gap-6">
    <ComboboxField
      name="asset_type"
      :model-value="value.file_types || []"
      :label="$t('labels.assets.fields.fileTypes')"
      :placeholder="$t('labels.blocks.fields.fileTypesPlaceholder')"
      multiple
      searchable
      :options="options"
      @update:model-value="emit('update:item-value', 'file_types', $event)"
    />
  </div>
</template>
