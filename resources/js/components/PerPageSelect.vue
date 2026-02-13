<script setup lang="ts">
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

withDefaults(
  defineProps<{
    modelValue: number
    options?: number[]
    label?: string
  }>(),
  {
    options: () => [12, 24, 36, 48],
    label: undefined,
  }
)

const emit = defineEmits<{
  'update:modelValue': [number]
}>()
</script>

<template>
  <div class="flex items-center gap-2">
    <label
      for="per-page-label"
      class="sr-only"
    >
      {{ label || 'Items per page' }}
    </label>
    <Select
      id="per-page-label"
      :model-value="modelValue"
      aria-labelledby="per-page-label"
      @update:model-value="emit('update:modelValue', $event as number)"
    >
      <SelectTrigger>
        <SelectValue>{{ modelValue }}</SelectValue>
      </SelectTrigger>
      <SelectContent>
        <SelectItem
          v-for="option in options"
          :key="option"
          :value="option"
        >
          {{ option }}
        </SelectItem>
      </SelectContent>
    </Select>
  </div>
</template>
