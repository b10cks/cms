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

// `Select` is reka-ui's renderless root, so anything id-related has to go on the
// trigger — that is the element the label needs to point at.
const labelId = `${useId()}-per-page-label`
</script>

<template>
  <div class="flex items-center gap-2">
    <label
      :id="labelId"
      class="sr-only"
    >
      {{ label || 'Items per page' }}
    </label>
    <Select
      :model-value="modelValue"
      @update:model-value="emit('update:modelValue', $event as number)"
    >
      <SelectTrigger :aria-labelledby="labelId">
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
