<script setup lang="ts" generic="T">
import type { HTMLAttributes } from 'vue'
import { computed, ref } from 'vue'
import { useVModel } from '@vueuse/core'
import FormField from './FormField.vue'
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
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

export interface ComboboxOption<T = unknown> {
  value: T
  label: string | CleanTranslation
  disabled?: boolean
}

const props = defineProps<{
  id?: string
  label?: string | CleanTranslation
  required?: boolean
  tooltip?: string | CleanTranslation
  description?: string | CleanTranslation
  error?: string
  class?: HTMLAttributes['class']
  comboboxClass?: HTMLAttributes['class']

  modelValue?: T[] | T
  defaultValue?: T[] | T
  placeholder?: string | CleanTranslation
  disabled?: boolean
  readonly?: boolean
  name: string

  options: ComboboxOption<T>[]
  multiple?: boolean
  searchable?: boolean

  filterFn?: (option: ComboboxOption<T>, search: string, selectedValues: T[]) => boolean
  displayFn?: (option: ComboboxOption<T>) => string
  valueFn?: (option: ComboboxOption<T>) => T

  emptyText?: string | CleanTranslation
  loadingText?: string | CleanTranslation
  loading?: boolean
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: T[] | T): void
  (e: 'select' | 'remove', payload: { option: ComboboxOption<T>; value: T }): void
}>()

const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue ?? (props.multiple ? [] : undefined),
})

const searchValue = ref('')

const selectedValues = computed(() => {
  if (props.multiple) {
    return Array.isArray(modelValue.value) ? modelValue.value : []
  }
  return modelValue.value ? [modelValue.value] : []
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
      modelValue.value = currentValues as T[] | T
    }
  } else {
    modelValue.value = value as T[] | T
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
      modelValue.value = currentValues as T[] | T

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
            :model-value="selectedValues"
            :disabled="disabled || readonly"
            :class="{ 'border-red-500': hasError, 'pl-2': selectedValues.length > 0 }"
          >
            <TagsInputItem
              v-for="value in selectedValues"
              :key="value"
              :value="value"
            >
              <TagsInputItemText>{{
                getOptionByValue(value)?.label || String(value)
              }}</TagsInputItemText>
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
              {{ getDisplayValue(option) }}
            </ComboboxItem>
          </ComboboxGroup>
        </ComboboxList>
      </Combobox>
    </template>
  </FormField>
</template>
