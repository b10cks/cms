<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <span :class="cn('ml-auto pl-4 text-sm font-normal text-muted', props.class)">
    <slot />
  </span>
</template>
