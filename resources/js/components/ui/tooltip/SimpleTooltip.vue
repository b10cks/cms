<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '~/components/ui/tooltip/index'

// Attributes go to the trigger, so a wrapping trigger such as a dropdown's can
// put its handlers and aria state on the slotted element.
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    tooltip: string
    delayDuration?: number
    side?: 'top' | 'right' | 'bottom' | 'left'
    disabled?: boolean
    /** The slot's single element is the trigger. Required when it is a link or button. */
    asChild?: boolean
    class?: HTMLAttributes['class']
  }>(),
  {
    delayDuration: 300,
    side: 'top',
    disabled: false,
    asChild: false,
    class: '',
  }
)

// A trigger wrapping a disabled button (a span) gets no focus event from its child, only focusin.
const open = ref(false)
const openOnKeyboardFocus = (event: FocusEvent) => {
  if ((event.target as HTMLElement).matches(':focus-visible')) open.value = true
}
</script>

<template>
  <TooltipProvider :delay-duration="delayDuration">
    <Tooltip
      v-model:open="open"
      ignore-non-keyboard-focus
    >
      <TooltipTrigger
        v-bind="$attrs"
        :as-child="asChild"
        :type="asChild ? undefined : 'button'"
        @focusin="openOnKeyboardFocus"
        @focusout="open = false"
        :class="props.class"
      >
        <slot />
      </TooltipTrigger>
      <TooltipContent
        v-if="!disabled"
        :side="side"
      >
        <p>{{ tooltip }}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
</template>
