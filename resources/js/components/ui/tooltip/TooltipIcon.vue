<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import TooltipProvider from '~/components/ui/tooltip/TooltipProvider.vue'
import Tooltip from '~/components/ui/tooltip/Tooltip.vue'
import TooltipTrigger from '~/components/ui/tooltip/TooltipTrigger.vue'
import TooltipContent from '~/components/ui/tooltip/TooltipContent.vue'

withDefaults(
  defineProps<{
    icon?: string
    size?: string
    delayDuration?: number
  }>(),
  {
    icon: 'lucide:info',
    size: '1rem',
    delayDuration: 300,
  }
)
</script>

<template>
  <TooltipProvider :delay-duration="delayDuration">
    <Tooltip>
      <TooltipTrigger
        type="button"
        class="flex items-center"
      >
        <Icon
          :name="icon"
          :size="size"
        />
      </TooltipTrigger>
      <TooltipContent>
        <slot />
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
</template>
