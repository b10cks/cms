<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

/**
 * One topic on a settings page: the heading and its explanation on the left, the controls on the
 * right, a footer for the action that commits them. Stacks below ~42rem of container width, so
 * the same section reads on a phone and in a wide pane. `wide` puts the heading above and gives
 * the controls the full width, for sections built around tables. Sections are separated by
 * their parent (`divide-y`), not by a card border each: a settings page is one document.
 * Ported from V1Per.
 */
const props = defineProps<{
  title: string
  description?: string
  /** Anchor target, so `#tokens` scrolls straight to the section. */
  id?: string
  /** Paints the heading in the destructive color. Reserve it for what cannot be undone. */
  destructive?: boolean
  /** Heading above, controls across the full width. */
  wide?: boolean
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <section
    :id="id"
    :aria-labelledby="id ? `${id}-title` : undefined"
    :class="cn('@container scroll-mt-24 py-8 first:pt-0 last:pb-0', props.class)"
  >
    <div
      :class="
        wide
          ? 'grid gap-6'
          : 'grid gap-6 @2xl:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] @2xl:gap-10'
      "
    >
      <header class="grid content-start gap-1 select-none">
        <h2
          :id="id ? `${id}-title` : undefined"
          class="text-panel font-semibold text-pretty"
          :class="destructive ? 'text-destructive' : 'text-primary'"
        >
          {{ title }}
        </h2>
        <p
          v-if="description"
          class="text-sm text-pretty text-muted"
        >
          {{ description }}
        </p>
        <slot name="aside" />
      </header>

      <div class="grid min-w-0 content-start gap-6">
        <slot />
        <div
          v-if="$slots.footer"
          class="flex flex-wrap items-center justify-end gap-2"
        >
          <slot name="footer" />
        </div>
      </div>
    </div>
  </section>
</template>
