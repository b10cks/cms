<script setup lang="ts">
import { TimeFieldInput, TimeFieldRoot, type TimeValue } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps<{
    modelValue?: TimeValue
    locale?: string
    hourCycle?: 12 | 24
    minValue?: TimeValue
    maxValue?: TimeValue
    disabled?: boolean
    readonly?: boolean
    class?: HTMLAttributes['class']
  }>(),
  {
    locale: 'en',
    hourCycle: 12,
  }
)

defineEmits<{
  (e: 'update:modelValue', value: TimeValue | undefined): void
}>()
</script>

<template>
  <TimeFieldRoot
    v-slot="{ segments }"
    :model-value="props.modelValue"
    :locale="props.locale"
    :hour-cycle="props.hourCycle"
    :min-value="props.minValue"
    :max-value="props.maxValue"
    :disabled="props.disabled"
    :readonly="props.readonly"
    granularity="minute"
    :class="
      cn(
        'inline-flex h-8 items-center rounded-md border border-input-border bg-input px-2 text-sm font-medium text-primary shadow-sm transition-colors focus-within:ring-2 focus-within:ring-ring',
        props.class
      )
    "
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <template
      v-for="item in segments"
      :key="item.part"
    >
      <TimeFieldInput
        v-if="item.part === 'literal'"
        :part="item.part"
        class="text-input-muted"
      >
        {{ item.value }}
      </TimeFieldInput>
      <TimeFieldInput
        v-else
        :part="item.part"
        class="rounded px-0.5 tabular-nums uppercase outline-none focus:bg-accent-fill focus:text-white data-[placeholder]:text-input-muted"
      >
        {{ item.value }}
      </TimeFieldInput>
    </template>
  </TimeFieldRoot>
</template>
