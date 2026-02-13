<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <h3 :class="cn('text-lg leading-none font-semibold tracking-tight text-primary', props.class)">
    <slot />
  </h3>
</template>
