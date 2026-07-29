<script setup lang="ts">
import { useVModel } from '@vueuse/core'
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

import Input from '../input/Input.vue'
import FormField from './FormField.vue'

type InputActionType = 'clear' | 'copy'

const { $t } = useI18n()

const props = defineProps<{
  // FormField props
  id?: string
  label?: string
  required?: boolean
  tooltip?: string
  description?: string
  error?: string | null
  autoFocus?: boolean
  class?: HTMLAttributes['class']
  inputClass?: HTMLAttributes['class']
  actions?: Array<InputActionType>
  actionTabindex?: number | string

  // Input props
  modelValue?: string | number | null
  defaultValue?: string | number | null
  placeholder?: unknown
  type?: string
  disabled?: boolean
  readonly?: boolean
  name: string
}>()

const icons = {
  clear: 'lucide:x-circle',
  copy: 'lucide:copy',
}

const emits = defineEmits<{
  (e: 'update:modelValue' | InputActionType, payload: string | number): void
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

const trigger = (action: InputActionType) => {
  if (action === 'clear') {
    modelValue.value = ''
  } else if (action === 'copy') {
    navigator.clipboard.writeText(modelValue.value as string)
    toast.info($t('notifications.inputField.copied'))
  }
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
      <div class="relative">
        <Input
          :id="id"
          v-model="modelValue"
          :class="{ 'border-red-500': hasError }"
          v-bind="{ ...inputProps, ...$attrs }"
        />
        <div class="absolute top-1/2 right-1 flex -translate-y-1/2 items-center gap-0.5">
          <slot name="append"></slot>
          <Button
            v-for="action in actions"
            :key="action"
            size="xs"
            :aria-label="action"
            :tabindex="actionTabindex"
            @click="trigger(action)"
          >
            <Icon :name="icons[action]" />
          </Button>
        </div>
      </div>
    </template>
  </FormField>
</template>
