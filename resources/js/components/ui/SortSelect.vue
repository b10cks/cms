<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { computed } from 'vue'
import { Button } from '~/components/ui/button'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
} from '~/components/ui/select'

interface SortOption {
  value: string
  label: string
}

const props = withDefaults(
  defineProps<{
    options: SortOption[]
    modelValue?: {
      column: string
      direction: 'asc' | 'desc'
    } | null
    placeholder?: string
  }>(),
  {
    modelValue: () => ({
      column: 'created_at',
      direction: 'desc' as const,
    }),
    placeholder: () => 'Sort by',
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: { column: string; direction: 'asc' | 'desc' }]
}>()

// Ensure we always have a valid sort value
const safeModelValue = computed(() => {
  const value = props.modelValue
  if (!value || typeof value !== 'object' || !('column' in value) || !value.column) {
    return {
      column: 'created_at',
      direction: 'desc' as const,
    }
  }
  return value
})

const sortDirection = computed(() => {
  const value = safeModelValue.value
  return value && value.direction ? value.direction : 'desc'
})

const sortField = computed(() => {
  const value = safeModelValue.value
  return value && value.column ? value.column : 'created_at'
})

const handleFieldChange = (field: string) => {
  if (typeof field !== 'string' || field.length === 0) return

  const currentDirection = sortDirection.value
  const safeDirection =
    currentDirection === 'asc' || currentDirection === 'desc' ? currentDirection : 'desc'
  emit('update:modelValue', {
    column: field,
    direction: safeDirection,
  })
}

const toggleDirection = () => {
  const currentColumn = sortField.value
  if (typeof currentColumn !== 'string' || currentColumn.length === 0) return

  const currentDir = sortDirection.value
  const newDirection = currentDir === 'asc' ? 'desc' : 'asc'
  emit('update:modelValue', {
    column: currentColumn,
    direction: newDirection,
  })
}

const selectedOptionLabel = computed(() => {
  const field = sortField.value
  if (!field) {
    return props.placeholder || 'Sort by'
  }
  const option = props.options.find((opt) => opt.value === field)
  return option ? option.label : props.placeholder || 'Sort by'
})

const directionIcon = computed(() => {
  return sortDirection.value === 'asc'
    ? 'lucide:arrow-up-narrow-wide'
    : 'lucide:arrow-down-wide-narrow'
})

const toggleButtonLabel = computed(() => {
  return sortDirection.value === 'asc' ? 'Switch to descending order' : 'Switch to ascending order'
})
</script>

<template>
  <div class="inline-flex items-center">
    <Select
      :model-value="sortField"
      @update:model-value="handleFieldChange"
    >
      <SelectTrigger class="rounded-r-none">
        <span>{{ selectedOptionLabel }}</span>
      </SelectTrigger>
      <SelectContent>
        <SelectGroup>
          <SelectItem
            v-for="option in options"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </SelectItem>
        </SelectGroup>
      </SelectContent>
      <Button
        size="icon"
        :aria-label="toggleButtonLabel"
        class="-mx-px rounded-l-none rounded-r-md border-l border-l-surface"
        @click="toggleDirection"
      >
        <Icon
          :name="directionIcon"
          class="transition-transform"
        />
      </Button>
    </Select>
  </div>
</template>
