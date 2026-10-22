<script setup lang="ts">
import { useVModel } from '@vueuse/core'
import type { HTMLAttributes } from 'vue'

import { Checkbox } from '~/components/ui/checkbox'
import FieldMessages from '~/components/ui/form/FieldMessages.vue'
import { TooltipIcon } from '~/components/ui/tooltip'
import { useFieldA11y } from '~/composables/useFieldA11y'

const props = defineProps<{
  // FormField props
  id?: string
  label?: unknown
  required?: boolean
  tooltip?: unknown
  description?: string
  error?: string | null
  class?: HTMLAttributes['class']

  // Input props
  modelValue?: boolean | 'indeterminate'
  defaultValue?: boolean | 'indeterminate'
  type?: string
  disabled?: boolean
  readonly?: boolean
  name: string
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: boolean | 'indeterminate'): void
}>()

// Create two-way binding for modelValue
const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue,
})

const { fieldId: uniqueId, hasError, describedBy, invalid } = useFieldA11y(props)
</script>

<template>
  <div class="grid w-full gap-2">
    <div class="flex items-center gap-3">
      <Checkbox
        :id="uniqueId"
        v-model="modelValue"
        class="relative z-10 cursor-pointer"
        :name="name"
        :aria-describedby="describedBy"
        :aria-invalid="invalid"
      />
      <div class="flex items-center gap-2">
        <label
          :for="uniqueId"
          class="relative z-10 cursor-pointer font-semibold text-primary"
        >
          {{ label }}
        </label>
        <TooltipIcon v-if="tooltip || description">
          <span class="block max-w-xs whitespace-pre-line">{{
            [tooltip, description].filter(Boolean).join('\n\n')
          }}</span>
        </TooltipIcon>
      </div>
    </div>
    <FieldMessages
      :id="uniqueId"
      :error="error"
      :description="description"
      :description-in-tooltip="!!label"
    />
  </div>
</template>
