<script setup lang="ts">
import type { DateValue } from '@internationalized/date'
import { DateFieldInput, DateFieldRoot } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps<{
    modelValue?: DateValue
    locale?: string
    class?: HTMLAttributes['class']
    'aria-label'?: string
  }>(),
  {
    locale: 'en',
  }
)

defineEmits<{
  (e: 'update:modelValue', value: DateValue | undefined): void
}>()
</script>

<template>
  <DateFieldRoot
    v-slot="{ segments }"
    :model-value="props.modelValue"
    :locale="props.locale"
    granularity="day"
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
      <DateFieldInput
        v-if="item.part === 'literal'"
        :part="item.part"
        class="text-muted"
      >
        {{ item.value }}
      </DateFieldInput>
      <DateFieldInput
        v-else
        :part="item.part"
        class="rounded px-0.5 tabular-nums outline-none focus:bg-accent focus:text-white data-[placeholder]:text-muted"
      >
        {{ item.value }}
      </DateFieldInput>
    </template>
  </DateFieldRoot>
</template>
