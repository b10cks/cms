<script setup lang="ts">
import { useVModel } from '@vueuse/core'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

import Input from '../input/Input.vue'
import FormField from './FormField.vue'

const props = defineProps<{
  // FormField props
  id?: string
  label?: string
  required?: boolean
  tooltip?: string
  description?: string
  error?: string | null
  class?: HTMLAttributes['class']
  inputClass?: HTMLAttributes['class']

  // DateTime-specific props
  modelValue?: string
  defaultValue?: string
  placeholder?: string
  disabled?: boolean
  readonly?: boolean
  name: string
  type?: 'date' | 'time' | 'datetime-local'
  min?: string
  max?: string
  step?: number
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: string): void
}>()

const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue,
})

const inputProps = computed(() => {
  const {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    id,
    label,
    tooltip,
    description,
    error,
    class: _class,
    modelValue: _modelValue,
    defaultValue: _defaultValue,
    ...rest
  } = props

  return {
    ...rest,
    class: props.inputClass,
    type: props.type || 'date',
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
    <template #default="{ id, hasError }">
      <Input
        :id="id"
        v-model="modelValue"
        :class="{ 'border-red-500': hasError }"
        v-bind="{ ...inputProps, ...$attrs }"
      />
    </template>
  </FormField>
</template>
