<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'
import { useVModel } from '@vueuse/core'
import FormField from './FormField.vue'
import Input from '../input/Input.vue'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import { Button } from '~/components/ui/button'
import { toast } from 'vue-sonner'

type InputActionType = 'clear' | 'copy'

const { $t } = useI18n()

const props = defineProps<{
  // FormField props
  id?: string
  label?: string | CleanTranslation
  required?: boolean
  tooltip?: string | CleanTranslation
  description?: string | CleanTranslation
  error?: string
  autoFocus?: boolean
  class?: HTMLAttributes['class']
  inputClass?: HTMLAttributes['class']
  actions?: Array<InputActionType>

  // Input props
  modelValue?: string | number
  defaultValue?: string | number
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
        <div
          v-if="actions?.length"
          class="absolute top-1/2 right-1 flex -translate-y-1/2 items-center gap-0.5"
        >
          <Button
            v-for="action in actions"
            :key="action"
            size="xs"
            :aria-label="action"
            @click="trigger(action)"
          >
            <Icon :name="icons[action]" />
          </Button>
        </div>
      </div>
    </template>
  </FormField>
</template>
