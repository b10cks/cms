<script setup lang="ts">
import type { BadgeVariants } from '~/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '~/components/ui/select';

const props = defineProps<{
  modelValue?: string | null
  placeholder?: string
  class?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

type PredefinedBadge = {
  value: string
  label: string
  variant: BadgeVariants['variant']
}

const PREDEFINED_BADGES: PredefinedBadge[] = [
  { value: 'sandbox',     label: 'Sandbox',     variant: 'secondary' },
  { value: 'development', label: 'Development', variant: 'info'      },
  { value: 'staging',     label: 'Staging',     variant: 'warning'   },
  { value: 'production',  label: 'Production',  variant: 'success'   },
]

// reka-ui Select requires a non-empty string; we use an empty string to represent "none"
const EMPTY = '__none__'

const internalValue = computed({
  get() {
    return props.modelValue ?? EMPTY
  },
  set(val: string) {
    emit('update:modelValue', val === EMPTY ? null : val)
  },
})
</script>

<template>
  <Select
    v-model="internalValue"
    :class="props.class"
  >
    <SelectTrigger>
      <SelectValue :placeholder="placeholder ?? 'Select a badge…'" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem :value="EMPTY">
        <span class="text-muted">None</span>
      </SelectItem>
      <SelectItem
        v-for="badge in PREDEFINED_BADGES"
        :key="badge.value"
        :value="badge.value"
      >
        {{ badge.label }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
