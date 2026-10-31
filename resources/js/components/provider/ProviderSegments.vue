<script setup lang="ts" generic="T extends string">
import { cn } from '~/lib/utils'

/** A row of filter segments with counts, as on the Tenants and Users pages. */
defineProps<{
  segments: Array<{ value: T; label: string; count?: number | null }>
}>()

const model = defineModel<T>({ required: true })
</script>

<template>
  <div
    role="radiogroup"
    class="inline-flex shrink-0 items-center gap-0.5 rounded-lg bg-input p-0.5"
  >
    <button
      v-for="segment in segments"
      :key="segment.value"
      type="button"
      role="radio"
      :aria-checked="model === segment.value"
      :class="
        cn(
          'flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-semibold text-muted transition-colors hover:text-primary',
          model === segment.value && 'bg-secondary text-primary shadow-sm'
        )
      "
      @click="model = segment.value"
    >
      {{ segment.label }}
      <span
        v-if="segment.count != null"
        class="tabular-nums"
      >{{ segment.count.toLocaleString() }}</span>
    </button>
  </div>
</template>
