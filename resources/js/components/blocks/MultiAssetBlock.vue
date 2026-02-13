<script setup lang="ts">
import { ComboboxField, InputField } from '~/components/ui/form'

const { $t } = useI18n()

defineProps<{ value: MultiAssetSchema }>()

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
      :label="$t('labels.blocks.fields.fileTypes')"
      :placeholder="$t('labels.blocks.fields.fileTypesPlaceholder')"
      multiple
      searchable
      :options="options"
      @update:model-value="emit('update:item-value', 'file_types', $event)"
    />
    <InputField
      name="min"
      :model-value="value.min"
      :label="$t('labels.blocks.fields.min')"
      :placeholder="$t('labels.blocks.fields.minPlaceholder')"
      type="number"
      @update:model-value="emit('update:item-value', 'min', $event)"
    />
    <InputField
      name="max"
      :model-value="value.max"
      :label="$t('labels.blocks.fields.max')"
      :placeholder="$t('labels.blocks.fields.maxPlaceholder')"
      type="number"
      @update:model-value="emit('update:item-value', 'max', $event)"
    />
  </div>
</template>
