<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import Tooltip from '~/components/ui/tooltip/Tooltip.vue'
import TooltipContent from '~/components/ui/tooltip/TooltipContent.vue'
import TooltipProvider from '~/components/ui/tooltip/TooltipProvider.vue'
import TooltipTrigger from '~/components/ui/tooltip/TooltipTrigger.vue'
import { useIsTouch } from '~/lib/viewport'

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

/** A tooltip never opens under a finger, so a tap opens the same text as a popover. */
const isTouch = useIsTouch()
</script>

<template>
  <Popover v-if="isTouch">
    <PopoverTrigger
      type="button"
      class="-m-2 flex items-center p-2 text-muted"
      :aria-label="$t('labels.moreInfo')"
    >
      <Icon
        :name="icon"
        :size="size"
      />
    </PopoverTrigger>
    <PopoverContent class="max-w-[calc(100vw-2rem)] text-sm">
      <slot />
    </PopoverContent>
  </Popover>
  <TooltipProvider
    v-else
    :delay-duration="delayDuration"
  >
    <Tooltip>
      <TooltipTrigger
        type="button"
        class="flex items-center text-muted transition-colors duration-100 hover:text-primary"
        :aria-label="$t('labels.moreInfo')"
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
