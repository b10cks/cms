<script setup lang="ts">
import { CheckboxField } from '~/components/ui/form'

const value = defineModel<boolean>()

defineProps<{
  item: BooleanSchema & { key: string }
}>()
</script>

<template>
  <CheckboxField
    v-model="value"
    name="item.key"
    :label="item.name || item.key"
    :description="item.description"
    class="relative z-10"
  />
</template>
