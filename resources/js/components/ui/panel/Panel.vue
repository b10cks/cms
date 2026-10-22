<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

/**
 * A block on the canvas: hairline edge, card tone, and an optional 56px header with the title, a
 * muted count or context after a bullet, and controls at the right end. The body is padded unless
 * `flush`, which suits a table or a chart that runs to the edges. Ported from V1Per.
 */
const props = defineProps<{
  title?: string
  /** Short muted context after the title, for example "214 entries". Hidden in narrow panels. */
  meta?: string
  /** Heading id, so the body can point `aria-labelledby` at it. */
  id?: string
  flush?: boolean
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <section
    :aria-labelledby="title && id ? id : undefined"
    :class="
      cn(
        '@container flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card text-card-foreground shadow-soft-sm',
        props.class
      )
    "
  >
    <header
      v-if="title || $slots.title || $slots.actions"
      class="flex h-14 shrink-0 items-center gap-2.5 pr-3 pl-5 select-none"
    >
      <slot name="title">
        <h2
          v-if="title"
          :id="id"
          class="min-w-0 truncate text-panel font-semibold text-primary"
        >
          {{ title }}
        </h2>
      </slot>
      <span
        v-if="meta"
        class="hidden min-w-0 shrink-[100] truncate text-sm text-muted tabular-nums @md:inline"
      >
        {{ meta }}
      </span>
      <div
        v-if="$slots.actions"
        class="ml-auto flex shrink-0 items-center gap-2"
      >
        <slot name="actions" />
      </div>
    </header>
    <div
      :class="
        flush
          ? 'min-w-0 flex-1 [&_td:first-child]:pl-5 [&_td:last-child]:pr-5 [&_th:first-child]:pl-5 [&_th:last-child]:pr-5'
          : cn('min-w-0 flex-1 px-5 pb-5', !(title || $slots.title || $slots.actions) && 'pt-5')
      "
    >
      <slot />
    </div>
  </section>
</template>
