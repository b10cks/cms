// File: components/editor/DateBlock.vue
<script setup lang="ts">
import { DatePickerField } from '~/components/ui/form'

const value = defineModel<string>()

defineProps<{
  item: DateSchema & { key: string }
}>()
</script>

<template>
  <DatePickerField
    v-model="value"
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description || undefined"
    :required="item.required"
    :format="item.format || 'date'"
    :min="item.min"
    :max="item.max"
  />
</template>
