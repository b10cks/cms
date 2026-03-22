<script lang="ts" setup>
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <ol
    :class="
      cn(
        'flex flex-wrap items-center gap-1.5 font-medium text-sm wrap-break-word text-muted',
        props.class
      )
    "
  >
    <slot />
  </ol>
</template>
