<script setup lang="ts">
import { CheckboxField } from '~/components/ui/form'

defineProps<{ value: MetaSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()
</script>

<template>
  <div class="flex flex-col gap-6">
    <CheckboxField
      :model-value="value.has_og_tags"
      name="required"
      :label="$t('labels.blocks.fields.has_og_tags')"
      @update:model-value="emit('update:item-value', 'has_og_tags', $event)"
    />
  </div>
</template>
