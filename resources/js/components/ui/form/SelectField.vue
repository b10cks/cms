<script setup lang="ts" generic="T extends AcceptableValue">
import type { AcceptableValue } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

import Icon from '~/components/Icon.vue'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

import FormField from './FormField.vue'

export interface SelectOption<T = unknown> {
  value: T
  label: string
  disabled?: boolean
}

const props = defineProps<{
  id?: string
  label?: string
  required?: boolean
  tooltip?: string
  description?: string
  error?: string | null
  class?: HTMLAttributes['class']
  selectClass?: HTMLAttributes['class']
  modelValue?: T
  defaultValue?: T
  placeholder?: string
  disabled?: boolean
  readonly?: boolean
  name: string
  options: SelectOption<T>[]
  displayFn?: (option: SelectOption<T>) => string
  valueFn?: (option: SelectOption<T>) => T
  emptyText?: string
  clearable?: boolean
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: T): void
  (e: 'select' | 'remove', payload: { option: SelectOption<T>; value: T }): void
}>()

// A plain writable computed rather than useVModel: the latter's conditional
// prop typing collapses to nonsense once the component is generic.
const innerValue = ref(props.defaultValue) as Ref<T | undefined>

watch(
  () => props.modelValue,
  (value) => {
    innerValue.value = value
  }
)

const modelValue = computed<T | undefined>({
  get: () => props.modelValue ?? innerValue.value,
  set: (value) => {
    innerValue.value = value
    emits('update:modelValue', value as T)
  },
})

const hasValue = computed(() => modelValue.value != null && modelValue.value !== '')

const getDisplayValue = (option: SelectOption<T>): string => {
  return props.displayFn ? props.displayFn(option) : option.label
}

const getOptionValue = (option: SelectOption<T>): T => {
  return props.valueFn ? props.valueFn(option) : option.value
}

const getOptionByValue = (value: T | undefined): SelectOption<T> | undefined => {
  return props.options.find((option) => {
    const optionValue = getOptionValue(option)
    return optionValue === value
  })
}

const emptyTextComputed = computed(() => {
  return props.emptyText || 'common.no_results'
})

const handleSelect = (raw: AcceptableValue | AcceptableValue[] | undefined) => {
  if (raw === '' || raw === null || raw === undefined) {
    handleClear()
    return
  }
  const value = raw as T
  modelValue.value = value
  const option = getOptionByValue(value)
  if (option) {
    emits('select', { option, value })
  }
}

const handleClear = () => {
  modelValue.value = undefined
}
</script>

<template>
  <FormField
    :id="id"
    :label="label"
    :name="name"
    :required="required"
    :tooltip="tooltip"
    :description="description"
    :error="error"
    :class="props.class"
  >
    <template #default="{ id, hasError }">
      <div class="flex items-center gap-1">
        <Select
          :model-value="modelValue"
          :disabled="disabled || readonly"
          :class="['flex-1', selectClass, { 'opacity-50': disabled || readonly }]"
          @update:model-value="handleSelect"
        >
          <SelectTrigger
            :id="id"
            :class="{ 'border-red-500': hasError }"
          >
            <SelectValue :placeholder="$t(String(placeholder || 'common.select'))">
              {{ getOptionByValue(modelValue)?.label || '' }}
            </SelectValue>
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <template v-if="options.length">
                <SelectItem
                  v-for="option in options"
                  :key="String(getOptionValue(option))"
                  :value="getOptionValue(option)"
                  :disabled="option.disabled"
                >
                  {{ getDisplayValue(option) }}
                </SelectItem>
              </template>
              <template v-else>
                <SelectLabel class="opacity-50">{{ $t(String(emptyTextComputed)) }}</SelectLabel>
              </template>
            </SelectGroup>
          </SelectContent>
        </Select>
        <button
          v-if="clearable && hasValue"
          size="icon"
          :aria-label="$t('actions.clear')"
          class="absolute right-8 shrink-0 text-muted-foreground hover:text-foreground"
          @click="handleClear"
        >
          <Icon name="lucide:x" />
        </button>
      </div>
    </template>
  </FormField>
</template>
