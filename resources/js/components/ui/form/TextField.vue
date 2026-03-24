<script setup lang="ts">
import { useVModel } from '@vueuse/core'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

import { Textarea } from '~/components/ui/textarea'

import FormField from './FormField.vue'

const props = defineProps<{
  // FormField props
  id?: string
  label?: string
  required?: boolean
  tooltip?: string
  description?: string
  error?: string
  class?: HTMLAttributes['class']
  inputClass?: HTMLAttributes['class']

  // Input props
  autoSize?: boolean | number
  modelValue?: string | number | null
  defaultValue?: string | number | null
  placeholder?: unknown
  disabled?: boolean
  readonly?: boolean
  rows?: number
  name: string
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: string | number): void
}>()

const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue,
})

const inputProps = computed(() => {
  const {
    //
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

  return { ...rest, class: props.inputClass }
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
      <Textarea
        :id="id"
        v-model="modelValue"
        :class="{ 'border-red-500': hasError }"
        v-bind="{ ...inputProps, ...$attrs }"
      />
    </template>
  </FormField>
</template>
