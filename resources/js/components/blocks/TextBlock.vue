<script setup lang="ts">
import { InputField } from '~/components/ui/form'

defineProps<{ value: TextSchema; readonly?: boolean }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()
</script>

<template>
  <InputField
    :model-value="typeof value.default === 'string' ? value.default : ''"
    name="default"
    :label="$t('labels.blocks.fields.default')"
    :disabled="readonly"
    @update:model-value="emit('update:item-value', 'default', String($event ?? ''))"
  />
</template>
