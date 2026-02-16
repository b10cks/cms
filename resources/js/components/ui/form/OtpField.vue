<script setup lang="ts">
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'
import { useVModel } from '@vueuse/core'
import type { HTMLAttributes } from 'vue'
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
  maxlength?: number
  modelValue?: string | number
  defaultValue?: string | number
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
    maxlength,
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
      <div class="flex justify-center">
        <InputOTP
          :maxlength="maxlength || 6"
          :id="id"
          :class="{ 'border-red-500': hasError }"
          v-model="modelValue"
        >
          <InputOTPGroup>
            <InputOTPSlot
              v-for="i in maxlength"
              :key="i"
              :index="i - 1"
              v-bind="{ ...inputProps, ...$attrs }"
            />
          </InputOTPGroup>
        </InputOTP>
      </div>
    </template>
  </FormField>
</template>
