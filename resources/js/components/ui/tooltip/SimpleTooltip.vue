<script setup lang="ts">
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '~/components/ui/tooltip/index'
import type { HTMLAttributes } from 'vue'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'

const props = withDefaults(
  defineProps<{
    tooltip: string | CleanTranslation
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
