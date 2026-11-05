<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
  /** Keeps the header in view while the page scrolls, from `xl` up; narrower, the table scrolls itself. */
  stickyHeader?: boolean
}>()
</script>

<template>
  <div :class="['relative w-full overflow-auto', stickyHeader && 'xl:overflow-visible']">
    <table
      :class="
        cn(
          'w-full caption-bottom text-sm',
          stickyHeader && 'xl:[&_th]:sticky xl:[&_th]:top-14 xl:[&_th]:z-10 [&_th]:bg-muted-background',
          props.class
        )
      "
    >
      <slot />
    </table>
  </div>
</template>
