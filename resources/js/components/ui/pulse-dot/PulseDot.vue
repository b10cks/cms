<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { pulseDotVariant, type PulseDotVariants } from '.'

defineProps<{
  variant?: PulseDotVariants['variant']
  size?: PulseDotVariants['size']
  class?: HTMLAttributes['class']
  live?: boolean
}>()
</script>

<template>
  <div class="relative inline-flex">
    <div :class="cn(pulseDotVariant({ size, variant }), 'rounded-full')" />
    <div
      :class="
        cn(
          pulseDotVariant({ size, variant }),
          'absolute top-0 left-0 rounded-full',
          live && 'animate-ping'
        )
      "
    />
    <div
      :class="
        cn(
          pulseDotVariant({ size, variant }),
          'absolute top-0 left-0 rounded-full',
          live && 'animate-pulse'
        )
      "
    />
  </div>
</template>
