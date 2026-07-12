<script setup lang="ts">
import { InputField } from '~/components/ui/form'

defineProps<{ value: NumberSchema; readonly?: boolean }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const toNumberDefault = (value: unknown): number => {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}
</script>

<template>
  <InputField
    :model-value="typeof value.default === 'number' ? value.default : 0"
    name="default"
    type="number"
    :label="$t('labels.blocks.fields.default')"
    :disabled="readonly"
    @update:model-value="emit('update:item-value', 'default', toNumberDefault($event))"
  />
</template>
