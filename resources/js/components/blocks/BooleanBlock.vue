<script setup lang="ts">
import { CheckboxField } from '~/components/ui/form'

defineProps<{ value: BooleanSchema; readonly?: boolean }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()
</script>

<template>
  <CheckboxField
    :model-value="value.default === true"
    name="default"
    :label="$t('labels.blocks.fields.default')"
    :disabled="readonly"
    @update:model-value="emit('update:item-value', 'default', Boolean($event))"
  />
</template>
