<script setup lang="ts">
import { TextField } from '~/components/ui/form'

defineProps<{ value: MarkdownSchema; readonly?: boolean }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()
</script>

<template>
  <TextField
    :model-value="typeof value.default === 'string' ? value.default : ''"
    name="default"
    :label="$t('labels.blocks.fields.default')"
    :disabled="readonly"
    @update:model-value="emit('update:item-value', 'default', String($event ?? ''))"
  />
</template>
