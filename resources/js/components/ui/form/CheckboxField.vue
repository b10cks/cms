<script setup lang="ts">
import { useVModel } from '@vueuse/core'
import type { Translation } from 'nuxt-i18n-micro-types/src'
import type { HTMLAttributes } from 'vue'
import { Checkbox } from '~/components/ui/checkbox'
import { TooltipIcon } from '~/components/ui/tooltip'

const props = defineProps<{
  // FormField props
  id?: string
  label: string | Translation
  required?: boolean
  tooltip?: string | Translation
  description?: string | Translation
  error?: string
  class?: HTMLAttributes['class']

  // Input props
  modelValue?: boolean
  defaultValue?: boolean
  type?: string
  disabled?: boolean
  readonly?: boolean
  name: string
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: string | number): void
}>()

// Create two-way binding for modelValue
const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue,
})

const uniqueId = computed(
  () => props.id || `${props.name}-${Math.random().toString(36).substring(2, 9)}`
)
const hasError = computed(() => !!props.error)
</script>

<template>
  <div class="grid w-full gap-2">
    <div class="flex items-center gap-3">
      <Checkbox
        :id="uniqueId"
        v-model="modelValue"
        :class="['relative z-10 cursor-pointer', hasError && 'border border-destructive']"
        name="translatable"
      />
      <div class="flex items-center gap-2">
        <label
          :for="uniqueId"
          class="relative z-10 cursor-pointer font-semibold text-primary"
        >
          {{ label }}
        </label>
        <TooltipIcon
          v-if="tooltip"
          size="1.2em"
          >{{ tooltip }}
        </TooltipIcon>
      </div>
    </div>
    <p
      v-if="hasError"
      class="mt-1 text-sm leading-tight whitespace-pre-line text-destructive"
    >
      {{ error }}
    </p>
    <p
      v-if="description"
      class="text-sm text-text-muted"
    >
      {{ description }}
    </p>
  </div>
</template>
