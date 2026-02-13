<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <thead :class="cn('bg-elevated [&_tr]:border-b [&_tr]:border-b-secondary', props.class)">
    <slot />
  </thead>
</template>
