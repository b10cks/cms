<script setup lang="ts">
import type { PrimitiveProps } from 'reka-ui'
import { Primitive } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import Icon from '~/components/Icon.vue'

import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '../dropdown-menu'
import { Spinner } from '../spinner'
import type { ButtonVariants } from './index'
import { buttonVariants } from './index'

interface Props extends PrimitiveProps {
  variant?: ButtonVariants['variant']
  size?: ButtonVariants['size']
  class?: HTMLAttributes['class']
  primaryAction?: () => void
  disabled?: boolean
  menuDisabled?: boolean
  hasMenu?: boolean
  loading?: boolean
  /** Announced on the primary button, e.g. `Control+Shift+P Meta+Shift+P`. */
  ariaKeyshortcuts?: string
}

const props = withDefaults(defineProps<Props>(), {
  as: 'div',
  variant: 'default',
  size: 'default',
  disabled: false,
  menuDisabled: false,
  hasMenu: true,
})

const primaryButtonClasses = computed(() => {
  return [
    buttonVariants({ variant: props.variant, size: props.size }),
    'relative focus-visible:z-10',
    props.hasMenu ? 'rounded-r-none' : null,
    props.class,
  ].filter(Boolean)
})

const triggerButtonClasses = computed(() => {
  return [
    buttonVariants({ variant: props.variant, size: props.size }),
    'relative rounded-l-none !px-2 focus-visible:z-10',
    // Outline draws its own edge: overlap it by a pixel so the seam is one line, not two
    props.variant === 'outline' ? '-ml-px' : 'border-l border-l-current/20',
  ].filter(Boolean)
})
</script>

<template>
  <Primitive
    :as="as"
    :as-child="asChild"
    class="flex"
  >
    <DropdownMenu>
      <button
        :class="primaryButtonClasses"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
        :aria-keyshortcuts="ariaKeyshortcuts"
        @click="primaryAction && primaryAction()"
      >
        <Spinner v-if="loading" />
        <slot />
      </button>
      <DropdownMenuTrigger
        v-if="hasMenu"
        :disabled="menuDisabled || loading"
        :class="triggerButtonClasses"
        :aria-label="$t('actions.moreOptions')"
      >
        <Icon name="lucide:chevron-down" />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <slot name="menu" />
      </DropdownMenuContent>
    </DropdownMenu>
  </Primitive>
</template>
