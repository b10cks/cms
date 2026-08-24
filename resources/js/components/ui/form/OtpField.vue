<script setup lang="ts">
import { useVModel } from '@vueuse/core'
import type { HTMLAttributes } from 'vue'

import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp'

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
  (e: 'complete', payload: string): void
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

const wrapper = ref<HTMLElement | null>(null)

/** The hidden input backing the slots; the visible boxes are plain divs. */
const input = () => wrapper.value?.querySelector('input')

/** Focus the field, e.g. to let the user retype a rejected code. */
const focus = () => input()?.focus()

/** Drop focus so password-manager inline menus close before the field unmounts. */
const blur = () => input()?.blur()

defineExpose({ focus, blur })
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
      <div
        ref="wrapper"
        class="flex justify-center"
      >
        <InputOTP
          :maxlength="maxlength || 6"
          :id="id"
          :class="{ 'border-red-500': hasError }"
          v-model="modelValue"
          @complete="emits('complete', $event)"
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
