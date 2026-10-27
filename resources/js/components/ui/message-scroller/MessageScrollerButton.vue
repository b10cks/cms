<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { computed } from 'vue'

import Icon from '~/components/Icon.vue'
import type { ButtonVariants } from '~/components/ui/button'
import { Button } from '~/components/ui/button'
import { cn } from '~/lib/utils'
import { useI18n } from '~/plugins/i18n'

import type { MessageScrollerButtonDirection } from './useMessageScroller'
import { useMessageScroller, useMessageScrollerScrollable } from './useMessageScroller'

const props = withDefaults(
  defineProps<{
    class?: HTMLAttributes['class']
    direction?: MessageScrollerButtonDirection
    behavior?: ScrollBehavior
    variant?: ButtonVariants['variant']
    size?: ButtonVariants['size']
  }>(),
  {
    direction: 'end',
    behavior: 'smooth',
    variant: 'default',
    size: 'icon',
  }
)

const { t } = useI18n()
const { scrollToEnd, scrollToStart } = useMessageScroller()
const scrollable = useMessageScrollerScrollable()

const active = computed(() =>
  props.direction === 'start' ? scrollable.value.start : scrollable.value.end
)

function onClick(event: MouseEvent) {
  if (!active.value) return
  const target = event.currentTarget as HTMLElement | null
  target?.blur()
  if (event.defaultPrevented) return
  if (props.direction === 'start') scrollToStart({ behavior: props.behavior })
  else scrollToEnd({ behavior: props.behavior })
}
</script>

<template>
  <Button
    data-slot="message-scroller-button"
    type="button"
    :data-direction="direction"
    :data-active="active ? 'true' : 'false'"
    :variant="variant"
    :size="size"
    :inert="!active"
    :tabindex="active ? undefined : -1"
    :class="
      cn(
        'absolute start-1/2 -translate-x-1/2 rounded-full border border-popover-border bg-popover text-foreground shadow-soft-lg transition-[translate,scale,opacity] duration-200 hover:bg-secondary hover:text-foreground data-[active=false]:pointer-events-none data-[active=false]:scale-95 data-[active=false]:opacity-0 data-[active=false]:duration-400 data-[active=false]:ease-[cubic-bezier(0.7,0,0.84,0)] data-[active=true]:translate-y-0 data-[active=true]:scale-100 data-[active=true]:opacity-100 data-[active=true]:ease-[cubic-bezier(0.23,1,0.32,1)] data-[direction=end]:bottom-4 data-[direction=end]:data-[active=false]:translate-y-full data-[direction=start]:top-4 data-[direction=start]:data-[active=false]:-translate-y-full rtl:translate-x-1/2 data-[direction=start]:[&_svg]:rotate-180',
        props.class
      )
    "
    @click="onClick"
  >
    <slot>
      <Icon name="lucide:arrow-down" />
      <span class="sr-only">{{ t(`components.messageScroller.${direction}`) }}</span>
    </slot>
  </Button>
</template>
