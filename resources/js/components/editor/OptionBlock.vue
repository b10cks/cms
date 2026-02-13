<script setup lang="ts">
import { SelectField } from '~/components/ui/form'

const value = defineModel<string>()

defineProps<{
  item: OptionSchema & { key: string }
}>()
</script>

<template>
  <SelectField
    v-model="value"
    :name="item.key"
    :label="item.name || item.key"
    :options="
      item.options.map((option) => ({ label: option.name || option.value, value: option.value }))
    "
  >
  </SelectField>
</template>
