// File: components/editor/DateBlock.vue
<script setup lang="ts">
import { DateTimeField } from '~/components/ui/form'

const value = defineModel<string>()

defineProps<{
  item: DateSchema & { key: string }
}>()
</script>

<template>
  <DateTimeField
    v-model="value"
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description || undefined"
    :required="item.required"
    :type="item.format || 'date'"
    :min="item.min === null ? undefined : item.min"
    :max="item.max === null ? undefined : item.max"
  />
</template>
