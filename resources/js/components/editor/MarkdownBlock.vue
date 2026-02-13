<script setup lang="ts">
import { TextField } from '~/components/ui/form'

const value = defineModel<string>()

defineProps<{
  item: MarkdownSchema & { key: string }
}>()
</script>

<template>
  <TextField
    v-model="value"
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description"
    :auto-size="600"
  />
</template>
