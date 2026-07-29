<script setup lang="ts" generic="T extends AcceptableValue">
import type { AcceptableInputValue, AcceptableValue } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { computed, ref, watch } from 'vue'

import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxGroup,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from '~/components/ui/combobox'
import {
  TagsInput,
  TagsInputInput,
  TagsInputItem,
  TagsInputItemDelete,
  TagsInputItemText,
} from '~/components/ui/tags-input'

import FormField from './FormField.vue'

export interface ComboboxOption<T = unknown> {
  value: T
  label: string
  disabled?: boolean
  icon?: string | null
  color?: string | null
  [key: string]: unknown
}

const props = defineProps<{
  id?: string
  label?: string
  required?: boolean
  tooltip?: string
  description?: string
  error?: string | null
  class?: HTMLAttributes['class']
  comboboxClass?: HTMLAttributes['class']

  modelValue?: T[] | T
  defaultValue?: T[] | T
  placeholder?: string
  disabled?: boolean
  readonly?: boolean
  name: string

  options: ComboboxOption<T>[]
  multiple?: boolean
  searchable?: boolean

  filterFn?: (option: ComboboxOption<T>, search: string, selectedValues: T[]) => boolean
  displayFn?: (option: ComboboxOption<T>) => string
  valueFn?: (option: ComboboxOption<T>) => T

  emptyText?: string
  loadingText?: string
  loading?: boolean
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: T[] | T): void
  (e: 'select' | 'remove', payload: { option: ComboboxOption<T>; value: T }): void
}>()

// A plain writable computed rather than useVModel: the latter's conditional
// prop typing collapses to nonsense once the component is generic.
const innerValue = ref(props.defaultValue ?? (props.multiple ? [] : undefined)) as Ref<
  T | T[] | undefined
>

watch(
  () => props.modelValue,
  (value) => {
    innerValue.value = value
  }
)

const modelValue = computed<T | T[] | undefined>({
  get: () => props.modelValue ?? innerValue.value,
  set: (value) => {
    innerValue.value = value
    emits('update:modelValue', value as T | T[])
  },
})

const searchValue = ref('')

const selectedValues = computed<T[]>(() => {
  if (props.multiple) {
    return Array.isArray(modelValue.value) ? modelValue.value : []
  }
  return modelValue.value == null ? [] : [modelValue.value as T]
})

const defaultFilterFn = (
  option: ComboboxOption<T>,
  search: string,
  selectedValues: T[]
): boolean => {
  const searchLower = search.toLowerCase()
  const isSelected = selectedValues.some((selected) => {
    const optionValue = props.valueFn ? props.valueFn(option) : option.value
    return selected === optionValue
  })

  if (props.multiple && isSelected) {
    return false
  }

  if (search && !option.label.toLowerCase().includes(searchLower)) {
    return false
  }

  return !option.disabled
}

const filteredOptions = computed(() => {
  const filterFn = props.filterFn || defaultFilterFn
  return props.options.filter((option) => filterFn(option, searchValue.value, selectedValues.value))
})

const getDisplayValue = (option: ComboboxOption<T>): string => {
  return props.displayFn ? props.displayFn(option) : option.label
}

const getOptionValue = (option: ComboboxOption<T>): T => {
  return props.valueFn ? props.valueFn(option) : option.value
}

const getOptionByValue = (value: T): ComboboxOption<T> | undefined => {
  return props.options.find((option) => {
    const optionValue = getOptionValue(option)
    return optionValue === value
  })
}

const handleSelect = (option: ComboboxOption<T>) => {
  const value = getOptionValue(option)

  if (props.multiple) {
    const currentValues = Array.isArray(modelValue.value) ? [...modelValue.value] : []
    if (!currentValues.includes(value)) {
      currentValues.push(value)
      modelValue.value = currentValues
    }
  } else {
    modelValue.value = value
  }

  searchValue.value = ''

  emits('select', { option, value })
}

const handleRemove = (value: T) => {
  if (props.multiple && Array.isArray(modelValue.value)) {
    const currentValues = [...modelValue.value]
    const index = currentValues.indexOf(value)
    if (index > -1) {
      currentValues.splice(index, 1)
      modelValue.value = currentValues

      const option = getOptionByValue(value)
      if (option) {
        emits('remove', { option, value })
      }
    }
  }
}

const emptyTextComputed = computed(() => {
  if (props.loading) {
    return props.loadingText || 'common.loading'
  }
  return props.emptyText || 'common.no_results'
})
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
      <Combobox
        :model-value="multiple ? selectedValues : modelValue"
        :disabled="disabled || readonly"
        :class="[comboboxClass, { 'opacity-50': disabled || readonly }]"
      >
        <ComboboxAnchor as-child>
          <TagsInput
            v-if="multiple"
            :model-value="(selectedValues as AcceptableInputValue[])"
            :disabled="disabled || readonly"
            :class="{ 'border-red-500': hasError, 'pl-2': selectedValues.length > 0 }"
          >
            <TagsInputItem
              v-for="value in selectedValues"
              :key="String(value)"
              :value="(value as AcceptableInputValue)"
            >
              <slot
                name="selected"
                :option="getOptionByValue(value)"
                :value="value"
              >
                <TagsInputItemText>{{
                  getOptionByValue(value)?.label || String(value)
                }}</TagsInputItemText>
              </slot>
              <TagsInputItemDelete @click="handleRemove(value)" />
            </TagsInputItem>
            <ComboboxInput
              v-if="searchable !== false"
              v-model="searchValue"
              :placeholder="$t(String(placeholder || 'common.search'))"
              :disabled="disabled || readonly"
              as-child
            >
              <TagsInputInput />
            </ComboboxInput>
          </TagsInput>
          <ComboboxInput
            v-else
            :id="id"
            v-model="searchValue"
            :placeholder="$t(String(placeholder || 'common.select'))"
            :disabled="disabled || readonly"
            :class="{ 'border-red-500': hasError }"
            :display-value="(value: T) => getOptionByValue(value as T)?.label || String(value)"
          />
        </ComboboxAnchor>
        <ComboboxList>
          <ComboboxEmpty>
            {{ $t(String(emptyTextComputed)) }}
          </ComboboxEmpty>
          <ComboboxGroup>
            <ComboboxItem
              v-for="option in filteredOptions"
              :key="String(getOptionValue(option))"
              :value="getOptionValue(option)"
              :disabled="option.disabled || loading"
              @select.prevent="handleSelect(option)"
            >
              <slot
                name="option"
                :option="option"
              >{{ getDisplayValue(option) }}</slot>
            </ComboboxItem>
          </ComboboxGroup>
        </ComboboxList>
      </Combobox>
    </template>
  </FormField>
</template>
