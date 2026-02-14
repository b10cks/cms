<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '~/components/ui/tooltip/index';

const props = withDefaults(
  defineProps<{
    tooltip: string
    delayDuration?: number
    side?: 'top' | 'right' | 'bottom' | 'left'
    class?: HTMLAttributes['class']
  }>(),
  {
    delayDuration: 300,
    side: 'top',
    class: '',
  }
)
</script>

<template>
  <TooltipProvider :delay-duration="delayDuration">
    <Tooltip>
      <TooltipTrigger
        type="button"
        :class="props.class"
      >
        <slot />
      </TooltipTrigger>
      <TooltipContent :side="side">
        <p>{{ tooltip }}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
</template>
