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
    :description="item.description"
    :type="item.format || 'date'"
    :min="item.min"
    :max="item.max"
  />
</template>
