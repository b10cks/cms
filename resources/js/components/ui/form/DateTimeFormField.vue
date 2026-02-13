<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'
import { useVModel } from '@vueuse/core'
import FormField from './FormField.vue'
import Input from '../input/Input.vue'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

const props = defineProps<{
  // FormField props
  id?: string
  label?: string | CleanTranslation
  required?: boolean
  tooltip?: string | CleanTranslation
  description?: string | CleanTranslation
  error?: string
  class?: HTMLAttributes['class']
  inputClass?: HTMLAttributes['class']

  // DateTime-specific props
  modelValue?: string | null
  defaultValue?: string | null
  placeholder?: string
  disabled?: boolean
  readonly?: boolean
  name: string
  min?: string | Date
  max?: string | Date
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: string | null): void
}>()

const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue || null,
})

// Format ISO datetime string to date-time-local format for native input
const dateTimeInputValue = computed({
  get: () => {
    if (!modelValue.value) return ''
    const date = new Date(modelValue.value)
    // Format: 2024-01-26T14:30
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')
    const hours = String(date.getHours()).padStart(2, '0')
    const minutes = String(date.getMinutes()).padStart(2, '0')
    return `${year}-${month}-${day}T${hours}:${minutes}`
  },
  set: (value: string) => {
    if (!value) {
      modelValue.value = null
      return
    }
    // Parse datetime-local format to ISO string
    const date = new Date(value)
    modelValue.value = date.toISOString()
  },
})

// Min/Max for input
const minDateTime = computed(() => {
  if (!props.min) return undefined
  const date = props.min instanceof Date ? props.min : new Date(props.min)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day}T${hours}:${minutes}`
})

const maxDateTime = computed(() => {
  if (!props.max) return undefined
  const date = props.max instanceof Date ? props.max : new Date(props.max)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day}T${hours}:${minutes}`
})

const inputProps = computed(() => {
  const {
    id: _id,
    label: _label,
    tooltip: _tooltip,
    description: _desc,
    error: _error,
    class: _class,
    inputClass: _inputClass,
    modelValue: _modelValue,
    defaultValue: _defaultValue,
    ...rest
  } = props

  return {
    ...rest,
    class: props.inputClass,
    type: 'datetime-local',
  }
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
    <template #default="{ id: fieldId, hasError }">
      <Input
        :id="fieldId"
        v-model="dateTimeInputValue"
        :class="[{ 'border-red-500': hasError }, props.inputClass]"
        :min="minDateTime"
        :max="maxDateTime"
        :placeholder="placeholder || 'Select date and time'"
        v-bind="{ ...inputProps, ...$attrs }"
      />
    </template>
  </FormField>
</template>
