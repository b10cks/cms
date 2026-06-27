<script setup lang="ts">
import { TimeFieldInput, TimeFieldRoot, type TimeValue } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps<{
    modelValue?: TimeValue
    locale?: string
    hourCycle?: 12 | 24
    class?: HTMLAttributes['class']
    'aria-label'?: string
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
    granularity="minute"
    :aria-label="props['aria-label']"
    :class="
      cn(
        'inline-flex h-8 items-center rounded-md border border-input-border bg-input px-2 text-sm font-semibold text-primary shadow-sm focus-within:ring-1 focus-within:ring-ring',
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
        class="text-muted"
      >
        {{ item.value }}
      </TimeFieldInput>
      <TimeFieldInput
        v-else
        :part="item.part"
        class="rounded px-0.5 tabular-nums uppercase outline-none focus:bg-accent focus:text-white data-[placeholder]:text-muted"
      >
        {{ item.value }}
      </TimeFieldInput>
    </template>
  </TimeFieldRoot>
</template>
