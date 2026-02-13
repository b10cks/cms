<script setup lang="ts">
import { InputField } from '~/components/ui/form'

const value = defineModel<string>()

defineProps<{
  item: TextSchema & { key: string }
}>()
</script>

<template>
  <InputField
    v-model="value"
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description"
  />
</template>
