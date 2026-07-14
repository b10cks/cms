<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <thead :class="cn('bg-elevated [&_tr]:border-b [&_tr]:border-b-border', props.class)">
    <slot />
  </thead>
</template>
